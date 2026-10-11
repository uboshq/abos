<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Notification;
use App\Modules\CreditControl\Services\CreditDaily;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CreditLimitRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ বাকির খবর বিজ্ঞপ্তি কেন্দ্র দিয়ে মালিকের কাছে — একবারই (বাকি নিয়ন্ত্রণ ধাপ ৩.৪)।
 *
 *   · অস্থায়ী সীমা তিন দিনের মধ্যে শেষ → খবর; রোজ চললেও একই শেষ দিনে একবার; পাঁচ দিন দূরে বা শেষ হয়ে যাওয়া → নয়।
 *   · নতুন সীমা বাড়ানোর অনুরোধ মালিকের সইয়ের অপেক্ষায় → খবর, সইয়ের পাতার ঠিকানায়; সই বন্ধ কোম্পানিতে মালিকের সরাসরি বদলে → নয়।
 *   · চেক ফেরতে বন্ধের খবর ধাপ ২.৪-এর দাবিতে ([[ABouncedChequeBlocksUntilTheOwnerLiftsItTest]])।
 */
final class TheCreditNoticesReachTheOwnerOnceTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_temporary_limit_ending_within_three_days_is_told_once(): void
    {
        $soon = $this->customer('NT-SOON', '1000');
        $soon->forceFill(['temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->addDays(2)->toDateString()])->save();
        $later = $this->customer('NT-LATER', '1000');
        $later->forceFill(['temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->addDays(5)->toDateString()])->save();
        $gone = $this->customer('NT-GONE', '1000');
        $gone->forceFill(['temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->subDay()->toDateString()])->save();

        $this->assertSame(1, app(CreditDaily::class)->temporaryEnding());
        app(CreditDaily::class)->temporaryEnding();

        $this->assertSame(1, $this->notices(CreditDaily::TEMPORARY_ENDING), '⛔ খবর গেল না, বা রোজ চলায় দুইবার গেল।');
        $this->assertStringContainsString('NT-SOON', (string) Notification::query()->withoutGlobalScopes()
            ->where('user_id', $this->owner->id)->where('type', CreditDaily::TEMPORARY_ENDING)->value('title'));
    }

    public function test_a_direct_change_by_the_owner_with_approvals_off_tells_nobody(): void
    {
        $direct = $this->customer('NT-DIRECT', '1000');
        app(CreditLimitRequests::class)->raise($direct, CustomerCreditRequest::LIMIT, '5000', null, 'মালিক নিজে', null, $this->owner);

        $this->assertSame(0, $this->notices(CreditLimitRequests::WAITING));
    }

    public function test_a_new_limit_request_tells_the_owner_once_with_the_signature_page(): void
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        $role = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $role->id]);

        $accountant = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'customer.view']);
        $shop = $this->customer('NT-ASK', '1000');
        $this->actingAs($accountant)->post(route('credit_control.limit_request.store', $shop->id),
            ['kind' => 'limit', 'amount' => '9000', 'reason' => 'মৌসুম'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->notices(CreditLimitRequests::WAITING), '⛔ নতুন অনুরোধের খবর মালিকের কাছে গেল না।');
        $approval = Approval::query()->findOrFail(CustomerCreditRequest::query()->where('customer_id', $shop->id)->value('approval_id'));
        $this->assertSame(route(...$approval->drillRoute()), Notification::query()->withoutGlobalScopes()
            ->where('user_id', $this->owner->id)->where('type', CreditLimitRequests::WAITING)->value('url'));
    }

    private function notices(string $type): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $this->owner->id)->where('type', $type)->count();
    }
}
