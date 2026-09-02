<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ক্রয়ের সংখ্যাগুলো হোম পর্দায়।
 *
 * বিক্রয়ের আয়নার মতো, আর ইচ্ছাকৃতভাবে কম: মালিক দিনে একবার দেখেন
 * "আজ কত কিনলাম", আর "কোন মাল এসেছে অথচ বিল আসেনি"। দ্বিতীয়টা টাকার
 * প্রশ্ন — বিল না এলে দায়টা খাতায় ওঠে না, অথচ মালটা গুদামে।
 */
final class PurchaseWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();

        return [
            new Widget(
                group: 'today',
                label: __('purchase::dashboard.purchases_today'),
                value: Money::format(self::billTotal($today, $today), 2),
                href: route('purchase.bill.index', ['from' => $today, 'to' => $today]),
                permission: 'purchase.bill.view',
                tone: 'money',
                sort: 50,
            ),

            new Widget(
                group: 'month',
                label: __('purchase::dashboard.purchases_this_month'),
                value: Money::format(self::billTotal($monthStart, $today), 2),
                href: route('purchase.bill.index', ['from' => $monthStart, 'to' => $today]),
                permission: 'purchase.bill.view',
                tone: 'money',
                sort: 50,
            ),

            new Widget(
                group: 'todo',
                label: __('purchase::dashboard.unbilled_lines'),
                value: (string) self::reportRows('purchase.uninvoiced'),
                href: route('purchase.report.show', ['slug' => 'uninvoiced']),
                permission: 'purchase.report',
                tone: 'warn',
                sort: 60,
                icon: 'challan',
            ),

            new Widget(
                group: 'todo',
                label: __('purchase::dashboard.pending_order_lines'),
                value: (string) self::reportRows('purchase.pending_orders'),
                href: route('purchase.report.show', ['slug' => 'pending-orders']),
                permission: 'purchase.report',
                tone: 'neutral',
                sort: 70,
                icon: 'clock',
            ),

            /*
             * মার্জিনটা শূন্য হলে ঘরটাই আসে না, তাই `null` ছাঁটা হয়।
             */
            ...array_filter([self::marginThisMonth()]),
        ];
    }

    private static function billTotal(string $from, string $to): string
    {
        return Money::of(PurchaseBill::query()
            ->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->sum('total'));
    }

    /** সংখ্যাটা রিপোর্ট থেকেই — ক্লিক করে যা খোলে, ঠিক তাই। */
    private static function reportRows(string $key): int
    {
        return app(ReportEngine::class)->run($key, perPage: 1)->totalRows;
    }

    /**
     * এই মাসে কোম্পানির মাল বেচে কত মার্জিন দাঁড়াল।
     *
     * ── কেন খতিয়ান নয়, বিলের অঙ্ক ───────────────────────────────────
     * মার্জিন = বিক্রয় − বিক্রীত পণ্যের ব্যয়, আর দুইটাই বিলের গায়ে
     * বসানো (`total`, `cost_of_goods`)। খতিয়ান থেকে বের করতে গেলে
     * আয় ও ব্যয়ের খাত ধরে গুনতে হত, আর তাতে বিক্রয় ছাড়া অন্য আয়ও
     * ঢুকে পড়ত — যেমন বাতিল হওয়া বিলের উল্টো এন্ট্রি।
     */
    private static function marginThisMonth(): ?Widget
    {
        $row = DB::table('sal_invoices')
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', ['confirmed', 'closed'])
            ->whereBetween('trx_date', [
                Carbon::today()->startOfMonth()->toDateString(),
                Carbon::today()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('COALESCE(SUM(total), 0) as sold, COALESCE(SUM(cost_of_goods), 0) as cost')
            ->first();

        $sold = (string) ($row->sold ?? '0');
        $cost = (string) ($row->cost ?? '0');

        if (bccomp($sold, '0', 4) === 0) {
            return null;
        }

        $margin = bcsub($sold, $cost, 4);

        /*
         * শতাংশটা ক্রয়মূল্যের উপর, বিক্রয়ের উপর নয়।
         *
         * কোম্পানি "৪%" বলতে ক্রয়মূল্যের উপর ৪% যোগ বোঝায় (১৭২.৫৪ →
         * ১৭৯.৪৪)। বিক্রয়ের উপর গুনলে ওটাই ৩.৮৫% দেখাত, আর মাস শেষে
         * ডিপো ভাবত কোম্পানি কম দিয়েছে। একই অঙ্ক, দুই রকম পড়া — আর
         * তর্কটা ঠিক ওখানেই বাধে।
         */
        $percent = bccomp($cost, '0', 4) > 0
            ? Money::round(bcmul(bcdiv($margin, $cost, 6), '100', 6), 2)
            : null;

        return new Widget(
            group: 'month',
            label: __('purchase::widget.margin_this_month'),
            value: Money::format($margin),
            href: route('purchase.report.show', ['slug' => 'settlement']),
            permission: 'purchase.settlement.view',
            tone: 'money',
            hint: $percent === null ? null : __('purchase::widget.margin_hint', ['percent' => $percent]),
            sort: 40,
            icon: 'scale',
            parts: [
                __('supplier::field.sold') => Money::format($sold),
                __('supplier::field.cost_of_sold') => Money::format($cost),
            ],
        );
    }
}
