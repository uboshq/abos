<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

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
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Restaurant\Services\ProductionService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ খাদ্য-খরচের % = খাদ্য-খরচ ÷ নিট বিক্রি — কেবল পাকা বিল, ভ্যাট বাদে, ফেরত বাদে (fe, নিট বিক্রি ২য় ভাগ, ২১ অক্টোবর ২০২৬;
 * IFRS 15.47/15.51; [[RestaurantReports::foodCost()]])।
 *
 * আসল পথে: ওয়েবের `restaurant.report.show` (food-cost) আর ফোনের `/api/v1/reports/restaurant.food_cost` (Sanctum + অ্যাপের ক্ষমতা)।
 *
 *   হাঁড়ি ৫০ প্লেট: ১০ কেজি চাল @ ৬০ + ৪ কেজি মাংস @ ৩০০ = ১,৮০০ → প্লেট ৩৬; তারপর বিরিয়ানিতে ১০% ভ্যাট (দামের উপরে)
 *   পাকা বিল ১০ প্লেট @ ২৫০ = ২,৫০০ + ভ্যাট ২৫০ · খরচ ৩৬০
 *   আংশিক ফেরত ১ প্লেট = ২৫০ + ভ্যাট ২৫ · খরচ ৩৬
 *   ⛔ বাতিল বিল ২ প্লেট, খসড়া বিল ৩ প্লেট, মোছা বিল ৪ প্লেট — কোনোটাই নেই
 *   ⇒ বিক্রি ৯ প্লেট · নিট বিক্রি ২,২৫০.০০ · খাদ্য-খরচ ৩২৪.০০ · ১৪.৪০%
 *   (আগে: খসড়া আর মোছা বিলও গোনা হত, বিক্রি ভ্যাটসহ, ফেরত বাদ না — ১৭ প্লেট, বিক্রি ৪,৬৭৫)
 */
