<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierCreditRequest;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সরবরাহকারীর বাকির সীমা বাড়ানো — অনুরোধ → অনুমোদন → শেষ অনুমোদনে নিজে বসে (মালিক, ১৮ নভেম্বর ২০২৬; [[SupplierLimitRequests]])।
 *
 *   · দুই ধাপের ছকে প্রথম ধাপের "হ্যাঁ"-তে কিছুই বসে না; শেষ ধাপে (মালিকের সইসহ) বসে।
 *   · মালিকের নিজের সই ছাড়া বসে না — গ্রাহকের নিয়মের হুবহু; ছকে মালিক না থাকলে অনুরোধই ওঠে না।
 *   · "না" হলে সীমা যেমন ছিল, অনুরোধ থাকে "না" হয়ে।
 *   · মাঝে সীমা অন্য পথে বদলালে পুরনো অনুরোধের অনুমোদন বসে না (`void`)।
 *   · ছক বন্ধ: কর্মী অনুরোধ তুলতে পারেন না; মালিকেরটা সরাসরি বসে।
 *   · সম্পাদনার ফর্মে সীমা বাড়ানো বা তুলে দেওয়া (০) কর্মীর জন্য বন্ধ; কমানো চলে।
 *   · একই সরবরাহকারীর দ্বিতীয় অপেক্ষার অনুরোধ নয়।
 *
 * ⓘ চালানো হয়নি — এই পিসিতে পরীক্ষা নিষেধ (মালিক); ম্যাকে চলবে।
 */
