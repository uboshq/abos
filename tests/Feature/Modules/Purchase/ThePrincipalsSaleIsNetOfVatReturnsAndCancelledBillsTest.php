<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Report\ReportResult;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
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
 * ⭐ প্রিন্সিপালের নিষ্পত্তি আর পুঁজির ফেরত — বিক্রি নিট (ভ্যাট বাদে, ফেরত বাদে ফেরতের তারিখে), বাতিল বিল বাদ, আর খরচও ফেরত
 * আর বাতিলে কমে (fe, নিট বিক্রি ২য় ভাগ, ২১ অক্টোবর ২০২৬; IFRS 15.47/15.51; [[SettlementReport::soldMoves()]])।
 *
 * আসল পথে: ওয়েবের `purchase.report.show` (settlement, return-on-capital) আর ফোনের `/api/v1/reports/{key}` (Sanctum + অ্যাপের ক্ষমতা),
 * আসল কাগজে — নিশ্চিত বিল ([[SalesInvoiceService]]), নিশ্চিত আংশিক ফেরত ([[SalesReturnService]]), মালিকের হাতে বাতিল-ইনভয়েস
 * ([[SalesInvoiceCancellationService]])।
 *
 *   মাল এল ২০ @ ৮,০০০ (প্রিন্সিপালের চালানে), তারপর পণ্যে ১০% ভ্যাট (দামের উপরে)
 *   বিল ১০ @ ১০,০০০ = ১,০০,০০০ + ভ্যাট ১০,০০০ · খরচ ৮০,০০০
 *   ফেরত ১ = ১০,০০০ + ভ্যাট ১,০০০ · খরচ ৮,০০০ ফিরল
 *   বাতিল বিল ২ @ ১০,০০০ + ভ্যাট — কোথাও নেই
 *   ⇒ নিট বিক্রি ৯০,০০০.০০ · খরচ ৭২,০০০.০০ · মার্জিন ১৮,০০০.০০ (আগে: বিক্রি ১,২০,০০০, খরচ ৯৬,০০০ — বাতিল বাদে ১,০০,০০০ আর ৮০,০০০)
 */
