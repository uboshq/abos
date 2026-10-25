<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Dashboard\SalesDashboard;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\RouteMetrics;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ লক্ষ্যের অর্জন নিট বিক্রিতে — VAT বাদে আর ফেরত বাদে (IFRS 15; fe, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি: বিক্রয়কর্মীর লক্ষ্য ১,০০,০০০; তাঁর ডিলারের কাছে VAT সহ ১,১০,০০০-এর বিল (১,০০,০০০ + ১০% VAT) আর VAT সহ ১১,০০০-এর ফেরত
 * (১০,০০০ + ১,০০০) — অর্জন ৯০,০০০, শতাংশ সেই অঙ্কে, চার জায়গায় একই:
 *   · লক্ষ্যের পাতা (`GET /sales/targets`, স্কোরবোর্ড);
 *   · বিক্রয়ের সারাংশের "লক্ষ্য" কার্ড (`GET /sales/overview`);
 *   · বিক্রয়ের ড্যাশবোর্ডের লক্ষ্যের ডোনাট;
 *   · রুটের লক্ষ্যের অর্জন ([[RouteMetrics::targetsFor()]])।
 * ⓘ ফেরতের আগে অর্জন ১,০০,০০০ (VAT বাদে) — VAT কখনো অর্জনে নয়; ফেরতের পরে ৯০,০০০।
 */
final class TargetsCountNetSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $sr;

    private Warehouse $warehouse;

    private Customer $rahim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->web();
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $vat = Tax::query()->create(['code' => 'TGT-VAT10', 'name_en' => 'TGT-VAT10', 'name_bn' => 'TGT-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->forceFill(['tax_id' => $vat->id])->save();

        $this->rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->rahim->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save();

        SalesTarget::query()->forMonth(Carbon::today())->where('user_id', $this->sr->id)->delete();
        SalesTarget::query()->create([
            'user_id' => $this->sr->id,
            'month' => Carbon::today()->startOfMonth()->toDateString(),
            'amount' => '100000',
        ]);
    }

    public function test_achievement_is_net_of_vat_and_returns_on_every_target_screen(): void
    {
        $before = $this->figures();

        $bill = $this->bill('10', '10000');
        $this->assertSame('110000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ১,১০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ১০,০০০ নয়।');

        $billed = $this->figures();
        $this->assertSame('100000.0000', bcsub($billed['scoreboard'], $before['scoreboard'], 4),
            '⛔ অর্জনে VAT ঢুকেছে, বা বিলটা বিক্রয়কর্মীর ঘরে যায়নি।');

        $return = $this->giveBack($bill, '1');
        $this->assertSame('11000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ১১,০০০ নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ১,০০০ নয়।');

        $after = $this->figures();

        foreach (['scoreboard', 'analytics', 'route'] as $where) {
            $this->assertSame('90000.0000', bcsub($after[$where], $before[$where], 4), "⛔ {$where}: অর্জন নিট বিক্রি (ভ্যাট ও ফেরত বাদে) নয়।");
        }

        // ⓘ শতাংশ সেই নিট অঙ্কে — লক্ষ্যের পাতা আর সারাংশের কার্ড
        $targets = app(SalesTargetService::class);
        $this->assertSame($targets->percent($after['scoreboard'], '100000'), $after['percent'], '⛔ লক্ষ্যের পাতার শতাংশ নিট অঙ্কে নয়।');

        $this->assertStringContainsString(Money::format($after['analytics']), $after['card_hint'], '⛔ সারাংশের লক্ষ্য কার্ড নিট অর্জন দেখায় না।');
        $this->assertSame(Money::format($after['analytics']), $after['donut'], '⛔ ডোনাটের অর্জন নিট নয়।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{scoreboard: string, percent: ?string, analytics: string, route: string, card_hint: string, donut: string} */
    private function figures(): array
    {
        $this->web();
        $month = Carbon::today()->startOfMonth();

        $row = collect($this->get(route('sales.target.index', ['month' => $month->toDateString()]))->assertOk()->viewData('rows'))
            ->first(fn (array $r) => (int) $r['user']->id === (int) $this->sr->id);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — লক্ষ্যের পাতায় বিক্রয়কর্মী নেই।');

        $this->web();
        $card = collect($this->get(route('sales.overview'))->assertOk()->viewData('cards'))->firstWhere('key', 'sales.overview.target');
        $this->assertNotNull($card, 'প্রস্তুতিটাই ভুল — সারাংশে লক্ষ্যের কার্ড নেই।');

        $this->web();
        $donut = collect(SalesDashboard::dashboard()->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('sales::dashboard.target'));
        $this->assertNotNull($donut, 'প্রস্তুতিটাই ভুল — লক্ষ্যের ডোনাট নেই।');

        $routeId = (int) $this->rahim->location_id;
        $this->assertGreaterThan(0, $routeId, 'প্রস্তুতিটাই ভুল — রহিম ট্রেডার্স কোনো রুটে নেই।');

        return [
            'scoreboard' => bcadd((string) $row['achieved'], '0', 4),
            'percent' => $row['percent'],
            'analytics' => bcadd(app(SalesAnalytics::class)->targetAchievement(Carbon::today())['achieved'], '0', 4),
            'route' => bcadd(app(RouteMetrics::class)->targetsFor([$routeId], $month)[$routeId]['achieved'], '0', 4),
            'card_hint' => (string) $card['hint'],
            'donut' => (string) $donut->parts[0]['value'],
        ];
    }

    private function bill(string $qty, string $rate): SalesInvoice
    {
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->rahim->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id,
            'qty' => $qty, 'rate' => $rate,
        ]]))->fresh('lines');
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
