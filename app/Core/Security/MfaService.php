<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * দুই ধাপের লগইন — চালু করা, যাচাই করা, পুনরুদ্ধার করা।
 *
 * ── কেন চালু করাটা দুই ধাপে ─────────────────────────────────────────
 * চাবি বসানোর সাথে সাথে চালু করলে যে অ্যাপে ভুল করে চাবিটা বসেনি,
 * তিনি পরের লগইনেই বাইরে — আর ঢুকে সেটা ঠিক করার কোনো পথ নেই। তাই
 * প্রথমে চাবি বসে, তারপর একটা কোড মিলিয়ে দেখা হয়, তবেই চালু।
 */
class MfaService
{
    /** কয়টা পুনরুদ্ধার কোড — আটটা, কারণ একটা করে খরচ হয়। */
    private const RECOVERY_CODES = 8;

    /**
     * চালু করার প্রস্তুতি — চাবি বসে, কিন্তু MFA এখনো বন্ধ।
     *
     * চাবিটা ফেরত দেওয়া হয় কারণ পর্দায় সেটাই দেখাতে হবে; এর পরে
     * আর কোথাও দেখানো হয় না।
     */
    public function begin(User $user): string
    {
        $secret = Totp::newSecret();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        return $secret;
    }

    /**
     * প্রথম কোডটা মিলল — এখন সত্যিই চালু, আর পুনরুদ্ধার কোড তৈরি।
     *
     * ── কোডগুলো একবারই দেখা যায় ─────────────────────────────────────
     * ফেরত দেওয়া তালিকাটাই একমাত্র সুযোগ — ডাটাবেজে কেবল হ্যাশ বসে।
     * সাদা রাখলে ডাটাবেজ ফাঁসে MFA অর্থহীন হত, কারণ একটা কোড দিয়েই
     * পেরিয়ে যাওয়া যায়।
     *
     * @return list<string>|null মিলে গেলে কোডগুলো, নাহলে null
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->mfa_secret) {
            return null;
        }

        /*
         * ⭐ চালুর কোডটাও খরচ হয় — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ চালুর মুহূর্তে কোডটা পর্দায় টাইপ হয়, প্রায়ই অন্যের সামনে।
         * খরচ না হলে ঐ একই ছয় অঙ্ক পরের দেড় মিনিট লগইনের দরজাও খুলত।
         */
        $step = Totp::matchingStep($user->mfa_secret, $code);

        if ($step === null || ! $this->spend($user, $step)) {
            return null;
        }

        $codes = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            /*
             * দশ অক্ষর, মাঝে একটা হাইফেন — হাতে লিখে রাখার জন্য।
             *
             * মানুষ এগুলো কাগজে লেখেন বা ছবি তোলেন। দীর্ঘ হলে ভুল
             * লেখা হত, আর ভুল লেখা পুনরুদ্ধার কোড মানে কোনো কোড নেই।
             */
            $plain = strtoupper(Str::random(5).'-'.Str::random(5));

