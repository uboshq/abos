<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Events\VoucherCancelled;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\ProfitDistribution;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Accounts থেকে ভাউচার বাতিল — অর্থের যে কাগজ তাতে টাকা নিয়েছিল, সে নিজেও ফেরে (পুরো-ERP অডিট, অর্থ M22, ১০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে উত্তোলন, লাভের ভাগ আর ভাড়ার মাসের ভাউচার Accounts-এর পর্দা থেকে বাতিল করলে খাতা উল্টাত, অথচ কাগজ "নিশ্চিত"/"পোস্ট"
 * থাকত — উত্তোলন মাসিক সীমা আর মূলধনে গোনা চলত, লাভের ভাগ "ঘোষিত"-এ থাকত, ভাড়ার মাস "দেওয়া" আর জামানতের কাটা গোনা হত।
 *
 * ⓘ খোঁজা হয় কাগজের নিজের `voucher_id` দিয়ে — তাই আগের ভাউচারেও খাটে (তাদের `against_type` খালি), মাইগ্রেশন লাগে না।
 *   · উত্তোলন → আবার খসড়া (নিজের [[Withdrawal::unsettle()]]): চাইলে আবার পোস্ট করা যায়
 *   · লাভের ভাগ → বাতিল: ঘোষণাটাই উল্টেছে
 *   · ভাড়ার মাস → মুছে ফেলা (soft delete): মাসটা আর দেওয়া নয়, জামানতের কাটাও ফেরে
 *   · মাসশেষের জমা (ভাড়ার প্রদেয়, ভাড়াটের চার্জ, জমার মুনাফা, ঋণের সুদ, অগ্রিম বীমা) → সারিটা মোছা, মাসটা আবার চালানো যায়
 *     (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬); সইয়ের "না"-তে একই কাজ আগে থেকেই হত। ⛔ আগে সারি থেকে যেত: মাসটা "হয়ে
 *     গেছে" দেখাত, আবার চালানো যেত না, আর ভাড়ার মাস শোধ করলে বাতিল প্রদেয়ের বিপরীতে Dr ২১৪১ বসত।
 *   · মাসশেষের উল্টো দাখিলা একা বাতিল হলে → সারির `reversal_voucher_id` খালি, পরের চালে আবার উল্টায়
 * ⓘ বাতিলের একই লেনদেনে চলে ([[VoucherCancelled]]) — এখানে ভুল উঠলে বাতিলটাও ফেরে।
 */
final class UndoThePaperOfACancelledVoucher
{
    public function handle(VoucherCancelled $event): void
    {
        $voucherId = (int) ($event->payload['voucher_id'] ?? 0);

        if ($voucherId <= 0) {
            return;
        }

        /*
         * ⛔ জমার চলাচলের ভাউচার একা বাতিল নয় — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬। ⓘ খোলার ভাউচার বাতিল হলে জমা "চালু" থাকত অথচ
         * ১১৬০-এ কিছু নেই, ভাঙলে ১১৬০ ঋণাত্মক; কিস্তি বাতিলে মূলধন বেশি থাকত। জমার পাতা থেকে বাতিল সবটা একসাথে ফেরায় ([[DepositService::cancel()]])।
         */
        // ⓘ কেবল খাতায় বসা ভাউচার — খসড়া বাতিলে (সইয়ের "না") খাতায় কিছু ছিল না, জমার পথ নিজেই সামলায়
        $posted = \App\Models\LedgerEntry::query()->whereIn('source_type', array_values(\App\Modules\Accounts\Models\Voucher::SOURCE_TYPES))
            ->where('source_id', $voucherId)->exists();

        if ($posted && ! DepositService::$cancelling && DepositMovement::query()->where('voucher_id', $voucherId)->exists()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.deposit_voucher_cancel_from_deposit'),
            ]);
        }

        /*
         * ⛔ ভাড়ার চুক্তির জামানত, টপ-আপ বা ফেরতের ভাউচার, আর বীমা দাবির মঞ্জুরি বা বন্ধের জার্নাল — খাতায় বসার পরে একা বাতিল নয়
         * (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬; ভাড়া ⛔২, বীমা ⛔৩)। ⓘ আগে খাতা উল্টাত অথচ চুক্তির জামানত বা দাবির মঞ্জুর অঙ্ক থেকে
         * যেত: বন্ধের সময় ফেরত বেশি, ১১৪০ ঋণাত্মক; দাবিতে ১১৫২-এ ডেবিট ছাড়াই ক্রেডিট। মাসশেষের জার্নাল (JOURNAL) নিজের পথে ফেরে, তাই বাদ।
         */
        if ($posted) {
            $voucher = \App\Modules\Accounts\Models\Voucher::acrossBranches()->find($voucherId);

            if ($voucher !== null && $voucher->against_type === \App\Modules\Finance\Models\RentalContract::drillSourceType()
                && $voucher->type !== \App\Modules\Accounts\Models\Voucher::JOURNAL) {
                throw ValidationException::withMessages([
                    'status' => __('finance::validation.rental_voucher_cancel_not_alone'),
                ]);
            }

            if (\App\Modules\Finance\Models\InsuranceClaim::query()->withoutGlobalScope('user-branch')
                ->where(fn ($q) => $q->where('approval_voucher_id', $voucherId)->orWhere('close_voucher_id', $voucherId))->exists()) {
                throw ValidationException::withMessages([
                    'status' => __('finance::validation.claim_journal_cancel_not_alone'),
                ]);
            }
        }

        Withdrawal::query()->where('voucher_id', $voucherId)->where('status', DocumentStatus::CONFIRMED)->get()
            ->each(fn (Withdrawal $withdrawal) => $withdrawal->unsettle($voucherId));

        /*
         * ⛔ লাভের ভাগ — খসড়াও বাতিল (নইলে ঘোষণার সীমা চিরকাল আটকে থাকত), আর যে ভাগ থেকে টাকা তোলা বা মূলধনে নেওয়া হয়ে গেছে তার
         * ঘোষণা বাতিল নয় — খাতা ২১৯০ পুরোটা ফেরাত অথচ তোলা টাকা থেকে যেত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
         */
        $shares = ProfitShare::query()->where('voucher_id', $voucherId)->whereIn('status', [ProfitShare::POSTED, ProfitShare::DRAFT])->get();

        foreach ($shares->where('status', ProfitShare::POSTED) as $share) {
            if ($share->person_id !== null
                && bccomp(app(ProfitDistribution::class)->outstandingFor((int) $share->person_id), (string) $share->amount, 4) < 0) {
                throw ValidationException::withMessages([
                    'status' => __('finance::validation.profit_share_already_used'),
                ]);
            }
        }

        ProfitShare::query()->whereKey($shares->pluck('id'))->update(['status' => ProfitShare::CANCELLED]);

        RentalAdjustment::query()->where('voucher_id', $voucherId)->get()
            ->each(fn (RentalAdjustment $month) => $month->delete());

        foreach ([RentalAccrual::class, TenancyCharge::class, DepositAccrual::class, InterestAccrual::class, InsurancePrepayment::class] as $model) {
            $model::query()->withoutGlobalScope('user-branch')->where('voucher_id', $voucherId)->get()->each->delete();
        }

        foreach ([DepositAccrual::class, InterestAccrual::class, InsurancePrepayment::class] as $model) {
            $model::query()->withoutGlobalScope('user-branch')->where('reversal_voucher_id', $voucherId)->update(['reversal_voucher_id' => null]);
        }
    }
}
