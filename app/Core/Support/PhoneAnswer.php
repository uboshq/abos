<?php

declare(strict_types=1);

namespace App\Core\Support;

use Closure;

/**
 * ⭐ "এখন ফোনের উত্তর তৈরি হচ্ছে" — যে অংশ ফোনে কখনো যায় না (বেতন, চুক্তি §৪), তা সার্ভারেই বাদ পড়ার চিহ্ন।
 *
 * ⓘ মালিক, ১৭ অক্টোবর ২০২৬: HR-এর ড্যাশবোর্ড ফোনে দেখাবে, বেতনের অঙ্ক ঢেকে — "সার্ভারে পাঠানোর সময়ই বাদ, শুধু ফোনে লুকানো নয়"।
 * কোর মডিউলকে চেনে না, তাই কোর কেবল চিহ্নটা দেয় ([[DashboardApiController]]); কোন অংশ বাদ যাবে তা মডিউল নিজে জানে ([[HrDashboard]])।
 * ⓘ গভীরতা গোনা — চিহ্নের ভিতরে আবার চিহ্ন বসালেও বাইরেরটা শেষ হলে তবেই নামে; ছোড়া ভুলেও নামে (`finally`)।
 */
final class PhoneAnswer
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public static function during(Closure $build): mixed
    {
        self::$depth++;

        try {
            return $build();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
