<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়মূল্য ঠিক হয় মাল আসার মুহূর্তে — তিন পথেই।
 *
 * ── কী বাদ পড়েছিল ───────────────────────────────────────────────────
 * ক্রয়দরের পাশে বিক্রয়মূল্যের ঘরটা (markup ও margin সহ) ছিল ক্রয় বিলে
 * ও Direct Receive-এ, কিন্তু **মাল বুঝে নেওয়ার পর্দায় ছিল না** — অথচ
 * ওখানেই মালটা সত্যিকারে গুদামে ঢোকে।
 *
 * ডিপোতে চালান আসে আগে, বিল পরে — কখনো সাতদিন পরে। ততক্ষণ নতুন দরে
 * কেনা মাল **পুরনো দামে** বিক্রি হয়ে যেত। ৪% মার্জিনের পরিবেশক
 * ব্যবসায় ওই কয়দিনেই মুনাফাটা মুছে যায়, আর কোনো ত্রুটিবার্তা আসে না —
 * শুধু বছরশেষে অঙ্কটা কম পড়ে।
 */
class ThePriceIsSetWhenTheGoodsArriveTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * তুফান বিস্কুটের মতো একটা চালান — ক্রয়দর ও বিক্রয়মূল্যসহ।
     *
     * @param  array<string, mixed>  $extra
     */
    private function receive(string $rate = '172.54', array $extra = []): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'received_qty' => '10',
                'rate' => $rate,
                ...$extra,
            ]],
        );
    }

    private function priceOfProduct(): string
    {
        return (string) $this->product->fresh()->sale_price;
    }

    /**
     * মাল বুঝে নেওয়ার সাথেই পণ্যের বিক্রয়মূল্য বসে।
     *
     * ১৭২.৫৪ ক্রয়দরে ৪% markup = ১৭৯.৪৪ — পরিবেশকের রোজকার অঙ্ক।
     */
    public function test_the_selling_price_lands_on_the_product(): void
    {
        app(PurchaseReceiptService::class)->confirm(
            $this->receive('172.54', ['sales_price' => '179.44']),
        );

        $this->assertSame(0, bccomp($this->priceOfProduct(), '179.44', 4),
            'চালানে দেওয়া বিক্রয়মূল্যটা পণ্যে বসেনি — মালটা পুরনো দামে বিক্রি হত।');
    }

    /**
     * ঘরটা খালি রাখলে আগের দামটাই টেকে।
     *
     * খালি মানে "দাম বদলাব না"। শূন্য ধরে নিলে দাম না বদলাতে চাওয়া
     * প্রতিটা লাইন পণ্যের দাম শূন্য করে দিত, আর প্রথম বিক্রিতেই পুরো
     * ক্রয়মূল্য লোকসান দেখাত।
     */
    public function test_an_empty_price_leaves_the_old_one_alone(): void
    {
        $this->product->update(['sale_price' => '200']);

        app(PurchaseReceiptService::class)->confirm($this->receive('172.54'));

        $this->assertSame(0, bccomp($this->priceOfProduct(), '200', 4),
            'খালি ঘরটা পণ্যের দাম বদলে দিয়েছে।');
    }

    /**
     * খসড়া চালানে দাম বসে না — নিশ্চিত করার আগে নয়।
     *
     * খসড়া কাগজ বদলানো যায়, মুছেও ফেলা যায়। ওই অবস্থাতেই দাম বসিয়ে
     * দিলে একটা বাতিল হয়ে যাওয়া চালানের দামে সারা মাস বিক্রি চলত।
     */
    public function test_a_draft_does_not_change_the_price(): void
    {
        $this->product->update(['sale_price' => '150']);

        $this->receive('172.54', ['sales_price' => '179.44']);

        $this->assertSame(0, bccomp($this->priceOfProduct(), '150', 4),
            'খসড়া চালানই পণ্যের দাম বদলে দিয়েছে।');
    }

    /** সম্পাদনার সময় দামটা ফর্মে ফিরে আসে — নাহলে প্রতিবার আবার টাইপ করতে হত। */
    public function test_the_price_comes_back_when_the_receipt_is_edited(): void
    {
        app(SettingsService::class)->set('purchase.screen_receipts', true);

        $receipt = $this->receive('172.54', ['sales_price' => '179.44']);

        $this->get(route('purchase.receipt.edit', $receipt))
            ->assertOk()
            ->assertSee('179.4400');
    }

    /** পর্দায় ঘরগুলো সত্যিই আছে — নাহলে কাজটা কোডে থাকত, ব্যবহারে নয়। */
    public function test_the_screen_offers_the_four_boxes(): void
    {
        app(SettingsService::class)->set('purchase.screen_receipts', true);

        $this->get(route('purchase.receipt.create'))
            ->assertOk()
            ->assertSee(__('purchase::field.sales_price'))
            ->assertSee(__('purchase::field.markup'))
            ->assertSee(__('purchase::field.margin'));
    }

    /**
     * বাক্সে মাল বুঝে নিলে দামটাও পিসে নামে।
     *
     * ── কেন এটা সবচেয়ে বিপজ্জনক ভুল ─────────────────────────────────
     * না নামালে বাক্সের দামটাই পণ্যের বিক্রয়মূল্য হয়ে মাস্টারে বসত, আর
     * পরদিন কাউন্টারে **প্রতিটা পিস বাক্সের দামে** বিক্রি হত। ক্রেতা
     * একবার টের পেলে দোকানের সুনামটাই যেত।
     */
    public function test_a_price_typed_in_boxes_comes_down_to_pieces(): void
    {
        /*
         * বাক্সটা এখানেই বানানো হয়, ডেমোর কোনো এককের উপর ভরসা করে নয়।
         *
         * আগে "বড় একক না থাকলে skip" লেখা ছিল, আর ডেমোতে ওটা ছিল না —
         * অর্থাৎ পরীক্ষাটা সবুজ দেখাত অথচ কিছুই পরীক্ষা করত না। যে
         * পাহারা অনুপস্থিতিতে সবুজ, সেটা পাহারা নয়।
         */
        $box = Unit::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'T-BOX',
            'name_en' => 'Box',
            'name_bn' => 'বাক্স',
            'base_unit_id' => $this->product->unit_id,
            'factor' => 10,
            'is_active' => true,
        ]);

        $this->assertNotNull(
            app(PackConversion::class)->unitsFor($this->product)->firstWhere('id', $box->id),
            'নতুন বাক্সটা এই পণ্যের এককের তালিকাতেই এল না।',
        );

        // এক বাক্সে ১০ পিস — তাই ১,৭৯৪.৪০ ÷ ১০ = ১৭৯.৪৪
        app(PurchaseReceiptService::class)->confirm($this->receive('1725.40', [
            'unit_id' => $box->id,
            'sales_price' => '1794.40',
        ]));

        $this->assertSame(0, bccomp($this->priceOfProduct(), '179.44', 4),
            'বাক্সের দামটাই পিসের দাম হয়ে বসেছে — কাউন্টারে প্রতিটা পিস বাক্সের দামে বিক্রি হত।');
    }
}
