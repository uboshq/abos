<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Engines\Report\ReportResult;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayerUse;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ খাদ্য-খরচেও বন্ধ মাস কখনো বদলায় না — আগস্টের বিল অক্টোবরে বাতিল বা ফেরত হলে বিয়োগ অক্টোবরে, আগস্ট অক্ষত (IAS 8; fe,
 * ২১ অক্টোবর ২০২৬; [[RestaurantReports::foodCost()]])।
 *
 * আসল পথে: ওয়েবের `restaurant.report.show` (food-cost) আর ফোনের `/api/v1/reports/restaurant.food_cost` (Sanctum + অ্যাপের ক্ষমতা)।
 *
 *   ১০ আগস্ট: হাঁড়ি ৫০ প্লেট (১,৮০০ → প্লেট ৩৬), তারপর ১০% ভ্যাট; বিল A ১০ প্লেট @ ২৫০ + ভ্যাট, বিল B ২ প্লেট @ ২৫০ + ভ্যাট
 *     → আগস্ট: ১২ প্লেট · নিট বিক্রি ৩,০০০ · খাদ্য-খরচ ৪৩২ · ১৪.৪০%
 *   ১০ অক্টোবর: বিল B বাতিল (মালিকের বাতিল-ইনভয়েস), বিল A-র ১ প্লেট ফেরত
 *     → আগস্ট হুবহু আগের মতো; অক্টোবর: −৩ প্লেট · বিক্রি −৭৫০ · খরচ −১০৮
 */
final class AnOldDishBillCancelledOrReturnedLaterLeavesItsMonthAloneTest extends TestCase
{
    use RefreshDatabase;

    private const AUGUST = ['from' => '2026-08-01', 'to' => '2026-08-31'];

