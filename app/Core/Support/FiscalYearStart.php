<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\FinancialYear;
use Illuminate\Support\Carbon;

/**
 * কোনো দিনের অর্থবছর কবে শুরু — কোম্পানির নিজের বছরের সারি থেকে, না থাকলে ১ জুলাই (বাংলাদেশের অর্থবছর)।
 *
 * <p>⭐ এক জায়গায় — আগে দুই রকম ছিল: হিসাবের [[MonthlyCashReport::yearStart()]] সারি না পেলে ১ জুলাই, বিক্রয়ের মাসওয়ারি পাতা সারি না
 * পেলে "এই মাস"। রিপোর্টের ডিফল্ট সময়কাল ([[ReportDefinition::$defaultPeriod]]) আর হিসাবের বছর এখন একই উত্তর পড়ে।
 */
final class FiscalYearStart
{
    public static function on(Carbon|string|null $date = null): string
    {
        $day = $date === null ? Carbon::today() : Carbon::parse($date);

        // ⓘ কোম্পানির দেয়াল মডেলের নিজের ([[BelongsToCompany]]); নামেও বলা — প্রসঙ্গ ছাড়া ডাকলে অন্যের বছর নয়
        $start = FinancialYear::query()->where('company_id', CompanyContext::id())
            ->whereDate('starts_on', '<=', $day->toDateString())->whereDate('ends_on', '>=', $day->toDateString())
            ->value('starts_on');

        if ($start !== null) {
            return Carbon::parse((string) $start)->toDateString();
        }

        return Carbon::create($day->month >= 7 ? $day->year : $day->year - 1, 7, 1)->toDateString();
    }
}
