<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\Ownership;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierCreditRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সরবরাহকারীর বাকির সীমা বাড়ানো — অনুরোধ → সই → শেষ সইয়ে নিজে বসে (মালিক, ১৮ নভেম্বর ২০২৬; দেয়াল [[SupplierCreditWall]])।
 *
 * ⓘ গ্রাহকের [[CreditLimitRequests]]-এর ছাঁচ, সরবরাহকারীর মাপে:
 *   · অনুরোধ যায় বিদ্যমান সইয়ের ব্যবস্থায় — `supplier.credit_limit` ছক। ⛔ কোনো ছক বীজ হিসেবে বসে না।
 *   · সইয়ের আগে চলতি সীমা এক পয়সাও বদলায় না; বসে কেবল শেষ সইয়ে ([[applySigned()]], শ্রোতা [[ApplyTheSignedSupplierLimit]]),
 *     আর সেই সইগুলোর মধ্যে মালিকের নিজের সই থাকা চাই ([[ownerSigned()]]) — গ্রাহকের নিয়মের হুবহু (সমন্বয়ক, ২০ নভেম্বর ২০২৬)।
 *     ছকে মালিক না থাকলে অনুরোধই ওঠে না।
 *   · যিনি চাইলেন তিনি সই দিতে পারেন না — ইঞ্জিনের নিজের নিয়ম।
 *   · কোম্পানির সই বন্ধ (কোনো সচল ছক নেই) → মালিক ছাড়া কেউ তোলেন না; মালিকেরটা সরাসরি বসে (UB-র মতো সই-বন্ধ কোম্পানি)।
 *     ⛔ নিজে থেকে পাস (auto-approve) নেই।
 *   · সইয়ের দিন সীমা অন্য পথে বদলে থাকলে কাগজটা পুরনো — বসে না, কারণসহ `void`।
 *   · কমাতে অনুরোধ লাগে না (দেয়াল শক্ত হয়) — সরবরাহকারীর ফর্মেই ([[SupplierService::assertLimitChangeIsAllowed()]])।
 */
final class SupplierLimitRequests
{
    public const MODULE = 'supplier';

