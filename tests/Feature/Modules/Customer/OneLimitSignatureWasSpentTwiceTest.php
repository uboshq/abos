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
 * ⛔ সীমা বাড়ানোর একটা সই বারবার খরচ হত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerService::assertRaiseIsSigned()]])।
 *
 * ⚠️ ০ → ১০ হাজারে সই পড়ল, সীমা বসল। তারপর কেউ সীমা ০ করল (কমাতে সই লাগে না), আবার ১০ হাজার লিখল — গ্রাহকের সারি
 * আবার ঠিক সই-দেওয়া অবস্থায়, প্রস্তাবও একই, তাই ছাপ হুবহু মিলত আর পুরনো সই-ই নতুন বাড়ানো ঢেকে দিত। যতবার খুশি।
 * ⭐ যে সই একবার সীমা বসিয়েছে ([[CustomerCreditChange]]-এ তার সূত্র), সেটা আর কিছু ঢাকে না।
 */
final class OneLimitSignatureWasSpentTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'TWICE', 'name_en' => 'Twice Traders']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($company->id, $branch->id);

        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@twice.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@twice.test', 'password' => 'x']);

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
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CUS-TWICE', 'name_en' => 'Twice Shop',
            'credit_limit' => '0', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
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

    public function test_a_signature_that_already_set_the_limit_does_not_cover_the_same_raise_again(): void
    {
        $service = app(CustomerService::class);

        try {
            $service->update($this->customer, ['credit_limit' => '10000']);
        } catch (ValidationException) {
            // ⓘ অনুরোধ বসল
        }

        app(ApprovalEngine::class)->approve(Approval::query()->where('status', Approval::PENDING)->firstOrFail(), $this->manager);
        $this->assertSame(0, bccomp((string) $this->customer->fresh()->credit_limit, '10000', 4), 'দৃশ্যটাই বানানো যায়নি — সইয়ে সীমা বসেনি');

        $service->update($this->customer->fresh(), ['credit_limit' => '0']);

        try {
            $service->update($this->customer->fresh(), ['credit_limit' => '10000']);
        } catch (ValidationException) {
            // ⭐ এটাই চাওয়া — নতুন সই লাগবে
        }

        $this->assertSame(0, bccomp((string) $this->customer->fresh()->credit_limit, '0', 4),
            '⛔ পুরনো, একবার খরচ হওয়া সই দিয়েই সীমা আবার বাড়ল।');
    }
}
