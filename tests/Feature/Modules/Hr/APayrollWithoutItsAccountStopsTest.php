<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\Hr\Services\SalaryTax;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ আইনি বেতনের খাত — বসানোর কমান্ড বারবার চালানো নিরাপদ, `--dry` কিছু বসায় না; আর খাত না থাকলে রান থামে বাংলায় কারণ বলে, ভুল
 * খাতে কখনো বসে না (a6, ২১ অক্টোবর ২০২৬; [[HrLawAccounts]], [[StandardChart::HR_LAW_CODES]])।
 */
final class APayrollWithoutItsAccountStopsTest extends TestCase
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
        Employee::query()->delete();

        $employee = app(EmployeeService::class)->create([
            'code' => 'AC-A', 'name_en' => 'Account Test', 'joining_date' => '2025-01-01', 'payment_method' => 'cash',
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-01', '61234.56');
        $salaries->set($employee, SalaryHead::query()->where('code', 'PF')->firstOrFail(), '2025-01-01', '8');
    }

    public function test_the_installer_is_dry_on_request_and_idempotent(): void
    {
        $this->drop(StandardChart::GRATUITY_EXPENSE);
        $this->drop(StandardChart::TDS_PAYABLE);

        $this->artisan('abos:hr-law-accounts', ['--dry' => true])->assertSuccessful();
        $this->assertSame([StandardChart::TDS_PAYABLE, StandardChart::GRATUITY_EXPENSE],
            $this->sorted(app(StandardChart::class)->missing(StandardChart::HR_LAW_CODES)), '⛔ শুকনো চালানো খাত বসিয়ে দিল।');

        $this->artisan('abos:hr-law-accounts')->assertSuccessful();
        $this->assertSame([], app(StandardChart::class)->missing(StandardChart::HR_LAW_CODES));
        // ⓘ ঠিক দলে — খরচ ৫২০০-এর নিচে, দায় ২১০০-এর নিচে
        $this->assertSame('5200', Account::query()->whereKey(StandardChart::find(StandardChart::GRATUITY_EXPENSE)->parent_id)->value('code'));
        $this->assertSame('2100', Account::query()->whereKey(StandardChart::find(StandardChart::TDS_PAYABLE)->parent_id)->value('code'));

        $before = Account::query()->count();
        $this->artisan('abos:hr-law-accounts')->assertSuccessful();
        $this->assertSame($before, Account::query()->count(), '⛔ দ্বিতীয়বার চালাতে আবার খাত বসল।');
    }

    public function test_without_the_fund_expense_account_the_run_stops_and_posts_nothing(): void
    {
        app(SettingsService::class)->set(ProvidentFund::ENABLED, true);
        $this->drop(StandardChart::PF_EMPLOYER_EXPENSE);

        $this->assertStops(fn () => app(PayrollService::class)->build('2026-07-01'), 'hr::validation.pf_accounts_missing');
    }

    public function test_without_the_tax_account_the_run_stops_and_posts_nothing(): void
    {
        app(SettingsService::class)->set(SalaryTax::SLABS, '350000:0, 100000:5, *:10');
        $this->drop(StandardChart::TDS_PAYABLE);

        $this->assertStops(fn () => app(PayrollService::class)->build('2026-07-01'), 'hr::validation.tds_account_missing');
    }

    public function test_without_the_bonus_account_the_bonus_run_stops_and_posts_nothing(): void
    {
        $this->drop(StandardChart::FESTIVAL_BONUS_EXPENSE);

        $this->assertStops(fn () => app(PayrollService::class)->buildBonus('2026-04-10'), 'hr::validation.bonus_account_missing');
    }

    private function assertStops(\Closure $build, string $message): void
    {
        try {
            $build();
            $this->fail('⛔ খাত নেই, অথচ রান বসল।');
        } catch (ValidationException $e) {
            $this->assertContains(__($message), collect($e->errors())->flatten()->all(), '⛔ থামল, কিন্তু কারণটা বাংলায় বলা নেই।');
        }

        $this->assertSame(0, PayrollRun::acrossBranches()->count(), '⛔ অর্ধেক রান রয়ে গেল।');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->count(), '⛔ ভুল খাতে কিছু বসল।');
    }

    private function drop(string $code): void
    {
        DB::table('accounts')->where('company_id', $this->company->id)->where('code', $code)->delete();
    }

    /** @param list<string> $codes @return list<string> */
    private function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }
}
