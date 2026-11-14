<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ এক পাতায় কয়টা — ২৫, ৫০, ১০০ বা ২০০ (মালিক, ১৩ নভেম্বর ২০২৬: "এক পেজে কতগুলো থাকে তার কোনো বক্স নাই"; SAP Fiori আর
 *   D365-এর তালিকার নিচের "প্রতি পাতায়")।
 *
 * ⓘ ক্রম: ঠিকানার `per_page` (তালিকার বাছাই) → এই মানুষের শেষ বাছাই (সেশন, সব তালিকায় এক) → তালিকার নিজের সংখ্যা।
 * ⛔ তালিকার বাইরে কিছু নয় — `per_page=100000` লিখে গোটা খাতা এক পাতায় টেনে আনা যায় না (মেমরি, ৫০০)।
 */
final class PerPage
{
    /** @var list<int> */
    public const CHOICES = [25, 50, 100, 200];

    public const DEFAULT = 50;

    public const SESSION_KEY = 'list.per_page';

    public static function of(int $default = self::DEFAULT): int
    {
        $asked = request()->query('per_page');

        if (is_numeric($asked) && in_array((int) $asked, self::CHOICES, true)) {
            if (request()->hasSession()) {
                request()->session()->put(self::SESSION_KEY, (int) $asked);
            }

            return (int) $asked;
        }

        $kept = request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null;

        return is_int($kept) && in_array($kept, self::CHOICES, true) ? $kept : $default;
    }
}
