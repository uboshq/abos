<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\User;
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
        if (! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $code)) {
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

        if ($user->mfa_secret !== null && Totp::verify($user->mfa_secret, $code)) {
            return true;
        }

        return $this->useRecoveryCode($user, $code);
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
