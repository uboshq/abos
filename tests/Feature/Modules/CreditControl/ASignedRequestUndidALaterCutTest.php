<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ পুরনো অনুরোধে মালিকের সই ঝুঁকিতে কমানো সীমা ফিরিয়ে দিত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CreditLimitRequests::applySigned()]])।
 *
 * ⚠️ "১০ হাজার থেকে ৫০ হাজার" অনুরোধ ঝুলে আছে; এর মধ্যে ঝুঁকি দেখে সীমা ০ করা হল। মালিক পুরনো কাগজে সই দিলেন — আগে ৫০ হাজার
 * বসত, কমানোটা মুছে। ⭐ এখন বসে না, অনুরোধ কারণসহ বাতিল।
 */
final class ASignedRequestUndidALaterCutTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_signature_on_a_request_older_than_a_cut_sets_nothing(): void
    {
        $this->bootCreditCompany();
        app(SettingsService::class)->set('customer.overdue_stop', false);
        $accountant = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'customer.view']);
        $shop = $this->customer('CUT-1', '10000');

        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        $ownerRole = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $ownerRole->id]);

        $this->actingAs($accountant)->from(route('credit_control.customer.show', $shop->id))
            ->post(route('credit_control.limit_request.store', $shop->id), ['kind' => 'limit', 'amount' => '50000', 'reason' => 'মৌসুম'])
            ->assertSessionHasNoErrors();
        $request = CustomerCreditRequest::query()->sole();

        // ⓘ ঝুঁকিতে সীমা কমানো — কমাতে সই লাগে না
        $this->actingAs($this->owner);
        Customer::query()->whereKey($shop->id)->firstOrFail()->forceFill(['credit_limit' => '0'])->save();

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->owner);

        $this->assertSame(0, bccomp((string) $shop->fresh()->credit_limit, '0', 4), '⛔ পুরনো অনুরোধের সই কমানো সীমা ফিরিয়ে ৫০ হাজার বসাল');
        $this->assertSame(CustomerCreditRequest::VOID, $request->fresh()->status);
    }
}
