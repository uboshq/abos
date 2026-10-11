<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Events\VoucherCancelled;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\Withdrawal;

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

        Withdrawal::query()->where('voucher_id', $voucherId)->where('status', DocumentStatus::CONFIRMED)->get()
            ->each(fn (Withdrawal $withdrawal) => $withdrawal->unsettle($voucherId));

        ProfitShare::query()->where('voucher_id', $voucherId)->where('status', ProfitShare::POSTED)
            ->update(['status' => ProfitShare::CANCELLED]);

        RentalAdjustment::query()->where('voucher_id', $voucherId)->get()
            ->each(fn (RentalAdjustment $month) => $month->delete());
    }
}
