<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Modules\CreditControl\CreditControlKit;
use Tests\TestCase;

/**
 * ⭐ ফোনে বাকি নিয়ন্ত্রণ — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
 *
 * দাবি ([[CreditControlApiController]]):
 *  - ড্যাশবোর্ডের সংখ্যা ওয়েবের ড্যাশবোর্ডের নিজের যোগ ([[CreditDashboard::figures()]])।
 *  - গ্রাহকের পাতা দেয়ালের হিসাব ([[CreditExposure::positionOf()]]) আর ঝুঁকির মাত্রা কারণসহ ([[CreditRisk::rate()]])।
 *  - বেশি ঝুঁকি আর মেয়াদ পেরোনো তালিকা বাকি নিয়ন্ত্রণের নিজের রিপোর্ট — সারিতে গ্রাহকের public_id।
 *  - সীমার অনুরোধ মালিকের সইয়ে যায়, সীমা বদলায় না; অনুরোধের চাবি ছাড়া ৪০৩; সইয়ের বাক্সে অনুরোধটা নিজের কথায় দেখায়।
 *  - চেক ফেরতের বন্ধ খোলেন কেবল মালিক — চাবিওয়ালা অন্য কেউ ৪০৩; হাতে বসানো বন্ধ চাবিওয়ালা খোলেন।
 *  - অন্য গ্রাহকের ক্রমিক id ঠিকানা নয় (৪০৪); ফোনে মডিউল বন্ধ থাকলে ৪০৩ `module_off`।
 */
final class ThePhoneRunsTheCreditDeskTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private Customer $late;

    private Customer $calm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
        app(SettingsService::class)->set(PhoneModules::key('credit_control'), true);

        // ⓘ বিপজ্জনক উপাত্ত: ৭০ দিন পেরোনো বড় বিল, সীমার বেশি, দুইটা ফেরত চেক — বেশি ঝুঁকি আর মেয়াদ পেরোনো দুইটাতেই পড়ে
        $this->late = $this->customer('PH-LATE', '10000');
        $this->bill($this->late, 'PH-INV-1', '15000', 70);
        $this->bouncedCheque($this->late, '2000');
        $this->bouncedCheque($this->late, '3000');
        $this->calm = $this->customer('PH-CALM', '50000');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_dashboard_is_the_desks_own_sum_and_a_customer_is_the_walls_own_position(): void
    {
        $dash = $this->phone($this->owner, 'GET', '/api/v1/credit-control/dashboard')->assertOk()->json();
        $web = CreditDashboard::figures()['totals'];

        foreach (['exposure', 'overdue', 'limit', 'available'] as $key) {
            $this->assertSame(0, bccomp((string) $dash['totals'][$key], (string) $web[$key], 4), "⛔ ফোনের '{$key}' ওয়েবের ড্যাশবোর্ড নয়।");
        }
        $this->assertSame((int) $web['blocked'], $dash['totals']['blocked']);
        $this->assertSame(1, bccomp((string) $dash['totals']['overdue'], '0', 4), 'দাবির ভিত্তি নেই — মেয়াদ পেরোনো কিছুই নেই।');

        $body = $this->phone($this->owner, 'GET', '/api/v1/credit-control/customers/'.$this->late->public_id)->assertOk()->json();
        $position = app(CreditExposure::class)->positionOf(Customer::query()->withOutstanding()->findOrFail($this->late->id));
        $risk = app(CreditRisk::class)->rate($this->late->fresh());

        $this->assertSame(0, bccomp($body['position']['exposure'], $position['exposure'], 4), '⛔ ফোনের ব্যবহৃত বাকি দেয়ালের নয়।');
        $this->assertSame(0, bccomp($body['position']['available'], $position['available'], 4));
        $this->assertSame($position['state'], $body['position']['state']);
        $this->assertSame($risk['level'], $body['risk']['level'], '⛔ ঝুঁকির মাত্রা ওয়েবের নয়।');
        $this->assertSame($risk['reasons'], $body['risk']['reasons'], '⛔ ঝুঁকির কারণ ফোনে নেই।');
        $this->assertContains($body['risk']['level'], [CreditRisk::HIGH, CreditRisk::CRITICAL], 'দাবির ভিত্তি নেই — গ্রাহকটা ঝুঁকিতে নেই।');
        $this->assertSame('PH-INV-1', $body['bills'][0]['no']);
        $this->assertTrue($body['can_request']);
    }

    public function test_the_high_risk_and_overdue_lists_are_the_desks_own_reports_with_public_ids(): void
    {
        $risky = $this->phone($this->owner, 'GET', '/api/v1/credit-control/lists/high-risk')->assertOk()->json('rows');
        $this->assertContains($this->late->public_id, array_column($risky, 'customer'), '⛔ বেশি ঝুঁকির গ্রাহক তালিকায় নেই।');
        $this->assertNotContains($this->calm->public_id, array_column($risky, 'customer'), '⛔ শান্ত গ্রাহক বেশি ঝুঁকির তালিকায়।');
        $this->assertNotEmpty(collect($risky)->firstWhere('customer', $this->late->public_id)['reasons']);

        $bills = $this->phone($this->owner, 'GET', '/api/v1/credit-control/lists/overdue-bills')->assertOk()->json('rows');
        $mine = array_values(array_filter($bills, fn (array $r) => $r['customer'] === $this->late->public_id));
        $this->assertCount(1, $mine, '⛔ মেয়াদ পেরোনো বিলটা তালিকায় নেই।');
        $this->assertSame('PH-INV-1', $mine[0]['document_no']);
        $this->assertSame(70, (int) $mine[0]['days_overdue']);

        foreach ($bills as $row) {
            $this->assertDoesNotMatchRegularExpression('/^\d+$/', $row['customer'], '⛔ ক্রমিক id ফোনে গেল।');
        }

        $this->phone($this->owner, 'GET', '/api/v1/credit-control/lists/everything')->assertNotFound();
    }

    public function test_a_limit_request_goes_to_the_owners_signature_and_reads_plainly_in_the_inbox(): void
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        $ownerRole = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $ownerRole->id]);

        $viewer = $this->staffWith(['credit_control.view', 'customer.view']);
        $this->phone($viewer, 'POST', '/api/v1/credit-control/customers/'.$this->late->public_id.'/limit-requests',
            ['kind' => 'limit', 'amount' => '40000', 'reason' => 'ঈদ'])->assertForbidden();

        $accountant = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'customer.view']);
        $this->phone($accountant, 'POST', '/api/v1/credit-control/customers/'.$this->late->public_id.'/limit-requests',
            ['kind' => 'limit', 'amount' => '40000', 'reason' => 'ঈদের মৌসুম'])->assertCreated()->assertJsonPath('waiting_signature', true);

        $request = CustomerCreditRequest::query()->sole();
        $this->assertSame(CustomerCreditRequest::PENDING, $request->status);
        $this->assertSame(0, bccomp((string) $this->late->fresh()->credit_limit, '10000', 4), '⛔ সইয়ের আগেই সীমা বাড়ল।');

        // ⓘ সইয়ের বাক্সে — কাগজের নাম আর অনুরোধের নিজের কথা
        $pending = $this->phone($this->owner, 'GET', '/api/v1/approvals/pending')->assertOk()->json('rows');
        $row = collect($pending)->firstWhere('documentType', 'CustomerCreditRequest');
        $this->assertNotNull($row, '⛔ সীমার অনুরোধ মালিকের ফোনের সইয়ের বাক্সে নেই।');

        $sheet = $this->phone($this->owner, 'GET', '/api/v1/approvals/'.$row['id'].'/sheet')->assertOk()->json('facts');
        $values = implode(' | ', array_column($sheet, 'value'));
        $this->assertStringContainsString('ঈদের মৌসুম', $values, '⛔ অনুরোধের কারণ সইয়ের পাতায় নেই।');
        $this->assertStringContainsString('PH-LATE', $values, '⛔ কোন গ্রাহক — সইয়ের পাতায় নেই।');
        $this->assertStringContainsString('40,000', $values, '⛔ চাওয়া সীমা সইয়ের পাতায় নেই।');
        $this->assertSame(Approval::PENDING, Approval::query()->findOrFail($request->approval_id)->status);
    }

    public function test_a_bounced_cheque_block_is_lifted_only_by_the_owner_a_hand_block_by_the_keyholder(): void
    {
        $keeper = $this->staffWith(['credit_control.view', 'customer.view', 'customer.update']);
        $this->late->forceFill([
            'credit_blocked_at' => now(), 'credit_blocked_by' => $this->owner->id,
            'credit_block_reason' => 'চেক ফেরত — BC-1', 'credit_block_kind' => Customer::BLOCK_BOUNCED_CHEQUE,
        ])->save();

        $this->assertFalse($this->phone($keeper, 'GET', '/api/v1/credit-control/customers/'.$this->late->public_id)->json('may_lift'));
        $this->phone($keeper, 'POST', '/api/v1/credit-control/customers/'.$this->late->public_id.'/lift-block', ['reason' => 'টাকা দিয়েছেন'])
            ->assertForbidden();
        $this->assertTrue($this->late->fresh()->isCreditBlocked(), '⛔ মালিক ছাড়া কেউ চেক ফেরতের বন্ধ খুললেন।');

        $this->assertTrue($this->phone($this->owner, 'GET', '/api/v1/credit-control/customers/'.$this->late->public_id)->json('may_lift'));
        $this->phone($this->owner, 'POST', '/api/v1/credit-control/customers/'.$this->late->public_id.'/lift-block', ['reason' => 'মালিক দেখেছেন'])
            ->assertOk();
        $this->assertFalse($this->late->fresh()->isCreditBlocked(), '⛔ মালিক খুলতে পারলেন না।');

        // ⓘ নতুন করে পড়া — স্মৃতির পুরনো মডেলে একই মান "বদল নয়" বলে save কিছুই লিখত না
        $this->late = $this->late->fresh();
        $this->late->forceFill([
            'credit_blocked_at' => now(), 'credit_blocked_by' => $this->owner->id,
            'credit_block_reason' => 'হাতে বন্ধ', 'credit_block_kind' => null,
        ])->save();
        $this->phone($keeper, 'POST', '/api/v1/credit-control/customers/'.$this->late->public_id.'/lift-block', ['reason' => 'ঠিক আছে'])
            ->assertOk();
        $this->assertFalse($this->late->fresh()->isCreditBlocked(), '⛔ হাতে বসানো বন্ধ চাবিওয়ালা খুলতে পারলেন না।');
    }

    public function test_a_plain_number_is_no_address_and_a_switched_off_phone_is_told_so(): void
    {
        $this->phone($this->owner, 'GET', '/api/v1/credit-control/customers/'.$this->late->id)->assertNotFound();

        $nobody = $this->staffWith(['customer.view']);
        $this->phone($nobody, 'GET', '/api/v1/credit-control/dashboard')->assertForbidden();

        app(SettingsService::class)->set(PhoneModules::key('credit_control'), false);
        $this->phone($this->owner, 'GET', '/api/v1/credit-control/dashboard')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    /** @param array<string, mixed> $data */
    private function phone(User $user, string $method, string $url, array $data = []): TestResponse
    {
        app(SettingsService::class)->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->json($method, $url, $data);
    }
}
