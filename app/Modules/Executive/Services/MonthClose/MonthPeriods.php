<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services\MonthClose;

use App\Models\FinancialYear;
use Carbon\CarbonImmutable;

/**
 * ⭐ মাস শেষের হিসাবের পাঁচ সময় — এই মাস, আগের মাস, গত বছরের একই মাস, বাজেট (এই মাসেরই), আর অর্থবছরের শুরু থেকে এই মাস (মালিক,
 * ১৭ নভেম্বর ২০২৬: নমুনা "Ok", তারপর "Sob ok")।
 *
 * ⛔ "আগের মাস" কখনো `subMonth()` নয় — ৩১ তারিখে সেটা উপচে এই মাসেই ফেরে (৩১ অক্টোবর − ১ মাস = "৩১ সেপ্টেম্বর" = ১ অক্টোবর;
 * [[last-month-on-the-31st-is-this-month]], ৩১ অক্টোবর ২০২৬ লাইভে দুইটা লাল)। ⓘ তাই প্রতিটা ধাপ `…NoOverflow()`, আর মাস সবসময়
 * মাসের ১ তারিখ থেকে গোনা।
 *
 * ⓘ ঠিকানায় মাস না থাকলে আগের মাস — মাস বন্ধ হয় মাস ফুরোলে, তাই ১৮ নভেম্বরে খোলা কাগজটা অক্টোবরের।
 */
final class MonthPeriods
{
    public readonly CarbonImmutable $month;

    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    private function __construct(CarbonImmutable $month)
    {
        $this->month = $month->startOfMonth()->startOfDay();
        $this->from = $this->month;
        $this->to = $this->month->endOfMonth()->startOfDay();
    }

    /** ঠিকানার `?month=YYYY-MM`; ভুল বা খালি হলে আগের মাস, ভবিষ্যতের মাস হলে চলতি মাস */
    public static function fromQuery(?string $raw): self
    {
        $today = CarbonImmutable::today();

        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000) {
            $month = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

            return new self($month->greaterThan($today->startOfMonth()) ? $today->startOfMonth() : $month);
        }

        return new self($today->subMonthNoOverflow()->startOfMonth());
    }

    public static function of(CarbonImmutable $month): self
    {
        return new self($month);
    }

    /** আগের মাস — ১ তারিখ থেকে, তাই কখনো উপচায় না */
    public function prior(): self
    {
        return new self($this->month->subMonthNoOverflow());
    }

    /** গত বছরের একই মাস */
    public function lastYear(): self
    {
        return new self($this->month->subYearNoOverflow());
    }

    /**
     * অর্থবছরের শুরু — কোম্পানির নিজের বছরের সারি থেকে; সারি না থাকলে বাংলাদেশের জুলাই–জুন ([[SalesMetrics::financialYear()]]-এর একই বছর,
     * কেবল আজকের নয়, এই মাসের)।
     */
    public function yearStart(): CarbonImmutable
    {
        $row = FinancialYear::forDate($this->to->toDateString());

        if ($row !== null && $row->starts_on !== null) {
            return CarbonImmutable::parse($row->starts_on)->startOfDay();
        }

        return CarbonImmutable::create($this->month->month >= 7 ? $this->month->year : $this->month->year - 1, 7, 1);
    }

    /** এই মাসের কর্মদিবস — শুক্রবার বাদে (সাপ্তাহিক ছুটি) */
    public function workingDays(): int
    {
        $days = 0;

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $days += $day->isFriday() ? 0 : 1;
        }

        return $days;
    }

    /** পরের মাসের একটা দিন — মূসক-৯.১ আর উৎসে করের জমা ১৫ তারিখে, বেতন ৭ তারিখে */
    public function nextMonthDay(int $day): CarbonImmutable
    {
        return $this->month->addMonthNoOverflow()->setDay($day);
    }

    public function key(): string
    {
        return $this->month->format('Y-m');
    }
}
