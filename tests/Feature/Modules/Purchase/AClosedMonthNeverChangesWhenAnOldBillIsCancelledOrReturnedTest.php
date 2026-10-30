<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Report\ReportResult;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ বন্ধ মাসের অঙ্ক কখনো বদলায় না — আগস্টের বিল অক্টোবরে বাতিল বা ফেরত হলে বিয়োগ অক্টোবরে, আগস্ট অক্ষত (fe, ২১ অক্টোবর ২০২৬;
 * IFRS 15 আর IAS 8: ফেরত বা বাতিল চলতি সময়ের ঘটনা, আগের সময়ের ভুল নয়; [[SettlementReport::soldMoves()]],
 * [[PrincipalCommission::costOfSales()]])।
 *
 * আসল পথে, তিন রিপোর্টে: নিষ্পত্তি আর পুঁজির ফেরত (`purchase.report.show`), প্রিন্সিপালের কমিশন (`supplier.report.show`,
 * "আসল" ভিত্তি, চক্র ১–৩১), আর ফোনের `/api/v1/reports/{key}` (Sanctum + অ্যাপের ক্ষমতা)।
 *
 *   ১০ আগস্ট: মাল এল ২০ @ ৮,০০০ (প্রিন্সিপালের চালানে), তারপর ১০% ভ্যাট; বিল A ১০ @ ১০,০০০ + ভ্যাট, বিল B ২ @ ১০,০০০ + ভ্যাট
 *     → আগস্ট: নিট বিক্রি ১,২০,০০০ · খরচ ৯৬,০০০ · মার্জিন ২৪,০০০ · প্রিন্সিপালের অংশ ৯৬,০০০
 *   ১০ অক্টোবর: বিল B বাতিল (মালিকের বাতিল-ইনভয়েস), বিল A-র ১টা ফেরত
 *     → আগস্ট হুবহু আগের মতো; অক্টোবর: বিক্রি −৩০,০০০ · খরচ −২৪,০০০ · মার্জিন −৬,০০০ · অংশ −২৪,০০০
 */
final class AClosedMonthNeverChangesWhenAnOldBillIsCancelledOrReturnedTest extends TestCase
{
    use RefreshDatabase;

    private const AUGUST = ['from' => '2026-08-01', 'to' => '2026-08-31'];

    private const OCTOBER = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    private Company $company;

    private User $owner;

