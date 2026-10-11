<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Modules\CreditControl\Models\CreditLimitRequest;
use App\Modules\CreditControl\Services\CreditLimitRequests;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ সীমা বাড়ানো একটা অনুরোধ, আর বসে কেবল মালিকের সইয়ে — বাকি নিয়ন্ত্রণ ধাপ ২.৫ (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬)।
 *
 *   · হিসাবরক্ষক অনুরোধ লেখেন; সই ঝুলে থাকতে চলতি সীমা এক পয়সাও বদলায় না, দেয়াল পুরনো সীমাই মাপে।
 *   · মালিকের সইয়ে ঠিক চাওয়া অঙ্ক বসে, ইতিহাসে কারণ আর সইয়ের সূত্রসহ; "না" হলে কিছুই বদলায় না, অনুরোধ থাকে।
 *   · যিনি লিখলেন তিনি সই দিতে পারেন না, মালিক ছাড়া কেউ পারেন না — ছক বা নিজের-সইয়ের সীমা যা-ই বলুক।
 *   · অনুমোদন বন্ধ থাকলে অনুরোধই নয় (নিজে থেকে "হ্যাঁ"-ও নয়); মালিক তখনও নিজের হাতে বসান, অন্য কেউ নয় (৪০৩)।
 *   · একই অনুরোধ আবার পাঠালে একটাই সারি; অস্থায়ী সীমার অনুরোধ শেষ দিন পেরোলে বসে না।
 */
