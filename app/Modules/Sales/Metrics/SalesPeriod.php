<?php

declare(strict_types=1);

namespace App\Modules\Sales\Metrics;

use Illuminate\Support\Carbon;

/**
 * বিক্রয়ের পর্দার সময়কাল — আজ, এই মাস, এই বছর, নয়তো হাতে বাছা দুই তারিখ।
 *
 * ── কেন "বছর" এখানে আলাদা করে গোনা হয় না ─────────────────────────────
 * বছরের দুই প্রান্ত [[SalesMetrics::financialYear()]] থেকে — জুলাই-জুন,
 * কোম্পানির নিজের সারি থেকে। ⛔ এখানে আবার লিখলে হোম পর্দার "এই বছরের
 * বিক্রয়" আর এই পর্দার "বছর" দুই দিন থেকে গুনত, আর দুই অঙ্ক বলত।
 *
 * ── ⛔ ভুল তারিখ চুপ করে ঠিক হয় না ──────────────────────────────────
 * উল্টো পরিসর (শেষ তারিখ শুরুর আগে), আজে-বাজে লেখা, বা এক বছরের বেশি —
 * তিনটাতেই পর্দা এই মাসে ফেরে **আর সেটা বলে** (`$refused`)। ⚠️ চুপ করে
 * দুই তারিখ অদলবদল করলে মানুষ ভাবতেন তিনি যা বেছেছেন তাই দেখছেন।
 */
final class SalesPeriod
{
    public const TODAY = 'today';

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const CUSTOM = 'custom';

    public const KINDS = [self::TODAY, self::MONTH, self::YEAR, self::CUSTOM];

    /**
     * হাতে বাছা পরিসরের সীমা — ৩৬৬ দিন।
     *
     * ⓘ এর বেশি হলে দৈনিক ধারা আর র‍্যাঙ্কিং দুইটাই কয়েক বছরের সারি
     * ঘাঁটত, আর পর্দাটা একজনের এক ক্লিকে ডাটাবেস আটকে রাখত।
     */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $kind,
        public readonly string $from,
        public readonly string $to,
        public readonly bool $refused = false,
    ) {}

    /**
     * অনুরোধ থেকে সময়কাল — না বললে "আজ", হোম পর্দার মতোই।
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $kind = is_string($query['period'] ?? null) && in_array($query['period'], self::KINDS, true)
            ? $query['period']
            : self::TODAY;

        if ($kind !== self::CUSTOM) {
            return self::preset($kind);
        }

        $from = self::date($query['from'] ?? null);
        $to = self::date($query['to'] ?? null);

        if ($from === null || $to === null || $to->lessThan($from)
            || $from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $month = self::preset(self::MONTH);

            return new self(self::MONTH, $month->from, $month->to, refused: true);
        }

        return new self(self::CUSTOM, $from->toDateString(), $to->toDateString());
    }

    public static function preset(string $kind): self
    {
        $today = Carbon::today()->toDateString();

        return match ($kind) {
            self::YEAR => new self(self::YEAR, SalesMetrics::financialYear()[0], $today),
            self::MONTH => new self(self::MONTH, Carbon::today()->startOfMonth()->toDateString(), $today),
            default => new self(self::TODAY, $today, $today),
        };
    }

    /** শেষ তারিখটা Carbon হিসেবে — ধারার জানালা এখান থেকে পেছনে গোনা হয়। */
    public function end(): Carbon
    {
        return Carbon::parse($this->to);
    }

    private static function date(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $raw));

        return checkdate($m, $d, $y) ? Carbon::createFromDate($y, $m, $d)->startOfDay() : null;
    }
}
