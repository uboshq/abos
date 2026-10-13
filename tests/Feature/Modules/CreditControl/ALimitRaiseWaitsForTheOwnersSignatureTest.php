<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ — হিসাব বা ম্যানেজার লেখেন, মালিক সই দিলে তবেই বসে (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬; ধাপ ২.৫)।
 *
 *   · অনুরোধ সইয়ে যায় (`customer.credit_limit` ছক); সইয়ের আগে চলতি সীমা বদলায় না, দেয়াল পুরনো সীমাতেই থামায়।
 *   · মালিক সই দিলে বসে — ইতিহাসে কারণ আর সইয়ের সূত্রসহ; অস্থায়ী অনুরোধে অস্থায়ী সীমা আর শেষ দিন।
 *   · "না" হলে কিছুই বদলায় না, অনুরোধ থাকে "না" হয়ে।
 *   · যিনি চাইলেন তিনি সই দিতে পারেন না — নিজের-সীমা যত বড়ই হোক।
 *   · সই বন্ধ কোম্পানিতে মালিক ছাড়া কেউ অনুরোধ তুলতে পারেন না; মালিকেরটা সরাসরি বসে; কোনো ছক বসে না।
 *   · ছকে মালিক না থাকলে অনুরোধই ওঠে না; মালিক ছাড়া কারও শেষ সইয়ে বসে না।
 *   · একই গ্রাহকের দ্বিতীয় অপেক্ষার অনুরোধ নয়। · ড্যাশবোর্ড আর অপেক্ষার তালিকায় অনুরোধটা ওঠে।
 */
