<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\LoginAttempt;
use Illuminate\Support\Carbon;

/**
 * একই পরিচয়ে বারবার ভুল পাসওয়ার্ড — কিছুক্ষণের জন্য দরজা বন্ধ।
 *
 * ── কী ছিল না, ৩১ আগস্ট ২০২৬ ─────────────────────────────────────────
 * লগইনে পাহারা ছিল একটাই: `throttle:10,1`, অর্থাৎ **IP ধরে** মিনিটে
 * দশবার। ওটা একটা মেশিন থেকে চালানো আক্রমণ ধীর করে, কিন্তু আজকের
 * সাধারণ আক্রমণটা তা নয় — একটাই পাসওয়ার্ড-তালিকা বহু IP থেকে চালানো
 * হয়, আর তখন প্রতিটা IP মিনিটে দশবারের নিচেই থাকে।
 *
 * অর্থাৎ পাহারাটা ছিল, আর তবু একটা অ্যাকাউন্টে দিনে হাজার হাজার চেষ্টা
 * করা যেত। `routes/auth.php`-এর মন্তব্য দাবি করত তার উপরে আরেকটা স্তর
 * (ক্যাপচা) আছে — কোডবেসে ওই শব্দটার একটাও হদিস ছিল না।
 *
 * ── কেন নতুন কোনো টেবিল নয় ───────────────────────────────────────────
 * প্রতিটা চেষ্টা আগে থেকেই `login_history`-তে লেখা হয় ([[LoginJournal]])
 * — কে, কখন, সফল না ব্যর্থ, আর কেন ব্যর্থ। গোনার জন্য যা দরকার সবই
 * ওখানে আছে। দ্বিতীয় একটা কাউন্টার রাখলে একদিন দুইটা আলাদা উত্তর দিত,
 * আর কোনটা সত্যি তা বলা যেত না।
 *
 * ── কেন "শেষ সফল লগইনের পর থেকে" গোনা হয় ────────────────────────────
 * ঘড়ির জানালা ধরে গুনলে (শেষ ১৫ মিনিট) সফল লগইনের পরেও পুরনো
 * ব্যর্থতাগুলো গোনায় থাকত — কেউ তিনবার ভুল করে চতুর্থবারে ঢুকে বেরিয়ে
 * এসে আবার একবার ভুল করলেই তালা পড়ত। শেষ সফল লগইনটাই স্বাভাবিক
 * রিসেট: **তুমি যে তুমিই, সেটা প্রমাণ হয়ে গেছে।**
 *
 * ── কেন টাইপ করা পরিচয় ধরে, ব্যবহারকারীর আইডি ধরে নয় ────────────────
 * অস্তিত্বহীন নামেও তালা পড়ে, আর সেটাই দরকার: তালা কেবল আসল নামে
 * পড়লে "তালা পড়ল কি না" দেখেই আক্রমণকারী ব্যবহারকারীর তালিকা বের করে
 * ফেলত। নামটা তাই ছোট হরফে ও ছাঁটা অবস্থায় গোনা হয়, যাতে
 * `Owner`, `owner ` আর `owner` তিনটা আলাদা গোনা না হয়।
 *
 * (খাতায় নামটা যেমন টাইপ হয়েছিল তেমনই বসে, ছোট হরফে নয় — মিলটা হয়
 * কলামের collation-এ, `utf8mb4_unicode_ci`, যেখানে বড়-ছোট হরফ এক।
 * ওটা বদলে case-sensitive করলে এই তালাটা ফাঁকি দেওয়া যেত: একবার
 * `owner`, একবার `Owner` লিখলেই দুইটা আলাদা গোনা হত।)
 */
final class LoginLock
{
    /** কয়টা ব্যর্থতার পর তালা। */
    public const TRIES = 8;

    /** কত মিনিট বন্ধ থাকবে। */
    public const MINUTES = 15;

    /**
     * এখনো কত মিনিট বাকি — খোলা থাকলে null।
     *
     * মিনিট ফেরত দেওয়া হয় যাতে পর্দা বলতে পারে "আর কতক্ষণ"। শুধু
     * "বন্ধ" বললে ব্যবহারকারী প্রতি দশ সেকেন্ডে আবার চেষ্টা করতেন, আর
     * প্রতিটা চেষ্টা তালাটাকে আরও লম্বা করত।
     */
    public function locked(string $identifier): ?int
    {
        $identifier = $this->normalise($identifier);

        if ($identifier === '') {
            return null;
        }

        $since = $this->lastSuccessAt($identifier);

        $failures = LoginAttempt::query()
            ->where('identifier', $identifier)
            ->where('succeeded', false)

            /*
             * কেবল পাসওয়ার্ড-আন্দাজের চেষ্টাগুলো।
             *
             * `needs_code` মানে পাসওয়ার্ড ঠিক ছিল, শুধু দুই ধাপের কোডটা
             * তখনো দেওয়া হয়নি — আর সেটা লগইনের **স্বাভাবিক প্রথম ধাপ**,
             * প্রতিবারই ঘটে। ওটা গোনায় ধরলে দুই-ধাপের লগইন ব্যবহার করা
             * প্রতিটা মানুষ আট বার লগইন করার পর নিজেই তালাবদ্ধ হতেন।
             */
            ->whereIn('reason', [
                LoginAttempt::UNKNOWN,
                LoginAttempt::WRONG_PASSWORD,
                LoginAttempt::INACTIVE,
                LoginAttempt::WRONG_CODE,
            ])
            ->when($since, fn ($q, Carbon $at) => $q->where('created_at', '>', $at))
            ->orderByDesc('created_at')
            ->limit(self::TRIES)
            ->pluck('created_at');

        if ($failures->count() < self::TRIES) {
            return null;
        }

        /*
         * তালা খোলে **শেষ** ব্যর্থতার পর থেকে, প্রথমটার পর থেকে নয়।
         *
         * প্রথমটা ধরলে আক্রমণকারী পনেরো মিনিট চুপ করে থেকে আবার আটটা
         * চেষ্টা করতে পারত, আর তালাটা কার্যত হারে সীমা বসাত না।
         */
        $until = $failures->first()->copy()->addMinutes(self::MINUTES);

        if ($until->isPast()) {
            return null;
        }

        // শূন্য মিনিট বললে "তাহলে এখনই কেন হচ্ছে না" — তাই অন্তত এক
        return max(1, (int) ceil(now()->diffInSeconds($until) / 60));
    }

    /**
     * এই পরিচয়ে শেষ সফল লগইন কখন — কখনো না হলে null।
     */
    private function lastSuccessAt(string $identifier): ?Carbon
    {
        return LoginAttempt::query()
            ->where('identifier', $identifier)
            ->where('succeeded', true)
            ->latest('created_at')
            ->value('created_at');
    }

    private function normalise(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }
}
