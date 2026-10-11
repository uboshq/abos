<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\PermissionSyncer;
use App\Models\Approval;
use App\Models\User;
use App\Modules\CreditControl\Models\CreditLimitRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ আর মালিকের নিজের হাত — বাকি নিয়ন্ত্রণ ধাপ ২.৫ (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ মালিকের নিয়ম ─────────────────────────────────────────────────────
 *   · হিসাবরক্ষক বা ম্যানেজার অনুরোধ লেখেন (সাধারণ সীমা, বা অস্থায়ী সীমা + শেষ দিন), কারণসহ, কাগজ চাইলে সাথে।
 *   · সই চায় অনুমোদনের ইঞ্জিনে (`customer.credit_limit`, সীমা বাড়ানোর একই ছক); সীমা বসে কেবল মালিকের সইয়ে
 *     ([[ApplyTheRequestOnTheOwnersSignature]]) — সই ঝুলে থাকতে চলতি সীমা এক পয়সাও বদলায় না।
 *   · যিনি লেখেন তিনি সই দিতে পারেন না ([[CreditLimitRequest::mayBeSignedBy()]])।
 *   · "না" বা মেয়াদ ফুরানো অনুরোধ থাকে, মোছে না।
 *   · ⛔ কোম্পানির অনুমোদন বন্ধ (ছক নেই বা বন্ধ) থাকলে অনুরোধই লেখা যায় না — নিজে থেকে "হ্যাঁ" নয়; তখন কেবল সুপার অ্যাডমিন
 *     নিজে সীমা বসান ([[setDirectly()]])।
 *   · সুপার অ্যাডমিন আগের মতোই সরাসরি বসাতে পারেন; কমানোতে সই লাগে না (ঝুঁকি কমে)।
 */
final class CreditLimitRequests
{
    public function __construct(
        private readonly DocumentApproval $approvals,
        private readonly ApprovalEngine $engine,
        private readonly CustomerService $customers,
        private readonly AttachmentEngine $attachments,
    ) {}

    /**
     * ⭐ অনুরোধ লেখা — চাবি `credit_control.request_limit`; একই অনুরোধ আবার পাঠালে (দুবার চাপ, ফোনের পুনরায় চেষ্টা) আগেরটাই ফেরে।
     */
    public function raise(Customer $customer, string $kind, string $amount, ?string $until, string $reason, ?UploadedFile $file, User $by): CreditLimitRequest
    {
        if (! $by->can('credit_control.request_limit')) {
            throw new AuthorizationException(__('credit_control::request.no_key'));
        }

        if ($this->isOwner($by)) {
            throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.owner_sets_directly')]);
        }

        // ⛔ অনুমোদন বন্ধ — অনুরোধ নয়, নিজে থেকে "হ্যাঁ"-ও নয় (নিয়ম ০.৭: কোনো ছক আমরা বসাই না)
        if (! $this->engine->requires('customer', 'credit_limit', null, class_basename(CreditLimitRequest::class))) {
            throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.approvals_off')]);
        }

        [$kind, $amount, $until, $reason] = $this->clean($kind, $amount, $until, $reason);

        $request = DB::transaction(function () use ($customer, $kind, $amount, $until, $reason, $by) {
            // ⓘ গ্রাহকের সারিতে তালা — দুইটা একই মুহূর্তের অনুরোধ দুইটা সারি বানায় না
            $fresh = Customer::query()->withoutGlobalScopes()->where('company_id', $customer->company_id)
                ->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $normal = bcadd((string) ($fresh->credit_limit ?? '0') ?: '0', '0', 4);

            if (bccomp($amount, $normal, 4) <= 0) {
                throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.not_a_raise', ['limit' => \App\Core\Support\Money::format($normal)])]);
            }

            $pending = CreditLimitRequest::query()->withoutGlobalScopes()
                ->where('company_id', $fresh->company_id)
                ->where('customer_id', $fresh->id)
                ->where('status', CreditLimitRequest::PENDING)
                ->first();

            if ($pending !== null) {
                $same = $pending->kind === $kind
                    && bccomp((string) $pending->requested_limit, $amount, 4) === 0
                    && $pending->temporary_until?->toDateString() === $until;

                if ($same && ! $pending->isPastItsDay()) {
                    return $pending;
                }

                throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.one_pending', ['no' => $pending->drillDocumentNo()])]);
            }

            $request = CreditLimitRequest::query()->create([
                'company_id' => $fresh->company_id,
                'branch_id' => $fresh->branch_id,
                'customer_id' => $fresh->id,
                'kind' => $kind,
                'current_limit' => $normal,
                'requested_limit' => $amount,
                'temporary_until' => $until,
                'reason' => $reason,
                'status' => CreditLimitRequest::PENDING,
                'requested_by' => $by->id,
            ]);

            $why = $kind === CreditLimitRequest::TEMPORARY
                ? __('credit_control::request.asks_temporary', ['from' => \App\Core\Support\Money::format($normal), 'to' => \App\Core\Support\Money::format($amount), 'until' => $until, 'reason' => $reason], 'bn')
                : __('credit_control::request.asks_limit', ['from' => \App\Core\Support\Money::format($normal), 'to' => \App\Core\Support\Money::format($amount), 'reason' => $reason], 'bn');

            /*
             * ⓘ অঙ্ক ছাড়া জিজ্ঞেস — ছকের সীমা (threshold) টুকরো করে পার হওয়ার দরজা না খোলে (সীমা বাড়ানোর পুরনো নিয়মের একই
             * কারণ, [[CustomerService::assertRaiseIsSigned()]]); অঙ্কটা অনুরোধ বসার পরে তার সারিতে লেখা হয়।
             */
            $held = $this->approvals->stopping(
                document: $request,
                module: 'customer',
                action: 'credit_limit',
                amount: null,
                reason: $why,
                payload: ['kind' => $kind, 'temporary_until' => $until],
            );

            if ($held === null || $held->status !== Approval::PENDING) {
                throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.approvals_off')]);
            }

            if ($held->amount === null) {
                $held->update(['amount' => $amount]);
            }

            $request->update(['approval_id' => $held->id]);

            return $request;
        });

        if ($file !== null && $request->wasRecentlyCreated) {
            $this->attach($request, $file);
        }

        return $request->fresh();
    }

    /**
     * ⭐ সুপার অ্যাডমিনের নিজের হাত — সাধারণ সীমা (বাড়ানো বা কমানো) বা অস্থায়ী সীমা, কারণসহ, ইতিহাসে; সই লাগে না।
     *
     * ⛔ দরজা যা-ই হোক, অন্য কেউ হলে ৪০৩ — পাহারা এখানে।
     */
    public function setDirectly(Customer $customer, string $kind, string $amount, ?string $until, string $reason, User $by): Customer
    {
        if (! $this->isOwner($by)) {
            throw new AuthorizationException(__('credit_control::request.owner_only'));
        }

        [$kind, $amount, $until, $reason] = $this->clean($kind, $amount, $until, $reason, allowLowering: true);

        if ($kind === CreditLimitRequest::TEMPORARY) {
            return $this->customers->setTemporaryLimit($customer, $amount, $until, $reason);
        }

        return DB::transaction(function () use ($customer, $amount, $reason) {
            $fresh = Customer::query()->withoutGlobalScopes()->where('company_id', $customer->company_id)
                ->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            CustomerCreditChange::because($reason, null, fn () => $fresh->forceFill(['credit_limit' => $amount])->save());

            return $fresh->fresh();
        });
    }

    /**
     * ⭐ সইয়ের ফল — শেষ সই পড়লে সীমা বসে, "না" হলে কেবল অবস্থা ([[ApplyTheRequestOnTheOwnersSignature]])।
     *
     * ⓘ একই ঘটনা দুবার এলে দ্বিতীয়বার কিছুই হয় না (অনুরোধ আর ঝুলে নেই)। ⓘ অস্থায়ী সীমার শেষ দিন সইয়ের আগেই পেরোলে বসে না —
     * "মেয়াদ ফুরানো"।
     */
    public function settle(int $requestId, Approval $approval): void
    {
        DB::transaction(function () use ($requestId, $approval): void {
            $request = CreditLimitRequest::query()->withoutGlobalScopes()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || $request->status !== CreditLimitRequest::PENDING || (int) $request->approval_id !== (int) $approval->id) {
                return;
            }

            $decider = $approval->decisions()->latest('id')->value('user_id');
            $done = ['decided_by' => $decider, 'decided_at' => now()];

            if ($approval->status === Approval::REJECTED) {
                $request->update(['status' => CreditLimitRequest::REJECTED, ...$done]);

                return;
            }

            if ($approval->status !== Approval::APPROVED) {
                return;
            }

            if ($request->isPastItsDay()) {
                $request->update(['status' => CreditLimitRequest::EXPIRED, ...$done]);

                return;
            }

            $customer = Customer::query()->withoutGlobalScopes()->where('company_id', $request->company_id)
                ->whereKey($request->customer_id)->lockForUpdate()->firstOrFail();

            if ($request->kind === CreditLimitRequest::TEMPORARY) {
                try {
                    $this->customers->setTemporaryLimit($customer, (string) $request->requested_limit,
                        $request->temporary_until?->toDateString(), (string) $request->reason, (int) $approval->id);
                } catch (ValidationException) {
                    // ⓘ সই পড়ার আগে সাধারণ সীমাই এর বেশি হয়ে গেছে — অস্থায়ী সীমার আর মানে নেই
                    $request->update(['status' => CreditLimitRequest::EXPIRED, ...$done]);

                    return;
                }
            } else {
                // ⭐ ঠিক সই পড়া অঙ্কটাই — বেশিও নয়, কমও নয়
                CustomerCreditChange::because((string) $request->reason, (int) $approval->id,
                    fn () => $customer->forceFill(['credit_limit' => (string) $request->requested_limit])->save());
            }

            $request->update(['status' => CreditLimitRequest::APPROVED, ...$done]);
        });
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: string}
     */
    private function clean(string $kind, string $amount, ?string $until, string $reason, bool $allowLowering = false): array
    {
        $reason = trim($reason);
        $amount = trim($amount);

        if (! in_array($kind, [CreditLimitRequest::LIMIT, CreditLimitRequest::TEMPORARY], true)) {
            throw ValidationException::withMessages(['kind' => __('credit_control::request.bad_kind')]);
        }

        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 4) < 0 || (! $allowLowering && bccomp($amount, '0', 4) === 0)) {
            throw ValidationException::withMessages(['requested_limit' => __('credit_control::request.bad_amount')]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('credit_control::request.reason_needed')]);
        }

        $until = $kind === CreditLimitRequest::TEMPORARY ? trim((string) $until) : null;

        if ($kind === CreditLimitRequest::TEMPORARY && ($until === '' || Carbon::parse($until)->lt(Carbon::today()))) {
            throw ValidationException::withMessages(['temporary_until' => __('customer::validation.temporary_until_future')]);
        }

        return [$kind, bcadd($amount, '0', 4), $until === null ? null : Carbon::parse($until)->toDateString(), mb_substr($reason, 0, 500)];
    }

    private function attach(CreditLimitRequest $request, UploadedFile $file): void
    {
        try {
            $this->attachments->store(file: $file, module: 'credit_control', entity: CreditLimitRequest::drillSourceType(), entityId: (int) $request->id);
        } catch (AttachmentException $refused) {
            // ⓘ অনুরোধ বসে গেছে — কাগজটা ফেরত, কারণসহ; পরে অনুরোধের পাতা থেকে আবার তোলা যায়
            throw ValidationException::withMessages(['file' => __('core.attachment.refused', ['reason' => $refused->getMessage()])]);
        }
    }

    private function isOwner(User $user): bool
    {
        return $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }
}
