<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ বাকির দিন বাড়ানো মালিকের সইয়ে — পূর্ণ নিরীক্ষা §৫, ১৮ অক্টোবর ২০২৬ ([[CustomerService::assertDaysRaiseIsSigned()]])।
 *
 * ⛔ আগে গ্রাহকের ফর্মে ৩০ থেকে ৯০ দিন করে দিলেই বসত — সীমা বাড়াতে সই লাগে, অথচ একই সীমায় টাকা তিনগুণ সময় বাইরে থাকত
 * আর মেয়াদ-পেরোনোর দেয়াল দুই মাস দেরিতে জাগত।
 *
 * দাবি:
 *  - কোনো ছক না থাকলে (সই বন্ধ) বাড়ানো আজকের মতো বসে; সীমার সই চালু অথচ দিনের ছক নেই → থামে, দিন যা ছিল তাই (গ্যাপ ২৬)।
 *  - মালিক নন এমন সইকারীর "হ্যাঁ"-তে বাড়ে না; মালিকের সইয়ে বাড়ে।
 *  - ৬০ দিনের সই ৯০ ঢাকে না; একবার খাটা সই আবার খাটে না (কমিয়ে আবার তুললে নতুন সই)।
 *  - কমাতে সই লাগে না; দিন না ছুঁয়ে অন্য ঘর বদলালেও না।
 */
final class TheCreditDaysRoseWithoutASignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'DAYS', 'name_en' => 'Days Traders']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($company->id, $branch->id);

        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@days.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@days.test', 'password' => 'x']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@days.test', 'password' => 'x']);
        foreach ([$this->manager, $this->owner] as $u) {
            $u->companies()->attach($company->id, ['is_active' => true]);
        }
        CompanyContext::forCompany((int) $company->id, fn () => $this->owner->assignRole(Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web')));
        $this->owner->unsetRelation('roles');

        $this->actingAs($clerk);

        $this->customer = Customer::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CUS-DAYS', 'name_en' => 'Days Shop',
            'credit_limit' => '0', 'credit_days' => 30, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⓘ fe-র গ্যাপ ২৬ (২০ অক্টোবর ২০২৬): সুইচ = সীমার সইয়ের পথ বা দিনের ছক। কোনোটাই নেই (সই বন্ধ কোম্পানি, যেমন UB) → আজকের
     * মতো সরাসরি বসে। ⓘ আগে এখানে থামত — তাতে সই বন্ধ কোম্পানিও আটকাত। সীমার পথ চালু অথচ দিনের ছক নেই → থামে।
     */
    public function test_without_any_flow_a_raise_lands_and_with_the_limit_path_on_it_stops(): void
    {
        $this->update(['credit_days' => 45]);
        $this->assertSame(45, $this->days(), '⛔ সই বন্ধ কোম্পানিতে দিন বাড়ানো আটকে গেল।');

        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit']);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(\App\Core\Engines\Approval\DocumentApproval::class);
        app()->forgetInstance(\App\Modules\Customer\Services\CustomerService::class);

        try {
            $this->update(['credit_days' => 90]);
            $this->fail('⛔ সীমার সই চালু, অথচ বাকির দিন ৪৫ থেকে ৯০ হলো সই ছাড়া।');
        } catch (ValidationException $e) {
            $this->assertSame([__('customer::validation.days_need_a_flow')], $e->errors()['credit_days'] ?? null);
        }

        $this->assertSame(45, $this->days());
    }

    public function test_only_the_owners_signature_raises_the_days_and_only_as_far_as_signed(): void
    {
        $step = $this->flowSignedBy($this->manager);

        // ⛔ মালিক নন এমন সইকারী
        app(ApprovalEngine::class)->approve($this->ask(60), $this->manager);
        $this->assertRefused(60, '⛔ মালিকের সই ছাড়া বাকির দিন বাড়ল।');

        // ⭐ মালিকের সই (নতুন কাগজ, ৭৫ দিন) — ৭৫ বসে, তবে ৯০ নয়
        $step->forceFill(['approver_id' => $this->owner->id])->save();
        // ⓘ ইঞ্জিনগুলো ছক মনে রাখে — সইকারী বদলের পরে নতুন করে বানানো (Mac-এর পালা, ১৮ অক্টোবর ২০২৬)
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(\App\Core\Engines\Approval\DocumentApproval::class);
        app()->forgetInstance(\App\Modules\Customer\Services\CustomerService::class);
        app(ApprovalEngine::class)->approve($this->ask(75), $this->owner);
        $this->update(['credit_days' => 75]);
        $this->assertSame(75, $this->days(), 'মালিকের সইয়ের পরেও দিন বাড়ল না — বাড়ানোর পথই নেই।');
        // ⓘ ৭৫-এর সই বসার পরে — ৯০ নতুন বাড়ানো, নতুন সই (আগে চাইলে নতুন অনুরোধটা সই-করা ৭৫-কে ঢেকে দিত: Mac-এর পালা, ১৯ অক্টোবর)
        $this->assertRefused(90, '⛔ ৭৫ দিনের সইয়ে ৯০ দিন বসল।');

        // ⛔ একবার খাটা সই — কমিয়ে আবার ৭৫-এ তুললে নতুন সই
        $this->update(['credit_days' => 30]);
        $this->assertSame(30, $this->days(), 'কমাতে সই লাগার কথা নয়।');
        $this->assertRefused(75, '⛔ একই সই দুবার খাটল।');
    }

    public function test_an_edit_that_leaves_the_days_alone_needs_no_signature(): void
    {
        $this->update(['name_en' => 'Days Shop Renamed', 'credit_days' => 30]);
        $this->assertSame('Days Shop Renamed', $this->customer->fresh()->name_en);
    }

    private function flowSignedBy(User $signer): ApprovalFlowStep
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_days']);

        return ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
    }

    private function ask(int $days): Approval
    {
        try {
            $this->update(['credit_days' => $days]);
        } catch (ValidationException) {
            // ⓘ সইয়ের অপেক্ষায় — এটাই চাওয়া
        }

        return Approval::query()->where('approvable_type', $this->customer->getMorphClass())->where('approvable_id', $this->customer->id)
            ->where('action', 'credit_days')->where('status', Approval::PENDING)->latest('id')->firstOrFail();
    }

    private function assertRefused(int $days, string $why): void
    {
        $before = $this->days();
        try {
            $this->update(['credit_days' => $days]);
            $this->fail($why);
        } catch (ValidationException) {
            $this->assertSame($before, $this->days(), $why);
        }
    }

    /** @param array<string, mixed> $data */
    private function update(array $data): void
    {
        app(CustomerService::class)->update($this->customer->fresh(), $data);
    }

    private function days(): int
    {
        return (int) $this->customer->fresh()->credit_days;
    }
}
