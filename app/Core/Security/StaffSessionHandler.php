<?php

declare(strict_types=1);

namespace App\Core\Security;

use Illuminate\Auth\SessionGuard;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * `sessions` টেবিলের `user_id` — কেবল কর্মীর (`users`) id, কখনো গ্রাহকের নয়।
 *
 * ── ⛔ যা ভাঙা ছিল (মালিকের সিদ্ধান্ত ১৬, ১৭ অক্টোবর ২০২৬) ─────────────
 * লাইভ `SESSION_DRIVER=database`-এ যাচ্ছে। ফ্রেমওয়ার্কের হ্যান্ডলার `user_id`-এ বসায় **ডিফল্ট গার্ডের** id,
 * আর `auth:portal` ডিফল্ট গার্ডকে পোর্টাল বানায় — তাই ডিলারের পোর্টাল-সেশনে বসত **গ্রাহকের** id। ⚠️ ঐ সংখ্যার
 * কর্মী তখন "আমার সেশন" পাতায় ডিলারের যন্ত্র আর আইপি দেখতেন, আর "অন্য সব জায়গা থেকে বের করুন" চাপলে
 * ডিলারকে বের করে দিতেন।
 *
 * ⭐ তাই প্রশ্নটা সবসময় কর্মীর গার্ডকে (`web`): এই অনুরোধে কর্মী বসানো থাকলে তাঁর id, নইলে সেশনে রাখা কর্মীর
 * চাবি (একই ব্রাউজারে কর্মী আর পোর্টাল দুইটাই খোলা থাকলে) — ⓘ গার্ডকে নতুন করে লগইন করানো হয় না
 * (`user()` ডাকলে "মনে রাখুন" কুকি দিয়ে লগইন ঘটে যেত), কেবল পড়া।
 */
final class StaffSessionHandler extends DatabaseSessionHandler
{
    protected function userId()
    {
        $guard = $this->container->make('auth')->guard('web');

        if ($guard->hasUser()) {
            return $guard->id();
        }

        if (! $guard instanceof SessionGuard) {
            return null;
        }

        $id = $guard->getSession()->get($guard->getName());

        return is_numeric($id) ? (int) $id : null;
    }
}