            $codes[] = $plain;
            $hashed[] = Hash::make($plain);
        }

        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $hashed,
        ])->save();

        return $codes;
    }

    /** এই ব্যবহারকারীর MFA সত্যিই চালু কি না। */
    public function isOn(User $user): bool
    {
        return $user->mfa_secret !== null && $user->mfa_confirmed_at !== null;
    }

    /**
     * লগইনের সময় কোডটা যাচাই — অ্যাপের কোড বা পুনরুদ্ধার কোড।
     *
     * ── কেন দুইটাই একই ঘরে ──────────────────────────────────────────
     * ফোন হারানো মানুষটা তখন হন্যে হয়ে খুঁজছেন কোথায় পুনরুদ্ধার কোড
     * দিতে হবে। আলাদা একটা লিংক দিলে সেটা খুঁজে না পেয়ে তিনি ফোন
     * করতেন। দৈর্ঘ্য দেখেই বোঝা যায় কোনটা কী, তাই একটাই ঘর যথেষ্ট।
     */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);

        $step = $user->mfa_secret !== null ? Totp::matchingStep($user->mfa_secret, $code) : null;

        if ($step !== null) {
            /*
             * ⛔ মিলল, কিন্তু আগে চলেছে কি না — সেটাই শেষ কথা।
             *
             * ⓘ খরচ হওয়া অ্যাপ-কোড পুনরুদ্ধার কোডের দিকে গড়িয়ে যায় না:
             * ছয় অঙ্ক কোনোদিন একটা পুনরুদ্ধার কোড নয়, আর গড়ালে প্রতিটা
             * পুনরাবৃত্তি আটটা bcrypt খরচ করাত।
             */
            return $this->spend($user, $step);
        }

        return $this->useRecoveryCode($user, $code);
    }

    /**
     * একটা ধাপের কোড খরচ করা — একজনের, একবারই।
     *
     * ── ⛔ কী ভাঙা ছিল, নিরীক্ষা ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────
     * ঘড়ির ছাড়ের জন্য একটা কোড প্রায় দেড় মিনিট "ঠিক" থাকে
     * ([[Totp::LIFETIME_SECONDS]]), আর ঐ পুরো সময়টায় সেটা যতবার খুশি
     * চলত। ⚠️ কাঁধের উপর দিয়ে দেখা, বা একটা নকল লগইন-পাতায় টাইপ করা
     * কোড দিয়ে তাই আসল মানুষটার পাশাপাশি দ্বিতীয় কেউও ঢুকতে পারতেন —
     * RFC 6238 §5.2 ঠিক এটাই নিষেধ করে।
     *
     * ── দুইটা দেয়াল, দুইটা আলাদা কাজ ─────────────────────────────────
     * **এক — শেষ গৃহীত ধাপের চেয়ে পুরনো বা সমান কিছু নয়।** নতুন একটা
     * কোড দিয়ে কেউ ঢুকে গেলে আগের ধাপের অব্যবহৃত কোডটাও তখনো "ঠিক",
     * কিন্তু সেটা এখন কেবল অন্য কারো হাতে থাকতে পারে।
     *
     * **দুই — `Cache::add`, যেটা "নেই তো বসাও" একটাই পারমাণবিক ধাপে
     * করে।** ⚠️ একই কোড নিয়ে দুইটা অনুরোধ একই মুহূর্তে এলে প্রথম
     * দেয়ালটা দুইজনকেই "আগে চলেনি" বলত; `add` কেবল একজনকে জেতায়।
     *
     * ── ⓘ কেন ক্যাশ, `users`-এর একটা ঘর নয় ─────────────────────────────
     * `users`-এ এমন কোনো ঘর নেই, আর চিহ্নটার আয়ু নব্বই সেকেন্ড — এর
     * পরে কোডটা এমনিতেই মেলে না। ⓘ Laravel-এর নিজের Fortify ঠিক এভাবেই
     * করে, আর এই অ্যাপের লগইন-throttle-ও একই ক্যাশে গোনে। ⚠️ দাম একটাই:
     * দেড় মিনিটের মধ্যে কেউ `cache:clear` চালালে সেই জানালায় একটা কোড
     * আবার চলতে পারে — টেকসই ঘরটা (`users.mfa_last_step`) প্রতিবেদনে।
     */
    private function spend(User $user, int $step): bool
    {
        $last = "mfa:totp:last:{$user->getKey()}";
        $spent = Cache::get($last);

        if ($spent !== null && $step <= (int) $spent) {
            return false;
        }

        if (! Cache::add("mfa:totp:{$user->getKey()}:{$step}", true, Totp::LIFETIME_SECONDS)) {
            return false;
        }

        Cache::put($last, max($step, (int) Cache::get($last, $step)), Totp::LIFETIME_SECONDS);

        return true;
    }

    /**
     * একটা পুনরুদ্ধার কোড খরচ করা।
     *
     * ── কেন খরচ হয়ে যায় ────────────────────────────────────────────
     * একই কোড বারবার চললে সেটা আর দ্বিতীয় ধাপ নয়, দ্বিতীয় একটা
     * পাসওয়ার্ড — আর ওটা কাগজে লেখা থাকে।
     */
    private function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (! Hash::check($code, $hash)) {
                continue;
            }

            unset($codes[$i]);

            $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

            return true;
        }

        return false;
    }

    /** আর কয়টা পুনরুদ্ধার কোড বাকি — ফুরিয়ে গেলে জানানো দরকার। */
    public function recoveryCodesLeft(User $user): int
    {
        return count($user->mfa_recovery_codes ?? []);
    }

    /**
     * বন্ধ করা — চাবি, তারিখ, কোড সব মুছে যায়।
     *
     * অর্ধেক মুছলে পরে "চালু আছে কি নেই" প্রশ্নের দুইটা উত্তর থাকত।
     */
    public function turnOff(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();
    }
}
