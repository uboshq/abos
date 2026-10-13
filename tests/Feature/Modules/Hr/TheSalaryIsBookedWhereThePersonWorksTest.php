<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
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
use Tests\TestCase;

/**
 * ⛔ গোটা কোম্পানির বেতন — প্রতিটা কর্মীর খরচ তাঁর নিজের শাখায়, রান যিনিই চালান (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ৬;
 * [[PayrollService::ledgerLines()]])।
 *
 * ⓘ রানটা সব শাখার কর্মীর, অথচ পুরো দাখিলা বসত রান-বানানো মানুষের শাখায় — ময়মনসিংহে বসে চালালে নেত্রকোনার বেতনও ময়মনসিংহের
 * লাভ-ক্ষতিতে খরচ, নেত্রকোনা দেখাত শূন্য বেতন। শাখা লেখা নেই এমন কর্মী (প্রধান অফিস) কোম্পানির প্রধান শাখায়।
 */
final class TheSalaryIsBookedWhereThePersonWorksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        // ⓘ কেরানি বসে আছেন নেত্রকোনায় — প্রধান শাখায় নয়
        CompanyContext::set($this->company->id, $this->branch('NTK')->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $this->employee('EMP-MMS', 'MMS', '20000');
        $this->employee('EMP-NTK', 'NTK', '35000');
        $this->employee('EMP-HQ', null, '10000');
    }

    public function test_each_persons_salary_lands_in_their_own_branch_and_every_branch_balances(): void
    {
        $this->assertNotSame($this->branch('NTK')->id, $this->company->defaultBranch()?->id, 'দৃশ্যটা প্রধান শাখার বাইরে বসে চালানো');

        $run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));
        $this->assertSame($this->branch('NTK')->id, (int) $run->branch_id, 'রানের ছাপ কেরানির শাখা — সেটা বদলায় না');

        $mms = $this->branch('MMS')->id;
        $ntk = $this->branch('NTK')->id;
        $head = (int) $this->company->defaultBranch()->id;

        $expected = [$mms => '20000', $ntk => '35000'];
        $expected[$head] = bcadd($expected[$head] ?? '0', '10000', 0);

        foreach ($expected as $branch => $salary) {
            $this->assertSame($salary, $this->sum($run, StandardChart::SALARY_EXPENSE, $branch, 'debit'),
                "⛔ শাখা {$branch}-এর বেতন-খরচ তার নিজের শাখায় বসেনি");
            $this->assertSame($salary, $this->sum($run, StandardChart::SALARY_PAYABLE, $branch, 'credit'),
                "⛔ শাখা {$branch}-এর বেতন-দেনা তার নিজের শাখায় বসেনি");
        }

        // ⓘ প্রতিটা শাখার দাখিলা নিজেই মেলে
        foreach (LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->groupBy('branch_id')->selectRaw('branch_id, SUM(debit) - SUM(credit) as gap')->get() as $row) {
            $this->assertSame(0, bccomp((string) $row->gap, '0', 4), '⛔ শাখা '.$row->branch_id.'-এর দাখিলা মেলে না');
        }

        // ⓘ বাতিলের উল্টো দাখিলাও একই শাখায় — প্রতিটা শাখা শূন্যে ফেরে
        app(PayrollService::class)->cancel($run->fresh(), 'test');
        $this->assertSame('0', $this->sum($run, StandardChart::SALARY_EXPENSE, $ntk, 'debit', net: true));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function employee(string $code, ?string $branch, string $basic): Employee
    {
        $employee = app(EmployeeService::class)->create(['code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['branch_id' => $branch === null ? null : $this->branch($branch)->id])->save();
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', $basic);

        return $employee;
    }

    private function sum(PayrollRun $run, string $code, int $branch, string $side, bool $net = false): string
    {
        $query = LedgerEntry::query()->where('account_id', StandardChart::find($code)->id)->where('branch_id', $branch)
            ->whereIn('source_type', $net ? [PayrollRun::SOURCE_TYPE, PayrollRun::SOURCE_TYPE.':reversal'] : [PayrollRun::SOURCE_TYPE])
            ->where('source_id', $run->id);

        return bcadd((string) ($net ? $query->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n') : $query->sum($side)), '0', 0);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
