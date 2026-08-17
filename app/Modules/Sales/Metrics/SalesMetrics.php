<?php

declare(strict_types=1);

namespace App\Modules\Sales\Metrics;

use App\Core\Contracts\ProvidesMetrics;
use App\Core\Metrics\Metric;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * বিক্রয়ের সংখ্যাগুলোর সংজ্ঞা — একটাই জায়গা।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই চার জায়গায় হিসাব হত: হোম পর্দা, কাউন্টার,
 * রিপোর্ট, শিফট। একবার তারা দুইটা আলাদা উত্তর দিয়েছিল — `todaysTotal()`
 * খসড়াও গুনত, তাই ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের ঘরে যোগ হয়ে
 * বসে থাকত, অথচ ড্রয়ারে আসেনি।
 *
 * ── "আজকের বিক্রয়" আসলে দুইটা প্রশ্ন ────────────────────────────────
 * `sales.today` — গোটা কোম্পানির আজ।
 * `sales.today_at_my_counter` — এই ক্যাশিয়ারের আজ, শিফট মেলানোর জন্য।
 *
 * এই দুইটা সংখ্যা প্রায়ই আলাদা, আর সেটাই ঠিক। আগে দুইটার কোথাও লেখা
 * ছিল না যে তারা আলাদা প্রশ্নের উত্তর — তাই না মিললে মনে হত কোথাও ভুল।
 * এখন দুইটাই নিজের সংজ্ঞা নিজে বলে।
 */
final class SalesMetrics implements ProvidesMetrics
{
    /** @return array<string, Metric> */
    public static function metrics(): array
    {
        $out = [];

        foreach ([
            self::salesToday(),
            self::salesThisMonth(),
            self::collectedToday(),
            self::collectedThisMonth(),
            self::salesThisYear(),
            self::collectedThisYear(),
            self::salesTodayAtMyCounter(),
        ] as $metric) {
            $out[$metric->key] = $metric;
        }

        return $out;
    }

    /** কোম্পানির আজকের বিক্রয়। */
    public static function salesToday(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.today',
            label: __('sales::dashboard.sales_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::invoiceTotal($today, $today),
        );
    }

    /** চলতি মাসের বিক্রয়, মাসের ১ তারিখ থেকে আজ পর্যন্ত। */
    public static function salesThisMonth(): Metric
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.month',
            label: __('sales::dashboard.sales_this_month'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::invoiceTotal($from, $to),
        );
    }

    /** আজ যত টাকা আদায় হয়েছে। */
    public static function collectedToday(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.collected_today',
            label: __('sales::dashboard.collected_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($today, $today),
        );
    }

    /** চলতি মাসের আদায়। */
    public static function collectedThisMonth(): Metric
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.collected_month',
            label: __('sales::dashboard.collected_this_month'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($from, $to),
        );
    }

    /**
     * চলতি অর্থবছরের বিক্রয়।
     *
     * ── কেন ক্যালেন্ডার বছর নয় ──────────────────────────────────────
     * ব্যবসার বছর জুলাই থেকে জুন। জানুয়ারি ধরে গুনলে সংখ্যাটা হিসাবের
     * খাতার সাথে মিলত না, আর মালিক দুইটা "এই বছর" নিয়ে বসতেন — একটা
     * পর্দায়, একটা হিসাবরক্ষকের কাছে।
     */
    public static function salesThisYear(): Metric
    {
        [$from, $to] = self::financialYear();

        return new Metric(
            key: 'sales.year',
            label: __('sales::dashboard.sales_this_year'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.invoice.view',
            value: fn () => self::invoiceTotal($from, $to),
        );
    }

    /** চলতি অর্থবছরের আদায়। */
    public static function collectedThisYear(): Metric
    {
        [$from, $to] = self::financialYear();

        return new Metric(
            key: 'sales.collected_year',
            label: __('sales::dashboard.collected_this_year'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.collection.view',
            value: fn () => self::collectionTotal($from, $to),
        );
    }

    /**
     * চলতি অর্থবছরের দুই প্রান্ত।
     *
     * বছরটা কোম্পানির নিজের সারি থেকেই আসে — কোথাও জুলাই-জুন লেখা
     * হয় না। কোনো বছর খোলা না থাকলে চলতি মাসটাই ধরা হয়, কারণ শূন্য
     * দেখানোর চেয়ে কম দেখানো ভালো — আর নতুন কোম্পানিতে সেটাই সত্যি।
     *
     * @return array{0: string, 1: string}
     */
    private static function financialYear(): array
    {
        $year = auth()->user()?->currentCompany?->currentFinancialYear();

        return $year === null
            ? [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()]
            : [$year->starts_on->toDateString(), Carbon::today()->toDateString()];
    }

    /**
     * এই ক্যাশিয়ারের আজ — কাউন্টারের পর্দার উপরে।
     *
     * ── কেন এটা `sales.today` নয় ────────────────────────────────────
     * ক্যাশিয়ার দিনশেষে নিজের ড্রয়ার মেলান, দোকানের মোট বিক্রয় নয়।
     * দুইজন ক্যাশিয়ার থাকলে সংখ্যা দুইটা আলাদা হবেই — আর সেটা ভুল নয়,
     * ভিন্ন প্রশ্ন। একই নামে রাখলে কেউ একদিন বলত "হোম পর্দায় এক, এখানে
     * আরেক", আর কোনটা ঠিক তা বলার কিছু থাকত না।
     */
    public static function salesTodayAtMyCounter(): Metric
    {
        $today = Carbon::today()->toDateString();

        return new Metric(
            key: 'sales.today_at_my_counter',
            label: __('sales::message.pos_today'),
            statuses: DocumentStatus::POSTED,
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.pos',
            value: fn () => Money::of(SalesInvoice::query()
                ->posted()
                ->whereDate('trx_date', $today)
                ->where('created_by', auth()->id())
                ->sum('total')),
        );
    }

    private static function invoiceTotal(string $from, string $to): string
    {
        return Money::of(SalesInvoice::query()
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->sum('total'));
    }

    private static function collectionTotal(string $from, string $to): string
    {
        return Money::of(Collection::query()
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->sum('amount'));
    }
}
