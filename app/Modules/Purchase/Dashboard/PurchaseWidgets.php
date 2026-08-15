<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;

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
            ),

            new Widget(
                group: 'todo',
                label: __('purchase::dashboard.pending_order_lines'),
                value: (string) self::reportRows('purchase.pending_orders'),
                href: route('purchase.report.show', ['slug' => 'pending-orders']),
                permission: 'purchase.report',
                tone: 'neutral',
                sort: 70,
            ),
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
}
