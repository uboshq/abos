<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ⭐ সময়ের ধারা — দিন, সপ্তাহ, মাস, ত্রৈমাসিক, বছর ধরে একটা সংখ্যা (মালিকের কেন্দ্র, ৮ অক্টোবর ২০২৬)।
 *
 * ── ⓘ কেন এটা আলাদা একটা ক্লাস, দ্বিতীয় রিপোর্ট ইঞ্জিন নয় ────────────────
 * রিপোর্টের "তুলনা" ([[ReportEngine::comparisonPeriod()]]) একটা পরিসরকে **আগের একই সময়** বা **গত বছরের
 * একই সময়ের** সাথে মেলায়। ধারা সেই একই প্রশ্নকে কয়েকবার করে — পরিসরটাকে খোপে খোপে ভাগ করে, প্রতিটা খোপে।
 * ⓘ তাই আগের-সময়ের নিয়মটা এখন এখানে ([[previous()]]), আর রিপোর্টের তুলনাও এটাই ডাকে — একটা নিয়ম,
 * দুই জায়গায় দুই রকম হওয়ার উপায় নেই।
 *
 * ⛔ সংখ্যাটা এই ক্লাস গোনে না: প্রতিটা খোপে ডাকা হয় মডিউলের **নিজের** সংজ্ঞা (`$figure($from, $to)`) —
 * এখানে কোনো SUM নেই, তাই একটা পর্দার ধারা আর মডিউলের নিজের সংখ্যা কখনো আলাদা হয় না।
 *
 * ⓘ কোরে আছে কারণ প্রশ্নটা কোনো মডিউলের নয় — কোনো মডিউলের নামও এখানে নেই (§১৯.৭)।
 */
