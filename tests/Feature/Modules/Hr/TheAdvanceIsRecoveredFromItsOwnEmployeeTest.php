<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ অগ্রিম কর্মী ধরে আদায়, আর খোলা জেরের বেশি নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔৪)।
 *
 * ⓘ অগ্রিম দেওয়া হয় কর্মীর নামে (১১৩১); বেতনের কর্তন আগে খাত ধরে একসাথে, নাম ছাড়া বসত — কারও নিজের জের কমত না। আর কিস্তি
 * মাসিক নির্দিষ্ট অঙ্ক, শোধ হলেও কাটা চলত।
 */
final class TheAdvanceIsRecoveredFromItsOwnEmployeeTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Employee $borrower;

    private Employee $clean;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $this->borrower = $this->employee('EMP-ADV', 'Took Advance');
        $this->clean = $this->employee('EMP-CLN', 'No Advance');

        // ⓘ ৩,০০০ অগ্রিম, ১ আগস্ট, কর্মীর নামে
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->putMoneyIn(app(CashTillService::class)->ensurePrimaryTill()->account, '100000', '2026-07-01');
        app(PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-08-01', lines: [
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'debit' => '3000', 'party_type' => 'employee', 'party_id' => $this->borrower->id],
            ['account_id' => $cash, 'credit' => '3000'],
        ], branchId: $this->company->defaultBranch()?->id);

        // ⓘ অন্যজন আগে ৫০০ বেশি ফেরত দিয়েছেন — তাঁর অগ্রিমের জের ঋণাত্মক; কিস্তি তবু শূন্য, ঋণাত্মক নয়
        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-08-01', lines: [
            ['account_id' => $cash, 'debit' => '500'],
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'credit' => '500', 'party_type' => 'employee', 'party_id' => $this->clean->id],
        ], branchId: $this->company->defaultBranch()?->id);
    }

    public function test_the_advance_comes_back_in_the_borrowers_name_and_stops_when_it_is_paid(): void
    {
        $this->assertSame('2000', $this->deducted('2026-08-01', $this->borrower), 'আগস্ট: পুরো কিস্তি');
        $this->assertSame('0', $this->deducted('2026-08-01', $this->clean), '⛔ অগ্রিম না নেওয়া কর্মীর বেতন থেকে কাটা হল');
        $this->assertSame('1000', $this->open($this->borrower), '⛔ আদায় কর্মীর নামে বসেনি — তাঁর জের কমেনি');
        $this->assertSame(0, LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)
            ->whereNull('party_id')->count(), '⛔ অগ্রিমের আদায় নাম ছাড়া বসল');

        $this->assertSame('1000', $this->deducted('2026-09-01', $this->borrower), '⛔ সেপ্টেম্বরে বাকির বেশি কাটা হল');
        $this->assertSame('0', $this->open($this->borrower));
        $this->assertSame('0', $this->deducted('2026-10-01', $this->borrower), '⛔ অগ্রিম শোধের পরেও কাটা চলল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function employee(string $code, string $name): Employee
    {
        $employee = app(EmployeeService::class)->create(['code' => $code, 'name_en' => $name, 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'ADVANCE')->firstOrFail(), '2026-01-15', '2000');

        return $employee;
    }

    /** মাসের রান বানিয়ে নিশ্চিত — কর্মীর অগ্রিমের কর্তন */
    private function deducted(string $month, Employee $employee): string
    {
        $run = PayrollRun::query()->forMonth($month)->first() ?? app(PayrollService::class)->confirm(app(PayrollService::class)->build($month));

        return bcadd((string) $run->payslips()->where('employee_id', $employee->id)->firstOrFail()
            ->lines()->where('head_code', 'ADVANCE')->sum('amount'), '0', 0);
    }

    private function open(Employee $employee): string
    {
        return bcadd((string) LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)
            ->where('party_type', 'employee')->where('party_id', $employee->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n'), '0', 0);
    }
}
