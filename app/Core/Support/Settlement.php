<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Services\SettingsService;

/**
 * ⭐ আদায় আর পরিশোধে কম-বেশি মিলিয়ে ফেলার নিয়ম — এক জায়গায়, মডিউল-নিরপেক্ষ (cb-র প্রস্তাব, a6, ২২ অক্টোবর ২০২৬)।
 *
 * বিক্রির আদায় ([[CollectionService]]) আর ক্রয়ের পরিশোধ (cb, money-form C) দুজনেই এটা ডাকে, যাতে ক্রয়কে বিক্রির সার্ভিস
 * ডাকতে না হয় (BoundariesTest), আর দুই দিক কখনো আলাদা কথা না বলে।
 *
 * নিয়ম (মালিক ১–২ অক্টোবর ২০২৬; fe ২২ অক্টোবর ২০২৬):
 *  - ছাড় — যেকোনো অঙ্কে মালিকের সই;
 *  - রাউন্ডিং — ৳০.৫০ পর্যন্ত সই ছাড়া (খাত 5305); তার বেশি, সীমা পর্যন্ত মালিকের সই; সীমার বেশি ফেরত।
 *
 * ⛔ এই সীমা বিলের মোট গোল করার সীমা (`sales.rounding_max`) নয় — দুটো আলাদা জিনিস, আলাদা সেটিং (fe, ২২ অক্টোবর ২০২৬;
 * SAP-এর payment difference tolerance বনাম বিলের রাউন্ডিং)।
 */
final class Settlement
{
    /** ⓘ সই ছাড়া রাউন্ডিং — ৳০.৫০ পর্যন্ত (মালিক, ১ অক্টোবর ২০২৬: "রাউন্ডিং 0.5 mane 50 poisa porzonto accept") */
    public const UNSIGNED = '0.50';

    /** ⓘ সীমার ডিফল্ট, টাকায় — নকশা-১০০-এ মালিকের অনুমোদিত ৳১০ */
    public const LIMIT = '10';

    /** ⓘ কোম্পানির নিজের সীমার সেটিং — নাম পুরনো (আদায় দিয়ে শুরু), কাজ আদায় আর পরিশোধ দুটোরই */
    public const LIMIT_KEY = 'sales.collection.rounding_limit';

    /**
     * ⭐ পার্থক্যে মালিকের সই লাগবে কি না — ছাড় হলে সবসময়; রাউন্ডিং হলে কেবল ৳০.৫০-এর বেশি হলে।
     * ⓘ সীমার উপরের রাউন্ডিং এখানে পৌঁছায় না — ডাকার জায়গা আগেই ফেরত দেয় ([[limit()]])।
     */
    public static function differenceNeedsOwner(?string $kind, string $amount): bool
    {
        if (bccomp($amount, '0', 4) <= 0) {
            return false;
        }

        return match ($kind) {
            'discount' => true,
            'rounding' => bccomp($amount, self::UNSIGNED, 4) > 0,
            default => false,
        };
    }

    /** ⓘ কোম্পানির সীমা, চার দশমিকে — খালি, অসংখ্যা বা ঋণাত্মক হলে ডিফল্ট ৳১০ */
    public static function limit(SettingsService $settings): string
    {
        $raw = trim((string) $settings->get(self::LIMIT_KEY, self::LIMIT));

        return is_numeric($raw) && bccomp($raw, '0', 4) >= 0 ? bcadd($raw, '0', 4) : bcadd(self::LIMIT, '0', 4);
    }
}
