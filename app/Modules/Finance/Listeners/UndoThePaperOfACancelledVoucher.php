<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Support\DocumentStatus;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Events\VoucherCancelled;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\ProfitDistribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Accounts থেকে ভাউচার বাতিল — অর্থের যে কাগজ তাতে টাকা নিয়েছিল, সে নিজেও ফেরে (পুরো-ERP অডিট, অর্থ M22, ১০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে উত্তোলন, লাভের ভাগ আর ভাড়ার মাসের ভাউচার Accounts-এর পর্দা থেকে বাতিল করলে খাতা উল্টাত, অথচ কাগজ "নিশ্চিত"/"পোস্ট"
 * থাকত — উত্তোলন মাসিক সীমা আর মূলধনে গোনা চলত, লাভের ভাগ "ঘোষিত"-এ থাকত, ভাড়ার মাস "দেওয়া" আর জামানতের কাটা গোনা হত।
 *
 * ⓘ খোঁজা হয় কাগজের নিজের `voucher_id` দিয়ে — তাই আগের ভাউচারেও খাটে (তাদের `against_type` খালি), মাইগ্রেশন লাগে না।
 *   · উত্তোলন → আবার খসড়া (নিজের [[Withdrawal::unsettle()]]): চাইলে আবার পোস্ট করা যায়
 *   · লাভের ভাগ → বাতিল: ঘোষণাটাই উল্টেছে — সইয়ের অপেক্ষার খসড়া ভাগও (নইলে ঘোষণাযোগ্য লাভ চিরকাল আটকে থাকত)
 *   · লাভকে মূলধনে নেওয়ার সারি → মোছা: পাওনাটা আবার পাওনা ([[ProfitDistribution::dropCapitalise()]]-এর একই ফল)
 *   · ভাড়ার মাস → মুছে ফেলা (soft delete): মাসটা আর দেওয়া নয়, জামানতের কাটাও ফেরে
 *
 * ── ⭐ মাসশেষের জমা আর মাসের দাবি (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬) ─────────────────────────────────
 * ⛔ আগে সুদ জমা, আমানতের অর্জিত মুনাফা, অগ্রিম বীমা, প্রদেয় ভাড়া আর ভাড়াটের মাসের দাবির ভাউচার Accounts থেকে বাতিল হলে মাসের
 * সারি থেকে যেত — মাসটা "বসানো" দেখাত আর কোনোদিন আবার বসত না; আর উল্টো দাখিলা আগেই বসে থাকলে সেটা খাতায় একা থেকে খরচ বা
 * আয় উল্টো দিকে সরিয়ে রাখত। উল্টো দাখিলাটাই বাতিল হলে সারি "উল্টানো" বলত, অথচ জমাটা খাতায় চিরকাল।
 *   · আসল জমা বাতিল → তার উল্টো দাখিলাও বাতিল, সারি মোছা — মাসটা আবার চালানো যায়
 *   · উল্টো দাখিলা বাতিল → সারি আবার "না-উল্টানো" — পরের চালে আবার উল্টায়
 *   · দেওয়া মাসের প্রদেয় ভাড়া বাতিল হয় না — দেওয়ার ভাউচার ২১৪১ শোধ করেছে, আগে সেটা বাতিল করতে হয়
 *
 * ── ⛔ যে কাগজ নিজে ফিরতে পারে না, তার পাকা ভাউচার এখানে বাতিল হয় না ─────────────────────────────────
 * জমার খোলা/কিস্তি/মুনাফা/ভাঙা, ভাড়ার চুক্তির জামানত/বাড়ানো/বন্ধের ফেরত, বীমা দাবির অনুমোদন (টাকা আসার পরে) আর বন্ধ দাবির
 * রসিদ — এদের কাগজে রাখা অঙ্ক (আসল, জামানত, অবস্থা) খাতা থেকে আলাদা হয়ে যেত, আর পরের বন্ধ বা ফেরত ভুল অঙ্কে খাতায় বসত।
 * ⓘ বাতিলটা একই লেনদেনে চলে ([[VoucherCancelled]]), তাই এখানে থামালে খাতার উল্টো দাখিলাও ফেরে — কারণ বাংলায়, কাগজের
 * নিজের পাতা থেকে করতে বলে। ⓘ খসড়া (সইয়ের অপেক্ষায়) ভাউচার আগের মতোই বাতিল হয় — খাতায় কিছু বসেনি। অর্থের নিজের পথ
 * (যেমন গোটা জমা বাতিল, [[DepositService::cancel()]]) [[byFinance()]]-এর ভিতরে ডাকে।
 */
final class UndoThePaperOfACancelledVoucher
{
    /** ⓘ অর্থের নিজের বাতিলের পথ চলছে — কাগজ নিজেই নিজের অবস্থা সামলায় */
    private static int $byFinance = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function byFinance(callable $work): mixed
    {
        self::$byFinance++;

        try {
            return $work();
        } finally {
            self::$byFinance--;
        }
    }

    public function handle(VoucherCancelled $event): void
    {
        $voucherId = (int) ($event->payload['voucher_id'] ?? 0);

        if ($voucherId <= 0) {
            return;
        }

        $voucher = Voucher::acrossBranches()->find($voucherId);

        if ($voucher !== null && self::$byFinance === 0 && $this->wasInTheBooks($voucher)) {
            $this->refuseWhatCannotUndoItself($voucher);
        }

        Withdrawal::query()->where('voucher_id', $voucherId)->where('status', DocumentStatus::CONFIRMED)->get()
            ->each(fn (Withdrawal $withdrawal) => $withdrawal->unsettle($voucherId));

        /*
         * ⛔ যে ভাগ থেকে টাকা তোলা বা মূলধনে নেওয়া হয়ে গেছে, তার ঘোষণা বাতিল নয় — খাতা ২১৯০ পুরোটা ফেরাত অথচ তোলা টাকা থেকে
         * যেত, ২১৯০ ঋণাত্মক (ec, Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[TheProfitOutlivedItsCancelledPaperTest]])।
         */
        foreach (ProfitShare::query()->where('voucher_id', $voucherId)->where('status', ProfitShare::POSTED)->get() as $share) {
            if ($share->person_id !== null
                && bccomp(app(ProfitDistribution::class)->outstandingFor((int) $share->person_id), (string) $share->amount, 4) < 0) {
                throw ValidationException::withMessages([
                    'cancel_reason' => __('finance::validation.profit_share_already_used'),
                ]);
            }
        }

        ProfitShare::query()->where('voucher_id', $voucherId)->whereIn('status', [ProfitShare::POSTED, ProfitShare::DRAFT])
            ->update(['status' => ProfitShare::CANCELLED]);

        CapitalEntry::query()->where('voucher_id', $voucherId)->where('in_kind', CapitalEntry::PROFIT)->delete();

        RentalAdjustment::query()->where('voucher_id', $voucherId)->get()
            ->each(fn (RentalAdjustment $month) => $month->delete());

        $this->undoTheMonthEnd($voucherId);
        $this->undoTheClaimStep($voucherId);
    }

    /**
     * ⓘ দাবির অনুমোদন বা বন্ধের দাখিলা বাতিল — সইয়ের "না"-র একই ফেরা ([[InsuranceClaimService::dropRefused()]]): অনুমোদনের ঘর খালি,
     * বা দাবি আবার খোলা। ⛔ আগে দাবি অনুমোদিত/বন্ধ দেখাত, আর পরের রসিদ বা বন্ধ বাতিল হওয়া দাখিলার উপর বসত।
     */
    private function undoTheClaimStep(int $voucherId): void
    {
        $claim = InsuranceClaim::query()
            ->where(fn ($q) => $q->where('approval_voucher_id', $voucherId)->orWhere('close_voucher_id', $voucherId))
            ->first();

        if ($claim === null) {
            return;
        }

        if ((int) $claim->approval_voucher_id === $voucherId) {
            $claim->forceFill(['approved_amount' => null, 'approved_on' => null, 'approval_ref' => null, 'approval_voucher_id' => null]);
        }

        if ((int) $claim->close_voucher_id === $voucherId) {
            $claim->forceFill(['closed_on' => null, 'close_note' => null, 'close_voucher_id' => null]);
        }

        $claim->save();
        app(InsuranceClaimService::class)->refresh($claim, excluding: $voucherId);
    }

    /**
     * ⭐ মাসশেষের সারি — আসলটা বাতিল হলে উল্টোটাও যায় আর সারি মোছে; উল্টোটা বাতিল হলে সারি আবার না-উল্টানো।
     */
    private function undoTheMonthEnd(int $voucherId): void
    {
        foreach ([InterestAccrual::class, DepositAccrual::class, InsurancePrepayment::class] as $class) {
            $class::query()->where('reversal_voucher_id', $voucherId)->update(['reversal_voucher_id' => null]);

            $class::query()->where('voucher_id', $voucherId)->get()->each(function (Model $row): void {
                $reversalId = $row->getAttribute('reversal_voucher_id');

                if ($reversalId !== null) {
                    $reversal = Voucher::acrossBranches()->find((int) $reversalId);

                    if ($reversal !== null && ! $reversal->isCancelled()) {
                        app(VoucherService::class)->cancel($reversal, __('finance::validation.month_end_reversal_follows'));
                    }
                }

                $row->delete();
            });
        }

        RentalAccrual::query()->where('voucher_id', $voucherId)->delete();
        TenancyCharge::query()->where('voucher_id', $voucherId)->delete();
    }

    /** ⓘ খাতায় বসেছিল কি — খসড়ার বাতিলে খাতায় কিছুই নেই, তাই কাগজও কিছু হারায় না */
    private function wasInTheBooks(Voucher $voucher): bool
    {
        return LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type] ?? '')
            ->where('source_id', $voucher->id)
            ->exists();
    }

    private function refuseWhatCannotUndoItself(Voucher $voucher): void
    {
        $id = (int) $voucher->id;
        $paper = null;

        if (($movement = DepositMovement::query()->where('voucher_id', $id)->with('deposit')->first()) !== null) {
            $paper = (string) $movement->deposit?->document_no;
        } elseif (($accrual = RentalAccrual::query()->where('voucher_id', $id)->first()) !== null) {
            $paid = RentalAdjustment::query()->where('rental_contract_id', $accrual->rental_contract_id)
                ->whereDate('for_month', $accrual->for_month->toDateString())->exists();
            $paper = $paid ? (string) RentalContract::query()->whereKey($accrual->rental_contract_id)->value('document_no') : null;
        } elseif ($voucher->against_type === RentalContract::drillSourceType()
            && ! RentalAdjustment::query()->where('voucher_id', $id)->exists()) {
            $paper = (string) RentalContract::query()->whereKey((int) $voucher->against_id)->value('document_no');
        } elseif (($claim = InsuranceClaim::query()->where('approval_voucher_id', $id)->first()) !== null) {
            // ⓘ অনুমোদন ফেরে কেবল যতক্ষণ টাকা আসেনি আর দাবি খোলা — নইলে পাওয়া টাকা কোন দাবির, তা খাতা আর জানত না
            $paper = $claim->closed_on !== null || bccomp((string) $claim->received_amount, '0', 4) > 0 ? $claim->drillDocumentNo() : null;
        } elseif ($voucher->against_type === InsuranceClaim::drillSourceType()
            && ($claim = InsuranceClaim::query()->find((int) $voucher->against_id)) !== null && $claim->closed_on !== null) {
            $paper = $claim->drillDocumentNo();
        }

        if ($paper !== null) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('finance::validation.voucher_belongs_to_finance_paper', ['no' => $voucher->document_no, 'paper' => $paper]),
            ]);
        }
    }
}
