<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\BillerReports;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Services\CommissionEngine;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesResponsibility;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ রিপোর্ট, লক্ষ্য আর কমিশন বিল যিনি কেটেছেন (`created_by`) তাঁকে বিক্রি দিত — মালিক, ২৩ নভেম্বর ২০২৬ ([[SalesResponsibility]])।
 *
 * ⓘ বিক্রি বিলে লেখা দায়িত্বপ্রাপ্ত জনের (`sales_person_id`); "বিল করেছেন" আলাদা কাজের হিসাব ([[BillerReports]]), বিক্রি নয়।
 * ⓘ DSM-এর দল: `staff_supervisors` ধরে নিচের সবার বিক্রি "দল মোট — নাম" ভাগে; সর্বমোট মানুষ ধরে একবার।
 */
final class TheReportsCountedTheBillersSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $biller;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->biller = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->biller);

        DB::table('dealer_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('area_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('staff_supervisors')->where('company_id', $this->company->id)->delete();
        app(SalesResponsibility::class)->forget();

        $this->customer = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
    }

    public function test_the_salesperson_report_the_target_and_the_commission_never_read_the_biller(): void
    {
        $sr = $this->person('SR Karim');
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);

        $sale = $this->sell('2');
        $this->assertSame($sr->id, (int) $sale->sales_person_id, 'প্রস্তুতিটাই ভুল — বিলে দায়িত্বের জন বসেননি।');
        $this->assertSame($this->biller->id, (int) $sale->created_by, 'প্রস্তুতিটাই ভুল — বিল যিনি কেটেছেন তিনি বদলে গেলেন।');

        $rows = $this->salespersonRows();
        $this->assertArrayHasKey($sr->id, $rows, '⛔ বিক্রয়কর্মীর রিপোর্টে দায়িত্বের জনের সারি নেই।');
        $this->assertArrayNotHasKey($this->biller->id, $rows, '⛔ বিক্রয়কর্মীর রিপোর্টে বিল যিনি কেটেছেন তিনি বিক্রি পেলেন।');
        $this->assertGreaterThan(0, (float) $rows[$sr->id]['net_sales']);

        $achieved = app(SalesTargetService::class)->achievedByUser(Carbon::today(), Carbon::today());
        $this->assertArrayHasKey($sr->id, $achieved, '⛔ লক্ষ্যের অর্জনে দায়িত্বের জন নেই।');
        $this->assertArrayNotHasKey($this->biller->id, $achieved, '⛔ লক্ষ্যের অর্জন বিল যিনি কেটেছেন তাঁর ঘরে উঠল।');

        // ⓘ কমিশনের নিয়ম থাকলেই জন আসে; জন এলে দায়িত্বের জনই, বিলকাটা নন
        $commission = app(CommissionEngine::class)->preview($sale->fresh());
        if (($commission['reason'] ?? null) === null) {
            $this->assertSame($sr->id, $commission['sales_rep_id'], '⛔ কমিশন বিল যিনি কেটেছেন তাঁর নামে।');
        }

        // ⛔ বিলের লেখক বদলালেও বিক্রি নড়ে না — রিপোর্ট `created_by` পড়েই না
        $stranger = $this->person('Desk Operator');
        DB::table('sal_invoices')->where('id', $sale->id)->update(['created_by' => $stranger->id]);
        $after = $this->salespersonRows();
        $this->assertSame($rows[$sr->id]['net_sales'], $after[$sr->id]['net_sales'] ?? null, '⛔ বিলের লেখক বদলাতে বিক্রয়কর্মীর বিক্রি বদলাল।');
        $this->assertArrayNotHasKey($stranger->id, $after, '⛔ নতুন লেখক বিক্রি পেলেন।');
        $this->assertArrayNotHasKey($stranger->id, app(SalesTargetService::class)->achievedByUser(Carbon::today(), Carbon::today()));
    }

    public function test_the_biller_view_counts_bills_by_who_typed_them_and_is_never_a_sale(): void
    {
        $sr = $this->person('SR Rahim');
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);

        $this->sell('1');
        $this->sell('3');

        $today = Carbon::today()->toDateString();
        $billers = collect(app(ReportEngine::class)->run(BillerReports::KEY, ['from' => $today, 'to' => $today])->rows)
            ->map(fn ($r) => (array) $r)->keyBy(fn (array $r) => (int) $r['biller_id']);

        $this->assertSame(2, (int) ($billers[$this->biller->id]['bill_count'] ?? 0), '⛔ "বিল করেছেন" বিল যিনি কেটেছেন তাঁর গোনা নয়।');
        $this->assertArrayNotHasKey($sr->id, $billers->all(), '⛔ বিল না কেটেও দায়িত্বের জন "বিল করেছেন"-এ এলেন।');
        $this->assertArrayNotHasKey($this->biller->id, $this->salespersonRows(), '⛔ বিলকাটা বিক্রয়কর্মীর রিপোর্টে।');
    }

    public function test_the_dsm_gets_his_teams_roll_up_and_the_grand_total_counts_each_sale_once(): void
    {
        $dsm = $this->person('A DSM');
        $asm = $this->person('B ASM');
        $sr = $this->person('C SR');
        StaffSupervisor::query()->create(['company_id' => $this->company->id, 'user_id' => $asm->id, 'supervisor_id' => $dsm->id]);
        StaffSupervisor::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'supervisor_id' => $asm->id]);
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);

        $sale = $this->sell('2');
        $this->assertSame($sr->id, (int) $sale->sales_person_id, 'প্রস্তুতিটাই ভুল — বিলে SR বসেননি।');

        $today = Carbon::today()->toDateString();
        $result = app(ReportEngine::class)->run(SalespersonReports::KEY, ['from' => $today, 'to' => $today], byBranch: true);
        $sections = collect($result->sections)->keyBy(fn ($s) => (int) $s->branchId);

        $this->assertTrue($sections->has($dsm->id), '⛔ DSM-এর দলের ভাগ নেই।');
        $this->assertTrue($sections->has($asm->id), '⛔ ASM-এর দলের ভাগ নেই।');
        $this->assertSame(
            (string) __('sales::salesperson_report.team_total', ['name' => 'A DSM']),
            $sections[$dsm->id]->totalLabel,
            '⛔ DSM-এর ভাগের মোটে "দল মোট — নাম" লেখা নেই।',
        );

        $net = (float) $this->salespersonRows()[$sr->id]['net_sales'];
        $this->assertEqualsWithDelta($net, (float) $sections[$dsm->id]->totals['net_sales'], 0.001, '⛔ SR-এর বিক্রি DSM-এর দলের মোটে ওঠেনি।');
        $this->assertEqualsWithDelta($net, (float) $sections[$asm->id]->totals['net_sales'], 0.001, '⛔ SR-এর বিক্রি ASM-এর দলের মোটে ওঠেনি।');
        $this->assertSame([$sr->id], collect($sections[$dsm->id]->rows)->map(fn ($r) => (int) ((array) $r)['seller_id'])->all(),
            '⛔ DSM নিজে সরাসরি বিক্রি পেলেন, বা দলের সারি ভুল।');

        // ⛔ একই বিক্রি দুই দলে — সর্বমোট দলের যোগ নয়, মানুষ ধরে একবার
        $this->assertEqualsWithDelta($net, (float) $result->totals['net_sales'], 0.001, '⛔ সর্বমোটে বিক্রি দুইবার গোনা হল।');
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function person(string $name): User
    {
        $u = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);

        return $u->fresh();
    }

    /** @return array<int, array<string, mixed>> মানুষ → সারি, আজ (জন বসানো নেই এমন সারি বাদ) */
    private function salespersonRows(): array
    {
        $today = Carbon::today()->toDateString();

        return collect(app(ReportEngine::class)->run(SalespersonReports::KEY, ['from' => $today, 'to' => $today])->rows)
            ->map(fn ($r) => (array) $r)
            ->filter(fn (array $r) => $r['seller_id'] !== null)
            ->keyBy(fn (array $r) => (int) $r['seller_id'])
            ->all();
    }
}
