<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Models\CustomerCreditRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ — লেখা, সইয়ে পাঠানো, আর মালিকের সই পড়লে বসানো (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬; ধাপ ২.৫)।
 *
 * ── ⭐ মালিকের নিয়ম ─────────────────────────────────────────────────
 * *"হিসাব বা ম্যানেজার অনুরোধ লেখেন, আমি সই দিলে তবেই বাড়ে"*। তাই:
 *   · অনুরোধ যায় বিদ্যমান সইয়ের ব্যবস্থায় — `customer.credit_limit` ছকে, নতুন কোনো ছক নয়। ⛔ কোনো ছক বীজ হিসেবে বসানো হয় না।
 *   · সইয়ের আগে চলতি সীমা এক পয়সাও বদলায় না; বসে কেবল শেষ সইয়ে, আর সেই সইগুলোর মধ্যে মালিকের সই থাকা চাই ([[applySigned()]])।
 *   · যিনি চাইলেন তিনি সই দিতে পারেন না — ইঞ্জিনের নিজের নিয়ম (অঙ্ক খালি রাখা হয়, তাই "নিজের সীমা" খাটে না)।
 *   · কোম্পানির সই বন্ধ (কোনো সচল ছক নেই) → মালিক ছাড়া কেউ অনুরোধই তুলতে পারেন না; মালিক নিজে সরাসরি বসান। ⛔ নিজে থেকে
 *     পাস করা (auto-approve) নেই।
 *   · না হওয়া, ফিরিয়ে নেওয়া আর মেয়াদ পেরোনো অনুরোধ থাকে — মোছে না।
 *   · কমাতে অনুরোধ লাগে না (ঝুঁকি কমায়) — গ্রাহকের ফর্মে আগের মতো।
 */
