<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কাউন্টার (POS)।
 *
 * ── এখানে যা প্রমাণ করা দরকার ─────────────────────────────────────────
 * POS দ্রুত, কিন্তু আলাদা কোনো হিসাব নয়। তাই সবচেয়ে জরুরি পরীক্ষাটা এই:
 * কাউন্টারের বিক্রিতেও ঠিক সেই দাখিলাগুলো বসে যেগুলো সাধারণ বিলে বসে —
 * আয়, পাওনা, বিক্রীত পণ্যের ব্যয়, আর স্টক।
 *
 * আলাদা পথ হলে অমিলটা ধরা পড়ত মাস শেষে, যখন কেউ বলত "দোকানে যা বেচলাম,
 * খাতায় তার চেয়ে কম"।
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function pos(): PosService
    {
        return app(PosService::class);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @return array{invoice: SalesInvoice, change: string}
     */
    private function sell(string $qty = '2', string $rate = '100', ?string $paid = null, ?array $lines = null): array
    {
        return $this->pos()->checkout(
            [
                'warehouse_id' => $this->warehouse->id,
                'paid' => $paid ?? bcmul($qty, $rate, 2),
            ],
            $lines ?? [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
        );
    }

    // ── একই হিসাব, শুধু দ্রুত ───────────────────────────────────────────

    /**
     * কাউন্টারের বিক্রিতেও পুরো দাখিলা বসে।
     *
     * এই একটাই পরীক্ষা POS-এর মূল দাবিটা ধরে: এটা সমান্তরাল কোনো পথ নয়।
     */
    public function test_a_counter_sale_posts_the_same_entries_as_any_invoice(): void
    {
        $stock = app(StockService::class);
        $floorBefore = $stock->floorQty($this->product, $this->warehouse);

        $result = $this->sell('2', '100');

        $invoice = $result['invoice'];

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);

        // আয় ও পাওনা
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::SALES), '-200', 4));

        // টাকা এসে গেছে, তাই পাওনা শূন্যে ফিরেছে
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '200', 4));

        // বিক্রীত পণ্যের ব্যয় — সাধারণ বিলের মতোই
        $cost = bcmul('2', (string) $this->product->purchase_price, 4);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COST_OF_GOODS_SOLD), $cost, 4));

        // স্টক নেমেছে
        $this->assertSame(0, bccomp(
            $stock->floorQty($this->product, $this->warehouse),
            bcsub($floorBefore, '2', 4),
            4,
        ));
    }

    /** বিল ও আদায় দুইটাই তৈরি হয়, আর দুইটাই নিশ্চিত। */
    public function test_both_an_invoice_and_a_collection_are_created(): void
    {
        $this->sell('1', '500');

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $collection = Collection::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status);
        $this->assertSame(0, bccomp($invoice->dueAmount(), '0', 4));
    }

    // ── নগদ গ্রাহক ──────────────────────────────────────────────────────

    /**
     * গ্রাহক না বাছলে সেটিংসের নগদ গ্রাহক বসে।
     *
     * কাউন্টারে নাম জিজ্ঞেস করার সময় নেই — লাইন দাঁড়িয়ে থাকে।
     */
    public function test_a_sale_with_no_customer_goes_to_the_walk_in_customer(): void
    {
        $walkinId = (int) app(SettingsService::class)->get('sales.walkin_customer_id');

        $this->assertGreaterThan(0, $walkinId, 'ডেমোতে নগদ গ্রাহক বসানো থাকতে হবে');

        $result = $this->sell();

        $this->assertSame($walkinId, (int) $result['invoice']->customer_id);
    }

    /** নাম দিতে চাইলে সেই গ্রাহকের নামেই বসে। */
    public function test_a_named_customer_is_used_when_chosen(): void
    {
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $result = $this->pos()->checkout(
            ['customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'paid' => '200'],
            [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],
        );

        $this->assertSame($customer->id, (int) $result['invoice']->customer_id);
    }

    /**
     * নগদ গ্রাহক বসানো না থাকলে থেমে যাওয়া হয়।
     *
     * চুপচাপ প্রথম গ্রাহককে ধরে নিলে দিনের সব নগদ বিক্রি কোনো একজন
     * অচেনা মানুষের খাতায় জমা হত — আর তিনি একদিন বকেয়ার তালিকায় উঠতেন।
     */
    public function test_it_stops_when_no_walk_in_customer_is_configured(): void
    {
        app(SettingsService::class)->set('sales.walkin_customer_id', 0);

        $this->expectException(ValidationException::class);

        $this->sell();
    }

    // ── টাকা ও ফেরত ─────────────────────────────────────────────────────

    /**
     * বেশি টাকা দিলে ফেরতটা হিসাব করে বলা হয়, কিন্তু খাতায় বসে না।
     *
     * ফেরতটা আদায়ে বসালে গ্রাহকের নামে এমন জমা দেখাত যা তিনি রাখেননি,
     * আর ক্যাশ টিলে টাকাটা দুইবার গোনা হত।
     */
    public function test_change_is_worked_out_but_never_posted(): void
    {
        $result = $this->sell('2', '100', '500');

        $this->assertSame(0, bccomp($result['change'], '300', 4));

        // জমা হয়েছে কেবল বিলের সমান
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '200', 4));
    }

    /**
     * আংশিক টাকা দিলে বাকিটা গ্রাহকের নামে পাওনা থেকে যায়।
     *
     * কাউন্টারেও অর্ধেক নগদ, অর্ধেক বাকি হয় — বিশেষ করে চেনা দোকানদারের
     * বেলায়। ওটা আটকে দিলে বিক্রিটাই কাগজের বাইরে চলে যেত।
     */
    public function test_a_part_payment_leaves_the_rest_outstanding(): void
    {
        $result = $this->sell('2', '100', '50');

        $this->assertSame(0, bccomp($result['change'], '0', 4));
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '150', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '150', 4));
    }

    /** কিছুই না দিলে পুরোটাই বাকি — আদায়ের সারি বসে না। */
    public function test_paying_nothing_creates_no_collection(): void
    {
        $before = Collection::query()->count();

        $result = $this->sell('2', '100', '0');

        $this->assertSame($before, Collection::query()->count());
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '200', 4));
    }

    public function test_an_empty_basket_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->pos()->checkout(['paid' => '0'], []);
    }

    // ── স্টকের নিয়ম POS-এও খাটে ─────────────────────────────────────────

    /**
     * তাকে যা নেই তা কাউন্টারেও বেচা যায় না।
     *
     * POS আলাদা পথ হলে এই নিয়মটা এখানে থাকত না, আর দিনের শেষে স্টক
     * ঋণাত্মক হয়ে যেত।
     */
    public function test_stock_that_is_not_there_cannot_be_sold_at_the_counter(): void
    {
        $available = app(StockService::class)->availableQty($this->product, $this->warehouse);

        $this->expectException(ValidationException::class);

        $this->sell(bcadd($available, '1', 4), '100');
    }

    // ── পর্দা ও অনুমতি ──────────────────────────────────────────────────

    public function test_the_counter_screen_opens_with_the_catalogue(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.pos.index'))->assertOk();

        $products = $response->viewData('products');

        $this->assertNotEmpty($products);

        // প্রতিটা পণ্যের সাথে বিক্রয়যোগ্য সংখ্যা — নেই এমন মাল বেচার
        // আগেই যাতে চোখে পড়ে
        $this->assertObjectHasProperty('available', $products->first());
    }

    public function test_checking_out_through_the_screen_ends_at_the_receipt(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.pos.checkout'), [
            'warehouse_id' => $this->warehouse->id,
            'paid' => '300',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '2', 'rate' => '150'],
            ],
        ]);

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        // সোজা ৮০mm রসিদে — কাউন্টারে বিক্রির পরের কাজটা ছাপা
        $response->assertRedirect(route('sales.print.invoice', [
            'invoice' => $invoice->id,
            'paper' => '80mm',
        ]));
    }

    public function test_the_barcode_lookup_finds_a_product(): void
    {
        $product = Product::query()->whereNotNull('barcode')->firstOrFail();

        $this->actingAs($this->user)
            ->getJson(route('sales.pos.lookup', ['code' => $product->barcode]))
            ->assertOk()
            ->assertJsonPath('id', $product->id);

        $this->actingAs($this->user)
            ->getJson(route('sales.pos.lookup', ['code' => 'কিছুই-নেই']))
            ->assertNotFound();
    }

    public function test_a_user_without_the_permission_cannot_reach_the_counter(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger)->get(route('sales.pos.index'))->assertForbidden();
    }

    /** এক কোম্পানির কাউন্টারে অন্য কোম্পানির পণ্য আসে না। */
    public function test_the_catalogue_never_crosses_companies(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->user->switchCompany($other->id);
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $products = $this->actingAs($this->user)
            ->get(route('sales.pos.index'))
            ->assertOk()
            ->viewData('products');

        $this->assertCount(0, $products);
    }
}
