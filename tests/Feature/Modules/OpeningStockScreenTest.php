<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * খোলা মজুদের পর্দা — পুরনো খাতা থেকে আসার দিনের একবারের কাজ।
 *
 * ── এখানে কী পাহারা দেওয়া হয় ────────────────────────────────────────
 * একটাই জিনিস, তিন কোণ থেকে: **তিন জায়গার মিল**। খোলা মজুদ বসলে গুদাম,
 * স্তর আর খতিয়ান — তিনটাই নড়তে হবে, আর একই অঙ্কে।
 *
 * এই ফাইলটার দরকার পড়েছে কারণ ঠিক এই মিলটাই একবার ভেঙেছিল: সিডার
 * গুদাম আর স্তরে বসাত, খতিয়ানে নয়। ৮,৪০,০০০ টাকার মাল তাকে ছিল,
 * ব্যালেন্স শিটে শূন্য। ৮১৫টা টেস্ট তখন সবুজ ছিল — কারণ কোনোটাই দুইটা
 * সংখ্যা পাশাপাশি রাখত না।
 */
class OpeningStockScreenTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        /*
         * সিডারের প্রতিটা পণ্যেই খোলা মজুদ বসানো, তাই একটা নতুন পণ্য
         * বানানো হয় — নইলে প্রতিটা পরীক্ষাই "আগেই বসানো হয়েছে" বলে
         * থামত, আর আসল আচরণটা কখনো পরীক্ষাই হত না।
         */
        $this->product = Product::query()->create([
            'company_id' => $company->id,
            'code' => 'NEW-001',
            'name_en' => 'Fresh Item',
            'name_bn' => 'নতুন পণ্য',
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '100.0000',
            'sale_price' => '120.0000',
            'is_active' => true,
        ]);
    }

    private function service(): OpeningStockService
    {
        return app(OpeningStockService::class);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0.0000',
            );
    }

    /**
     * এক এন্ট্রি, তিন জায়গায় — আর তিনটাই একই অঙ্কে।
     *
     * এটাই এই পর্দার একমাত্র আসল দায়িত্ব। বাকি সব পরীক্ষা এই একটার
     * প্রান্তগুলো দেখে।
     */
    public function test_one_entry_moves_the_shelf_the_layers_and_the_ledger(): void
    {
        $stock = app(StockService::class);
        $layers = app(CostLayerService::class);

        $floorBefore = $stock->floorQty($this->product, $this->warehouse);
        $ledgerBefore = $this->balanceOf(StandardChart::INVENTORY);
        $equityBefore = $this->balanceOf(StandardChart::RETAINED_EARNINGS);

        $this->service()->bringIn($this->product, $this->warehouse, '25', '80');

        // ১. গুদাম
        $this->assertSame(0, bccomp(
            $stock->floorQty($this->product, $this->warehouse),
            bcadd($floorBefore, '25', 4),
            4,
        ));

        // ২. স্তর — ২৫ × ৮০
        $this->assertSame(0, bccomp($layers->valueOnHand($this->product), '2000', 4));

        // ৩. খতিয়ান — মজুদ ঠিক ততটাই বাড়ল
        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $ledgerBefore, 4),
            '2000',
            4,
        ));

        // আর প্রতিপক্ষ অবশিষ্ট মুনাফা, আয় নয়
        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::RETAINED_EARNINGS), $equityBefore, 4),
            '-2000',
            4,
        ));

        $this->assertSame(0, bccomp('0', $this->balanceOf(StandardChart::SALES), 4));
        $this->assertSame(0, bccomp('0', $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS), 4));
    }

    /**
     * একই পণ্য দুই গুদামে — দুইটা আলাদা দাখিলা, একটাও হারায় না।
     *
     * উৎস হিসেবে পণ্যের id দিলে দ্বিতীয়টা নীরবে বাদ পড়ত: পোস্টিং ইঞ্জিন
     * একই উৎসে দুইবার বসতে দেয় না। সিডারে ঠিক তাই হয়েছিল — নেত্রকোনার
     * ৪০ বস্তা চাল, ১,৩৬,০০০ টাকা, কোনো ত্রুটিবার্তা ছাড়াই।
     */
    public function test_the_same_product_in_two_warehouses_both_reach_the_ledger(): void
    {
        $second = Warehouse::query()->where('id', '!=', $this->warehouse->id)->firstOrFail();

        $before = $this->balanceOf(StandardChart::INVENTORY);

        $this->service()->bringIn($this->product, $this->warehouse, '10', '50');
        $this->service()->bringIn($this->product, $second, '4', '50');

        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $before, 4),
            '700',
            4,
        ));

        $this->assertSame(2, StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('source_type', OpeningStockService::SOURCE_TYPE)
            ->count());
    }

    /** একই পণ্য একই গুদামে দুইবার বসে না — বসলে সংখ্যাটা দ্বিগুণ হত। */
    public function test_the_same_product_and_warehouse_cannot_be_entered_twice(): void
    {
        $this->service()->bringIn($this->product, $this->warehouse, '10', '50');

        $this->expectException(ValidationException::class);

        $this->service()->bringIn($this->product, $this->warehouse, '10', '50');
    }

    /**
     * মাল একবার নড়ে গেলে খোলা মজুদের সময় পেরিয়ে যায়।
     *
     * FIFO স্তর টানে বসার ক্রমে, তারিখে নয় — তাই পরে বসানো স্তর সারির
     * শেষে দাঁড়াত, আর শুরুর দিনের সস্তা মালটা তাকে পড়ে থেকে লাভ-ক্ষতি
     * নীরবে এলোমেলো করত।
     */
    public function test_opening_stock_is_refused_once_the_goods_have_moved(): void
    {
        $rice = Product::query()->orderBy('id')->firstOrFail();

        $this->assertFalse($this->service()->stillOpen($rice, $this->warehouse));

        $this->expectException(ValidationException::class);

        $this->service()->bringIn($rice, $this->warehouse, '10', '50');
    }

    /** দর ছাড়া খোলা মজুদ বসে না — শূন্য দরের মাল বেচলে পুরোটাই মুনাফা। */
    public function test_a_rate_is_required(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->bringIn($this->product, $this->warehouse, '10', '0');
    }

    /** পরিমাণ শূন্য বা ঋণাত্মক হলে কিছুই বসে না। */
    public function test_the_quantity_must_be_positive(): void
    {
        foreach (['0', '-5'] as $qty) {
            try {
                $this->service()->bringIn($this->product, $this->warehouse, $qty, '50');
                $this->fail("{$qty} পরিমাণে খোলা মজুদ বসে গেল।");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    /**
     * ব্যর্থ হলে কিছুই বসে না — তিনটার একটাও নয়।
     *
     * তিনটা কাজ একটা লেনদেনে বাঁধা। না বাঁধলে দর ভুল হলে মাল গুদামে
     * উঠে যেত আর খতিয়ান খালি থাকত — অর্থাৎ ঠিক যে ফাঁকটা সারানো হল,
     * সেটাই ফিরে আসত।
     */
    public function test_a_refused_entry_leaves_nothing_behind(): void
    {
        $stock = app(StockService::class);

        $floorBefore = $stock->floorQty($this->product, $this->warehouse);
        $ledgerBefore = $this->balanceOf(StandardChart::INVENTORY);

        try {
            $this->service()->bringIn($this->product, $this->warehouse, '10', '0');
        } catch (ValidationException) {
            // প্রত্যাশিত
        }

        $this->assertSame(0, bccomp($stock->floorQty($this->product, $this->warehouse), $floorBefore, 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::INVENTORY), $ledgerBefore, 4));
    }

    // ── পর্দা ────────────────────────────────────────────────────────

    public function test_the_screen_opens_and_lists_what_is_already_entered(): void
    {
        $response = $this->get(route('inventory.stock.opening'));

        $response->assertOk();

        // সিডারের সাতটা ঢোকা এখানেই দেখা যাওয়ার কথা
        $response->assertSee(Warehouse::query()->orderBy('id')->firstOrFail()->name(), false);
    }

    public function test_the_form_writes_all_three_places(): void
    {
        $ledgerBefore = $this->balanceOf(StandardChart::INVENTORY);

        $response = $this->post(route('inventory.stock.opening.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '12',
            'unit_cost' => '75',
            'trx_date' => now()->toDateString(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('saved');

        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $ledgerBefore, 4),
            '900',
            4,
        ));

        $this->assertSame(0, bccomp(
            app(CostLayerService::class)->valueOnHand($this->product),
            '900',
            4,
        ));
    }

    /**
     * চাবিটা আলাদা — যিনি রোজ গুদাম গোনেন তাঁর হাতে এটা থাকা উচিত নয়।
     *
     * এই একটা পর্দাই কোনো কাগজ বা অনুমোদন ছাড়া সরাসরি অবশিষ্ট মুনাফায়
     * টাকা বসাতে পারে, কারণ শুরুর দিনের মালের আগে কোনো কাগজ থাকেই না।
     */
    public function test_the_adjust_permission_alone_does_not_open_this_screen(): void
    {
        $role = Role::findOrCreate('stock-counter');
        $role->syncPermissions(Permission::query()->whereIn('name', [
            'inventory.stock.view',
            'inventory.stock.adjust',
            'inventory.stock.hold',
        ])->get());

        $counter = User::query()->create([
            'name' => 'গুদামের লোক',
            'email' => 'counter@abos.test',
            'password' => bcrypt('password'),
        ]);

        // চলতি কোম্পানি mass assignment-এ বসে না — switchCompany() সদস্যপদ
        // যাচাই করে, আর সেই পাহারাটা এড়ানোর পথ খোলা রাখা হয়নি
        $counter->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $counter->assignRole($role);

        $this->actingAs($counter)
            ->get(route('inventory.stock.opening'))
            ->assertForbidden();
    }
}