final class CreditLimitRequests
{
    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly Ownership $owners,
    ) {}

    /**
     * @param  string  $kind  [[CustomerCreditRequest::LIMIT]] বা [[CustomerCreditRequest::TEMPORARY]]
     */
    public function raise(Customer $customer, string $kind, string $amount, ?string $until, string $reason, ?UploadedFile $file, User $by): CustomerCreditRequest
    {
        $amount = bcadd($amount, '0', 4);
        $reason = trim($reason);
        $base = bcadd((string) ($customer->credit_limit ?? '0') ?: '0', '0', 4);
        $temporary = $kind === CustomerCreditRequest::TEMPORARY;

        // ⓘ কেবল বাড়ানো — কমাতে সই লাগে না, গ্রাহকের ফর্মেই
        $floor = $temporary ? $customer->effectiveCreditLimit() : $base;

        if (bccomp($amount, $floor, 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('customer::credit_request.not_a_raise', ['limit' => Money::format($floor)])]);
        }

        if ($temporary && ($until === null || Carbon::parse($until)->lt(Carbon::today()))) {
            throw ValidationException::withMessages(['temp_until' => __('customer::credit_request.until_past')]);
        }

        $isOwner = CustomerService::isSuperAdmin($by);
        $flowOn = $this->approvals->requires('customer', 'credit_limit', null, class_basename(CustomerCreditRequest::class));

        // ⛔ সই বন্ধ — মালিক ছাড়া কেউ নন; আর মালিকেরটা সরাসরি বসে, সইয়ে যায় না (যাওয়ার জায়গাই নেই)
        if (! $flowOn && ! $isOwner) {
            throw ValidationException::withMessages(['amount' => __('customer::credit_request.approvals_off')]);
        }

        if ($flowOn && ! $this->ownerCanSign()) {
            throw ValidationException::withMessages(['amount' => __('customer::credit_request.owner_not_in_flow')]);
        }

        return DB::transaction(function () use ($customer, $kind, $amount, $until, $reason, $file, $by, $base, $temporary, $flowOn) {
            // ⓘ গ্রাহকের সারিতে তালা — একই গ্রাহকে দুইটা অনুরোধ একসাথে বসে না
            $locked = Customer::query()->withoutGlobalScopes()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $waiting = CustomerCreditRequest::query()->withoutGlobalScopes()
                ->where('company_id', $locked->company_id)
                ->where('customer_id', $locked->id)
                ->where('status', CustomerCreditRequest::PENDING)
                ->get()
                ->contains(fn (CustomerCreditRequest $r) => $r->shownStatus() === CustomerCreditRequest::PENDING);

            if ($waiting) {
                throw ValidationException::withMessages(['amount' => __('customer::credit_request.already_waiting')]);
            }

            $request = CustomerCreditRequest::query()->create([
                'company_id' => (int) $locked->company_id,
                'branch_id' => $locked->branch_id,
                'customer_id' => (int) $locked->id,
                'kind' => $kind,
                'current_limit' => $base,
                'requested_limit' => $amount,
                'temp_until' => $temporary ? Carbon::parse((string) $until)->toDateString() : null,
                'reason' => mb_substr($reason, 0, 500),
                'status' => CustomerCreditRequest::PENDING,
                'requested_by' => $by->id,
            ]);

            if ($file !== null) {
                app(AttachmentEngine::class)->store($file, 'customer', CustomerCreditRequest::class, (int) $request->id, userId: $by->id);
            }

            if (! $flowOn) {
                // ⓘ মালিক নিজে, সই বন্ধ কোম্পানিতে — সরাসরি বসানো, অনুরোধের সারিতে লেখা থাকে
                $this->apply($request, $locked, null);

                return $request->fresh();
            }

            $approval = $this->approvals->request(
                document: $request,
                module: 'customer',
                action: 'credit_limit',
                amount: null,
                reason: $this->wordFor($request, $locked),
                userId: $by->id,
            );

            if ($approval === null) {
                // ⛔ ছক এইমাত্র বন্ধ হলো — সই ছাড়া কিছুই বসে না
                throw ValidationException::withMessages(['amount' => __('customer::credit_request.approvals_off')]);
            }

            $request->forceFill(['approval_id' => $approval->id])->save();

            return $request->fresh();
        });
    }

    /**
     * ⭐ শেষ সই পড়ল বা "না" এলো — অনুরোধের ফল ([[ApplyTheSignedCreditRequest]])।
     *
     * ⛔ মালিকের সই না থাকলে বসে না (`void`) — ছকে অন্য কেউ শেষ সইকারী হলেও মালিকের নিয়ম "আমি সই দিলে তবেই"।
     * ⓘ অস্থায়ী সীমার শেষ দিন সইয়ের আগেই পেরোলে বসে না (`expired`)। ⓘ একই ঘটনা দুইবার এলে দ্বিতীয়বার কিছুই নয়।
     */
    public function applySigned(Approval $approval): void
    {
        DB::transaction(function () use ($approval): void {
            $request = CustomerCreditRequest::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($approval->approvable_id)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->status !== CustomerCreditRequest::PENDING) {
                return;
            }

            if ($approval->status === Approval::REJECTED) {
                $request->forceFill(['status' => CustomerCreditRequest::REJECTED, 'decided_at' => now()])->save();

                return;
            }

            if ($approval->status !== Approval::APPROVED) {
                return;
            }

            if (! $this->anOwnerSigned($approval)) {
                $request->forceFill(['status' => CustomerCreditRequest::VOID, 'decided_at' => now(),
                    'note' => __('customer::credit_request.no_owner_signature')])->save();

                return;
            }

            if ($request->kind === CustomerCreditRequest::TEMPORARY && Carbon::today()->gt($request->temp_until)) {
                $request->forceFill(['status' => CustomerCreditRequest::EXPIRED, 'decided_at' => now()])->save();

                return;
            }

            $customer = Customer::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($request->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->apply($request, $customer, (int) $approval->id);
        });
    }

    /** গ্রাহকের সারিতে বসানো — ইতিহাসে অনুরোধের কারণ আর সইয়ের সূত্রসহ */
    private function apply(CustomerCreditRequest $request, Customer $customer, ?int $approvalId): void
    {
        $fields = $request->kind === CustomerCreditRequest::TEMPORARY
            ? ['temp_credit_limit' => (string) $request->requested_limit, 'temp_credit_limit_until' => $request->temp_until?->toDateString()]
            : ['credit_limit' => (string) $request->requested_limit];

        CustomerCreditChange::because($request->reason, $approvalId, fn () => $customer->forceFill($fields)->save());

        $request->forceFill(['status' => CustomerCreditRequest::APPROVED, 'decided_at' => now(), 'applied_at' => now()])->save();
    }

    /** সইয়ের সারিতে কোনো "হ্যাঁ" কি এই কোম্পানির মালিকের */
    private function anOwnerSigned(Approval $approval): bool
    {
        $owners = $this->owners->activeOwnersIn((int) $approval->company_id)->pluck('id')->all();

        return ApprovalDecision::query()
            ->where('approval_id', $approval->id)
            ->where('decision', 'approved')
            ->where(fn ($q) => $q->whereIn('user_id', $owners ?: [0])->orWhereIn('on_behalf_of', $owners ?: [0]))
            ->exists();
    }

    /**
     * ⓘ সচল ছকের কোনো ধাপে মালিক সই দিতে পারেন কি না — না পারলে অনুরোধ তোলাই হয় না, নইলে সই পড়েও বসত না।
     */
    private function ownerCanSign(): bool
    {
        $owners = $this->owners->activeOwnersIn((int) CompanyContext::id());

        $flows = ApprovalFlow::query()
            ->where('module', 'customer')
            ->where('action', 'credit_limit')
            ->whereIn('document_type', ['', class_basename(CustomerCreditRequest::class)])
            ->where('is_active', true)
            ->with('steps')
            ->get();

        return $flows->contains(fn (ApprovalFlow $flow) => $flow->steps->contains(
            fn ($step) => $owners->contains(fn (User $owner) => $step->allows($owner)),
        ));
    }

    /** সইকারী যা পড়েন — কার, কত থেকে কত, কবে পর্যন্ত, কেন */
    private function wordFor(CustomerCreditRequest $request, Customer $customer): string
    {
        $key = $request->kind === CustomerCreditRequest::TEMPORARY ? 'customer::credit_request.word_temporary' : 'customer::credit_request.word_limit';

        return mb_substr(__($key, [
            'customer' => $customer->name(),
            'from' => Money::format((string) $request->current_limit),
            'to' => Money::format((string) $request->requested_limit),
            'until' => $request->temp_until === null ? '' : DateFormat::format($request->temp_until),
            'reason' => $request->reason,
        ]), 0, 500);
    }
}
