<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * লটের নিজের তথ্য বদলানো — ছাপা দাম আর মেয়াদ।
 *
 * ── কেন ছাপা দাম বদলানো যায় ─────────────────────────────────────────
 * মালিকের কথা, ২০২৬-০৮-১৩: **MRP বাড়ে-কমে**। প্রস্তুতকারক দাম বদলে
 * নতুন করে ছাপেন, আর কখনো একই লটের গায়ে স্টিকার বসিয়ে দেন। ঘরটা একবার
 * বসিয়ে তালা মেরে দিলে দোকানিকে হয় ভুল দামে বেচতে হত, নয় একটা নকল লট
 * খুলতে হত — আর নকল লট মানে রিকলের খাতায় একটা মিথ্যা সারি।
 *
 * ── কেন তবু যা খুশি লেখা যায় না ──────────────────────────────────────
 * সিলিংটা আইন। ওই ঘরটা যিনি বদলাতে পারেন, তিনি কার্যত সিলিংটাই
 * বদলাতে পারেন — তাই তিনটা পাহারা:
 *
 *   ১. আলাদা অনুমতি (`inventory.batch.reprice`) — বিক্রয়ের লোকের নয়
 *   ২. পুরনো ও নতুন মান দুইটাই অডিটে (Batch IsAudited ব্যবহার করে)
 *   ৩. কারণ লেখা বাধ্যতামূলক — "কেন বাড়ল" প্রশ্নের উত্তর ছয় মাস পরেও
 *
 * ── যা ইচ্ছাকৃতভাবে করা হয় না ────────────────────────────────────────
 * আগের বিক্রয়গুলো ছোঁয়া হয় না। যে বিল ২০ টাকায় কাটা হয়েছে সেটা ২০-ই
 * থাকে; দাম বদলের অর্থ "আজ থেকে", "সবসময়" নয়। উল্টোটা করলে গতকালের
 * ছাপা বিল আর আজকের পর্দা দুই কথা বলত।
 */
final class BatchService
{
    /**
     * ছাপা দাম বদলানো।
     *
     * @param  string|null  $mrp  খালি মানে "গায়ে দাম নেই" — তখন সিলিংও নেই
     */
    public function reprice(Batch $batch, ?string $mrp, string $reason): Batch
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('inventory::validation.reprice_needs_a_reason'),
            ]);
        }

        $mrp = $mrp === null || trim($mrp) === '' ? null : trim($mrp);

        if ($mrp !== null && (! is_numeric($mrp) || bccomp($mrp, '0', 4) < 0)) {
            throw ValidationException::withMessages([
                'mrp' => __('inventory::validation.not_a_price'),
            ]);
        }

        return DB::transaction(function () use ($batch, $mrp, $reason) {
            $batch->update(['mrp' => $mrp]);

            /*
             * কারণটা অডিটের নিজের সারিতে, ব্যাচের কোনো ঘরে নয়।
             *
             * ঘরে রাখলে সেখানে কেবল **শেষ** কারণটা থাকত, আর দাম তিনবার
             * বদলালে প্রথম দুইবারের কারণ হারিয়ে যেত। অডিটে প্রতিটা
             * বদলের নিজের সারি, নিজের কারণসহ।
             *
             * update()-এর পরে ডাকা হয়, কারণ update() নিজেই পুরনো-নতুন
             * মান সহ একটা সারি লেখে; কাজের নামটা তার পাশে বসে।
             */
            $batch->auditAction('repriced', $reason);

            return $batch->fresh();
        });
    }

    /**
     * মেয়াদের তারিখ শোধরানো — টাইপো, বা কার্টনে ভুল ছাপা।
     *
     * ── কেন এটাও অডিটে, আর কারণসহ ───────────────────────────────────
     * মেয়াদ পিছিয়ে দিলে মেয়াদোত্তীর্ণ মাল আবার বিক্রয়যোগ্য হয়ে যায়।
     * এক ঘরের একটা সম্পাদনা, অথচ ফল হতে পারে মেয়াদ পেরোনো ওষুধ
     * কাউন্টারে ফিরে আসা — তাই দামের মতোই পাহারা।
     */
    public function correctExpiry(Batch $batch, ?string $expiry, string $reason): Batch
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('inventory::validation.reprice_needs_a_reason'),
            ]);
        }

        $date = $expiry === null || trim($expiry) === '' ? null : Carbon::parse($expiry)->toDateString();

        return DB::transaction(function () use ($batch, $date, $reason) {
            $batch->update(['expiry_date' => $date]);
            $batch->auditAction('expiry_corrected', $reason);

            return $batch->fresh();
        });
    }
}
