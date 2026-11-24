<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\OpenPeriod;
use App\Models\User;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Reversal;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * উল্টো কাগজ — পাকা ভাউচার আর নোটের (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬: "পাকা কাগজ বদলায় না; ভুল শোধরায় উল্টো কাগজে")।
 *
 * ── কী হয় ──────────────────────────────────────────────────────────────────
 * নিজের নম্বরে (REV-xxxx) একটা কাগজ, মূলের সূত্রসহ; মূলের খাতা পুরো উল্টায় — উল্টো সারিগুলো এই নম্বরে
 * ([[VoucherService::cancel()]] / [[NoteService::cancel()]]-এর একই উল্টানো, এখন কাগজ-নম্বরসহ)। মূল কাগজ থাকে, "বাতিল"
 * হয়ে, পাশে "উল্টো কাগজ: REV-…"। ঠিক কাগজটা দরকার হলে তারপর নতুন করে।
 *
 * ── ⛔ কখন নয় ───────────────────────────────────────────────────────────────
 *   · মূলের মাস বন্ধ — অনুমতিপ্রাপ্ত কেউ কারণ লিখে মাস খুললে তবেই;
 *   · ভাউচারের সারি ব্যাংক মেলানোয় ধরা (মাসের মেলানো বা বিবরণীর লাইন) — আগে মেলানো খুলুন; নইলে ব্যাংক "মেলানো" বলত অথচ
 *     খাতায় সারিটা উল্টে গেছে;
 *   · কারণ নেই, বা আগেই উল্টানো।
 * ⓘ কেবল পাকা কাগজ — খসড়ার বাতিল আগের মতোই সাধারণ (খাতায় কিছুই বসেনি)।
 */
