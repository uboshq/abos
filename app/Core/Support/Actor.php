<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\User;
use App\Modules\Customer\Models\Customer;

/**
 * কে করছেন — কেবল কর্মী (`users`) হলে তাঁর id, নাহলে null (২ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন `auth()->id()` নয় ──────────────────────────────────────────
 * গ্রাহক পোর্টালে (`auth:portal`) ঢোকা মানুষটা একজন **গ্রাহক** — `auth()->id()` তখন গ্রাহকের id দেয়।
 * সেটা `audit_trails.user_id` বা `attachments.uploaded_by`-তে বসলে: ⚠️ একই নম্বরের কর্মী থাকলে
 * নিরীক্ষায় দোকানির কাজ সেই কর্মীর নামে চড়ত (গ্রাহক ১ → ব্যবহারকারী ১ = মালিক), আর না থাকলে
 * foreign key ভেঙে পুরো অনুরোধ ৫০০। ধরা পড়ে স্লিপসহ দাবির পরীক্ষায় ([[TheDepositRequestCameWithItsSlipTest]])।
 *
 * ⓘ ফোনের Sanctum টোকেনও `User` — সেখানে আগের মতোই id আসে।
 */
final class Actor
{
    public static function userId(): ?int
    {
        $who = auth()->user();

        return $who instanceof User ? (int) $who->getKey() : null;
    }

    /**
     * ⭐ পোর্টালের গ্রাহক — কর্তা কর্মী না হয়ে `auth:portal`-এর গ্রাহক হলে তাঁর id, নাহলে null (৩ অক্টোবর ২০২৬)।
     * ⓘ পোর্টালের গ্রাহক নিজের নামে সই চাইতে পারেন ([[ApprovalEngine::request()]]); কোর মডিউলের নাম জানে না, কেবল গার্ড।
     */
    public static function portalCustomerId(): ?int
    {
        if (auth()->user() instanceof User) {
            return null;
        }

        $id = auth('portal')->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * ⭐ কাগজের নিচে "কে ছাপল" — কর্মী হলে তাঁর নাম, পোর্টালের গ্রাহক হলে গ্রাহকের নাম (গ্রাহক পোর্টাল, ধাপ ১)।
     *
     * ⛔ আগে ছাপার পাতা সরাসরি `auth()->user()->name` পড়ত। পোর্টালের দরজায় `auth()` গ্রাহককে দেয়, আর গ্রাহকের
     * `name` একটা পদ্ধতি — ফলে গ্রাহক নিজের বিলের PDF চাইলেই ৫০০।
     */
    public static function displayName(): ?string
    {
        $who = auth()->user();

        if ($who instanceof User) {
            return (string) $who->name;
        }

        return $who instanceof Customer ? $who->name() : null;
    }
}
