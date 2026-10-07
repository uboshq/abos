<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionStatus;
use Illuminate\Support\Carbon;

/**
 * মেয়াদ পেরোনো অফারের অবস্থা বদলানো — স্পেক §১৮।
 *
 * ── ⭐ স্পেক ─────────────────────────────────────────────────────────
 * *"End Date/Time পার হলে promotion automatically Inactive/Expired হবে,
 * আর expired promotion নতুন transaction-এ apply করা যাবে না।"*
 *
 * ── ⚠️ এই কাজটা না চললেও অফার থামে — আর সেটা ইচ্ছাকৃত ────────────────
 * ⓘ [[Promotion::isLiveOn()]] আর `scopeLiveOn` **তারিখ-সময়** দেখে, অবস্থা
 * নয়। ⛔ তাই এই নির্ধারিত কাজটা একদিন চলতে ভুলে গেলেও মেয়াদ পেরোনো
 * অফার কোনো নতুন বিলে বসে না।
 *
 * ⭐ এই কাজের দায় কেবল **সত্য বলা**: তালিকায় অফারটা যেন *"চলছে"* না
 * দেখায় যখন ওটা আর চলছে না। ⓘ চলতে ভুলে গেলে ড্যাশবোর্ডের *"মেয়াদ
 * পেরিয়েছে"* সতর্কতাটা জ্বলে ওঠে — সেটাই এই কাজের পাহারা।
 *
 * ── ⓘ কেন সেবা, কমান্ড নয় ───────────────────────────────────────────
 * ⚠️ এখানে মডিউলের নিজের কমান্ড নিজে থেকে নিবন্ধিত হয় না — কমান্ড থাকে
 * `app/Console/Commands`-এ, সময়সূচি `routes/console.php`-এ, দুইটাই কোরের।
 * ⓘ তাই হিসাবটা এখানে, আর কোরের ছোট কমান্ডটা কেবল এটাকে ডাকে — ঠিক যেভাবে
 * `abos:notices-due` নোটিশের সেবাকে ডাকে।
 */
final class PromotionExpiry
{
    /**
     * ⭐ যেগুলোর শেষ মুহূর্ত পেরিয়েছে, সেগুলো `EXPIRED`।
     *
     * ── ⚠️ শেষ **মুহূর্ত**, শেষ তারিখ নয় ─────────────────────────────
     * ⓘ *"৩০ জুন সন্ধ্যা ৬টায় শেষ"* অফার সন্ধ্যা ৬টার পরেই মেয়াদোত্তীর্ণ,
     * রাত ১২টায় নয়। ⛔ কেবল তারিখ দেখলে সন্ধ্যা ৬টা থেকে মধ্যরাত পর্যন্ত
     * তালিকা *"চলছে"* বলত, অথচ ইঞ্জিন ততক্ষণে থেমে গেছে — দুইটা পর্দা
     * দুই রকম কথা বলত।
     *
     * ⓘ থামানো অফারও (`PAUSED`) মেয়াদ পেরোলে `EXPIRED` — নাহলে একটা
     * থামানো অফার মেয়াদের পরে আবার চালু করা যেত।
     *
     * @return int কয়টা বদলানো হলো
     */
    public function expireLapsed(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $today = $now->toDateString();
        $clock = $now->format('H:i:s');

        $lapsed = Promotion::query()
            ->whereIn('status', [PromotionStatus::ACTIVE->value, PromotionStatus::PAUSED->value])
            ->where(fn ($q) => $q
                ->where('ends_on', '<', $today)
                ->orWhere(fn ($same) => $same
                    ->where('ends_on', $today)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', $clock)))
            ->get();

        foreach ($lapsed as $offer) {
            /*
             * ⓘ `canBecome()` দিয়ে — অবস্থার মানচিত্রের বাইরে কোনো পথ নয়,
             * এমনকি নির্ধারিত কাজের জন্যও। ⚠️ দুইটা অবস্থাই মানচিত্রে
             * `EXPIRED`-এ যেতে পারে, তাই এটা কখনো আটকায় না — কিন্তু কাল
             * কেউ মানচিত্র বদলালে এখানে নীরবে ভুল অবস্থা বসত না।
             */
            if (! $offer->status->canBecome(PromotionStatus::EXPIRED)) {
                continue;
            }

            $offer->status = PromotionStatus::EXPIRED;
            $offer->save();
        }

        return $lapsed->count();
    }
}
