<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যাংক-ফাইল আর খাতা একই বেতন দিত না — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ২ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * হাজিরায় বেতন কাটলে খাতের অঙ্ক চার ঘরে থাকত (৳২০,০০০ × ২৩/৩১ = ১৪,৮৩৮.৭০৯৭)। খাতায় সেটাই বসত, আর
 * ব্যাংক-ফাইল প্রতিজনকে দুই ঘরে গোল করত — তাই "প্রদেয় বেতন"-এ পাঠানোর পরেও ভগ্নাংশ পয়সা ঝুলে থাকত, আর
 * কয়েকজন মিলে ফাইলের মোট আর খাতার মোট আলাদা।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * প্রতিটা খাতের অঙ্ক বেতনশিট বানানোর সময়েই পয়সায় গোল ([[PayrollService::build()]]) — বেতনশিট, খাতা আর ফাইল
 * একই অঙ্ক। দুইজন কর্মী, দুই রকম কামাই, যাতে গোলের তফাত জমতে পারে।
 */
final class TheBankFileAndTheBooksPaidTheSameSalaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        // ⓘ ডেমোর মালিক-কর্মী বাদ — [[PayrollTest]]-এর একই কারণে
        Employee::query()->delete();

        app(SettingsService::class)->set('hr.attendance_affects_salary', true);
    }

    public function test_every_amount_is_in_paisa_and_the_file_pays_what_the_books_owe(): void
    {
        $this->employee('EMP-101', '1111111111', absentDays: 8);
        $this->employee('EMP-102', '2222222222', absentDays: 5);

        $payroll = app(PayrollService::class);
        $run = $payroll->confirm($payroll->build('2026-07-01'));

        foreach (PayslipLine::query()->whereIn('payslip_id', $run->payslips()->pluck('id'))->get() as $line) {
            $this->assertSame(0, bccomp((string) $line->amount, Money::round($line->amount, 2), 4),
                "⛔ বেতনের খাত '{$line->head_code}'-এর অঙ্ক {$line->amount} — পয়সার নিচে ভগ্নাংশ।");
        }

        $fileTotal = '0';

        foreach (array_slice(explode("\r\n", trim($payroll->bankFile($run)['content'])), 1) as $row) {
            $fileTotal = bcadd($fileTotal, str_getcsv($row)[2], 4);
        }

        $payable = StandardChart::find(StandardChart::SALARY_PAYABLE);
        $owed = (string) LedgerEntry::query()
            ->where('account_id', $payable->id)
            ->where('source_id', $run->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as owed')
            ->value('owed');

        $netTotal = $run->payslips()->get()->reduce(fn ($c, $s) => bcadd($c, (string) $s->net, 4), '0');

        $this->assertGreaterThan(0, bccomp($fileTotal, '0', 4), 'প্রস্তুতিটাই ভুল — ফাইলে কোনো টাকা নেই।');
        $this->assertSame(0, bccomp($fileTotal, $netTotal, 4), "⛔ ব্যাংক-ফাইলের মোট {$fileTotal}, বেতনশিটের নিট {$netTotal}।");
        $this->assertSame(0, bccomp($fileTotal, bcsub($owed, $this->deductionsOnPayable($run->id), 4), 4),
            "⛔ ব্যাংক-ফাইল দেয় {$fileTotal}, খাতা প্রদেয় রাখে নিটের জন্য ".bcsub($owed, $this->deductionsOnPayable($run->id), 4).'।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** কর্তন যাদের নিজের খাত নেই, তারাও প্রদেয় বেতনে পড়ে ([[PayrollService::fallbackAccount()]]) — নিট মাপতে সেটা বাদ। */
    private function deductionsOnPayable(int $runId): string
    {
        $payable = StandardChart::find(StandardChart::SALARY_PAYABLE);

        return (string) PayslipLine::query()
            ->whereIn('payslip_id', fn ($q) => $q->select('id')->from('hr_payslips')->where('payroll_run_id', $runId))
            ->where('kind', SalaryHead::DEDUCTION)
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $payable->id))
            ->sum('amount');
    }

    private function employee(string $code, string $bankAccount, int $absentDays): void
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code,
            'name_en' => 'Worker '.$code,
            'joining_date' => '2026-01-15',
            'payment_method' => 'bank',
            'bank_name' => 'Sonali',
            'bank_account_no' => $bankAccount,
            'bank_account_name' => 'Worker '.$code,
        ]);

        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
        $salaries->set($employee, SalaryHead::query()->where('code', 'HRA')->firstOrFail(), '2026-01-15', '50');
        $salaries->set($employee, SalaryHead::query()->where('code', 'PF')->firstOrFail(), '2026-01-15', '10');

        foreach (range(1, $absentDays) as $day) {
            Attendance::query()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'work_date' => sprintf('2026-07-%02d', $day + 1),
                'status' => Attendance::ABSENT,
            ]);
        }
    }
}
