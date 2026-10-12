<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ধাপে গন্তব্য না থাকলে দেরির কাগজ মালিকের ক্রমে উপরে যায় (মালিকের ডিফল্ট, fe-র মাধ্যমে, ১১ অক্টোবর ২০২৬)।
 *
 * ক্রম: (১) ধাপে বসানো গন্তব্য — আগের মতো; (২) কাগজের শাখায় নাগাল আছে এমন চালু "Manager" — শাখা-সীমা না থাকলে সব শাখায়;
 * (৩) তা-ও না থাকলে কোম্পানির সুপার অ্যাডমিন। যিনি খবর পান, তিনিই সই দিতে পারেন আর তাঁর তালিকায় কাগজটা আসে।
 * ⛔ সব ছক বন্ধ থাকলে (UB-র মতো) কোনো অনুরোধই বসে না, তাই কিছুই উপরে যায় না।
 */
final class ALateApprovalWithNoTargetGoesUpTheLadderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $boss;

    private Customer $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);

        $users = User::query()->orderBy('id')->take(2)->get();
        [$this->clerk, $this->boss] = [$users[0], $users[1]];
        foreach ([$this->clerk, $this->boss] as $user) {
            $user->switchCompany((int) $this->company->id);
        }

        // ⓘ কাগজটা একটা শাখার — ডেমোর গ্রাহকে শাখা বসানো নেই, তাই মূল শাখা দেওয়া
        $this->paper = Customer::query()->orderBy('id')->firstOrFail();
        $this->paper->forceFill(['branch_id' => $this->company->defaultBranch()->id])->save();
    }

    public function test_step_two_an_active_manager_who_reaches_the_papers_branch_gets_it_and_may_sign(): void
    {
        $this->flow(active: true);
        $reaching = $this->member('Reaching manager', manager: true);
        $elsewhere = $this->member('Other-branch manager', manager: true, limitedTo: $this->otherBranch());
        $off = $this->member('Inactive manager', manager: true, active: false);

        $approval = $this->lateRequest();
        $this->artisan('abos:approvals-due')->expectsOutputToContain('escalated=1')->assertSuccessful();
        $approval->refresh();

        $this->assertNotNull($approval->escalated_at, '⛔ গন্তব্যহীন দেরির কাগজ কোথাও গেল না।');
        $this->assertSame((int) $reaching->id, (int) $approval->escalated_to);
        $this->assertTrue($this->heard($reaching), '⛔ শাখায় নাগাল থাকা ব্যবস্থাপক খবর পেলেন না।');
        $this->assertFalse($this->heard($elsewhere), '⛔ অন্য শাখার ব্যবস্থাপক খবর পেলেন।');
        $this->assertFalse($this->heard($off), '⛔ নিষ্ক্রিয় ব্যবস্থাপক খবর পেলেন।');

        $engine = app(ApprovalEngine::class);
        $this->assertTrue($engine->canDecide($approval, $reaching->fresh()), '⛔ খবর পেয়েও সই দিতে পারেন না।');
        $this->assertFalse($engine->canDecide($approval, $elsewhere->fresh()), '⛔ অন্য শাখার ব্যবস্থাপক সই দিতে পারেন।');
        $this->assertTrue($engine->pendingFor($reaching->fresh())->contains('id', $approval->id), '⛔ খবর পেলেন, তালিকায় কাগজ নেই।');
    }

    public function test_step_three_with_no_manager_the_super_admin_gets_it(): void
    {
        $this->flow(active: true);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $approval = $this->lateRequest();
        $this->artisan('abos:approvals-due')->expectsOutputToContain('escalated=1')->assertSuccessful();

        $this->assertNotNull($approval->fresh()->escalated_at);
        $this->assertTrue($this->heard($owner), '⛔ ব্যবস্থাপক নেই, তবু সুপার অ্যাডমিন খবর পেলেন না।');
        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval->fresh(), $owner->fresh()));
    }

    public function test_step_one_a_target_on_the_step_still_wins(): void
    {
        $chief = $this->member('Named chief', manager: false);
        $this->member('A manager who must not get it', manager: true);
        $this->flow(active: true, escalateTo: $chief);

        $approval = $this->lateRequest();
        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertSame((int) $chief->id, (int) $approval->fresh()->escalated_to, '⛔ ধাপের নিজের গন্তব্য ছাপিয়ে গেল।');
    }

    public function test_with_every_flow_switched_off_nothing_goes_up(): void
    {
        $this->flow(active: false);
        $this->member('A manager', manager: true);

        $made = app(ApprovalEngine::class)->request($this->paper, 'customer', 'credit_limit', '50000', null, 'test', $this->clerk->id);
        $this->assertNull($made, 'প্রস্তুতিটাই ভুল — বন্ধ ছকে অনুরোধ বসল।');

        $this->artisan('abos:approvals-due')->expectsOutputToContain('escalated=0')->assertSuccessful();
        $this->assertSame(0, Notification::query()->where('type', 'approval.escalated')->count());
    }

    private function flow(bool $active, ?User $escalateTo = null): void
    {
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'customer', 'action' => 'credit_limit',
            'document_type' => 'Customer', 'threshold_amount' => '0', 'is_active' => $active]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->boss->id, 'requires_all' => false, 'sla_hours' => 4,
            'escalate_to_type' => $escalateTo === null ? null : 'user', 'escalate_to_id' => $escalateTo?->id]);
    }

    private function lateRequest(): Approval
    {
        $approval = app(ApprovalEngine::class)->request($this->paper, 'customer', 'credit_limit', '50000', null, 'test', $this->clerk->id);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — অনুরোধ বসেনি।');
        $approval->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        return $approval;
    }

    private function member(string $name, bool $manager, bool $active = true, ?Branch $limitedTo = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => $active, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($manager) {
            $role = Role::query()->firstOrCreate(['company_id' => $this->company->id, 'name' => 'Manager', 'guard_name' => 'web']);
            CompanyContext::forCompany((int) $this->company->id, fn () => $user->assignRole($role));
        }

        if ($limitedTo !== null) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $limitedTo->id]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user->fresh();
    }

    private function otherBranch(): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot($this->paper->branch_id)->firstOrFail();
    }

    private function heard(User $user): bool
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', 'approval.escalated')->exists();
    }
}
