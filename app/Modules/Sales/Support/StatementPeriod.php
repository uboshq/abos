<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ⭐ গ্রাহকের বিবরণীর সময় — আজ, এই মাস, চলতি অর্থবছর, বা নিজের বাছা দুই তারিখ (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ কিছু না বললে চলতি অর্থবছরের প্রথম দিন থেকে আজ — খতিয়ানের পুরনো নিয়মটাই ([[ThePortalLedgerStartsThisYearTest]]);
 * আগের সব লেনদেন "আগের জের" সারিতে এক অঙ্কে আসে।
 * ⓘ পর্দা আর PDF দুইটাই এখান থেকে সময় নেয় — যাতে PDF-এ ঠিক সেই সারিগুলোই থাকে যা পর্দায় দেখা গেল।
 */
final class StatementPeriod
{
    public const TODAY = 'today';

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const ALL = [self::TODAY, self::MONTH, self::YEAR, self::CUSTOM];

    private function __construct(
        public readonly string $period,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public static function of(Request $request, string $yearStart): self
    {
        $today = now()->toDateString();
        $asked = $request->query('period');

        return match ($asked) {
            self::TODAY => new self(self::TODAY, $today, $today),
            self::MONTH => new self(self::MONTH, now()->startOfMonth()->toDateString(), $today),
            self::YEAR => new self(self::YEAR, $yearStart, $today),
            default => self::custom($request, $yearStart, $today),
        };
    }

    /** ⓘ নিজের তারিখ — ভাঙা তারিখ চুপচাপ ডিফল্টে ফেরে, পাতা ৫০০ দেয় না; উল্টো সীমা (শেষ < শুরু) অদলবদল হয় */
    private static function custom(Request $request, string $yearStart, string $today): self
    {
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));

        if ($from === null && $to === null) {
            return new self(self::YEAR, $yearStart, $today);
        }

        $from ??= $yearStart;
        $to ??= $today;

        return $from <= $to ? new self(self::CUSTOM, $from, $to) : new self(self::CUSTOM, $to, $from);
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->toDateString() === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