final class ALimitRaiseWaitsForTheOwnersSignatureTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private User $accountant;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
        app(SettingsService::class)->set('customer.overdue_stop', false);

        $this->accountant = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'customer.view']);
        $this->shop = $this->customer('REQ-1', '10000');
        $this->owes($this->shop, '8000');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_request_changes_nothing_until_the_owner_signs_then_it_is_set_and_written(): void
    {
        $this->ownerFlow();

        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'ঈদের মৌসুম'])->assertSessionHasNoErrors();

        $request = CustomerCreditRequest::query()->sole();
        $this->assertSame(CustomerCreditRequest::PENDING, $request->status);
        $this->assertSame((int) $this->accountant->id, (int) $request->requested_by);
        $approval = Approval::query()->findOrFail($request->approval_id);
        $this->assertSame(Approval::PENDING, $approval->status);

        // ⛔ সইয়ের আগে: সীমা ১০,০০০-ই, দেয়াল সেটাতেই থামায়
        $this->assertSame(0, bccomp($this->shop->fresh()->effectiveCreditLimit(), '10000', 4), '⛔ সইয়ের আগেই সীমা বেড়ে গেল।');
        $this->assertFalse(app(CreditExposure::class)->check($this->shop->fresh(), '20000')['fits']);

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner);

        $this->assertSame(0, bccomp((string) $this->shop->fresh()->credit_limit, '50000', 4), '⛔ মালিকের সইয়ের পরেও সীমা বসেনি।');
        $this->assertTrue(app(CreditExposure::class)->check($this->shop->fresh(), '20000')['fits']);
        $this->assertSame(CustomerCreditRequest::APPROVED, $request->fresh()->status);

        $row = CustomerCreditChange::query()->where('customer_id', $this->shop->id)->where('field', 'credit_limit')->sole();
        $this->assertSame((int) $approval->id, (int) $row->approval_id);
        $this->assertSame('ঈদের মৌসুম', $row->reason);
    }

    public function test_a_temporary_request_sets_a_temporary_limit_and_its_last_day(): void
    {
        $this->ownerFlow();
        $until = now()->addDays(5)->toDateString();

        $this->ask(['kind' => 'temporary', 'amount' => '80000', 'temp_until' => $until, 'reason' => 'বড় চালান'])->assertSessionHasNoErrors();
        $this->assertNull($this->shop->fresh()->temp_credit_limit, '⛔ সইয়ের আগেই অস্থায়ী সীমা বসল।');
        $this->assertSame(0, bccomp($this->shop->fresh()->effectiveCreditLimit(), '10000', 4), '⛔ অপেক্ষার অনুরোধ চলতি সীমায় গোনা হলো।');

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail(CustomerCreditRequest::query()->sole()->approval_id), $this->owner);

        $fresh = $this->shop->fresh();
        $this->assertSame(0, bccomp((string) $fresh->temp_credit_limit, '80000', 4));
        $this->assertSame($until, $fresh->temp_credit_limit_until?->toDateString());
        $this->assertSame(0, bccomp((string) $fresh->credit_limit, '10000', 4), 'সাধারণ সীমা ছোঁয়ার কথা নয়।');
        $this->assertSame(0, bccomp($fresh->effectiveCreditLimit(), '80000', 4));
    }

    public function test_a_rejected_request_is_kept_and_changes_nothing(): void
    {
        $this->ownerFlow();
        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();

        $request = CustomerCreditRequest::query()->sole();
        app(ApprovalEngine::class)->reject(Approval::query()->findOrFail($request->approval_id), $this->owner, 'এখন নয়');

        $this->assertSame(CustomerCreditRequest::REJECTED, $request->fresh()->status, '⛔ "না"-এর অনুরোধ হারিয়ে গেল বা অবস্থা বদলায়নি।');
        $this->assertSame(0, bccomp((string) $this->shop->fresh()->credit_limit, '10000', 4));
        $this->assertSame(0, CustomerCreditChange::query()->where('customer_id', $this->shop->id)->count());
    }

    public function test_the_one_who_asked_cannot_sign_whatever_the_self_limit(): void
    {
        // ⓘ ছকের এক ধাপে চাওয়ার মানুষটিও আছেন, আর নিজের-সীমা বিশাল — তবু নিজের অনুরোধে সই নয়
        $flow = $this->ownerFlow();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->accountant->id]);
        app(SettingsService::class)->set('approval.self_limit', '999999999');

        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $approval = Approval::query()->findOrFail(CustomerCreditRequest::query()->sole()->approval_id);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->accountant), '⛔ যিনি চাইলেন তিনিই সই দিতে পারছেন।');
        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->owner));
    }

    public function test_with_approvals_off_only_the_owner_sets_it_directly_and_no_flow_is_made(): void
    {
        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasErrors('amount');
        $this->assertSame(0, CustomerCreditRequest::query()->count(), '⛔ সই বন্ধ কোম্পানিতে অনুরোধ উঠল।');
        $this->assertSame(0, bccomp((string) $this->shop->fresh()->credit_limit, '10000', 4));

        $this->actingAs($this->owner)->post(route('credit_control.limit_request.store', $this->shop->id),
            ['kind' => 'limit', 'amount' => '50000', 'reason' => 'মালিকের সিদ্ধান্ত'])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp((string) $this->shop->fresh()->credit_limit, '50000', 4), 'মালিকের সরাসরি বদল বসেনি।');
        $request = CustomerCreditRequest::query()->sole();
        $this->assertNull($request->approval_id);
        $this->assertSame(CustomerCreditRequest::APPROVED, $request->status);
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'customer')->where('action', 'credit_limit')->count(),
            '⛔ অনুরোধের পথে একটা সইয়ের ছক বসে গেল।');
        $this->assertSame(0, Approval::query()->where('module', 'customer')->where('action', 'credit_limit')->count(),
            '⛔ সই বন্ধ কোম্পানিতে সইয়ের অনুরোধ বসল (নিজে থেকে পাস)।');
    }

    public function test_a_flow_without_the_owner_refuses_the_request_and_a_signature_without_him_sets_nothing(): void
    {
        $manager = $this->staffWith(['credit_control.view']);
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $manager->id]);

        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasErrors('amount');
        $this->assertSame(0, CustomerCreditRequest::query()->count());

        // ⓘ অনুরোধের পরে কেউ ছক থেকে মালিককে সরালেন — ম্যানেজারের শেষ সইয়েও সীমা বসে না
        $owner = ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $this->ownerRole()->id]);
        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $owner->delete();

        $request = CustomerCreditRequest::query()->sole();
        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $manager);

        $this->assertSame(CustomerCreditRequest::VOID, $request->fresh()->status);
        $this->assertSame(0, bccomp((string) $this->shop->fresh()->credit_limit, '10000', 4), '⛔ মালিকের সই ছাড়াই সীমা বসল।');
    }

    public function test_one_waiting_request_per_customer_and_the_desk_counts_it(): void
    {
        $this->ownerFlow();
        $this->ask(['kind' => 'limit', 'amount' => '50000', 'reason' => 'চাই'])->assertSessionHasNoErrors();
        $this->ask(['kind' => 'limit', 'amount' => '60000', 'reason' => 'আবার'])->assertSessionHasErrors('amount');
        $this->assertSame(1, CustomerCreditRequest::query()->count());

        $this->actingAs($this->owner);
        $this->assertSame(1, CreditDashboard::figures()['totals']['pending_requests']);
        $rows = collect(app(ReportEngine::class)->run(CreditPositionReports::PENDING_REQUESTS, [], perPage: 1000)->rows);
        $this->assertSame(0, bccomp((string) $rows->firstWhere('customer_code', 'REQ-1')['asked_limit'], '50000', 2));

        $this->get(route('credit_control.customer.show', $this->shop->id))->assertOk()
            ->assertSee('data-request-status="pending"', false);
    }

    /** ⛔ `1e5` ফর্মের ভুল-বার্তা, ৫০০ নয় — বাকি নিয়ন্ত্রণের লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ওয়েব আর ফোন) */
    public function test_an_exponent_amount_is_a_field_error_not_a_crash(): void
    {
        $this->ownerFlow();

        $this->ask(['kind' => 'limit', 'amount' => '1e5', 'reason' => 'চাই'])->assertSessionHasErrors('amount');
        $this->assertSame(0, CustomerCreditRequest::query()->count());
    }

    // ── যন্ত্রপাতি ──

    /** @param  array<string, string>  $data */
    private function ask(array $data)
    {
        return $this->actingAs($this->accountant)
            ->from(route('credit_control.customer.show', $this->shop->id))
            ->post(route('credit_control.limit_request.store', $this->shop->id), $data);
    }

    /** ⓘ কেবল এই পরীক্ষার কোম্পানিতে — মালিকের রোলে একটা ধাপ (কোডে কোনো ছক বীজ হয় না) */
    private function ownerFlow(): ApprovalFlow
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $this->ownerRole()->id]);

        return $flow;
    }

    private function ownerRole(): Role
    {
        return Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
    }
}
