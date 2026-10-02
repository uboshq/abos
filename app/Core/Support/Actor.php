<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\User;

/**
 * কে করছেন — কেবল কর্মী (`users`) হলে তাঁর id, নাহলে null (২ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন `auth()->id()` নয় ──────────────────────────────────────────
 * ডিলার পোর্টালে (`auth:portal`) ঢোকা মানুষটা একজন **গ্রাহক** — `auth()->id()` তখন গ্রাহকের id দেয়।
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
}
