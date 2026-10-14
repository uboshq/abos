<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Customer\Models\CustomerCreditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ মালিক যে ছকে ছিলেন, সেটা চলতই না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CreditLimitRequests::ownerCanSign()]])।
 *
 * ⚠️ সাধারণ `customer.credit_limit` ছকে মালিক, আর অনুরোধের নিজের ধরনের ছকে কেবল একজন ম্যানেজার। আগে যাচাই যেকোনো ছকে মালিক
 * পেলেই অনুরোধ তুলত; ইঞ্জিন চালাত ধরনের ছকটা — ম্যানেজার সই দিতেন, আর মালিকের সই নেই বলে প্রতিটা অনুরোধ `void`। ⭐ এখন
 * অনুরোধ ওঠার আগেই কারণসহ থামে।
 */
final class TheOwnerWasOnAFlowThatNeverRanTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_request_is_refused_when_the_flow_that_will_run_has_no_owner(): void
    {
        $this->bootCreditCompany();
        app(SettingsService::class)->set('customer.overdue_stop', false);
        $accountant = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'customer.view']);
        $manager = $this->staffWith(['customer.view'], 'credit-manager');
        $shop = $this->customer('FLOW-1', '10000');

        $ownerRole = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $generic = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $generic->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $ownerRole->id]);
        $own = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'document_type' => class_basename(CustomerCreditRequest::class),
            'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $own->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $manager->id]);

        $this->actingAs($accountant)->from(route('credit_control.customer.show', $shop->id))
            ->post(route('credit_control.limit_request.store', $shop->id), ['kind' => 'limit', 'amount' => '50000', 'reason' => 'মৌসুম'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, CustomerCreditRequest::query()->count(), '⛔ মালিকহীন ছকে অনুরোধ উঠে গেল — সই পড়লেও বসত না');
    }
}