final class ThePrincipalsSaleIsNetOfVatReturnsAndCancelledBillsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $principal;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->principal = Supplier::query()->whereIn('id', Supplier::onlySuppliersIds())->orderBy('id')->firstOrFail();

        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save());

        // ⓘ টাটকা পণ্য — ডেমোর স্তর ছাড়া; মাল আগে আসে, ভ্যাট পরে বসে (কেনার দামে ভ্যাট যেন খরচে না মেশে)
        $this->product = Product::query()->create([
            'company_id' => $this->company->id, 'code' => 'NET-SET', 'name_en' => 'Net settle rice', 'name_bn' => 'নিট নিষ্পত্তির চাল',
            'unit_id' => Product::query()->value('unit_id'), 'purchase_price' => '8000', 'sale_price' => '10000', 'is_active' => true,
        ]);
        $this->receive('20', '8000');

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');
        $vat = Tax::query()->create(['code' => 'SET-VAT10', 'name_en' => 'SET-VAT10', 'name_bn' => 'SET-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        $this->product->forceFill(['tax_id' => $vat->id])->save();
    }

    public function test_the_settlement_sells_net_of_vat_and_returns_and_leaves_a_cancelled_bill_out(): void
    {
        $before = $this->settlement();

        [$bill, $return, $cancelled] = $this->fixture();

        $after = $this->settlement();
        $d = fn (string $k) => bcsub($after['row'][$k], $before['row'][$k], 4);

        $this->assertSame('90000.0000', $d('sold'), '⛔ নিষ্পত্তির বিক্রি নিট নয় — ভ্যাট, ফেরত বা বাতিল বিল ঢুকেছে।');
        $this->assertSame('72000.0000', $d('cost_of_sold'), '⛔ বিক্রীত মালের ব্যয় ফেরত বা বাতিলে কমেনি।');
        $this->assertSame(bcsub((string) $bill->cost_of_goods, (string) $return->cost_of_goods, 4), $d('cost_of_sold'),
            '⛔ ব্যয় = বিলের খরচ − ফেরতের খরচ নয়।');
        $this->assertSame('18000.0000', $d('margin'), '⛔ মার্জিন = নিট বিক্রি − নিট খরচ নয়।');

        // ── মোটের সারি: প্রতিটা টাকার ঘরের মোট = সারিগুলোর যোগ, পয়সায়; আর নিট বিক্রির মোটও ৯০,০০০ বাড়ল ──
        foreach (['sold', 'cost_of_sold', 'margin'] as $key) {
            $this->assertSame($after['sum'][$key], $after['totals'][$key], "⛔ মোটের সারির {$key} সারিগুলোর যোগ নয়।");
        }
        $this->assertSame('90000.00', bcsub($after['totals']['sold'], $before['totals']['sold'], 2), '⛔ মোটের সারির বিক্রি নিট নয়।');

        // ── ফোন = ওয়েব ──
        $phone = $this->phone('purchase.settlement');
        $row = collect($phone['rows'])->firstWhere('supplier_name', $after['row']['supplier_name']);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — ফোনে প্রিন্সিপালের সারি নেই।');
        foreach (['sold', 'cost_of_sold', 'margin'] as $key) {
            $this->assertSame($after['row'][$key], bcadd((string) $row[$key], '0', 4), "⛔ ফোনের {$key} আর ওয়েব দুই অঙ্ক।");
            $this->assertSame($after['totals'][$key], bcadd((string) $phone['totals'][$key], '0', 2), "⛔ ফোনের মোট {$key} আর ওয়েব দুই অঙ্ক।");
        }

        // ── নাম ──
        $this->assertSame('নিট বিক্রি (ভ্যাট ও ফেরত বাদে)', $after['labels']['sold'], '⛔ নিষ্পত্তির বিক্রির ঘরের নাম নিট নয়।');
        $this->assertSame(DocumentStatus::CANCELLED, $cancelled->status);
    }

    public function test_return_on_capital_earns_the_same_net_margin(): void
    {
        $before = $this->capital();

        $this->fixture();

        $after = $this->capital();
        $this->assertSame('18000.0000', bcsub($after['margin'], $before['margin'], 4),
            '⛔ পুঁজির ফেরতের মার্জিন নিট নয় — ভ্যাট, ফেরত বা বাতিল বিল ঢুকেছে।');
        $this->assertSame($after['sum_margin'], $after['total_margin'], '⛔ মোটের সারির মার্জিন সারিগুলোর যোগ নয়।');

        // ⓘ একই পথ — নিষ্পত্তির মার্জিন আর পুঁজির ফেরতের মার্জিন একই প্রিন্সিপালে এক অঙ্ক (একই মাস)
        $settlement = $this->settlement(['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->endOfMonth()->toDateString()]);
        $capitalMonth = $this->capital(['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->endOfMonth()->toDateString()]);
        $this->assertSame($settlement['row']['margin'], $capitalMonth['margin'], '⛔ নিষ্পত্তি আর পুঁজির ফেরত দুই মার্জিন।');

        $phone = $this->phone('purchase.return_on_capital', ['from' => Carbon::today()->startOfYear()->toDateString(), 'to' => Carbon::today()->endOfYear()->toDateString()]);
        $row = collect($phone['rows'])->firstWhere('supplier_name', $after['supplier_name']);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — ফোনে প্রিন্সিপালের সারি নেই।');
        $this->assertSame($after['margin'], bcadd((string) $row['margin'], '0', 4), '⛔ ফোনের মার্জিন আর ওয়েব দুই অঙ্ক।');
    }

    // ── প্রস্তুতি ─────────────────────────────────────────────────────────

    /** @return array{0: SalesInvoice, 1: SalesReturn, 2: SalesInvoice} */
    private function fixture(): array
    {
        $today = Carbon::today()->toDateString();
        $bill = $this->bill('10');
        $return = $this->giveBack($bill, '1', $today);
        $cancelled = $this->bill('2');
        app(SalesInvoiceCancellationService::class)->request($cancelled->fresh(), $this->owner->fresh(), 'ভুল গ্রাহক');
        $cancelled = $cancelled->fresh();

        // প্রস্তুতি: VAT সত্যিই বিলে আর ফেরতে, ফেরত পাকা আর আজকের, বাতিল বিল সত্যিই বাতিল আর তার খরচ স্তরে ফিরেছে
        $this->assertSame('110000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ১,১০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ১০,০০০ নয়।');
        $this->assertSame('80000.0000', bcadd((string) $bill->cost_of_goods, '0', 4), 'প্রস্তুতিটাই ভুল — বিলের খরচ ১০ × ৮,০০০ নয়।');
        $this->assertSame('11000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ১১,০০০ নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ১,০০০ নয়।');
        $this->assertSame('8000.0000', bcadd((string) $return->cost_of_goods, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতের খরচ ৮,০০০ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame($today, $return->trx_date->toDateString());
        $this->assertSame('22000.0000', bcadd((string) $cancelled->total, '0', 4), 'প্রস্তুতিটাই ভুল — বাতিলের বিলটা VAT সহ ২২,০০০ নয়।');
        $this->assertSame(DocumentStatus::CANCELLED, $cancelled->status, 'প্রস্তুতিটাই ভুল — বিলটা বাতিল হয়নি।');
        $this->assertSame('-2.0000', bcadd((string) CostLayerUse::query()->where('source_type', SalesInvoice::STOCK_SOURCE.':cancel')
            ->where('source_id', $cancelled->id)->sum('qty'), '0', 4), 'প্রস্তুতিটাই ভুল — বাতিল বিলের খরচ স্তরে ফেরেনি।');

        return [$bill, $return, $cancelled];
    }

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
        ], [[
            'product_id' => $this->product->id,
            'qty' => $qty, 'rate' => '10000',
        ]]))->fresh('lines');
    }

    private function giveBack(SalesInvoice $bill, string $qty, string $on): SalesReturn
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => $on,
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => $qty,
        ]]))->fresh();
    }

    // ── আসল দরজা ──────────────────────────────────────────────────────────

    /**
     * @param  array{from: string, to: string}|null  $dates
     * @return array{row: array<string, string>, totals: array<string, string>, sum: array<string, string>, labels: array<string, string>}
     */
    private function settlement(?array $dates = null): array
    {
        $result = $this->page('settlement', $dates ?? $this->month());
        $keys = ['sold', 'cost_of_sold', 'margin'];
        $row = collect($result->rows)->map(fn ($r) => (array) $r)->first(fn (array $r) => (int) $r['supplier_id'] === (int) $this->principal->id);

        return [
            'row' => ['supplier_name' => (string) ($row['supplier_name'] ?? '')]
                + collect($keys)->mapWithKeys(fn (string $k) => [$k => bcadd((string) ($row[$k] ?? '0'), '0', 4)])->all(),
            'totals' => collect($keys)->mapWithKeys(fn (string $k) => [$k => bcadd((string) ($result->totals[$k] ?? '0'), '0', 2)])->all(),
            'sum' => collect($keys)->mapWithKeys(fn (string $k) => [$k => $this->sum($result->rows, $k)])->all(),
            'labels' => collect($result->report->columns)->mapWithKeys(fn ($c) => [$c->key => (string) __($c->label, [], 'bn')])->all(),
        ];
    }

    /**
     * @param  array{from: string, to: string}|null  $dates
     * @return array{margin: string, supplier_name: string, sum_margin: string, total_margin: string}
     */
    private function capital(?array $dates = null): array
    {
        $result = $this->page('return-on-capital', $dates ?? ['from' => Carbon::today()->startOfYear()->toDateString(), 'to' => Carbon::today()->endOfYear()->toDateString()]);
        $row = collect($result->rows)->map(fn ($r) => (array) $r)->first(fn (array $r) => (int) $r['supplier_id'] === (int) $this->principal->id);

        return [
            'margin' => bcadd((string) ($row['margin'] ?? '0'), '0', 4),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'sum_margin' => $this->sum($result->rows, 'margin'),
            'total_margin' => bcadd((string) ($result->totals['margin'] ?? '0'), '0', 2),
        ];
    }

    /** @param  array{from: string, to: string}  $dates */
    private function page(string $slug, array $dates): ReportResult
    {
        $this->web();
        $result = $this->get(route('purchase.report.show', ['slug' => $slug] + $dates))->assertOk()->viewData('result');
        $this->assertInstanceOf(ReportResult::class, $result, 'প্রস্তুতিটাই ভুল — পাতাটা ইঞ্জিনের রিপোর্ট নয়।');
        $this->assertLessThanOrEqual($result->perPage, $result->totalRows, 'প্রস্তুতিটাই ভুল — সারি এক পাতায় ধরেনি, যোগ মিলবে না।');

        return $result;
    }

    /**
     * @param  array{from: string, to: string}|null  $dates
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function phone(string $key, ?array $dates = null): array
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $json = $this->getJson('/api/v1/reports/'.$key.'?'.http_build_query(($dates ?? $this->month()) + ['perPage' => 200]))->assertOk()->json();
        $this->web();

        return ['rows' => $json['rows'], 'totals' => $json['totals']];
    }

    /**
     * সারিগুলোর যোগ, মোটের সারির একই গোলে (দুই ঘর, অর্ধেক হলে উপরে — [[Money::round()]])।
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $key): string
    {
        return Money::round(array_reduce($rows, fn (string $s, $r) => bcadd($s, (string) (((array) $r)[$key] ?? '0'), 8), '0'), 2);
    }

    /** @return array{from: string, to: string} */
    private function month(): array
    {
        return ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->endOfMonth()->toDateString()];
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
