<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ফ্রি-ভাণ্ডারে নেই, তাই ফ্রি দেওয়া গেল না — মালিক, ৪ অক্টোবর ২০২৬ (UB): *"free dewal ta tule daw othoba control panel e
 * switch daw. lote free thakle auto bosbe, na thakle free dite parbe"*।
 *
 * ⭐ সুইচ `sales.free_beyond_pool` (ডিফল্ট বন্ধ)। চালু: ভাণ্ডারে যতটা আছে ততটা সেখান থেকে, বাকিটা ঐ লটের নিজের মাল থেকে —
 * খাতায় Dr প্রচারের খরচ (৫২২২) / Cr মজুদ (১১২০), খরচের স্তরের দামে (IFRS ১৫: ফ্রি আয় নয়, খরচ)। লটের সীমা তবু খাটে।
 * ⓘ হাতে গোনা: লট "809", কেনা ১০০ @ ৪০, ফ্রি ০; ২৪ বেচে ২ ফ্রি → লটে ১০০ − ২৬ = ৭৪, প্রচারের খরচ ২ × ৪০ = ৮০।
 */
final class TheFreeCameOutOfOurOwnStockTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '10000000'])->save();
    }

    /** ⭐ একই মানুষ, একই লট: সুইচ বন্ধ — থামে; চালু — চলে, লট থেকে ২৬ বেরোয়, প্রচারের খরচ ৮০, খাতা মেলে */
    public function test_free_beyond_the_pool_comes_out_of_the_lot_only_while_the_switch_is_on(): void
    {
        [$product, $lot] = $this->lotWithoutFree('100');

        $this->assertFalse((bool) app(SettingsService::class)->get('sales.free_beyond_pool', false), 'ডিফল্ট বন্ধ নয়।');
        $this->sell($product, $lot, '24', '2')->assertSessionHasErrors();
        $this->assertSame('100', $this->floor($lot), '⛔ বন্ধ সুইচে থামা বিক্রিও মাল নিয়েছে।');

        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        $this->sell($product, $lot, '24', '2')->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->assertSame('74', $this->floor($lot), '⛔ লট থেকে ২৪ + ২ = ২৬ বেরোয়নি।');
        $this->assertSame('0', $this->freePool($lot), '⛔ ফ্রি-ভাণ্ডারে হাত পড়েছে।');
        $this->assertSame('80.0000', $this->booked(StandardChart::PROMOTION_EXPENSE, 'debit', $challan), '⛔ প্রচারের খরচ খাতায় নেই।');
        $this->assertSame('80.0000', $this->booked(StandardChart::INVENTORY, 'credit', $challan), '⛔ মজুদ থেকে ফ্রির খরচ বের হয়নি।');
        $this->assertBooksBalance();
    }

    /** ভাণ্ডারে ১ আছে, ফ্রি ৩ — ১ ভাণ্ডার থেকে, ২ নিজের মাল থেকে (খরচ ৮০) */
    public function test_the_pool_goes_first_and_only_the_rest_comes_from_stock(): void
    {
        [$product, $lot] = $this->lotWithoutFree('100', free: '1');
        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        $this->sell($product, $lot, '24', '3')->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->assertSame('0', $this->freePool($lot), '⛔ ভাণ্ডারের ১টা আগে যায়নি।');
        $this->assertSame('74', $this->floor($lot), '⛔ মাল থেকে ২৪ + ২ বেরোনোর কথা।');
        $this->assertSame('80.0000', $this->booked(StandardChart::PROMOTION_EXPENSE, 'debit', $challan));
    }

    /** ⛔ লটের সীমা তবু খাটে — লটে ১০, বেচা ৯ + ফ্রি ২ = ১১: থামে, কিছুই বসে না */
    public function test_the_lot_limit_still_holds_for_goods_and_free_together(): void
    {
        [$product, $lot] = $this->lotWithoutFree('10');
        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        $this->sell($product, $lot, '9', '2')->assertSessionHasErrors();
        $this->assertSame('10', $this->floor($lot));
        $this->assertSame(0, DeliveryChallan::query()->where('status', 'confirmed')->count());
    }

    /** সম্পাদনায় ফ্রি তুলে দিলে — নিজের মাল থেকে দেওয়া ফ্রি তাকে ফেরে, প্রচারের খরচ উল্টায়, খাতা মেলে */
    public function test_an_edit_that_drops_the_free_puts_the_stock_and_the_cost_back(): void
    {
        [$product, $lot] = $this->lotWithoutFree('100');
        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        $this->sell($product, $lot, '24', '2')->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->sell($product, $lot, '24', '0', ['edit_invoice_id' => $invoice->id])->assertSessionHasNoErrors();

        $this->assertSame('76', $this->floor($lot), '⛔ ফ্রির ২টা তাকে ফেরেনি।');
        $this->assertSame('0.0000', bcsub($this->booked(StandardChart::PROMOTION_EXPENSE, 'debit', $challan), $this->booked(StandardChart::PROMOTION_EXPENSE, 'credit', $challan), 4),
            '⛔ প্রচারের খরচ উল্টায়নি।');
        $this->assertBooksBalance();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function sell(Product $product, Batch $lot, string $qty, string $free, array $extra = [])
    {
        return $this->post(route('sales.direct.store'), [
            'own_transport' => '1',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'lines' => [['product_id' => $product->id, 'batch_id' => $lot->id, 'qty' => $qty, 'rate' => '50', 'free_qty' => $free]],
            ...$extra,
        ]);
    }

    /** @return array{0: Product, 1: Batch} */
    private function lotWithoutFree(string $qty, string $free = '0'): array
    {
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'OWN-FREE-'.uniqid(),
            'name_en' => 'Pineapple',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'is_active' => true,
            'sale_price' => '50',
            'purchase_price' => '40',
            'track_batch' => true,
        ]);

        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => $qty, 'free_qty' => '0', 'rate' => '40', 'batch_no' => '809']],
        ));

        $lot = Batch::query()->where('product_id', $product->id)->where('batch_no', '809')->firstOrFail();

        app(StockService::class)->place(
            product: $product, warehouse: $this->warehouse, qty: $qty,
            sourceType: PurchaseBill::STOCK_SOURCE, sourceId: $bill->id, batch: $lot,
        );

        // ⓘ সরবরাহকারীর ফ্রি থাকলে — লটের সারিতে, তারপর তাকে ([[PurchaseBillService::bringInFree()]]-এর একই পথ)
        if (bccomp($free, '0', 4) > 0) {
            app(StockService::class)->move(
                product: $product, warehouse: $this->warehouse,
                sourceType: PurchaseBill::STOCK_SOURCE.':free', sourceId: $bill->id,
                date: now()->toDateString(), documentNo: $bill->document_no, unplacedFree: $free, batch: $lot,
            );
            app(StockService::class)->place(
                product: $product, warehouse: $this->warehouse, qty: '0',
                sourceType: PurchaseBill::STOCK_SOURCE, sourceId: $bill->id, batch: $lot, freeQty: $free,
            );
        }

        return [$product, $lot];
    }

    private function floor(Batch $lot): string
    {
        $v = (string) StockMovement::query()->where('batch_id', $lot->id)->sum('floor_change');

        return rtrim(rtrim(bcadd($v, '0', 4), '0'), '.') ?: '0';
    }

    private function freePool(Batch $lot): string
    {
        return rtrim(rtrim(bcadd($lot->fresh()->freeBalance($this->warehouse), '0', 4), '0'), '.') ?: '0';
    }

    private function booked(string $code, string $side, DeliveryChallan $challan): string
    {
        return bcadd((string) DB::table('ledger_entries as le')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('a.code', $code)
            ->whereIn('le.source_type', [DeliveryChallan::STOCK_SOURCE.':free', DeliveryChallan::STOCK_SOURCE.':free:reversal'])
            ->where('le.source_id', $challan->id)
            ->sum('le.'.$side), '0', 4);
    }

    private function assertBooksBalance(): void
    {
        $sums = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();

        $this->assertSame(0, bccomp((string) $sums->d, (string) $sums->c, 4), '⛔ খাতার দুই পাশ মেলে না।');
    }
}