final class AccountsReversalService
{
    public const DOC_TYPE = 'REV';

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly NoteService $notes,
        private readonly NumberSeriesEngine $numbers,
        private readonly TradeVoucherService $trades,
    ) {}

    public function reverseVoucher(Voucher $voucher, User $by, string $reason): Reversal
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($voucher, $by, $reason) {
            // ⓘ শাখার দেয়াল ছাড়া — ডাকার জায়গা ভাউচারটা আগেই হাতে পেয়েছে; আন্তঃকোম্পানির শাখাহীন দিক দেয়ালে লুকাত ([[AnInterCompanyTransferIsUndoneOnBothSidesOrNotAtAllTest]])
            $locked = Voucher::acrossBranches()->lockForUpdate()->findOrFail($voucher->id);

            if (! $locked->isPosted() || $locked->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.not_posted', ['no' => $locked->document_no])]);
            }

            $this->assertMonthOpen($locked->document_no, $locked->trx_date);
            $this->assertNotReconciled($locked);

            $paper = $this->paper(Reversal::VOUCHER, (int) $locked->id, $locked->document_no, (int) $locked->branch_id, (string) $locked->amount, $reason, $by);

            $this->vouchers->cancel($locked, $this->narration($paper, $reason), Carbon::today()->toDateString(), $paper->document_no);

            $locked->auditAction('reversed', $paper->document_no.' — '.$reason);

            return $paper;
        });
    }

    public function reverseNote(Note $note, User $by, string $reason): Reversal
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($note, $by, $reason) {
            $locked = Note::acrossBranches()->lockForUpdate()->findOrFail($note->id);

            if (! $locked->isConfirmed()) {
                throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.not_posted', ['no' => $locked->document_no])]);
            }

            $this->assertMonthOpen($locked->document_no, $locked->trx_date);

            // ⭐ উল্টো কাগজের অঙ্ক নোটের পুরো প্রভাব — নিজের মোট আর বাঁধা ফেরতের কাগজ (SR/PR), দুটোই এই একই লেনদেনে ফেরে ([[NoteService::cancel()]])
            $paper = $this->paper(Reversal::NOTE, (int) $locked->id, $locked->document_no, (int) $locked->branch_id, $locked->grandTotal(), $reason, $by);

            $this->notes->cancel($locked, $this->narration($paper, $reason), $paper->document_no);

            $locked->auditAction('reversed', $paper->document_no.' — '.$reason);

            return $paper;
        });
    }

    /**
     * ⭐ স্টক ছাড়া বিক্রয়/ক্রয় ভাউচারের উল্টো কাগজ — ২৪ নভেম্বর ২০২৬: আগে কেবল লেখার চাবিতে, কোনো কাগজ আর অনুমোদন ছাড়া বাতিল হত।
     *
     * ⓘ ভাউচারের পোস্টের যে ছক (`sales_voucher`/`purchase_voucher`), উল্টানোরও সেটাই; ছক না থাকলে সাথে সাথে। ⛔ পোস্টের পুরনো অনুমোদন
     * উল্টানোর অনুমোদন নয় — প্রতিবার নতুন অনুরোধ, `reverse` চিহ্নসহ; শেষ অনুমোদনে [[finishTradeVoucher()]]।
     *
     * @return Reversal|null  `null` — অনুমোদনের অপেক্ষায়; কিছুই উল্টায়নি
     */
    public function reverseTradeVoucher(TradeVoucher $voucher, User $by, string $reason): ?Reversal
    {
        $reason = $this->reason($reason);
        $this->assertTradeReversible($voucher);

        $waiting = app(ApprovalEngine::class)->request(
            document: $voucher,
            module: AccountsSignature::MODULE,
            action: $voucher->isSale() ? AccountsSignature::SALES_VOUCHER : AccountsSignature::PURCHASE_VOUCHER,
            amount: (string) $voucher->total,
            payload: ['reverse' => true, 'reason' => $reason],
            reason: mb_substr((string) __('accounts::reversal.asked', ['no' => $voucher->document_no, 'reason' => $reason]), 0, 250),
            userId: (int) $by->id,
            stateHash: app(DocumentFingerprint::class)->of($voucher),
            matchOn: ApprovalEngine::fieldsOf($voucher),
        );

        return $waiting === null ? $this->writeTradeReversal($voucher, (int) $by->id, $reason) : null;
    }

    /** শেষ অনুমোদনে — যিনি চেয়েছিলেন তাঁর নামে, তাঁর লেখা কারণে; আগেই উল্টানো হলে কিছু নয় */
    public function finishTradeVoucher(TradeVoucher $voucher, \App\Models\Approval $approval): ?Reversal
    {
        if (! $voucher->isConfirmed()) {
            return null;
        }

        $by = $approval->requested_by;

        return $this->writeTradeReversal($voucher, $by === null ? null : (int) $by, $this->reason((string) (((array) $approval->payload)['reason'] ?? '')));
    }

    private function writeTradeReversal(TradeVoucher $voucher, ?int $by, string $reason): Reversal
    {
        return DB::transaction(function () use ($voucher, $by, $reason) {
            $locked = TradeVoucher::acrossBranches()->lockForUpdate()->findOrFail($voucher->id);
            $this->assertTradeReversible($locked);

            $paper = $this->paper(Reversal::TRADE_VOUCHER, (int) $locked->id, $locked->document_no, (int) $locked->branch_id, (string) $locked->total, $reason, $by);

            $this->trades->cancel($locked, $this->narration($paper, $reason), $paper->document_no);
            $locked->auditAction('reversed', $paper->document_no.' — '.$reason);

            return $paper;
        });
    }

    private function assertTradeReversible(TradeVoucher $voucher): void
    {
        if (! $voucher->isConfirmed()) {
            throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.not_posted', ['no' => $voucher->document_no])]);
        }

        $this->assertMonthOpen($voucher->document_no, $voucher->trx_date);
    }

    private function paper(string $type, int $id, string $no, int $branchId, string $amount, string $reason, User|int|null $by): Reversal
    {
        if (Reversal::of($type, $id) !== null) {
            throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.already', ['no' => $no])]);
        }

        return Reversal::query()->create([
            'branch_id' => $branchId ?: null,
            'reversible_type' => $type,
            'reversible_id' => $id,
            'reversed_no' => $no,
            'document_no' => $this->numbers->next(self::DOC_TYPE, $branchId ?: null),
            'trx_date' => Carbon::today()->toDateString(),
            'reason' => $reason,
            'amount' => $amount,
            'created_by' => $by instanceof User ? $by->id : $by,
        ]);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.reason_required')]);
        }

        return $reason;
    }

    private function narration(Reversal $paper, string $reason): string
    {
        return (string) __('accounts::reversal.narration', ['rev' => $paper->document_no, 'no' => $paper->reversed_no, 'reason' => $reason]);
    }

    private function assertMonthOpen(string $no, mixed $date): void
    {
        $date = Carbon::parse($date);

        if (app(OpenPeriod::class)->lockOn($date->format('Y-m-d')) !== null) {
            throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.month_closed', [
                'no' => $no, 'month' => $date->format('m/Y'),
            ])]);
        }
    }

    private function assertNotReconciled(Voucher $voucher): void
    {
        $lineIds = $voucher->lines()->pluck('id');

        if ($voucher->lines()->whereNotNull('reconciliation_id')->exists()
            || BankStatementLine::query()->whereIn('matched_line_id', $lineIds)->exists()) {
            throw ValidationException::withMessages(['cancel_reason' => __('accounts::reversal.reconciled', ['no' => $voucher->document_no])]);
        }
    }
}