final class ASupplierLimitRaiseWaitsForTheLastSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $manager;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->clerk = $this->staffWith(['supplier.view', 'supplier.update']);
        $this->manager = $this->staffWith(['supplier.view']);

        $this->supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $this->supplier->forceFill(['credit_limit' => '10000'])->save();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_first_step_sets_nothing_and_the_last_step_sets_the_limit(): void
    {
        $this->twoStepFlow();

        $this->ask(['amount' => '50000', 'reason' => 'ঈদের চালান'])->assertSessionHasNoErrors();

        $request = SupplierCreditRequest::query()->sole();
        $this->assertSame(SupplierCreditRequest::PENDING, $request->status);
        $approval = Approval::query()->findOrFail($request->approval_id);

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->manager);
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '10000', 4),
            '⛔ প্রথম ধাপের অনুমোদনেই সীমা বেড়ে গেল।');
        $this->assertSame(SupplierCreditRequest::PENDING, $request->fresh()->status);

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner);
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '50000', 4), '⛔ শেষ অনুমোদনের পরেও সীমা বসেনি।');
        $this->assertSame(SupplierCreditRequest::APPROVED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->applied_at);
    }

    /**
     * ⛔ মালিকের নিজের সই ছাড়া সীমা বসে না — গ্রাহকের নিয়মের হুবহু (সমন্বয়ক, ২০ নভেম্বর ২০২৬;
     * [[ALimitRaiseWaitsForTheOwnersSignatureTest]])।
     */
    public function test_a_flow_without_the_owner_refuses_the_request_and_a_signature_without_him_sets_nothing(): void
    {
        $flow = ApprovalFlow::create(['module' => 'supplier', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->manager->id]);

        // ⓘ ছকে মালিক নেই — অনুরোধই ওঠে না
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasErrors('amount');
        $this->assertSame(0, SupplierCreditRequest::query()->count(), '⛔ মালিকহীন ছকে অনুরোধ উঠল।');

        // ⓘ অনুরোধের পরে কেউ ছক থেকে মালিককে সরালেন — ম্যানেজারের শেষ সইয়েও সীমা বসে না
        $owner = ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $this->ownerRole()->id]);
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $owner->delete();

        $request = SupplierCreditRequest::query()->sole();
        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->manager);

        $this->assertSame(SupplierCreditRequest::VOID, $request->fresh()->status, '⛔ মালিকের সই ছাড়া শেষ অনুমোদনে অনুরোধ বাতিল হয়নি।');
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '10000', 4), '⛔ মালিকের সই ছাড়াই সীমা বসল।');
    }

    public function test_a_rejected_request_changes_nothing_and_is_kept(): void
    {
        $this->twoStepFlow();
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();

        $request = SupplierCreditRequest::query()->sole();
        app(ApprovalEngine::class)->reject(Approval::query()->findOrFail($request->approval_id), $this->manager, 'এখন নয়');

        $this->assertSame(SupplierCreditRequest::REJECTED, $request->fresh()->status);
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '10000', 4));
    }

    public function test_a_limit_moved_in_between_voids_the_old_request(): void
    {
        $this->oneStepOwnerFlow();
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $request = SupplierCreditRequest::query()->sole();

        // ⓘ মাঝে ঝুঁকিতে সীমা কমানো হলো (কমানো ফর্মেই চলে)
        $this->supplier->forceFill(['credit_limit' => '5000'])->save();

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->owner);

        $this->assertSame(SupplierCreditRequest::VOID, $request->fresh()->status, '⛔ পুরনো অনুরোধের অনুমোদন কমানোটা মুছে দিল।');
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '5000', 4));
    }

    public function test_with_approvals_off_staff_cannot_ask_and_the_owner_sets_it_directly(): void
    {
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasErrors('amount');
        $this->assertSame(0, SupplierCreditRequest::query()->count());

        $this->actingAs($this->owner)->post(route('supplier.limit_request.store', $this->supplier->id),
            ['amount' => '50000', 'reason' => 'মালিকের সিদ্ধান্ত'])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '50000', 4));
        $request = SupplierCreditRequest::query()->sole();
        $this->assertNull($request->approval_id);
        $this->assertSame(SupplierCreditRequest::APPROVED, $request->status);
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'supplier')->count(), '⛔ অনুরোধের পথে একটা ছক বসে গেল।');
    }

    public function test_a_second_waiting_request_and_a_non_raise_are_refused(): void
    {
        $this->oneStepOwnerFlow();

        $this->ask(['amount' => '9000', 'reason' => 'কম'])->assertSessionHasErrors('amount');
        $this->ask(['amount' => '1e5', 'reason' => 'ভুল সংখ্যা'])->assertSessionHasErrors('amount');
        $this->ask(['amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $this->ask(['amount' => '60000', 'reason' => 'আবার'])->assertSessionHasErrors('amount');

        $this->assertSame(1, SupplierCreditRequest::query()->count());
    }

    public function test_the_edit_form_lowers_freely_but_does_not_raise_or_remove_the_wall(): void
    {
        $form = ['name_en' => $this->supplier->name_en, 'credit_days' => (int) $this->supplier->credit_days];

        $this->actingAs($this->clerk)->put(route('supplier.update', $this->supplier), [...$form, 'credit_limit' => '20000'])
            ->assertSessionHasErrors('credit_limit');
        $this->actingAs($this->clerk)->put(route('supplier.update', $this->supplier), [...$form, 'credit_limit' => '0'])
            ->assertSessionHasErrors('credit_limit');
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '10000', 4), '⛔ ফর্মে সীমা ঢিলা হয়ে গেল।');

        $this->actingAs($this->clerk)->put(route('supplier.update', $this->supplier), [...$form, 'credit_limit' => '8000'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->credit_limit, '8000', 4), '⛔ সীমা কমানো থামল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function ask(array $data)
    {
        return $this->actingAs($this->clerk)
            ->from(route('supplier.show', $this->supplier->id))
            ->post(route('supplier.limit_request.store', $this->supplier->id), $data);
    }

    private function twoStepFlow(): void
    {
        $flow = ApprovalFlow::create(['module' => 'supplier', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->manager->id]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $this->ownerRole()->id]);
    }

    private function oneStepOwnerFlow(): void
    {
        $flow = ApprovalFlow::create(['module' => 'supplier', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $this->ownerRole()->id]);
    }

    private function ownerRole(): Role
    {
        return Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
    }

    /** @param  list<string>  $keys */
    private function staffWith(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            $r = Role::create(['name' => 'supplier-limit-'.Str::random(5), 'guard_name' => 'web']);
            $r->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
            $user->assignRole($r);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
