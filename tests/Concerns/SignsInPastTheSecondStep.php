<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Security\Totp;
use App\Models\User;

/**
 * লগইনের দ্বিতীয় ধাপের কোড — যাঁর দুই ধাপ চালু, কেবল তাঁর জন্য।
 *
 * ── কেন লাগল, ২৯ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * acda6265 (২৮ সেপ্টেম্বর) সুপার অ্যাডমিনের দুই ধাপ বাধ্যতামূলক করেছে, আর
 * DemoSeeder ডেমো মালিকের TOTP চালু রাখে। ⚠️ যে পরীক্ষাগুলো মালিককে কেবল
 * পাসওয়ার্ডে ঢোকাত, সেগুলোর লগইন তখন থেকে ফেরত আসে ("কোডটা দিন") — আর
 * লাল পড়ে পরের পর্দায়, যেন দোষটা পর্দার।
 *
 * ⭐ কোড পাঠানো হয় আসল পথেই — পাহারা পাশ কাটিয়ে নয়। দুই ধাপ বন্ধ থাকলে
 * `null`, তাই যে কারও লগইনে এটা বসানো নিরাপদ।
 *
 * ⚠️ একটা কোড একবারই চলে ([[TheSameCodeOpenedTheDoorTwiceTest]]) — একই
 * পরীক্ষায় দ্বিতীয়বার ঢুকতে `$step = 1` দিন (পরের ৩০ সেকেন্ডের কোড, যা
 * যাচাই এখনো মেনে নেয়)।
 */
trait SignsInPastTheSecondStep
{
    protected function secondStepCode(string $identifier, int $step = 0): ?string
    {
        $user = User::query()->where('email', $identifier)->first();

        if ($user === null || $user->mfa_confirmed_at === null || blank($user->mfa_secret)) {
            return null;
        }

        return Totp::codeFor((string) $user->mfa_secret, time() + 30 * $step);
    }
}
