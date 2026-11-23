<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Reports\BillerReports;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিক্রি দায়িত্বপ্রাপ্ত জনের, বিল যিনি কেটেছেন তাঁর কখনো নয় — মালিক, ২৩ নভেম্বর ২০২৬ ([[SalespersonReports]], [[BillerReports]])।
 *
 * ⓘ জন বিলে সরাসরি বসানো (`sales_person_id`) — জন বাছাইয়ের নিয়ম ([[SalesResponsibility]]) এই দাবির বিষয় নয়।
 * ⛔ বিলকাটা A, দায়িত্বে B → বিক্রি B-র, A-র কিছু নয়; জন নেই → "দায়িত্ব বসানো নেই"; DSM-এর দলের মোট নিচের সবার যোগ, সর্বমোট
 * দুইবার নয়; "কে কত বিল করেছেন" `created_by` ধরে, লক্ষ্যে কখনো নয়; লক্ষ্যের স্কোরবোর্ড বিলের জন পড়ে।
 */
final class TheBillerNeverGetsTheSalesmansCreditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $biller;

    private Customer $customer;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->biller = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->biller);
        $this->customer = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        DB::table('staff_supervisors')->where('company_id', $this->company->id)->delete();

        // ⓘ ডেমোর বিলের বাইরে একটা মাস — অঙ্কগুলো কেবল এই দাবির
        $this->day = Carbon::today()->subMonthsNoOverflow(5)->startOfMonth()->addDays(9);
    }

    public function test_a_bill_typed_by_a_goes_to_responsible_b_and_a_gets_nothing(): void
    {
        $b = $this->person('B Responsible');
        $this->bill('BB-1', '700', $b);

        $rows = $this->rows();

        $this->assertMoney('700', $rows[$b->id]['net_sales'] ?? null, '⛔ দায়িত্বপ্রাপ্ত জন বিক্রি পাননি');
        $this->assertArrayNotHasKey((int) $this->biller->id, $rows, '⛔ বিল যিনি কেটেছেন তিনি বিক্রি পেলেন');
    }

    public function test_a_bill_with_nobody_responsible_shows_under_its_own_row(): void
    {
        $this->bill('BB-2', '450', null);

        $rows = $this->rows();

        $this->assertMoney('450', $rows[0]['net_sales'] ?? null, '⛔ জন নেই এমন বিলের বিক্রি আলাদা সারিতে নেই');
        $this->assertSame((string) __('sales::salesperson_report.nobody'), $rows[0]['salesperson']);
        $this->assertSame('দায়িত্ব বসানো নেই', (string) __('sales::salesperson_report.nobody', [], 'bn'));
        $this->assertArrayNotHasKey((int) $this->biller->id, $rows, '⛔ জন না থাকলে বিলকাটা বিক্রি পেলেন');
    }

    public function test_the_dsm_team_total_sums_his_whole_tree_and_the_grand_total_is_not_doubled(): void
    {
        $dsm = $this->person('A DSM');
        $asm = $this->person('B ASM');
        $sr1 = $this->person('C SR');
        $sr2 = $this->person('D SR');
        $loose = $this->person('E Loose');
        $this->supervise($asm, $dsm);
        $this->supervise($sr1, $asm);
        $this->supervise($sr2, $dsm);

        $this->bill('BB-T1', '100', $sr1);
        $this->bill('BB-T2', '200', $sr2);
        $this->bill('BB-T3', '50', $asm);
        $this->bill('BB-T4', '1000', $loose);
        $this->bill('BB-T5', '7', null);

        $result = app(ReportEngine::class)->run(SalespersonReports::KEY, $this->range(), byBranch: true);
        $sections = collect($result->sections)->keyBy(fn ($s) => (int) $s->branchId);

        $this->assertMoney('350', $sections[$dsm->id]->totals['net_sales'] ?? null, '⛔ DSM-এর দলে সরাসরি ও পরোক্ষ সবার যোগ নেই');
        $this->assertMoney('150', $sections[$asm->id]->totals['net_sales'] ?? null, '⛔ ASM-এর দলে নিজে আর নিচের SR নেই');
        $this->assertSame((string) __('sales::salesperson_report.team_total', ['name' => 'A DSM']), $sections[$dsm->id]->totalLabel);
        $this->assertSame([$dsm->id, $asm->id, 0], $sections->keys()->all(), '⛔ দলের ক্রম গাছের নয় (শীর্ষ আগে, দলের বাইরে শেষে)');

        // ⓘ দলের বাইরে: যিনি কারো দলে নেই, আর জন বসানো নেই এমন বিল
        $this->assertMoney('1007', $sections[0]->totals['net_sales'] ?? null, '⛔ দলের বাইরের ভাগে দলের বাইরের জন বা জনহীন বিল নেই');

        // ⛔ SR-এর বিক্রি দুই দলে দেখা যায়, তবু সর্বমোট মানুষ ধরে একবার
        $this->assertMoney('1357', $result->totals['net_sales'] ?? null, '⛔ সর্বমোট দলের মোট যোগ করে দুইবার গোনা হল');
    }

    public function test_the_biller_report_counts_by_who_typed_the_bill_and_is_never_in_the_target(): void
    {
        $b = $this->person('B Responsible');
        $this->bill('BB-3', '300', $b);
        $this->bill('BB-4', '200', $b);

        $billers = collect(app(ReportEngine::class)->run(BillerReports::KEY, $this->range())->rows)
            ->map(fn ($r) => (array) $r)->keyBy(fn (array $r) => (int) $r['biller_id']);

        $this->assertSame(2, (int) ($billers[$this->biller->id]['bill_count'] ?? 0), '⛔ "বিল করেছেন" বিল যিনি কেটেছেন তাঁর নামে নয়');
        $this->assertMoney('500', $billers[$this->biller->id]['amount'] ?? null, '⛔ বিলের অঙ্ক ভুল');
        $this->assertArrayNotHasKey((int) $b->id, $billers->all(), '⛔ বিল না কেটেও দায়িত্বপ্রাপ্ত জন "বিল করেছেন"-এ');

        $achieved = app(SalesTargetService::class)->achievedByUser($this->day->copy()->startOfMonth(), $this->day->copy()->endOfMonth());
        $this->assertArrayNotHasKey((int) $this->biller->id, $achieved, '⛔ বিল কাটার কাজ লক্ষ্যের অর্জনে উঠল');
        $this->assertMoney('500', $achieved[$b->id] ?? null, '⛔ লক্ষ্যের অর্জন দায়িত্বপ্রাপ্ত জনের নয়');
    }

    public function test_the_scoreboard_reads_the_person_on_the_bill(): void
    {
        $b = $this->person('B Responsible');
        CompanyContext::forCompany($this->company->id, fn () => $b->assignRole('salesman'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->bill('BB-5', '640', $b);
        app(SalesTargetService::class)->setForMonth($this->day, [$b->id => '1000', $this->biller->id => '1000']);

        $board = collect(app(SalesTargetService::class)->scoreboard($this->day))->keyBy(fn (array $r) => (int) $r['user']->id);

        $this->assertTrue($board->has($b->id), 'প্রস্তুতিটাই ভুল — বিক্রয়কর্মী স্কোরবোর্ডে নেই');
        $this->assertMoney('640', $board[$b->id]['achieved'], '⛔ স্কোরবোর্ডে দায়িত্বপ্রাপ্ত জনের অর্জন নেই');

        $this->assertMoney('0', $board[$this->biller->id]['achieved'] ?? '0', '⛔ স্কোরবোর্ডে বিলকাটার নামে অর্জন');
        $this->assertMoney('640', app(SalesTargetService::class)->achievedByUser($this->day->copy()->startOfMonth(), $this->day->copy()->endOfMonth())[$b->id] ?? null,
            '⛔ স্কোরবোর্ডের উৎসে দায়িত্বপ্রাপ্ত জন নেই');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{from: string, to: string} */
    private function range(): array
    {
        return ['from' => $this->day->toDateString(), 'to' => $this->day->toDateString()];
    }

    /** @return array<int, array<string, mixed>> জন (জন নেই = 0) → সারি */
    private function rows(): array
    {
        return collect(app(ReportEngine::class)->run(SalespersonReports::KEY, $this->range())->rows)
            ->map(fn ($r) => (array) $r)
            ->keyBy(fn (array $r) => (int) ($r['seller_id'] ?? 0))
            ->all();
    }

    private function person(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function supervise(User $user, User $boss): void
    {
        StaffSupervisor::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id, 'supervisor_id' => $boss->id]);
    }

    private function bill(string $no, string $amount, ?User $person): void
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $this->customer->id,
            'trx_date' => $this->day->toDateString(),
            'subtotal' => $amount,
            'tax' => '0',
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            // ⓘ বিল কেটেছেন সবসময় মালিক (A); দায়িত্বপ্রাপ্ত জন আলাদা (B) বা কেউ নন
            'created_by' => $this->biller->id,
            'sales_person_id' => $person?->id,
        ]);

        (new SalesInvoiceLine)->forceFill([
            'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'tax' => '0',
        ])->save();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