    public const ACTION = 'credit_limit';

    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly Ownership $owners,
    ) {}

    /** সই চালু কি না — এই কোম্পানিতে সীমা বাড়ানোর সচল ছক আছে কি না */
    public function flowOn(): bool
    {
        return $this->approvals->requires(self::MODULE, self::ACTION, null, class_basename(SupplierCreditRequest::class));
    }

    public function raise(Supplier $supplier, string $amount, string $reason, User $by): SupplierCreditRequest
    {
        // ⓘ কেবল সাধারণ দশমিক — `1e5` `numeric` পার হয়ে bcmath-এ ৫০০ দিত (গ্রাহকের দিকের একই শিক্ষা)
        if (preg_match('/^[+]?(\d+(\.\d*)?|\.\d+)$/', trim($amount)) !== 1) {
            throw ValidationException::withMessages(['amount' => __('validation.numeric', ['attribute' => 'amount'])]);
        }

        $amount = bcadd(trim($amount), '0', 4);
        $reason = trim($reason);
        $base = bcadd((string) ($supplier->credit_limit ?? '0') ?: '0', '0', 4);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => __('supplier::credit_request.reason')])]);
        }

        // ⓘ কেবল বাড়ানো — কমাতে সই লাগে না, ফর্মেই
        if (bccomp($amount, $base, 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('supplier::credit_request.not_a_raise', ['limit' => Money::format($base)])]);
        }

        $isOwner = $this->owners->isOwnerIn($by, (int) $supplier->company_id);
        $flowOn = $this->flowOn();

        if (! $flowOn && ! $isOwner) {
            throw ValidationException::withMessages(['amount' => __('supplier::credit_request.approvals_off')]);
        }

        // ⛔ ছকে মালিক সই দিতে না পারলে অনুরোধই ওঠে না — নইলে সই পড়েও কখনো বসত না (গ্রাহকের নিয়মের হুবহু, ২০ নভেম্বর ২০২৬)
        if ($flowOn && ! $this->ownerCanSign((int) $supplier->company_id)) {
            throw ValidationException::withMessages(['amount' => __('supplier::credit_request.owner_not_in_flow')]);
        }

        return DB::transaction(function () use ($supplier, $amount, $reason, $by, $base, $flowOn) {
            // ⓘ সরবরাহকারীর সারিতে তালা — একই জনের দুইটা অনুরোধ একসাথে বসে না
            $locked = Supplier::query()->withoutGlobalScopes()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();

            $waiting = SupplierCreditRequest::query()->withoutGlobalScopes()
                ->where('company_id', $locked->company_id)
                ->where('supplier_id', $locked->id)
                ->where('status', SupplierCreditRequest::PENDING)
                ->get()
                ->contains(fn (SupplierCreditRequest $r) => $r->shownStatus() === SupplierCreditRequest::PENDING);

            if ($waiting) {
                throw ValidationException::withMessages(['amount' => __('supplier::credit_request.already_waiting')]);
            }

            $request = SupplierCreditRequest::query()->create([
                'company_id' => (int) $locked->company_id,
                'branch_id' => $locked->branch_id,
                'supplier_id' => (int) $locked->id,
                'current_limit' => $base,
                'requested_limit' => $amount,
                'reason' => mb_substr($reason, 0, 500),
                'status' => SupplierCreditRequest::PENDING,
                'requested_by' => $by->id,
            ]);

            if (! $flowOn) {
                // ⓘ মালিক নিজে, সই-বন্ধ কোম্পানিতে — সরাসরি বসে, অনুরোধের সারিতে লেখা থাকে
                $this->apply($request, $locked, null);

                return $request->fresh();
            }

            $approval = $this->approvals->request(
                document: $request,
                module: self::MODULE,
                action: self::ACTION,
                amount: null,
                reason: $this->wordFor($request, $locked),
                userId: $by->id,
            );

            if ($approval === null) {
                // ⛔ ছক এইমাত্র বন্ধ হলো — সই ছাড়া কিছুই বসে না
                throw ValidationException::withMessages(['amount' => __('supplier::credit_request.approvals_off')]);
            }

            $request->forceFill(['approval_id' => $approval->id])->save();

            return $request->fresh();
        });
    }

    /**
     * ⭐ শেষ সই পড়ল বা "না" এলো — অনুরোধের ফল। ⓘ একই ঘটনা দুইবার এলে দ্বিতীয়বার কিছুই নয় (অবস্থা আর `pending` নয়)।
     */
    public function applySigned(Approval $approval): void
    {
        DB::transaction(function () use ($approval): void {
            $request = SupplierCreditRequest::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($approval->approvable_id)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->status !== SupplierCreditRequest::PENDING) {
                return;
            }

            if ($approval->status === Approval::REJECTED) {
                $request->forceFill(['status' => SupplierCreditRequest::REJECTED, 'decided_at' => now()])->save();

                return;
            }

            if ($approval->status !== Approval::APPROVED) {
                return;
            }

            /*
             * ⛔ মালিকের নিজের সই ছাড়া সীমা বসে না — ছকের অন্য ধাপ যা-ই হোক (সমন্বয়ক, ২০ নভেম্বর ২০২৬: গ্রাহকের নিয়মের হুবহু,
             * [[CreditLimitRequests::ownerSigned()]])। ⓘ অনুরোধ `void`, কারণ লেখা; সীমা যেমন ছিল।
             */
            if (! self::ownerSigned($approval)) {
                $request->forceFill(['status' => SupplierCreditRequest::VOID, 'decided_at' => now(),
                    'note' => __('supplier::credit_request.no_owner_signature')])->save();

                return;
            }

            $supplier = Supplier::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($request->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * ⛔ অনুরোধের পরে সীমা অন্য পথে বদলে থাকলে কাগজটা পুরনো — সইকারী পড়েছেন "১ লাখ থেকে দেড় লাখ", অথচ মাঝে সীমা কমানো
             * হয়েছিল; বসালে কমানোটা নীরবে মুছত (গ্রাহকের দিকের একই শিক্ষা)। ⓘ বসে না, কারণসহ `void`; দরকার হলে নতুন অনুরোধ।
             */
            if (bccomp(bcadd((string) ($supplier->credit_limit ?? '0') ?: '0', '0', 4), (string) $request->current_limit, 4) !== 0) {
                $request->forceFill(['status' => SupplierCreditRequest::VOID, 'decided_at' => now(),
                    'note' => __('supplier::credit_request.limit_moved')])->save();

                return;
            }

            $this->apply($request, $supplier, (int) $approval->id);
        });
    }

    /**
     * ⭐ সইয়ের "হ্যাঁ"-গুলোর মধ্যে এই কোম্পানির মালিকের (super_admin) কোনোটা আছে কি না — মালিক নিজে, বা তাঁর হয়ে দায়িত্বপ্রাপ্ত।
     * ⓘ গ্রাহকের [[CreditLimitRequests::ownerSigned()]]-এর হুবহু; এখানে লেখা — সরবরাহকারী গ্রাহকের উপর নির্ভর করে না ([[BoundariesTest]])।
     */
    public static function ownerSigned(Approval $approval): bool
    {
        $owners = app(Ownership::class)->activeOwnersIn((int) $approval->company_id)->pluck('id')->all();

        return ApprovalDecision::query()
            ->where('approval_id', $approval->id)
            ->where('decision', 'approved')
            ->where(fn ($q) => $q->whereIn('user_id', $owners ?: [0])->orWhereIn('on_behalf_of', $owners ?: [0]))
            ->exists();
    }

    /**
     * ⓘ ইঞ্জিন যে ছক চালাবে (অনুরোধের নিজের ধরনের ছক, নইলে সাধারণটা — [[ApprovalEngine::flowFor()]]) তার কোনো ধাপে মালিক সই দিতে
     * পারেন কি না। গ্রাহকের [[CreditLimitRequests::ownerCanSign()]]-এর হুবহু।
     */
    private function ownerCanSign(int $companyId): bool
    {
        $owners = $this->owners->activeOwnersIn($companyId);

        $flows = ApprovalFlow::query()
            ->where('module', self::MODULE)
            ->where('action', self::ACTION)
            ->whereIn('document_type', ['', class_basename(SupplierCreditRequest::class)])
            ->where('is_active', true)
            ->with('steps')
            ->get();

        $flow = $flows->first(fn (ApprovalFlow $f) => $f->document_type === class_basename(SupplierCreditRequest::class))
            ?? $flows->first(fn (ApprovalFlow $f) => (string) $f->document_type === '');

        return $flow !== null && $flow->steps->contains(
            fn ($step) => $owners->contains(fn (User $owner) => $step->allows($owner)),
        );
    }

    /** সরবরাহকারীর সারিতে বসানো — নিরীক্ষায় আগে-পরে, কারণ আর সইয়ের সূত্রসহ */
    private function apply(SupplierCreditRequest $request, Supplier $supplier, ?int $approvalId): void
    {
        $before = (string) $supplier->credit_limit;

        $supplier->forceFill(['credit_limit' => (string) $request->requested_limit])->save();

        app(AuditEngine::class)->recordAction($supplier, 'credit_limit_raised', __('supplier::credit_request.audit', [
            'from' => Money::format($before),
            'to' => Money::format((string) $request->requested_limit),
            'reason' => $request->reason,
            'approval' => $approvalId === null ? '—' : '#'.$approvalId,
        ]));

        $request->forceFill(['status' => SupplierCreditRequest::APPROVED, 'decided_at' => now(), 'applied_at' => now()])->save();
    }

    /** সইকারী যা পড়েন — কার, কত থেকে কত, কেন */
    private function wordFor(SupplierCreditRequest $request, Supplier $supplier): string
    {
        return mb_substr(__('supplier::credit_request.word', [
            'supplier' => $supplier->name(),
            'from' => Money::format((string) $request->current_limit),
            'to' => Money::format((string) $request->requested_limit),
            'reason' => $request->reason,
        ]), 0, 500);
    }
}
