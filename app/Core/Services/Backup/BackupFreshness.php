<?php

declare(strict_types=1);

namespace App\Core\Services\Backup;

use App\Core\Services\BackupService;
use Illuminate\Support\Carbon;

/**
 * শেষ সফল ব্যাকআপ কত পুরনো — আর সেটা কি চিৎকার করার মতো পুরনো।
 *
 * ── ⛔ কেন এই পাহারাটা সবচেয়ে দরকারি, ১৫ সেপ্টেম্বর ২০২৬ ─────────────
 * লাইভে ব্যাকআপ **ছয় দিন ধরে ব্যর্থ হচ্ছিল**, আর কেউ জানত না। ⓘ
 * ব্যর্থতার কারণটা (`proc_open` বন্ধ) কোনো লগে ছিল না।
 *
 * ⚠️ শিডিউলার সৎ ছিল — সে exit code দেখে `FAIL` লিখত। ⛔ কিন্তু লিখত
 * এমন এক ফাইলে যা কেউ খোলে না, আর না-পড়া সতর্কবার্তা আর না-থাকা
 * সতর্কবার্তার মধ্যে কোনো তফাত নেই।
 *
 * ⚠️ মূল রোগটা `proc_open` নয় — সেটা একটা পরিবেশের সীমা, আর সারানো
 * গেছে ([[PdoDumper]])। আসল রোগ: **কেউ জানত না**। ছয় দিন ধরে
 * প্রতিষ্ঠানের পুরো হিসাব ফেরানোর কোনো উপায় ছিল না, আর পর্দায় সব সবুজ।
 *
 * ── ⭐ তাই এই ক্লাসটা "ব্যাকআপ নেওয়া হয়েছে" জিজ্ঞেস করে না ───────────
 * সে জিজ্ঞেস করে **"শেষ কবে একটা সত্যিকারের ফাইল তৈরি হয়েছিল"**।
 *
 * ⛔ পার্থক্যটা মূলে: কমান্ড সফল বলল কি না সেটা একটা দাবি; ফাইলটা আছে
 * কি না সেটা একটা ঘটনা। আজকের পুরো শিক্ষা এই এক লাইনে — দাবির উপর
 * পাহারা বসালে দাবিটাই পাহারা দেয় নিজেকে।
 *
 * ⓘ তবু ফাইলের **অস্তিত্বই** যথেষ্ট নয়, আর সেটাও আজ শেখা: একটা ফাইল
 * থাকতে পারে, আকার ঘোষণা করতে পারে, আর ভিতরে এক বাইটও না থাকতে পারে।
 * তাই আকারটাও দেখা হয়।
 */
final class BackupFreshness
{
    /**
     * কত ঘণ্টা পেরোলে চিৎকার।
     *
     * ⓘ ৪৮, ২৪ নয় — একটা রাত বাদ পড়া (সার্ভার রিবুট, ডিস্ক ভরা) স্বাভাবিক
     * আর নিজে থেকে সেরে যায়। দুই রাত বাদ পড়া মানে কিছু একটা ভাঙা।
     *
     * ⚠️ খুব ছোট করলে মানুষ সতর্কবার্তা উপেক্ষা করতে শেখে, আর তখন
     * পাহারাটা থাকা না থাকা সমান।
     */
    public const STALE_AFTER_HOURS = 48;

    /**
     * ⓘ এর চেয়ে ছোট ফাইল ব্যাকআপ নয় — ১৬২ টেবিলের একটা gzip ডাম্প
     * কখনোই এত ছোট হতে পারে না। ⚠️ সংখ্যাটা উদার রাখা: উদ্দেশ্য "খালি
     * বা কাটা পড়া" ধরা, আকার নিয়ে বিচার করা নয়।
     */
    private const AT_LEAST_BYTES = 1024;

    public function __construct(private readonly BackupService $backups) {}

    /**
     * শেষ কাজের ব্যাকআপের সময় — একটাও না থাকলে `null`।
     */
    public function lastUsableAt(): ?Carbon
    {
        foreach (array_reverse($this->backups->all()) as $file) {
            if (is_file($file) && filesize($file) >= self::AT_LEAST_BYTES) {
                return Carbon::createFromTimestamp(filemtime($file), config('app.timezone'));
            }
        }

        return null;
    }

    public function hoursOld(?Carbon $now = null): ?float
    {
        $last = $this->lastUsableAt();

        return $last === null ? null : $last->diffInRealHours($now ?? Carbon::now(), false) * -1;
    }

    public function isStale(?Carbon $now = null): bool
    {
        $last = $this->lastUsableAt();

        if ($last === null) {
            return true;
        }

        return $last->lt(($now ?? Carbon::now())->copy()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * কী বলতে হবে — না বলার মতো কিছু থাকলে `null`।
     */
    public function complaint(?Carbon $now = null): ?string
    {
        if (! $this->isStale($now)) {
            return null;
        }

        $last = $this->lastUsableAt();

        if ($last === null) {
            return __('backup::error.never');
        }

        return __('backup::error.stale', [
            'hours' => (string) (int) abs($last->diffInRealHours($now ?? Carbon::now())),
            'limit' => (string) self::STALE_AFTER_HOURS,
        ]);
    }
}