final class ALimitRaiseWaitsForTheOwnersSignatureTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private Customer $dealer;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();

        $this->dealer = $this->customer('RAISE-1', '100000');
        $this->owes($this->dealer, '95000');
        $this->clerk = $this->staffWith(['credit_control.view', 'credit_control.request_limit', 'approval.view', 'approval.decide']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_pending_request_changes_nothing_and_the_wall_keeps_the_old_limit(): void
    {
        $this->flowSignedBy($this->owner);
        $request = $this->raise('300000');

        $this->assertSame(CreditLimitRequest::PENDING, $request->status);
        $approval = Approval::query()->findOrFail($request->approval_id);
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame(0, bccomp((string) $approval->amount, '300000', 4), 'সইকারী যে অঙ্ক দেখেন সেটা অনুরোধেই বাঁধা।');
        $this->assertSame((int) $this->clerk->id, (int) $approval->requested_by);

        $this->assertSame(0, bccomp($this->dealer->fresh()->effectiveCreditLimit(), '100000', 4), '⛔ সইয়ের আগেই সীমা বদলেছে।');
        $this->expectException(ValidationException::class);
        app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '10000');
    }

    public function test_the_owners_signature_sets_exactly_the_asked_limit_into_the_history(): void
    {
        $this->flowSignedBy($this->owner);
        $request = $this->raise('300000');
        $others = fn () => Customer::query()->withoutGlobalScopes()->whereKeyNot($this->dealer->id)->orderBy('id')->pluck('credit_limit', 'id')->all();
        $before = $others();

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->owner);

        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '300000', 4), '⛔ মালিকের সইয়েও সীমা বসেনি।');
        // ⛔ অনুরোধের নম্বর আর গ্রাহকের নম্বর গুলিয়ে অন্য কারও সীমা বসেনি (পুরনো সইয়ের শ্রোতা কেবল গ্রাহকের সারি শোনে)
        $this->assertSame($before, $others());
        $request->refresh();
        $this->assertSame(CreditLimitRequest::APPROVED, $request->status);
        $this->assertSame((int) $this->owner->id, (int) $request->decided_by);

        $row = CustomerCreditChange::query()->where('customer_id', $this->dealer->id)->where('field', 'credit_limit')->latest('id')->firstOrFail();
        $this->assertSame([(int) $request->approval_id, 'ঈদের মাল'], [(int) $row->approval_id, $row->reason]);

        app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '10000');
    }

    public function test_a_rejected_request_changes_nothing_and_is_kept(): void
    {
        $this->flowSignedBy($this->owner);
        $request = $this->raise('300000');

        app(ApprovalEngine::class)->reject(Approval::query()->findOrFail($request->approval_id), $this->owner, 'বকেয়া বেশি');

        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '100000', 4));
        $this->assertSame(CreditLimitRequest::REJECTED, $request->fresh()->status);
        $this->assertSame(1, CreditLimitRequest::query()->where('customer_id', $this->dealer->id)->count(), '⛔ নাকচ অনুরোধ মুছে গেছে।');
    }

    public function test_the_requester_cannot_sign_it_whatever_the_flow_or_self_limit_says(): void
    {
        // ⓘ ছকের ধাপে লেখকেরই নাম, আর নিজের-সইয়ের সীমা সবকিছু ঢাকে — তবু না
        $this->flowSignedBy($this->clerk);
        app(SettingsService::class)->set('approval.self_limit', '999999999');
        $request = $this->raise('300000');
        $approval = Approval::query()->findOrFail($request->approval_id);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->clerk), '⛔ লেখক নিজের অনুরোধে সই দিতে পারছেন।');

        try {
            app(ApprovalEngine::class)->approve($approval, $this->clerk);
            $this->fail('⛔ লেখক নিজের অনুরোধে সই দিয়েছেন।');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '100000', 4));
    }

    public function test_nobody_but_the_owner_can_sign_it(): void
    {
        $manager = $this->staffWith(['approval.view', 'approval.decide']);
        $this->flowSignedBy($manager);
        $request = $this->raise('300000');

        $this->assertFalse(app(ApprovalEngine::class)->canDecide(Approval::query()->findOrFail($request->approval_id), $manager),
            '⛔ মালিক ছাড়া অন্য কেউ সীমা বাড়ানোয় সই দিতে পারছেন।');
    }

    public function test_with_approvals_off_no_request_can_be_written_and_nothing_is_auto_approved(): void
    {
        try {
            $this->raise('300000');
            $this->fail('⛔ অনুমোদন বন্ধ, তবু অনুরোধ বসেছে।');
        } catch (ValidationException $e) {
            $this->assertSame([__('credit_control::request.approvals_off')], $e->errors()['requested_limit'] ?? null);
        }

        $this->assertSame(0, CreditLimitRequest::query()->count());
        $this->assertSame(0, Approval::query()->where('action', 'credit_limit')->count());
        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '100000', 4));

        // ⓘ মালিক তখনও নিজের হাতে বসান — কারণসহ, ইতিহাসে
        $this->post(route('credit_control.customer.limit_direct', $this->dealer->id),
            ['kind' => 'limit', 'requested_limit' => '250000', 'reason' => 'পুরনো ক্রেতা'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '250000', 4));
        $this->assertSame('পুরনো ক্রেতা', CustomerCreditChange::query()->where('customer_id', $this->dealer->id)->latest('id')->value('reason'));
    }

    public function test_only_the_owner_sets_directly_and_only_key_holders_request(): void
    {
        $this->actingAs($this->clerk)
            ->post(route('credit_control.customer.limit_direct', $this->dealer->id), ['kind' => 'limit', 'requested_limit' => '900000', 'reason' => 'x'])
            ->assertForbidden();

        $viewer = $this->staffWith(['credit_control.view']);
        $this->flowSignedBy($this->owner);
        $this->actingAs($viewer)
            ->post(route('credit_control.customer.limit_request', $this->dealer->id), ['kind' => 'limit', 'requested_limit' => '900000', 'reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->credit_limit, '100000', 4));
        $this->assertSame(0, CreditLimitRequest::query()->count());
    }

    public function test_a_retried_request_makes_one_record_and_a_different_one_waits(): void
    {
        $this->flowSignedBy($this->owner);

        $first = $this->raise('300000');
        $again = $this->raise('300000');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CreditLimitRequest::query()->count());
        $this->assertSame(1, Approval::query()->where('action', 'credit_limit')->count());

        $this->expectException(ValidationException::class);
        $this->raise('400000');
    }

    public function test_the_form_door_writes_the_request(): void
    {
        $this->flowSignedBy($this->owner);

        $this->actingAs($this->clerk)
            ->post(route('credit_control.customer.limit_request', $this->dealer->id),
                ['kind' => 'temporary', 'requested_limit' => '200000', 'temporary_until' => now()->addDays(10)->toDateString(), 'reason' => 'মৌসুম'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $request = CreditLimitRequest::query()->firstOrFail();
        $this->assertSame([CreditLimitRequest::TEMPORARY, now()->addDays(10)->toDateString()], [$request->kind, $request->temporary_until?->toDateString()]);
    }

    public function test_a_signed_temporary_request_sets_the_temporary_limit_and_a_pending_one_does_not_count(): void
    {
        $this->flowSignedBy($this->owner);
        $request = $this->raise('200000', CreditLimitRequest::TEMPORARY, now()->addDays(10)->toDateString());

        $this->assertNull($this->dealer->fresh()->temporary_credit_limit, '⛔ ঝুলে থাকা অস্থায়ী অনুরোধ সীমায় গোনা হয়েছে।');
        $this->assertSame(0, bccomp($this->dealer->fresh()->effectiveCreditLimit(), '100000', 4));

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->owner);

        $fresh = $this->dealer->fresh();
        $this->assertSame(0, bccomp($fresh->effectiveCreditLimit(), '200000', 4));
        $this->assertSame(0, bccomp((string) $fresh->credit_limit, '100000', 4), '⛔ অস্থায়ী অনুরোধে সাধারণ সীমা বদলেছে।');
        $this->assertSame(now()->addDays(10)->toDateString(), $fresh->temporary_credit_until?->toDateString());
    }

    public function test_a_temporary_request_signed_after_its_day_expires_unapplied(): void
    {
        $this->flowSignedBy($this->owner);
        $request = $this->raise('200000', CreditLimitRequest::TEMPORARY, now()->addDays(2)->toDateString());

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(CreditLimitRequest::EXPIRED, $request->fresh()->shownStatus());

        app(ApprovalEngine::class)->approve(Approval::query()->findOrFail($request->approval_id), $this->owner);

        $this->assertSame(CreditLimitRequest::EXPIRED, $request->fresh()->status);
        $this->assertNull($this->dealer->fresh()->temporary_credit_limit);
        $this->assertSame(1, CreditLimitRequest::query()->count(), '⛔ মেয়াদ ফুরানো অনুরোধ মুছে গেছে।');
    }

    public function test_the_profile_offers_the_request_to_staff_and_the_direct_set_to_the_owner(): void
    {
        $this->flowSignedBy($this->owner);
        $this->raise('300000');

        $this->get(route('credit_control.customer.show', $this->dealer->id))->assertOk()
            ->assertSee('data-limit-form="direct"', false)
            ->assertSee('data-request-status="pending"', false);

        $this->actingAs($this->clerk)->get(route('credit_control.customer.show', $this->dealer->id))->assertOk()
            ->assertSee('data-limit-form="request"', false)
            ->assertDontSee('data-limit-form="direct"', false);
    }

    private function raise(string $amount, string $kind = CreditLimitRequest::LIMIT, ?string $until = null): CreditLimitRequest
    {
        $this->actingAs($this->clerk);

        try {
            return app(CreditLimitRequests::class)->raise($this->dealer->fresh(), $kind, $amount, $until, 'ঈদের মাল', null, $this->clerk);
        } finally {
            $this->actingAs($this->owner);
        }
    }

    /** ⓘ পরীক্ষার কোম্পানিতেই, পরীক্ষার ভিতরে — কোনো সিডার ছক বসায় না (নিয়ম ০.৭) */
    private function flowSignedBy(User $signer): void
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
    }
}
