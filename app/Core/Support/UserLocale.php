<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\User;

/**
 * ⭐ একজন মানুষের ভাষা — ওয়েবের বদল ([[WorkspaceController::switchLocale()]]) আর ফোনের বদল
 * ([[MeLocaleController]]) একই নিয়মে: কেবল bn বা en, ব্যবহারকারীর রেকর্ডে (সেশনে নয় — অন্য ডিভাইসেও একই ভাষা,
 * নিয়ম ৯), আর নিরীক্ষার খাতায় একটা সারি (fe, ২১ অক্টোবর ২০২৬; ০.৪.৪৩ ভাষা বদল)।
 *
 * ⓘ `locale` [[User::auditIgnores()]]-এ — সাধারণ সম্পাদনার সারি এড়াতে; ভাষা বদল এখানে নিজের নামে
 * (`locale_changed`) একবারই লেখা হয়, পুরনো আর নতুন সহ।
 */
final class UserLocale
{
    public const SUPPORTED = ['bn', 'en'];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['locale' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED)]];
    }

    /** বৈধ ভাষাটা লেখা; আগের মতোই থাকলে কিছু নয় (খাতায় ফাঁকা সারি নয়) */
    public static function save(User $user, string $locale): void
    {
        $before = $user->locale;

        if ($before === $locale) {
            return;
        }

        $user->forceFill(['locale' => $locale])->save();

        app(AuditEngine::class)->record($user, 'locale_changed', ['locale' => [$before, $locale]]);
    }

    /**
     * `Accept-Language` থেকে আমাদের একটা ভাষা — প্রথম ট্যাগের মূল অংশ ("en-US" → en); অচেনা বা না-থাকা → অ্যাপের
     * ডিফল্ট (bn)।
     */
    public static function fromHeader(?string $header): string
    {
        $first = strtolower(trim(explode(',', (string) $header)[0]));
        $primary = explode('-', explode(';', $first)[0])[0];

        return in_array($primary, self::SUPPORTED, true) ? $primary : (string) config('app.locale', 'bn');
    }
}