final class TheFoodCostIsOverNetSalesOfFinalBillsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Product $biryani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();

        // ⓘ রান্নাঘরের সুইচ খোলা — বন্ধ থাকলে পর্দাগুলো ৪০৪ ([[ThePotIsCookedOnceAndSoldFiftyTimesTest]])
        app(SettingsService::class)->set('restaurant.enabled', true);
        // ⓘ রেস্তোরাঁ ফোনে ডিফল্টে বন্ধ ([[PhoneModules::ON_BY_DEFAULT]]-এ নেই) — ফোনের রিপোর্ট-দরজা নইলে ৪০৩ (module_off)
        app(SettingsService::class)->set(\App\Core\Services\PhoneModules::key('restaurant'), true);
        $this->assertTrue(app(\App\Core\Services\PhoneModules::class)->isOn('restaurant', false), 'প্রস্তুতিটাই ভুল — ফোনে রেস্তোরাঁ চালু হয়নি।');

        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save());

        $rice = $this->product('NS-RICE', 'Net rice');
        $meat = $this->product('NS-MEAT', 'Net chicken');
        $this->biryani = $this->product('NS-BIRYANI', 'Net biryani');
        $this->receive($rice, '100', '60.00');
        $this->receive($meat, '50', '300.00');

        $recipe = Recipe::query()->create(['product_id' => $this->biryani->id, 'kind' => Recipe::BATCH, 'yield_qty' => '50', 'is_active' => true]);
        foreach ([[$rice, '10'], [$meat, '4']] as $i => [$product, $qty]) {
            RecipeLine::query()->create(['recipe_id' => $recipe->id, 'product_id' => $product->id, 'qty' => $qty, 'waste_pct' => '0', 'sort' => $i]);
        }

        $kitchen = app(ProductionService::class);
        $pot = $kitchen->confirm($kitchen->create(['recipe_id' => $recipe->id, 'warehouse_id' => $this->warehouse->id, 'qty' => '50']));
        $this->assertSame('36.0000', $pot->unitCost(), 'প্রস্তুতিটাই ভুল — প্লেটের খরচ ৩৬ নয়।');

        // ⭐ ভ্যাট রান্নার পরে — বিক্রিতে ভ্যাট, খরচে নয়
        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');
        $vat = Tax::query()->create(['code' => 'FC-VAT10', 'name_en' => 'FC-VAT10', 'name_bn' => 'FC-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        $this->biryani->forceFill(['tax_id' => $vat->id])->save();
    }

    public function test_the_food_cost_is_over_net_sales_of_final_bills_only(): void
    {
        $today = Carbon::today()->toDateString();

        $bill = $this->bill('10');
        $return = $this->giveBack($bill, '1', $today);
        $cancelled = $this->bill('2');
        app(SalesInvoiceCancellationService::class)->request($cancelled->fresh(), $this->owner->fresh(), 'ভুল টেবিল');
        $draft = $this->bill('3', false);
        $deleted = $this->bill('4');
        // ⓘ মোছা পাকা বিল — পর্দায় এমন পথ নেই, কিন্তু পুরনো বা হাতে-সারানো সারিতে থাকতে পারে; রিপোর্ট তাকে গুনবে না
        DB::table('sal_invoices')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        // ── প্রস্তুতি: VAT সত্যিই বিলে আর ফেরতে, ফেরত পাকা আর আজকের, বাকি তিনটা বিল সত্যিই বাতিল, খসড়া আর মোছা ──
        $this->assertSame('2750.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ২,৭৫০ নয়।');
        $this->assertSame('250.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ২৫০ নয়।');
        $this->assertSame('36.0000', bcadd((string) $bill->lines->first()->unit_cost, '0', 4), 'প্রস্তুতিটাই ভুল — বিলের প্লেটের খরচ ৩৬ নয়।');
        $this->assertSame('275.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ২৭৫ নয়।');
        $this->assertSame('25.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ২৫ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame($today, $return->trx_date->toDateString());
        $this->assertSame(DocumentStatus::CANCELLED, $cancelled->fresh()->status, 'প্রস্তুতিটাই ভুল — বিলটা বাতিল হয়নি।');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, 'প্রস্তুতিটাই ভুল — খসড়া বিলটা খসড়া নয়।');
        $gone = SalesInvoice::withTrashed()->find($deleted->id);
        $this->assertTrue($gone !== null && $gone->trashed(), 'প্রস্তুতিটাই ভুল — বিলটা মোছা হয়নি।');
        $this->assertContains($gone->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — মোছা বিলটা পাকা ছিল না।');

        // ── ওয়েবের পাতা ──
        $result = $this->page();
        $row = collect($result->rows)->map(fn ($r) => (array) $r)->first(fn (array $r) => (int) $r['product_id'] === (int) $this->biryani->id);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — খাদ্য-খরচে বিরিয়ানির সারি নেই।');

        $this->assertSame('9.0000', bcadd((string) $row['sold'], '0', 4), '⛔ প্লেটের সংখ্যায় খসড়া, বাতিল বা মোছা বিল ঢুকেছে, বা ফেরত বাদ যায়নি।');
        $this->assertSame('2250.0000', bcadd((string) $row['revenue'], '0', 4), '⛔ বিক্রি নিট নয় — ভ্যাট, ফেরত, খসড়া, বাতিল বা মোছা বিল ঢুকেছে।');
        $this->assertSame('324.0000', bcadd((string) $row['food_cost'], '0', 4), '⛔ খাদ্য-খরচ ফেরতে কমেনি, বা খসড়া/মোছা বিলের খরচ ঢুকেছে।');
        $this->assertSame('14.40', bcadd((string) $row['food_cost_pct'], '0', 2), '⛔ খাদ্য-খরচের % নিট বিক্রির উপর নয়।');

        // ── মোটের সারি: টাকা ও প্লেটের মোট = সারির যোগ, আর % = মোট খরচ ÷ মোট নিট বিক্রি (শতাংশের যোগ নয়) ──
        $sum = fn (string $k) => array_reduce($result->rows, fn (string $s, $r) => bcadd($s, (string) (((array) $r)[$k] ?? '0'), 8), '0');
        $this->assertSame(Money::round($sum('revenue'), 2), bcadd((string) $result->totals['revenue'], '0', 2), '⛔ মোটের সারির নিট বিক্রি সারির যোগ নয়।');
        $this->assertSame(Money::round($sum('food_cost'), 2), bcadd((string) $result->totals['food_cost'], '0', 2), '⛔ মোটের সারির খাদ্য-খরচ সারির যোগ নয়।');
        $this->assertSame(1, bccomp((string) $result->totals['revenue'], '0', 2), 'প্রস্তুতিটাই ভুল — মোটের সারিতে বিক্রি নেই।');
        $this->assertSame(bcdiv(bcmul((string) $result->totals['food_cost'], '100', 6), (string) $result->totals['revenue'], 2),
            (string) $result->totals['food_cost_pct'], '⛔ মোটের সারির % মোট খরচ ÷ মোট নিট বিক্রি নয়।');

        // ── ফোন = ওয়েব ──
        $phone = $this->phone();
        $line = collect($phone['rows'])->firstWhere('product_name', $row['product_name']);
        $this->assertNotNull($line, 'প্রস্তুতিটাই ভুল — ফোনে বিরিয়ানির সারি নেই।');
        foreach (['sold', 'revenue', 'food_cost'] as $key) {
            $this->assertSame(bcadd((string) $row[$key], '0', 4), bcadd((string) $line[$key], '0', 4), "⛔ ফোনের {$key} আর ওয়েব দুই অঙ্ক।");
        }
        $this->assertSame('14.40', bcadd((string) $line['food_cost_pct'], '0', 2), '⛔ ফোনের % আর ওয়েব দুই অঙ্ক।');
        $this->assertSame(bcadd((string) $result->totals['revenue'], '0', 2), bcadd((string) $phone['totals']['revenue'], '0', 2), '⛔ ফোনের মোট আর ওয়েব দুই অঙ্ক।');

        // ── নাম ──
        $label = collect($result->report->columns)->firstWhere('key', 'revenue')?->label;
        $this->assertSame('নিট বিক্রি (ভ্যাট ও ফেরত বাদে)', (string) __((string) $label, [], 'bn'), '⛔ বিক্রির ঘরের নাম নিট নয়।');
    }

    // ── প্রস্তুতি ─────────────────────────────────────────────────────────

    private function bill(string $qty, bool $confirm = true): SalesInvoice
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);
        $bill = $service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [['product_id' => $this->biryani->id, 'qty' => $qty, 'rate' => '250.00']]);

        return ($confirm ? $service->confirm($bill) : $bill)->fresh('lines');
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

    private function product(string $code, string $name): Product
    {
        return Product::query()->create([
            'code' => $code, 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);
    }

    private function receive(Product $product, string $qty, string $unitCost): void
    {
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: $unitCost, sourceType: 'test.opening', sourceId: $product->id);
    }

    // ── আসল দরজা ──────────────────────────────────────────────────────────

    /** @return array{from: string, to: string} */
    private function dates(): array
    {
        return ['from' => Carbon::today()->subDay()->toDateString(), 'to' => Carbon::today()->addDay()->toDateString()];
    }

    private function page(): ReportResult
    {
        $this->web();
        $result = $this->get(route('restaurant.report.show', ['slug' => 'food-cost'] + $this->dates()))->assertOk()->viewData('result');
        $this->assertInstanceOf(ReportResult::class, $result, 'প্রস্তুতিটাই ভুল — পাতাটা ইঞ্জিনের রিপোর্ট নয়।');
        $this->assertLessThanOrEqual($result->perPage, $result->totalRows, 'প্রস্তুতিটাই ভুল — সারি এক পাতায় ধরেনি।');

        return $result;
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    private function phone(): array
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $json = $this->getJson('/api/v1/reports/restaurant.food_cost?'.http_build_query($this->dates() + ['perPage' => 200]))->assertOk()->json();
        $this->web();

        return ['rows' => $json['rows'], 'totals' => $json['totals']];
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
