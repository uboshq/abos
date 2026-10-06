<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ একই মাসের বেতন দুবার চালানো যেত, অন্য শাখা বেছে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔১)।
 *
 * ⓘ রান পুরো কোম্পানির কর্মীদের, কিন্তু "এ মাসে রান আছে কি" যাচাই হেডারের শাখার দেয়ালের পিছনে ছিল। NTK বেছে চালানো রান MMS বাছা
 * অবস্থায় অদৃশ্য — যাচাই পার, আর সব কর্মীর বেতন আবার।
 */
final class APayrollRunHidInAnotherBranchTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-08-01';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(SalaryHeadService::class)->installDefaults();
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-HB', 'name_en' => 'Hidden Branch', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_a_run_made_in_one_branch_stops_the_same_month_in_another(): void
    {
        $this->choose($this->branch('NTK')->id);
        $first = app(PayrollService::class)->build(self::MONTH);
        $this->assertSame($this->branch('NTK')->id, (int) $first->branch_id, 'দৃশ্যটাই বানানো যায়নি — রান NTK-তে বসেনি');

        $this->choose($this->branch('MMS')->id);
        $this->assertSame(0, PayrollRun::query()->count(), 'দৃশ্যটাই বানানো যায়নি — MMS বাছা অবস্থায় NTK-র রান দেখা যায়');

        try {
            app(PayrollService::class)->build(self::MONTH);
            $this->fail('⛔ অন্য শাখায় বানানো রান থাকতেও একই মাসের বেতন আবার চলল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame(1, PayrollRun::acrossBranches()->count(), '⛔ একই মাসের দ্বিতীয় রান বসল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