    private const OCTOBER = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Product $biryani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-08-10 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();
        app(SettingsService::class)->set('restaurant.enabled', true);
        // ⓘ রেস্তোরাঁ ফোনে ডিফল্টে বন্ধ ([[PhoneModules::ON_BY_DEFAULT]]-এ নেই) — ফোনের রিপোর্ট-দরজা নইলে ৪০৩ (module_off)
        app(SettingsService::class)->set(\App\Core\Services\PhoneModules::key('restaurant'), true);
        $this->assertTrue(app(\App\Core\Services\PhoneModules::class)->isOn('restaurant', false), 'প্রস্তুতিটাই ভুল — ফোনে রেস্তোরাঁ চালু হয়নি।');

        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 365])->save());

        $rice = $this->product('OD-RICE', 'Old rice');
        $meat = $this->product('OD-MEAT', 'Old chicken');
        $this->biryani = $this->product('OD-BIRYANI', 'Old biryani');
        $this->receive($rice, '100', '60.00');
        $this->receive($meat, '50', '300.00');

        $recipe = Recipe::query()->create(['product_id' => $this->biryani->id, 'kind' => Recipe::BATCH, 'yield_qty' => '50', 'is_active' => true]);
        foreach ([[$rice, '10'], [$meat, '4']] as $i => [$product, $qty]) {
            RecipeLine::query()->create(['recipe_id' => $recipe->id, 'product_id' => $product->id, 'qty' => $qty, 'waste_pct' => '0', 'sort' => $i]);
        }
        $kitchen = app(ProductionService::class);
        $pot = $kitchen->confirm($kitchen->create(['recipe_id' => $recipe->id, 'warehouse_id' => $this->warehouse->id, 'qty' => '50']));
        $this->assertSame('36.0000', $pot->unitCost(), 'প্রস্তুতিটাই ভুল — প্লেটের খরচ ৩৬ নয়।');

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $vat = Tax::query()->create(['code' => 'OD-VAT10', 'name_en' => 'OD-VAT10', 'name_bn' => 'OD-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        $this->biryani->forceFill(['tax_id' => $vat->id])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_august_stays_as_it_was_and_october_takes_the_cancellation_and_the_return(): void
    {
        $kept = $this->bill('10');
        $dropped = $this->bill('2');
        $this->assertSame('250.0000', bcadd((string) $kept->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT নেই।');
        $this->assertSame('2026-08-10', $kept->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা আগস্টের নয়।');

        $august = $this->figures(self::AUGUST);
        $this->assertSame(['sold' => '12.0000', 'revenue' => '3000.0000', 'food_cost' => '432.0000', 'pct' => '14.40'], $august,
            'প্রস্তুতিটাই ভুল — আগস্টের দুই বিল খাদ্য-খরচে নেই।');

        // ── অক্টোবর: পুরনো বিল বাতিল, আর পুরনো বিলের আংশিক ফেরত ──
        Carbon::setTestNow('2026-10-10 10:00:00');
        $october = $this->figures(self::OCTOBER);

        app(SalesInvoiceCancellationService::class)->request($dropped->fresh(), $this->owner->fresh(), 'ভুল টেবিল');
        $return = $this->giveBack($kept, '1');

        // প্রস্তুতি: বাতিলটা সত্যিই বাতিল আর তার উল্টো টান অক্টোবরের; ফেরত পাকা, অক্টোবরের, VAT সহ
        $this->assertSame(DocumentStatus::CANCELLED, $dropped->fresh()->status, 'প্রস্তুতিটাই ভুল — বিলটা বাতিল হয়নি।');
        $reversal = CostLayerUse::query()->where('source_type', SalesInvoice::STOCK_SOURCE.':cancel')->where('source_id', $dropped->id)->get();
        $this->assertNotEmpty($reversal, 'প্রস্তুতিটাই ভুল — বাতিল বিলের খরচ স্তরে ফেরেনি।');
        $this->assertSame(['2026-10-10'], $reversal->map(fn ($u) => Carbon::parse($u->trx_date)->toDateString())->unique()->values()->all(),
            'প্রস্তুতিটাই ভুল — বাতিলের উল্টো টান বাতিলের দিনে লেখা নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame('2026-10-10', $return->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — ফেরতটা অক্টোবরের নয়।');
        $this->assertSame('25.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT নেই।');

        // ⛔ আগস্ট হুবহু — ওয়েব আর ফোন
        $this->assertSame($august, $this->figures(self::AUGUST), '⛔ বন্ধ মাসের খাদ্য-খরচ বদলাল — বাতিল বা ফেরত বিলের মাসে বসেছে।');

        // ⭐ অক্টোবরে বিয়োগ: বাতিল ২ প্লেট (৫০০ / ৭২) আর ফেরত ১ প্লেট (২৫০ / ৩৬)
        $now = $this->figures(self::OCTOBER);
        $this->assertSame([
            'sold' => '-3.0000', 'revenue' => '-750.0000', 'food_cost' => '-108.0000',
        ], [
            'sold' => bcsub($now['sold'], $october['sold'], 4),
            'revenue' => bcsub($now['revenue'], $october['revenue'], 4),
            'food_cost' => bcsub($now['food_cost'], $october['food_cost'], 4),
        ], '⛔ অক্টোবরে বাতিল বা ফেরতের বিয়োগ নেই।');
    }

    // ── অঙ্কগুলো, আসল দরজা দিয়ে ──────────────────────────────────────────

    /**
     * ⓘ সারি না থাকলে শূন্য। অক্টোবরে কেবল বিয়োগ, তাই সারিটা ঋণাত্মক — রিপোর্ট সেটাও দেখায় (`SUM(qty) <> 0`)।
     *
     * @param  array{from: string, to: string}  $month
     * @return array<string, string>
     */
    private function figures(array $month): array
    {
        $this->web();
        $result = $this->get(route('restaurant.report.show', ['slug' => 'food-cost'] + $month))->assertOk()->viewData('result');
        $this->assertInstanceOf(ReportResult::class, $result, 'প্রস্তুতিটাই ভুল — পাতাটা ইঞ্জিনের রিপোর্ট নয়।');
        $row = collect($result->rows)->map(fn ($r) => (array) $r)->first(fn (array $r) => (int) $r['product_id'] === (int) $this->biryani->id) ?? [];

        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = collect($this->getJson('/api/v1/reports/restaurant.food_cost?'.http_build_query($month + ['perPage' => 200]))->assertOk()->json('rows'))
            ->firstWhere('product_name', $row['product_name'] ?? '__none__') ?? [];
        $this->web();

        $out = [
            'sold' => bcadd((string) ($row['sold'] ?? '0'), '0', 4),
            'revenue' => bcadd((string) ($row['revenue'] ?? '0'), '0', 4),
            'food_cost' => bcadd((string) ($row['food_cost'] ?? '0'), '0', 4),
            'pct' => bcadd((string) ($row['food_cost_pct'] ?? '0'), '0', 2),
        ];

        if ($row !== []) {
            $this->assertSame($out['revenue'], bcadd((string) ($phone['revenue'] ?? '0'), '0', 4), '⛔ ফোনের নিট বিক্রি আর ওয়েব দুই অঙ্ক।');
            $this->assertSame($out['food_cost'], bcadd((string) ($phone['food_cost'] ?? '0'), '0', 4), '⛔ ফোনের খাদ্য-খরচ আর ওয়েব দুই অঙ্ক।');
        }

        return $out;
    }

    // ── প্রস্তুতি ─────────────────────────────────────────────────────────

    private function bill(string $qty): SalesInvoice
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [['product_id' => $this->biryani->id, 'qty' => $qty, 'rate' => '250.00']]))->fresh('lines');
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

    private function web(): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($this->owner->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }
}
