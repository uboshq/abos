<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoiceLine;

/**
 * ⭐ চালানের সারির অফারের ছাড়ের কতটা এই বিলের সারিতে যায় (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ কেন একটা আলাদা জায়গা ────────────────────────────────────────────
 * চালান থেকে বিল হয় একাধিক পথে — অফিসের বিল-ফর্ম ([[SalesInvoiceService::replaceLines()]]),
 * আর মালিকের নতুন নকশায় ডেলিভারি নিশ্চিতের পরের বিল। ⛔ প্রতিটা পথে ভাগের হিসাব আলাদা
 * লিখলে একদিন এক পথে অফারের ছাড় বিলে যেত, অন্য পথে যেত না। ⭐ তাই প্রতিটা পথ এটাই ডাকে।
 *
 * ── ⭐ ভাগের নিয়ম — পয়সা হারায় না ─────────────────────────────────────
 * ⓵ বিল যতটা পরিমাণ নেয়, ছাড় তার অনুপাতে: `ছাড় × এই বিলের পরিমাণ ÷ চালানের পরিমাণ`।
 * ⓶ কিন্তু কখনো "বাকি" ছাড়ের বেশি নয় — বাকি = চালানের ছাড় − অন্য সচল বিলে যা গেছে।
 * ⓷ আর এই বিল যদি চালানের শেষ পরিমাণটুকু নেয়, তবে পুরো বাকিটাই — ⚠️ নাহলে তিন ভাগে
 *   ভাগ করা ১০০ টাকা ৩৩.৩৩ + ৩৩.৩৩ + ৩৩.৩৩ = ৯৯.৯৯ হত, এক পয়সা চিরকাল হারাত।
 *
 * ⓘ বাতিল বিলের ভাগ গোনায় নেই — বাতিলে সেই পরিমাণ চালানে ফেরে, ছাড়ও ফেরে।
 */
final class ChallanOfferShare
{
    /**
     * @param  int|null  $exceptInvoiceId  যে বিল এখন লেখা হচ্ছে — তার পুরনো সারিগুলো "অন্য বিল" নয়
     */
    public function of(?DeliveryChallanLine $line, string $qty, ?int $exceptInvoiceId = null): string
    {
        if ($line === null) {
            return '0';
        }

        $total = (string) ($line->promotion_discount ?? '0');

        if (bccomp($total, '0', 4) <= 0 || bccomp((string) $line->delivered_qty, '0', 4) <= 0) {
            return '0';
        }

        $billed = SalesInvoiceLine::query()
            ->where('delivery_challan_line_id', $line->id)
            ->when($exceptInvoiceId !== null, fn ($q) => $q->where('sales_invoice_id', '<>', $exceptInvoiceId))
            ->whereHas('invoice', fn ($q) => $q->where('status', '<>', 'cancelled'));

        $takenQty = (string) ((clone $billed)->sum('qty') ?: '0');
        $takenShare = (string) ((clone $billed)->sum('promotion_discount') ?: '0');

        $left = bcsub($total, $takenShare, 4);

        if (bccomp($left, '0', 4) <= 0) {
            return '0';
        }

        $remainingQty = bcsub((string) $line->delivered_qty, $takenQty, 4);

        // ⓷ শেষ পরিমাণটুকু — পুরো বাকি ছাড়
        if (bccomp($qty, $remainingQty, 4) >= 0) {
            return $left;
        }

        // ⓵ অনুপাত, ⓶ বাকির বেশি নয়
        $share = bcdiv(bcmul($total, $qty, 8), (string) $line->delivered_qty, 4);

        return bccomp($share, $left, 4) > 0 ? $left : $share;
    }
}
