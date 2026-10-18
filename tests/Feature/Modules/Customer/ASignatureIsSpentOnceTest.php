<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
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
 * ⭐ একটা সই একবারই খাটে — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[Approval::markUsed()]])।
 *
 * ⛔ আগে: বাকির সীমা ১ থেকে ২ লাখে মালিকের সই পেয়ে বসল। তারপর সীমা ১ লাখে নামানো হলো (নামাতে সই লাগে না), তারপর আবার
 * ২ লাখ — গ্রাহকের সারি হুবহু আগের মতো, তাই ছাপ মিলত আর পুরনো সইয়েই বারবার। বছর বন্ধের সইও একই ফাঁদে: বন্ধ, আবার খোলা,
 * আবার বন্ধ।
 */
final class ASignatureIsSpentOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Customer $customer;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'ONCE', 'name_en' => 'Once Traders']);
        $this->branch = Branch::create(['company_id' => $company->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($company->id, $this->branch->id);

        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@once.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@once.test', 'password' => 'x']);

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

        $this->customer = Customer::create(['company_id' => $company->id, 'branch_id' => $this->branch->id, 'code' => 'CUS-ONCE',
            'name_en' => 'Once Shop', 'credit_limit' => '100000', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true]);

        foreach (['customer' => 'credit_limit', 'accounts' => 'year_end'] as $module => $action) {
            $flow = ApprovalFlow::create(['module' => $module, 'action' => $action, 'threshold_amount' => null]);
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $this->manager->id]);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_limit_lowered_and_raised_again_needs_a_new_signature(): void
    {
        $service = app(CustomerService::class);

        $this->refused(fn () => $service->update($this->customer->fresh(), ['credit_limit' => '200000']), 'প্রস্তুতি — সই চাওয়ার কথা');
        app(ApprovalEngine::class)->approve(Approval::query()->where('status', Approval::PENDING)->firstOrFail(), $this->manager);
        $this->assertSame('200000.0000', (string) $this->customer->fresh()->credit_limit, 'প্রস্তুতি — সই-করা সীমা বসেনি');

        $service->update($this->customer->fresh(), ['credit_limit' => '100000']);

        $this->refused(fn () => $service->update($this->customer->fresh(), ['credit_limit' => '200000']),
            '⛔ সীমা নামিয়ে আবার তোলা — পুরনো সইয়েই আবার ২ লাখ বসল।');
        $this->assertSame('100000.0000', (string) $this->customer->fresh()->credit_limit);
        $this->assertSame(1, Approval::query()->where('status', Approval::PENDING)->count(), '⛔ নতুন সই চাওয়া হয়নি।');
    }

    public function test_a_spent_signature_on_any_paper_asks_again(): void
    {
        $guard = app(DocumentApproval::class);
        $paper = Branch::create(['code' => 'Y'.uniqid(), 'name_en' => 'Year']);

        $asked = $guard->stopping($paper, 'accounts', 'year_end');
        $this->assertNotNull($asked);
        app(ApprovalEngine::class)->approve($asked, $this->manager);
        $this->assertNull($guard->stopping($paper->fresh(), 'accounts', 'year_end'), 'প্রস্তুতি — সই খাটার কথা');

        $asked->fresh()->markUsed();

        $again = $guard->stopping($paper->fresh(), 'accounts', 'year_end');
        $this->assertNotNull($again, '⛔ একবার খাটানো সই আবার খাটল।');
        $this->assertSame(Approval::PENDING, $again->status);
        $this->assertSame((int) $asked->id, (int) ($again->payload['supersedes'] ?? 0));
    }

    private function refused(callable $what, string $why): void
    {
        try {
            $what();
            $this->fail($why);
        } catch (ValidationException) {
        }
    }
}
