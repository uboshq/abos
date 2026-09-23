<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeAck;
use App\Models\NoticeRead;
use App\Models\NoticeReminder;

/**
 * সময় যা নিজে থেকে করে — প্রকাশ, মেয়াদ, আর তাগাদা।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৩১ ও ৩২ ───────────────
 * *"Publish Job · Expire Job · Reminder Job · Escalation Job"*, আর
 * *"System failure হলেও Notice হারানো যাবে না"* — retry, idempotency,
 * duplicate prevention।
 *
 * ── ⚠️ কেন প্রতিটা কাজ একাধিকবার চলার জন্য তৈরি ──────────────────────
 * ⓘ সময়ের কাজ দুইবার চলে, আর সেটা স্বাভাবিক: সার্ভার রিস্টার্ট, কিউ
 * পুনরায় চেষ্টা, কিংবা দুইজন একসাথে হাতে চালানো।
 *
 * ⛔ দ্বিতীয়বার চললে যদি দ্বিতীয় ইমেল যায় বা নোটিশটা দুইবার প্রকাশিত
 * হয়, তবে ভুলটা মানুষ দেখে — যন্ত্র নয়। ⚠️ তাই প্রতিটা কাজ **যা
 * ইতিমধ্যে হয়ে গেছে তা আবার করে না**, আর আটকানোটা ডাটাবেজের ইউনিক
 * সূচকে, কোডের শর্তে নয়: ⓘ শর্ত দুইজনের মাঝে হেরে যায়, সূচক হারে না।
 */
final class NoticeScheduler
{
    public function __construct(private readonly NoticeLifecycle $life) {}