    private Supplier $principal;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-08-10 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->principal = Supplier::query()->whereIn('id', Supplier::onlySuppliersIds())->orderBy('id')->firstOrFail();
        // ⓘ প্রিন্সিপাল "আসল" ভিত্তিতে, বিলের শাখায়, চক্র মাসের ১ থেকে শেষ দিন — অংশ = বিক্রি হওয়া মালের কেনা দাম
        $this->principal->forceFill(['principal_branch_id' => $this->warehouse->branch_id, 'commission_basis' => 'actual', 'commission_rate' => null,
            'cycle_start_day' => 1, 'cycle_close_day' => 31])->save();

        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 365])->save());

        $this->product = Product::query()->create([
            'company_id' => $this->company->id, 'code' => 'OWN-DATE', 'name_en' => 'Own date rice', 'name_bn' => 'নিজের দিনের চাল',
            'unit_id' => Product::query()->value('unit_id'), 'purchase_price' => '8000', 'sale_price' => '10000', 'is_active' => true,
        ]);
        $this->receive('20', '8000');

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $vat = Tax::query()->create(['code' => 'OWN-VAT10', 'name_en' => 'OWN-VAT10', 'name_bn' => 'OWN-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        $this->product->forceFill(['tax_id' => $vat->id])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_august_stays_as_it_was_and_october_takes_the_cancellation_and_the_return(): void
    {
        $empty = $this->figures(self::AUGUST);

        $kept = $this->bill('10');
        $dropped = $this->bill('2');
        $this->assertSame('10000.0000', bcadd((string) $kept->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT নেই।');
        $this->assertSame('2026-08-10', $kept->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা আগস্টের নয়।');

        $august = $this->figures(self::AUGUST);
        $this->assertSame([
            'sold' => '120000.0000', 'cost' => '96000.0000', 'margin' => '24000.0000', 'capital_margin' => '24000.0000', 'share' => '96000.00',
        ], $this->delta($august, $empty), 'প্রস্তুতিটাই ভুল — আগস্টের দুই বিল রিপোর্টে নেই।');

        // ── অক্টোবর: পুরনো বিল বাতিল, আর পুরনো বিলের আংশিক ফেরত ──
        Carbon::setTestNow('2026-10-10 10:00:00');
        $october = $this->figures(self::OCTOBER);

        app(SalesInvoiceCancellationService::class)->request($dropped->fresh(), $this->owner->fresh(), 'ভুল গ্রাহক');
        $return = $this->giveBack($kept, '1');

        // প্রস্তুতি: বাতিলটা সত্যিই বাতিল আর তার উল্টো টান অক্টোবরের; ফেরত পাকা, অক্টোবরের, VAT সহ
        $this->assertSame(DocumentStatus::CANCELLED, $dropped->fresh()->status, 'প্রস্তুতিটাই ভুল — বিলটা বাতিল হয়নি।');
        $reversal = CostLayerUse::query()->where('source_type', SalesInvoice::STOCK_SOURCE.':cancel')->where('source_id', $dropped->id)->get();
        $this->assertSame('-2.0000', bcadd((string) $reversal->sum('qty'), '0', 4), 'প্রস্তুতিটাই ভুল — বাতিল বিলের খরচ স্তরে ফেরেনি।');
        $this->assertSame(['2026-10-10'], $reversal->map(fn ($u) => Carbon::parse($u->trx_date)->toDateString())->unique()->values()->all(),
            'প্রস্তুতিটাই ভুল — বাতিলের উল্টো টান বাতিলের দিনে লেখা নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame('2026-10-10', $return->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — ফেরতটা অক্টোবরের নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT নেই।');

        // ⛔ আগস্ট হুবহু — বিক্রি, খরচ, মার্জিন, পুঁজির মার্জিন, প্রিন্সিপালের অংশ আর কমিশন, ওয়েব আর ফোন
        $this->assertSame($august, $this->figures(self::AUGUST), '⛔ বন্ধ মাসের অঙ্ক বদলাল — বাতিল বা ফেরত বিলের তারিখে বসেছে।');

        // ⭐ অক্টোবরে বিয়োগ: বাতিল ২ (২০,০০০ / ১৬,০০০) আর ফেরত ১ (১০,০০০ / ৮,০০০)
        $this->assertSame([
            'sold' => '-30000.0000', 'cost' => '-24000.0000', 'margin' => '-6000.0000', 'capital_margin' => '-6000.0000', 'share' => '-24000.00',
        ], $this->delta($this->figures(self::OCTOBER), $october), '⛔ অক্টোবরে বাতিল বা ফেরতের বিয়োগ নেই।');
    }

    // ── অঙ্কগুলো, আসল দরজা দিয়ে ──────────────────────────────────────────

    /**
     * @param  array{from: string, to: string}  $month
     * @return array<string, string>
     */
    private function figures(array $month): array
    {
        $settlement = $this->row($this->page('purchase.report.show', ['slug' => 'settlement'] + $month));
        $capital = $this->row($this->page('purchase.report.show', ['slug' => 'return-on-capital'] + $month));
        $commission = $this->row($this->page('supplier.report.show', ['slug' => 'principal-commission', 'month' => substr($month['from'], 0, 7)]));

        $out = [
            'sold' => bcadd((string) ($settlement['sold'] ?? '0'), '0', 4),
            'cost' => bcadd((string) ($settlement['cost_of_sold'] ?? '0'), '0', 4),
            'margin' => bcadd((string) ($settlement['margin'] ?? '0'), '0', 4),
            'capital_margin' => bcadd((string) ($capital['margin'] ?? '0'), '0', 4),
            'share' => bcadd((string) ($commission['share'] ?? '0'), '0', 2),
            'commission' => bcadd((string) ($commission['commission'] ?? '0'), '0', 2),
        ];

        // ⓘ ফোন = ওয়েব, একই মাসে
        $phone = [
            'sold' => $this->phoneRow('purchase.settlement', $month, $settlement)['sold'] ?? '0',
            'capital_margin' => $this->phoneRow('purchase.return_on_capital', $month, $capital)['margin'] ?? '0',
            'share' => $this->phoneRow('supplier.principal_commission', ['month' => substr($month['from'], 0, 7)], $commission)['share'] ?? '0',
        ];
        $this->assertSame($out['sold'], bcadd((string) $phone['sold'], '0', 4), '⛔ ফোনের নিষ্পত্তি আর ওয়েব দুই অঙ্ক।');
        $this->assertSame($out['capital_margin'], bcadd((string) $phone['capital_margin'], '0', 4), '⛔ ফোনের পুঁজির মার্জিন আর ওয়েব দুই অঙ্ক।');
        $this->assertSame($out['share'], bcadd((string) $phone['share'], '0', 2), '⛔ ফোনের প্রিন্সিপালের অংশ আর ওয়েব দুই অঙ্ক।');

        return $out;
    }

    /**
     * @param  array<string, string>  $after
     * @param  array<string, string>  $before
     * @return array<string, string>
     */
    private function delta(array $after, array $before): array
    {
        return [
            'sold' => bcsub($after['sold'], $before['sold'], 4),
            'cost' => bcsub($after['cost'], $before['cost'], 4),
            'margin' => bcsub($after['margin'], $before['margin'], 4),
            'capital_margin' => bcsub($after['capital_margin'], $before['capital_margin'], 4),
            'share' => bcsub($after['share'], $before['share'], 2),
        ];
    }

    /** @param  array<string, mixed>  $query */
    private function page(string $route, array $query): ReportResult
    {
        $this->web();
        $result = $this->get(route($route, $query))->assertOk()->viewData('result');
        $this->assertInstanceOf(ReportResult::class, $result, "প্রস্তুতিটাই ভুল — {$route} ইঞ্জিনের রিপোর্ট নয়।");

        return $result;
    }

    /** @return array<string, mixed> প্রিন্সিপালের সারি, না থাকলে খালি */
    private function row(ReportResult $result): array
    {
        return collect($result->rows)->map(fn ($r) => (array) $r)
            ->first(fn (array $r) => (int) ($r['supplier_id'] ?? 0) === (int) $this->principal->id) ?? [];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $web  ওয়েবের সারি — নাম ধরে মেলাতে
     * @return array<string, mixed>
     */
    private function phoneRow(string $key, array $query, array $web): array
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $rows = $this->getJson('/api/v1/reports/'.$key.'?'.http_build_query($query + ['perPage' => 200]))->assertOk()->json('rows');
        $this->web();

        if ($web === []) {
            return [];
        }

        $row = collect($rows)->firstWhere('supplier_name', $web['supplier_name']);
        $this->assertNotNull($row, "প্রস্তুতিটাই ভুল — ফোনের {$key}-এ প্রিন্সিপালের সারি নেই।");

        return (array) $row;
    }

    // ── প্রস্তুতি ─────────────────────────────────────────────────────────

    private function receive(string $qty, string $rate): void
    {
        $this->web();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $this->principal->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => $qty, 'rate' => $rate]],
        ));

        app(StockService::class)->place(product: $this->product, warehouse: $this->warehouse, qty: $qty,
            sourceType: PurchaseReceipt::STOCK_SOURCE, sourceId: $receipt->id);
    }

    private function bill(string $qty): SalesInvoice
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '10000']]))->fresh('lines');
    }

    private function giveBack(SalesInvoice $bill, string $qty): SalesReturn
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => $qty,
        ]]))->fresh();
    }

    private function web(): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($this->owner->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }
}
