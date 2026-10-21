<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Print\PrintableDocument;
use App\Core\Security\FieldSecurity;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ বেতনের অঙ্ক কেবল `hr.salary.view`-এ — কাগজেও; নিজের স্লিপ নিজের চোখে খোলা (fe, ২১ অক্টোবর ২০২৬; [[PayslipPrintController]])।
 *
 * দাবি: চাবি ছাড়া অন্যের স্লিপ ছাপলে মানুষ আর মাস থাকে, মোট আয়-কর্তন-নিট ঢাকা ([[FieldSecurity::mask()]]) আর কথায় অঙ্ক নেই; নিজের
 * স্লিপ খোলা; পুরো রান ছাপলে নিজেরটা খোলা, অন্যেরটা ঢাকা; চাবি পেলে অন্যেরটাও খোলে। (তালিকা আর রানের পাতা:
 * [[ThePayrollListShowsOnlyMyPeopleTest]]; বেতনশিট রিপোর্ট: [[TheHrReportsCountWhatTheHrScreensCountTest]]; ড্যাশবোর্ড:
 * [[TheDashboardCountsTodaysRollTest]])
 */
final class TheSalaryNeedsTheSalaryKeyEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $viewer;

    private Payslip $mine;

    private Payslip $theirs;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        Permission::findOrCreate('hr.payroll.view', 'web');
        Permission::findOrCreate('hr.salary.view', 'web');
        $this->viewer = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->viewer->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->viewer->givePermissionTo('hr.payroll.view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'document_no' => 'PRL-KEY', 'month' => '2026-09-01', 'trx_date' => '2026-09-30',
            'status' => DocumentStatus::CONFIRMED, 'employee_count' => 2, 'gross_total' => '0', 'deduction_total' => '0', 'net_total' => '0',
        ]);

        $this->mine = $this->slip('KEY-ME', 'Viewer Self', $this->viewer->id, '31415.92');
        $this->theirs = $this->slip('KEY-OT', 'Someone Else', null, '27182.81');
    }

    public function test_someone_elses_payslip_prints_without_the_amounts_and_ones_own_prints_with_them(): void
    {
        $theirs = $this->printed(route('hr.payslip.print', $this->theirs))[0];
        $this->assertContains('Someone Else', $theirs->meta, '⛔ ঢাকতে গিয়ে মানুষটাই হারাল।');
        $this->assertSame([FieldSecurity::mask()], array_values(array_unique($theirs->totals)), '⛔ চাবি ছাড়া অন্যের বেতনের অঙ্ক কাগজে।');
        $this->assertNull($theirs->amountInWords, '⛔ কথায় অঙ্ক — বেতনটা কথায় বলে দেয়।');

        $mine = $this->printed(route('hr.payslip.print', $this->mine))[0];
        $this->assertContains(Money::format('31415.92'), $mine->totals, '⛔ নিজের স্লিপে নিজের বেতন ঢাকা।');
        $this->assertNotNull($mine->amountInWords);

        // ⭐ পুরো রান — নিজেরটা খোলা, অন্যেরটা ঢাকা
        $all = $this->printed(route('hr.payroll.payslips', $this->run));
        $this->assertCount(2, $all);
        $open = array_values(array_filter($all, fn (PrintableDocument $d) => in_array(Money::format('31415.92'), $d->totals, true)));
        $this->assertCount(1, $open);
        $this->assertNotContains(Money::format('27182.81'), array_merge(...array_map(fn ($d) => array_values($d->totals), $all)));
    }

    public function test_with_the_salary_key_someone_elses_payslip_shows_the_amounts(): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->viewer->givePermissionTo('hr.salary.view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $theirs = $this->printed(route('hr.payslip.print', $this->theirs))[0];
        $this->assertContains(Money::format('27182.81'), $theirs->totals);
        $this->assertNotNull($theirs->amountInWords);
    }

    /** @return list<PrintableDocument> ছাপার পাতায় যাওয়া কাগজগুলো — ব্লেডে যা পৌঁছায় */
    private function printed(string $url): array
    {
        $documents = null;
        View::composer('*', function ($view) use (&$documents): void {
            $data = $view->getData();

            if ($documents === null && isset($data['documents']) && is_array($data['documents'])) {
                $documents = $data['documents'];
            }
        });

        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($this->viewer->fresh())->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertIsArray($documents, 'দৃশ্যটাই বানানো যায়নি — ছাপার পাতায় কাগজ পৌঁছায়নি');

        return array_values($documents);
    }

    private function slip(string $code, string $name, ?int $user, string $net): Payslip
    {
        $employee = Employee::query()->create(['company_id' => $this->company->id, 'code' => $code, 'name_en' => $name,
            'joining_date' => '2026-01-01', 'user_id' => $user]);

        return Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id, 'gross' => $net, 'deductions' => '0', 'net' => $net, 'payment_method' => 'cash']);
    }
}