    /**
     * ⭐ যেগুলোর সময় হয়ে গেছে — প্রকাশ করা।
     *
     * ⓘ `SCHEDULED` অবস্থার নোটিশ, যার শুরুর তারিখ আজ বা পেরিয়ে গেছে।
     * ⚠️ `APPROVED`-গুলো ছোঁয়া হয় না: ওগুলোর সময় বসানোই হয়নি, তাই
     * মানুষ নিজে প্রকাশ করবেন।
     *
     * @return int কয়টা প্রকাশ হলো
     */
    public function publishWhatIsDue(): int
    {
        $done = 0;

        Notice::query()
            ->where('status', NoticeStatus::SCHEDULED->value)
            ->whereNotNull('starts_on')
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($notices) use (&$done): void {
                foreach ($notices as $notice) {
                    /*
                     * ⚠️ প্রতিটা নোটিশ নিজের লেনদেনে, সবগুলো একসাথে নয়।
                     *
                     * ⛔ একটা লেনদেনে করলে একশোটার একটা ভাঙলে সবগুলোই
                     * উল্টে যেত — আর পরের বার আবার সবগুলো চেষ্টা হত।
                     * ⓘ একটা ভাঙলে বাকি নিরানব্বইটা প্রকাশিত থাকুক।
                     */
                    try {
                        $this->life->publish($notice);
                        $done++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $done;
    }

    /**
     * ⛔ যেগুলোর সময় ফুরিয়েছে — নামিয়ে দেওয়া।
     *
     * ── ⚠️ কেন `expires_at` আর `ends_on` দুইটাই দেখা হয় ─────────────
     * ⓘ `ends_on` মানুষের বসানো তারিখ, `expires_at` প্রকাশের সময়
     * হিসাব করে বসানো ক্ষণ। ⛔ কেবল একটা দেখলে অন্যটা দিয়ে লেখা
     * নোটিশ চিরকাল বারে বসে থাকত।
     */
    public function expireWhatIsOver(): int
    {
        $done = 0;

        Notice::query()
            ->where('status', NoticeStatus::PUBLISHED->value)
            ->where(function ($q): void {
                $q->where(fn ($w) => $w->whereNotNull('expires_at')->where('expires_at', '<', now()))
                    ->orWhere(fn ($w) => $w->whereNull('expires_at')
                        ->whereNotNull('ends_on')
                        ->whereDate('ends_on', '<', now()->toDateString()));
            })
            ->orderBy('id')
            ->chunkById(100, function ($notices) use (&$done): void {
                foreach ($notices as $notice) {
                    try {
                        $this->life->expire($notice);
                        $done++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $done;
    }

    /**
     * ⭐ যাঁরা এখনো সই দেননি তাঁদের তাগাদা।
     *
     * ── ⓘ মালিকের স্পেক, ধারা ১৯ ────────────────────────────────────
     * *"After 24 hours → Reminder · After 48 hours → Second Reminder ·
     * After 72 hours → Escalate to Manager"*, আর সবটাই সুইচে বসানো।
     *
     * ── ⚠️ কাকে তাগাদা দেওয়া হয়, আর কেন তালিকাটা ছোট ────────────────
     * ⓘ যাঁরা নোটিশটা **ছুঁয়েছেন** (পড়েছেন) অথচ সই দেননি। ⛔ লক্ষ্যের
     * ভিতরের প্রত্যেককে খুঁজতে গেলে হাজার ব্যবহারকারীর চাবি বানাতে হত,
     * আর এই কাজটা চলে প্রতি ঘণ্টায়।
     *
     * ⚠️ এর দাম আছে আর সেটা জেনে নেওয়া: যিনি নোটিশটা একবারও খোলেননি
     * তিনি তাগাদা পান না। ⓘ ওটা ধাপ ৭-এর চ্যানেলের কাজ — ইমেল সবার
     * কাছে যাবে, তাগাদা নয়।
     *
     * @return int কয়টা তাগাদা গেল
     */
    public function remindWhoHasNotSigned(): int
    {
        $settings = app(SettingsService::class);

        $hours = [
            1 => (int) $settings->get('notice.remind_after_hours', 24),
            2 => (int) $settings->get('notice.remind_again_hours', 48),
            3 => (int) $settings->get('notice.escalate_after_hours', 72),
        ];

        $sent = 0;

        Notice::query()
            ->where('status', NoticeStatus::PUBLISHED->value)
            ->where('ack_required', true)
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->chunkById(50, function ($notices) use (&$sent, $hours): void {
                foreach ($notices as $notice) {
                    $sent += $this->remindFor($notice, $hours);
                }
            });

        return $sent;
    }

    /**
     * একটা নোটিশের জন্য তাগাদা।
     *
     * @param  array<int, int>  $hours
     */
    private function remindFor(Notice $notice, array $hours): int
    {
        $age = $notice->published_at?->diffInHours(now()) ?? 0;

        /*
         * ⓘ সবচেয়ে বড় যে ধাপটার সময় হয়েছে, কেবল সেটাই।
         *
         * ⚠️ ছোট থেকে বড় দিকে গেলে একটা নোটিশ তিন দিন পুরনো হলে একই
         * দিনে তিনটা তাগাদা যেত — ⛔ আর মানুষ ভাবতেন যন্ত্রটা পাগল।
         */
        $round = 0;

        foreach ($hours as $step => $after) {
            if ($age >= $after) {
                $round = $step;
            }
        }

        if ($round === 0) {
            return 0;
        }

        $signed = NoticeAck::query()->where('notice_id', $notice->id)->pluck('user_id')->all();

        $owing = NoticeRead::query()
            ->where('notice_id', $notice->id)
            ->whereNotIn('user_id', $signed === [] ? [0] : $signed)
            ->pluck('user_id')
            ->all();

        $sent = 0;

        foreach ($owing as $userId) {
            /*
             * ⭐ দুইবার পাঠানো আটকায় ডাটাবেজ, এই শর্তটা নয়।
             *
             * ⚠️ `firstOrCreate` দুইজন একসাথে চালালে দুইটা সারি বসাতে
             * চাইত, আর ইউনিক সূচকটাই (`nrem_once`) দ্বিতীয়টাকে ফিরিয়ে
             * দেয়। ⓘ তাই ব্যতিক্রমটা ধরা হয় আর চুপচাপ পরেরজনে যাওয়া
             * হয় — ওটা ভুল নয়, ওটাই পাহারা কাজ করার চিহ্ন।
             */
            try {
                $row = NoticeReminder::query()->create([
                    'company_id' => $notice->company_id,
                    'notice_id' => $notice->id,
                    'user_id' => $userId,
                    'round' => $round,
                    'sent_at' => now(),
                    'escalated' => $round >= 3,
                ]);

                if ($row->exists) {
                    $sent++;
                }
            } catch (\Throwable) {
                // ⓘ আগেই পাঠানো হয়েছে — কিছু করার নেই
            }
        }

        return $sent;
    }

    /**
     * ⓘ তিনটা কাজ একসাথে — কমান্ড আর সময়সূচি দুইটাই এটাই ডাকে।
     *
     * ⚠️ ক্রমটা ইচ্ছাকৃত: আগে প্রকাশ, তারপর মেয়াদ, শেষে তাগাদা। ⛔
     * উল্টো করলে আজ প্রকাশ হওয়া নোটিশটা এই চক্রেই তাগাদা পেত না, আর
     * এক ঘণ্টা দেরি হত — ⓘ যা ভুল নয়, কেবল অপ্রয়োজনীয়।
     *
     * @return array{published: int, expired: int, reminded: int}
     */
    public function runEverything(): array
    {
        return [
            'published' => $this->publishWhatIsDue(),
            'expired' => $this->expireWhatIsOver(),
            'reminded' => $this->remindWhoHasNotSigned(),
        ];
    }
}