final class Trend
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const YEARLY = 'yearly';

    /** @var list<string> */
    public const GRAINS = [self::DAILY, self::WEEKLY, self::MONTHLY, self::QUARTERLY, self::YEARLY];

    /**
     * ⭐ আগের মাসের একই দিনগুলো — "এ মাস বনাম আগের মাস" (মালিকের কেন্দ্রের তুলনা, ৯ অক্টোবর ২০২৬)।
     *
     * ⓘ রিপোর্টের "আগের সময়" ঠিক ততদিন আগে — ১–৯ অক্টোবরের আগে ২২–৩০ সেপ্টেম্বর। মালিক মাস ধরে
     * ভাবেন: ১–৯ অক্টোবর বনাম ১–৯ সেপ্টেম্বর। ⚠️ দুইটা আলাদা প্রশ্ন, তাই আলাদা নাম — রিপোর্টের
     * নিয়ম বদলায় না।
     */
    public const PREVIOUS_MONTH = 'previous_month';

    /** ⚠️ সপ্তাহ শুরু শনিবারে — এখানকার অফিসের সপ্তাহ, শুক্রবার ছুটি ([[PromotionCalendar::WEEK_STARTS]]-এর একই নিয়ম) */
    public const WEEK_STARTS = Carbon::SATURDAY;

    /**
     * ⓘ একটা ধারায় সবচেয়ে বেশি কয়টা খোপ — বছরের দিন ধরে চাইলেও ৩৬৬-এর বেশি নয়।
     *
     * ⛔ সীমা না থাকলে দশ বছরের দৈনিক ধারা চাইলে প্রতিটা খোপে একটা কোয়েরি, অর্থাৎ হাজার হাজার — পাতাটা
     * আটকে থাকত, আর শেয়ার্ড হোস্ট প্রক্রিয়াটাই মেরে ফেলত।
     */
    public const MOST_BUCKETS = 366;

    /**
     * পরিসরটা খোপে ভাগ — প্রতিটা খোপ পরিসরের ভিতরে কাটা (প্রথম আর শেষ খোপ আধা হতে পারে)।
     *
     * ⓘ খোপ কাটা হয় যাতে যোগফল মেলে: প্রতিটা খোপের যোগ = গোটা পরিসরের সংখ্যা। ⛔ প্রথম মাসটা পুরো
     * নিলে ১০ তারিখ থেকে চাওয়া ধারার প্রথম দণ্ডে ১–৯ তারিখের বিক্রিও ঢুকত।
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public static function buckets(string $grain, string $from, string $to): array
    {
        if (! in_array($grain, self::GRAINS, true)) {
            throw new InvalidArgumentException("Unknown trend grain '{$grain}'.");
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

        $out = [];
        $cursor = self::startOf($grain, $start->copy());

        while ($cursor->lte($end) && count($out) < self::MOST_BUCKETS) {
            $next = self::next($grain, $cursor->copy());
            $bucketFrom = $cursor->lt($start) ? $start->copy() : $cursor->copy();
            $bucketTo = $next->copy()->subDay();
            $bucketTo = $bucketTo->gt($end) ? $end->copy() : $bucketTo;

            $out[] = [
                'key' => $cursor->toDateString(),
                'label' => self::label($grain, $cursor),
                'from' => $bucketFrom->toDateString(),
                'to' => $bucketTo->toDateString(),
            ];

            $cursor = $next;
        }

        return $out;
    }

    /**
     * ধারা — প্রতিটা খোপে মডিউলের নিজের সংখ্যা।
     *
     * @param  callable(string, string): string  $figure  খোপের প্রথম ও শেষ দিন দিলে সংখ্যাটা (bcmath স্ট্রিং)
     * @return list<array{key: string, label: string, from: string, to: string, value: string}>
     */
    public static function series(string $grain, string $from, string $to, callable $figure): array
    {
        return array_map(
            fn (array $bucket): array => [...$bucket, 'value' => (string) $figure($bucket['from'], $bucket['to'])],
            self::buckets($grain, $from, $to),
        );
    }

    /**
     * কোন সময়ের সাথে তুলনা — রিপোর্টের "তুলনা"-র একমাত্র নিয়ম।
     *
     * ⓘ দুইটা প্রশ্ন, আর তারা আলাদা: আগের একই সময় বলে **গতি** — বাড়ছে না কমছে; গত বছরের একই সময় বলে
     * **মৌসুম বাদে** কেমন — রোজার মাসের বিক্রি আগের মাসের চেয়ে সবসময়ই বেশি, তাই ঐ তুলনা কিছু বলে না।
     *
     * ⚠️ "ঠিক ততদিন আগে", ক্যালেন্ডারের মাস নয়: ১–১০ তারিখের পরিসর গোটা আগের মাসের সাথে মেলালে দশ দিনের
     * সাথে ত্রিশ দিনের তুলনা হত, আর সংখ্যাটা সবসময় ভয়ংকর কমে যাওয়া দেখাত।
     *
     * @return array{from: string, to: string}|null
     */
    public static function previous(string $mode, string $from, string $to): ?array
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        return match ($mode) {
            ReportEngine::COMPARE_PREVIOUS => [
                'from' => $start->copy()->subDays((int) $start->diffInDays($end) + 1)->toDateString(),
                'to' => $start->copy()->subDay()->toDateString(),
            ],
            ReportEngine::COMPARE_LAST_YEAR => [
                'from' => $start->copy()->subYear()->toDateString(),
                'to' => $end->copy()->subYear()->toDateString(),
            ],
            // ⚠️ মাসের শেষ পেরোয় না: ৩১ মার্চ → ২৮/২৯ ফেব্রুয়ারি, ১ মার্চ নয়
            self::PREVIOUS_MONTH => [
                'from' => $start->copy()->subMonthNoOverflow()->toDateString(),
                'to' => $end->copy()->subMonthNoOverflow()->toDateString(),
            ],
            default => null,
        };
    }

    /**
     * পরিবর্তনের শতাংশ — আগেরটা শূন্য হলে `null`।
     *
     * ⓘ শূন্য থেকে কিছু হলে শতাংশ অসীম; "নতুন" আর "১০০% বেড়েছে" এক কথা নয়, আর দ্বিতীয়টা মিথ্যা
     * ([[ReportEngine::addComparison()]]-এর একই নিয়ম)।
     */
    public static function change(string $now, string $was): ?string
    {
        if (bccomp($was, '0', 4) === 0) {
            return null;
        }

        return bcdiv(bcmul(bcsub($now, $was, 4), '100', 6), self::abs($was), 2);
    }

    private static function abs(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }

    private static function startOf(string $grain, Carbon $day): Carbon
    {
        return match ($grain) {
            self::DAILY => $day,
            self::WEEKLY => $day->startOfWeek(self::WEEK_STARTS)->startOfDay(),
            self::MONTHLY => $day->startOfMonth(),
            self::QUARTERLY => $day->firstOfQuarter(),
            self::YEARLY => $day->startOfYear(),
        };
    }

    private static function next(string $grain, Carbon $start): Carbon
    {
        return match ($grain) {
            self::DAILY => $start->addDay(),
            self::WEEKLY => $start->addWeek(),
            self::MONTHLY => $start->addMonthNoOverflow(),
            self::QUARTERLY => $start->addMonthsNoOverflow(3),
            self::YEARLY => $start->addYear(),
        };
    }

    private static function label(string $grain, Carbon $start): string
    {
        return match ($grain) {
            self::DAILY, self::WEEKLY => $start->translatedFormat('j M'),
            self::MONTHLY => $start->translatedFormat('M y'),
            self::QUARTERLY => __('core.trend.quarter', ['q' => (int) ceil($start->month / 3), 'year' => $start->year]),
            self::YEARLY => (string) $start->year,
        };
    }
}
