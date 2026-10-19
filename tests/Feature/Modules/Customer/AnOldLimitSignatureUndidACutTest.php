<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
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
use App\Core\Services\PermissionSyncer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ পুরনো কাগজের সই ঝুঁকিতে কমানো সীমা নীরবে ফিরিয়ে দিত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[ApplyTheLimitOnTheLastSignature]])।
 *
 * ⚠️ "৫ হাজার থেকে ১০ হাজার" অনুরোধ ঝুলে আছে; এর মধ্যে ঝুঁকি দেখে সীমা ০ করা হল। পরে সইকারী পুরনো কাগজে সই দিলেন —
 * আগে শেষ সইয়ে ১০ হাজার বসত, কমানোটা মুছে। ⭐ এখন অনুরোধের পরে সীমা বদলে থাকলে কিছুই বসে না।
 */
final class AnOldLimitSignatureUndidACutTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'CUTS', 'name_en' => 'Cut Traders']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($company->id, $branch->id);

        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@cuts.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@cuts.test', 'password' => 'x']);

        /*
         * ⓘ সইকারী এই কোম্পানির মালিক — D5 (পূর্ণ নিরীক্ষা, ১৮ অক্টোবর ২০২৬) থেকে বাকির সীমা বাড়ে কেবল মালিকের সইয়ে
         * ([[CreditLimitRequests::ownerSigned()]])। এই দাবির বিষয় সইয়ের অঙ্ক/একবার-খাটা, সইকারী কে তা নয়; মালিক নন এমন
         * সইকারীর দাবি [[TheCreditWallUnderTheFullAuditTest]]-এ। চাওয়া হয় তবু কেরানির হাতে — বদল কেবল সইকারীর।
         */
        $this->manager->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $company->id, fn () => $this->manager->assignRole(
            Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web')));
        $this->manager->unsetRelation('roles');
        $this->actingAs($clerk);

        $this->customer = Customer::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CUS-CUTS', 'name_en' => 'Cut Shop',
            'credit_limit' => '5000', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);

        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null]);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->manager->id,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_signature_on_a_paper_older_than_a_cut_does_not_put_the_limit_back(): void
    {
        $service = app(CustomerService::class);

        try {
            $service->update($this->customer, ['credit_limit' => '10000']);
        } catch (ValidationException) {
            // ⓘ অনুরোধ বসল
        }

        $service->update($this->customer->fresh(), ['credit_limit' => '0']);
        $this->assertSame(0, bccomp((string) $this->customer->fresh()->credit_limit, '0', 4), 'দৃশ্যটাই বানানো যায়নি — কমানো বসেনি');

        app(ApprovalEngine::class)->approve(Approval::query()->where('status', Approval::PENDING)->firstOrFail(), $this->manager);

        $this->assertSame(0, bccomp((string) $this->customer->fresh()->credit_limit, '0', 4),
            '⛔ পুরনো কাগজের সই ঝুঁকিতে কমানো সীমা ফিরিয়ে ১০ হাজার বসাল।');
    }

    public function test_a_signature_with_no_change_in_between_still_sets_the_limit(): void
    {
        try {
            app(CustomerService::class)->update($this->customer, ['credit_limit' => '10000']);
        } catch (ValidationException) {
            // ⓘ অনুরোধ বসল
        }

        app(ApprovalEngine::class)->approve(Approval::query()->where('status', Approval::PENDING)->firstOrFail(), $this->manager);

        $this->assertSame(0, bccomp((string) $this->customer->fresh()->credit_limit, '10000', 4), 'সই পড়ার পরেও সীমা বসল না।');
    }
}
