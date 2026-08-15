<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Metrics\Metric;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * বিক্রয়ের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন ডকুমেন্ট থেকে গোনা, খতিয়ান থেকে নয় ──────────────────────────
 * "আজ কত বিক্রি হলো" প্রশ্নটার উত্তর দোকানদার বিলের খাতা গুনে দেন, আর
 * ক্লিক করে তিনি সেই বিলগুলোই দেখতে চান। খতিয়ানের আয় হিসাব থেকে গুনলে
 * সংখ্যাটা একই হত (দুটোই একই লেনদেন থেকে), কিন্তু ক্লিক করলে নামতে হত
 * খতিয়ানের সারিতে — যেখানে বিলের নম্বর আছে, গ্রাহকের নাম নেই।
 *
 * ব্যালেন্স (বকেয়া, প্রদেয়) উল্টো — ওগুলো হিসাবের প্রশ্ন, আর ওগুলো
 * খতিয়ান থেকেই আসে (Accounts মডিউলের উইজেট)।
 */
final class SalesWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();

        return [
            self::money(SalesMetrics::salesToday(), 'today', 10,
                route('sales.invoice.index', ['from' => $today, 'to' => $today])),

            self::money(SalesMetrics::collectedToday(), 'today', 20,
                route('sales.collection.index', ['from' => $today, 'to' => $today])),

            self::money(SalesMetrics::salesThisMonth(), 'month', 10,
                route('sales.invoice.index', ['from' => $monthStart, 'to' => $today])),

            self::money(SalesMetrics::collectedThisMonth(), 'month', 20,
                route('sales.collection.index', ['from' => $monthStart, 'to' => $today])),

            /*
             * খসড়া বিল — কেউ শুরু করে শেষ করেনি।
             *
             * খসড়া বিল কোনো হিসাবে নেই: মাল হয়তো চলে গেছে, টাকা পাওনা
             * দেখাচ্ছে না। এটাই সেই ধরনের কাজ যা কেউ ইচ্ছাকৃতভাবে ফেলে
             * রাখে না, শুধু ভুলে যায় — আর মাস শেষে হিসাব মেলে না।
             */
            new Widget(
                group: 'todo',
                label: __('sales::dashboard.draft_invoices'),
                value: (string) SalesInvoice::query()->where('status', DocumentStatus::DRAFT)->count(),
                href: route('sales.invoice.index', ['sort' => 'oldest']),
                permission: 'sales.invoice.view',
                tone: 'warn',
                sort: 10,
            ),

            new Widget(
                group: 'todo',
                label: __('sales::dashboard.uninvoiced_lines'),
                value: (string) self::reportRows('sales.uninvoiced'),
                href: route('sales.report.show', ['slug' => 'uninvoiced']),
                permission: 'sales.report',
                tone: 'warn',
                sort: 20,
            ),

            new Widget(
                group: 'todo',
                label: __('sales::dashboard.pending_order_lines'),
                value: (string) self::reportRows('sales.pending_orders'),
                href: route('sales.report.show', ['slug' => 'pending-orders']),
                permission: 'sales.report',
                tone: 'neutral',
                sort: 30,
            ),
        ];
    }

    /**
     * একটা ঘোষিত সংখ্যাকে হোম পর্দার একটা ঘরে বসানো।
     *
     * ── কেন উইজেট নিজে গোনে না ──────────────────────────────────────
     * আগে গুনত, আর কাউন্টারও আলাদা করে গুনত — একবার তারা দুইটা আলাদা
     * উত্তর দিয়েছিল। এখন প্রশ্নটার উত্তর এক জায়গা থেকেই আসে, আর
     * সংজ্ঞাটাও সঙ্গে আসে, তাই ঘরটার উপর মাউস রাখলে দেখা যায় কী গোনা
     * হয়েছে — যে সংখ্যার সংজ্ঞা লুকানো, দুইজন মানুষ তার দুই অর্থ করে।
     */
    private static function money(Metric $metric, string $group, int $sort, string $href): Widget
    {
        return new Widget(
            group: $group,
            label: $metric->label,
            value: Money::format($metric->value(), $metric->scale),
            href: $href,
            permission: $metric->permission,
            tone: 'money',
            sort: $sort,
            hint: $metric->definition(),
        );
    }

    /**
     * "কী বাকি" — সংখ্যাটা রিপোর্ট থেকেই, নিজের কোয়েরি থেকে নয়।
     *
     * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────────
     * এখানে নিজে করে গুনলে দুইটা সংখ্যা তৈরি হত: হোম পর্দায় একটা, আর
     * ক্লিক করে যে রিপোর্ট খোলে তাতে আরেকটা। "বিল বাকি ৭" দেখে ক্লিক
     * করে পাঁচটা সারি পেলে ব্যবহারকারী দুইটার কোনটাই আর বিশ্বাস করেন
     * না — আর তিনি ঠিকই করেন, কারণ একটা তো ভুল।
     *
     * রিপোর্ট ইঞ্জিন যে ছাঁকনি ছাড়া চালালে চলতি মাস ধরে, পর্দাটাও তাই
     * ধরে — তাই সংখ্যাটা আর তালিকাটা একই কথা বলে।
     */
    private static function reportRows(string $key): int
    {
        return app(ReportEngine::class)->run($key, perPage: 1)->totalRows;
    }
}
