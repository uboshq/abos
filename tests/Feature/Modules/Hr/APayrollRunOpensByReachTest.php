<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বেতনের রান খোলে নাগাল ধরে, মাথার শাখা ধরে নয় — fe, ২১ অক্টোবর ২০২৬ (a6; [[OpensInReach]], ভাউচারের একই নিয়ম)।
 *
 * ⓘ তালিকা মাথার শাখা মানে: একটা শাখা বাছা থাকলে শাখা ছাড়া রান তালিকায় নেই, "সব শাখা"-য় আছে। ⛔ কিন্তু আগে সেই রানের লিংক —
 * খবর, সইয়ের পাতা বা হাতে লেখা — ৪০৪ দিত। এখন সরাসরি খোলা (পাতা, ব্যাংকের ফাইল) মানুষের নাগালে: শাখা ছাড়া রান কোম্পানির
 * নাগালের সবার জন্য; অন্য শাখার রান নাগালের বাইরের মানুষের জন্য ৪০৪; সেই রানের সইকারী নাগালের বাইরে থেকেও পড়তে পারেন, বদলাতে নয়।
 */
final class APayrollRunOpensByReachTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.payroll.view', 'hr.payroll.manage', 'hr.salary.view', 'hr.identity.view'];

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private PayrollRun $branchless;

    private PayrollRun $faraway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'RCH', 'name_en' => 'Reach Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $this->netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->branchless = $this->makeRun(null, 'PRL-NONE');
        $this->faraway = $this->makeRun($this->netrokona, 'PRL-FAR');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_with_one_branch_chosen_a_branchless_run_is_off_the_list_but_opens_from_a_link(): void
    {
        $hr = $this->person([$this->dhaka, $this->netrokona]);

        $this->choose($hr, $this->dhaka);
        $this->get(route('hr.payroll.index'))->assertOk()->assertDontSee('PRL-NONE', false);
        $this->get(route('hr.payroll.show', $this->branchless->id))->assertOk()->assertSee('PRL-NONE', false);
        $this->get(route('hr.payroll.bank_file', $this->branchless->id))->assertOk();

        $this->choose($hr, null);
        $this->get(route('hr.payroll.index'))->assertOk()->assertSee('PRL-NONE', false);
    }

    public function test_a_person_without_reach_gets_404_but_the_branchless_run_still_opens(): void
    {
        $dhakaOnly = $this->person([$this->dhaka]);
        $this->choose($dhakaOnly, null);

        $this->get(route('hr.payroll.show', $this->faraway->id))->assertNotFound();
        $this->get(route('hr.payroll.bank_file', $this->faraway->id))->assertNotFound();
        $this->get(route('hr.payroll.show', $this->branchless->id))->assertOk();
    }

    public function test_the_runs_signer_outside_the_reach_may_read_it_but_not_act_on_it(): void
    {
        $dhakaOnly = $this->person([$this->dhaka]);

        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => PayrollRun::class, 'approvable_id' => $this->faraway->id,
            'module' => 'hr', 'action' => 'payroll', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $dhakaOnly->id, 'requested_at' => now(),
        ]);

        $this->choose($dhakaOnly, null);

        $this->get(route('hr.payroll.show', $this->faraway->id))->assertOk()->assertSee('PRL-FAR', false);
        $this->post(route('hr.payroll.cancel', $this->faraway->id), ['reason' => 'from outside'])->assertNotFound();
        $this->assertSame(DocumentStatus::CONFIRMED, $this->faraway->fresh()->status, '⛔ নাগালের বাইরের সইকারী রানটা বাতিল করলেন।');
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    private function makeRun(?Branch $branch, string $no): PayrollRun
    {
        return PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch?->id, 'document_no' => $no,
            'month' => Carbon::today()->startOfMonth()->toDateString(), 'trx_date' => Carbon::today()->toDateString(),
            'status' => DocumentStatus::CONFIRMED, 'employee_count' => 0,
            'gross_total' => '0', 'deduction_total' => '0', 'net_total' => '0',
        ]);
    }

    /** @param list<Branch> $reach */
    private function person(array $reach): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(self::KEYS));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($reach as $branch) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id]);
        }

        return $user->fresh();
    }

    /** ⓘ হেডারে একটা শাখা বাছা — `view_all_branches` false; `null` = "সব শাখা" */
    private function choose(User $user, ?Branch $branch): void
    {
        $user->forceFill(['current_branch_id' => $branch?->id, 'view_all_branches' => $branch === null])->save();
        CompanyContext::set($this->company->id, $branch?->id);
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }
}
