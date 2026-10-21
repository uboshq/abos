<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Stat;
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
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ড্যাশবোর্ডের চার্ট আর গ্রাহকের এক নজর — সব নিট বিক্রি (বিল − ফেরত, VAT বাদে; fe-র নিট বিক্রি ২, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল পথে, আসল নিশ্চিত বিল ১,০০,০০০ + VAT ১০,০০০ আর আংশিক ফেরত ১০,০০০ + VAT ১,০০০ দিয়ে:
 *   · বিক্রয়ের ড্যাশবোর্ড (`GET /dashboard/sales` আর ফোনের `GET /api/v1/dashboard/sales`): "বিক্রেতা ধরে" দণ্ডে মালিক আর
 *     "এরিয়া ধরে" দণ্ডের যোগ ঠিক ৯০,০০০ বাড়ে ([[SalesCharts]] → [[SalesAnalytics]]);
 *   · গ্রাহকের ড্যাশবোর্ড (`GET /dashboard/customer`): "এ মাসের নিট বিক্রি" ঠিক ৯০,০০০ বাড়ে; গড় বিল আগের মতো বিলের মোট;
 *     ফোনের (`GET /api/v1/dashboard/customer`) সবচেয়ে বড় ক্রেতার সারি হাতে গোনা নিট, নিচে যোগের সারি;
 *   · গ্রাহকের সারাংশ (`GET /customers/{id}/summary`): এই মাস আর সব মিলিয়ে ঠিক ৯০,০০০ বাড়ে, পাশে লেখা "নিট বিক্রি"।
 */
final class TheDashboardChartsAndTheCustomerGlanceAreNetSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Customer $rahim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web();
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');

        $vat = Tax::query()->create(['code' => 'NS2A-VAT10B', 'name_en' => 'NS2A-VAT10B', 'name_bn' => 'NS2A-VAT10B', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->forceFill(['tax_id' => $vat->id])->save();

        $this->rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->rahim->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save();
    }

    public function test_the_sales_dashboard_seller_and_area_bars_are_net(): void
    {
        $before = $this->salesBars();
        $this->fixture();
        $after = $this->salesBars();

        $this->assertSame('90000.0000', bcsub($after['seller'], $before['seller'], 4),
            '⛔ বিক্রেতার দণ্ড VAT বা ফেরত বাদ দেয় না (১,১০,০০০ বা ১,০০,০০০ বেড়েছে)।');
        $this->assertSame('90000.0000', bcsub($after['area'], $before['area'], 4), '⛔ এরিয়ার দণ্ডের যোগ VAT বা ফেরত বাদ দেয় না।');

        // ⓘ ফোনের একই দরজা — একই দণ্ড
        $this->assertSame($after['seller'], $after['phone_seller'], '⛔ ফোনের বিক্রেতার দণ্ড ওয়েবের সাথে মেলে না।');

        $this->assertStringContainsString('নিট বিক্রি', __('sales::dashboard.by_seller'));
        $this->assertStringContainsString('নিট বিক্রি', __('sales::dashboard.by_territory'));
    }

    public function test_the_customer_dashboard_month_sales_and_top_buyers_are_net(): void
    {
        $before = $this->customerStat();
        [$bill] = $this->fixture();
        $after = $this->customerStat();

        $this->assertSame('90000.0000', bcsub($after, $before, 4), '⛔ গ্রাহকের ড্যাশবোর্ডের "এ মাসের বিক্রি" VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('এ মাসের নিট বিক্রি (VAT ও ফেরত বাদে)', __('customer::dashboard.sales_month'));

        // ── সবচেয়ে বড় ক্রেতা — ফোনের দরজা, হাতে গোনা নিট, আর যোগের সারি ──
        $this->phone();
        $listing = collect($this->getJson('/api/v1/dashboard/customer')->assertOk()->json('listings'))
            ->firstWhere('label', __('customer::dashboard.top_buyers'));
        $this->assertNotNull($listing, 'প্রস্তুতিটাই ভুল — সবচেয়ে বড় ক্রেতার তালিকা নেই।');
        $keys = array_column($listing['columns'], 'key');
        $rows = array_map(fn (array $r) => array_combine($keys, $r), $listing['rows']);
        $mine = collect($rows)->firstWhere('code', $this->rahim->code);
        $this->assertNotNull($mine, 'প্রস্তুতিটাই ভুল — রহিম এত বড় বিলের পরেও প্রথম পাঁচে নেই।');

        $this->assertSame(Money::format($this->handNetOf($this->rahim)), $mine['sold'], '⛔ বড় ক্রেতার "এ মাসে কেনা" হাতে গোনা নিট নয়।');
        $this->assertStringContainsString('·', $mine['name'], '⛔ ক্রেতার নামের পাশে পয়েন্ট নেই (নাম · পয়েন্ট · শাখা)।');

        $this->assertArrayHasKey('total', $listing, '⛔ বড় ক্রেতার তালিকার নিচে যোগের সারি নেই।');
        $sum = array_reduce($rows, fn (string $s, array $r) => bcadd($s, str_replace(',', '', $r['sold']), 4), '0');
        $this->assertSame(Money::format($sum), $listing['total']['sold'], '⛔ যোগের সারি সারিগুলোর যোগ নয়।');
        $this->assertNotNull($bill);
    }

    public function test_the_customer_glance_month_and_lifetime_are_net(): void
    {
        $before = $this->glance();
        $this->fixture();
        $after = $this->glance();

        $this->assertSame('90000.0000', bcsub($after['month'], $before['month'], 4), '⛔ সারাংশের "এই মাসে" VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('90000.0000', bcsub($after['lifetime'], $before['lifetime'], 4), '⛔ সারাংশের "সব মিলিয়ে" VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame(1, $after['count'] - $before['count'], 'প্রস্তুতিটাই ভুল — বিলের সংখ্যা এক বাড়েনি।');
        $this->assertStringContainsString('নিট বিক্রি (VAT ও ফেরত বাদে)', $after['html'], '⛔ সারাংশ বলে না যে টাকাটা নিট বিক্রি।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{0: SalesInvoice, 1: SalesReturn} বিল ১,১০,০০০ (VAT ১০,০০০) আর আংশিক ফেরত ১১,০০০ (VAT ১,০০০), আজ, খাতায় */
    private function fixture(): array
    {
        $today = Carbon::today()->toDateString();
        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);
        $bill = $service->confirm($service->create([
            'customer_id' => $this->rahim->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => $today,
        ], [[
            'product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id,
            'qty' => '10', 'rate' => '10000',
        ]]))->fresh('lines');

        $this->web();
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);
        $return = $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => $today,
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => '1',
        ]]))->fresh();

        $this->assertSame('110000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ১,১০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ১০,০০০ নয়।');
        $this->assertSame('0.0000', bcadd((string) $bill->freight_charge, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে গাড়িভাড়া আছে।');
        $this->assertContains($bill->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিল খাতায় বসেনি।');
        $this->assertSame('11000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ১১,০০০ নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ১,০০০ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');

        return [$bill, $return];
    }

    /** @return array{seller: string, area: string, phone_seller: string} মালিকের দণ্ড আর এরিয়ার দণ্ডের যোগ, কমা ছাড়া */
    private function salesBars(): array
    {
        $this->web();
        config(['abos.dashboards_v2' => true]);
        $dashboard = $this->get('/dashboard/sales')->assertOk()->viewData('dashboard');
        $bars = fn (string $key) => collect($dashboard->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('sales::dashboard.'.$key));
        $seller = $bars('by_seller');
        $area = $bars('by_territory');
        $value = fn (?Breakdown $b, ?string $label) => bcadd(str_replace(',', '', (string) (collect($b?->parts ?? [])->firstWhere('label', $label)['value'] ?? '0')), '0', 4);

        $this->phone();
        config(['abos.dashboards_v2' => true]);
        $phoneBar = collect($this->getJson('/api/v1/dashboard/sales')->assertOk()->json('panels'))->firstWhere('label', __('sales::dashboard.by_seller'));
        $phoneValue = collect($phoneBar['parts'] ?? [])->firstWhere('label', $this->owner->name)['value'] ?? '0';

        return [
            'seller' => $value($seller, $this->owner->name),
            'area' => array_reduce($area?->parts ?? [], fn (string $s, array $p) => bcadd($s, str_replace(',', '', (string) $p['value']), 4), '0.0000'),
            'phone_seller' => bcadd(str_replace(',', '', (string) $phoneValue), '0', 4),
        ];
    }

    private function customerStat(): string
    {
        $this->web();
        config(['abos.dashboards_v2' => true]);
        $stat = collect($this->get('/dashboard/customer')->assertOk()->viewData('dashboard')->stats)
            ->first(fn (Stat $s) => $s->label === __('customer::dashboard.sales_month'));
        $this->assertNotNull($stat, 'প্রস্তুতিটাই ভুল — গ্রাহকের ড্যাশবোর্ডে এ মাসের বিক্রির কার্ড নেই।');

        return bcadd(str_replace(',', '', (string) $stat->value), '0', 4);
    }

    /** রহিমের এ মাসের নিট — পাকা বিলের (মোট − VAT − ভাড়া) বাদ পাকা ফেরতের (মোট − VAT), সারি ধরে PHP-তে */
    private function handNetOf(Customer $customer): string
    {
        $this->web();
        $month = [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()];
        $sold = SalesInvoice::query()->posted()->where('customer_id', $customer->id)->whereBetween('trx_date', $month)->get()
            ->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, bcsub(bcsub((string) $i->total, (string) $i->tax, 4), (string) $i->freight_charge, 4), 4), '0');

        return SalesReturn::query()->posted()->where('customer_id', $customer->id)->whereBetween('trx_date', $month)->get()
            ->reduce(fn (string $c, SalesReturn $r) => bcsub($c, bcsub((string) $r->total, (string) $r->tax, 4), 4), $sold);
    }

    /** @return array{month: string, lifetime: string, count: int, html: string} */
    private function glance(): array
    {
        $this->web();
        $html = $this->get(route('customer.summary', $this->rahim))->assertOk()->getContent();
        $figure = function (string $key) use ($html): string {
            $this->assertSame(1, preg_match('/data-figure="'.preg_quote($key, '/').'"[^>]*>([^<]*)</u', $html, $m), "প্রস্তুতিটাই ভুল — সারাংশে {$key} নেই।");

            return trim(html_entity_decode($m[1]));
        };

        return [
            'month' => bcadd(str_replace(',', '', $figure('month-amount')), '0', 4),
            'lifetime' => bcadd(str_replace(',', '', $figure('lifetime-amount')), '0', 4),
            'count' => (int) preg_replace('/\D+/', '', strtr($figure('lifetime-count'), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])),
            'html' => $html,
        ];
    }

    private function web(): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($this->owner->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
