<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Models\Collection;
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
            new Widget(
                group: 'today',
                label: __('sales::dashboard.sales_today'),
                value: Money::format(self::invoiceTotal($today, $today), 2),
                href: route('sales.invoice.index', ['from' => $today, 'to' => $today]),
                permission: 'sales.invoice.view',
                tone: 'money',
                sort: 10,
            ),

            new Widget(
                group: 'today',
                label: __('sales::dashboard.collected_today'),
                value: Money::format(self::collectionTotal($today, $today), 2),
                href: route('sales.collection.index', ['from' => $today, 'to' => $today]),
                permission: 'sales.collection.view',
                tone: 'money',
                sort: 20,
            ),

            new Widget(
                group: 'month',
                label: __('sales::dashboard.sales_this_month'),
                value: Money::format(self::invoiceTotal($monthStart, $today), 2),
                href: route('sales.invoice.index', ['from' => $monthStart, 'to' => $today]),
                permission: 'sales.invoice.view',
                tone: 'money',
                sort: 10,
            ),

            new Widget(
                group: 'month',
                label: __('sales::dashboard.collected_this_month'),
                value: Money::format(self::collectionTotal($monthStart, $today), 2),
                href: route('sales.collection.index', ['from' => $monthStart, 'to' => $today]),
                permission: 'sales.collection.view',
                tone: 'money',
                sort: 20,
            ),

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
     * বাতিল বাদে বিলের মোট।
     *
     * খসড়াও বাদ: খসড়া বিল এখনো বিক্রয় নয়, আর ওটা যোগ করলে হোম পর্দার
     * সংখ্যা আর বিক্রয় খাতার সংখ্যা আলাদা হয়ে যেত।
     */
    private static function invoiceTotal(string $from, string $to): string
    {
        return Money::of(SalesInvoice::query()
            ->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED])
            ->whereBetween('trx_date', [$from, $to])
            ->sum('total'));
    }

    private static function collectionTotal(string $from, string $to): string
    {
        return Money::of(Collection::query()
            ->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED])
            ->whereBetween('trx_date', [$from, $to])
            ->sum('amount'));
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
