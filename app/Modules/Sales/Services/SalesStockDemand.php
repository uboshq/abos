<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\StockDemand;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Carbon;

/**
 * ⭐ বিক্রয়ের দিক থেকে মালের চাহিদা — মজুদের ড্যাশবোর্ডের "মজুদ শেষ" ঘর ([[StockDemand]])।
 *
 * ⓘ বাকি = আদেশ − "আর দেওয়া হবে না" − চালানে যা গেছে (বাতিল চালান বাদ) — [[SalesOrderLine::pendingQty()]]-এর একই সংজ্ঞা,
 * এক প্রশ্নে সব লাইন (withSum), তাই লাইন ধরে আলাদা প্রশ্ন নয়।
 * ⓘ আদেশের মডেলের দেয়াল (কোম্পানি, শাখা) `whereHas`-এর ভিতরে নিজে চলে।
 */
final class SalesStockDemand implements StockDemand
{
    public function openByProduct(): array
    {
        $out = [];

        SalesOrderLine::query()
            ->whereHas('order', fn ($q) => $q->open())
            ->withSum(['challanLines as delivered_sum' => fn ($q) => $q->whereHas('challan', fn ($c) => $c->where('status', '<>', DocumentStatus::CANCELLED))], 'delivered_qty')
            ->get(['id', 'sales_order_id', 'product_id', 'ordered_qty', 'rejected_qty', 'rate'])
            ->each(function (SalesOrderLine $line) use (&$out): void {
                $left = bcsub(bcsub((string) $line->ordered_qty, (string) ($line->rejected_qty ?? '0'), 4), (string) ($line->getAttribute('delivered_sum') ?? '0'), 4);

                if (bccomp($left, '0', 4) <= 0) {
                    return;
                }

                $id = (int) $line->product_id;
                $out[$id] ??= ['qty' => '0', 'value' => '0', 'lines' => 0];
                $out[$id]['qty'] = bcadd($out[$id]['qty'], $left, 4);
                $out[$id]['value'] = bcadd($out[$id]['value'], bcmul($left, (string) $line->rate, 4), 4);
                $out[$id]['lines']++;
            });

        return $out;
    }

    public function lostByProduct(string $from): array
    {
        $out = [];

        SalesOrderLine::query()
            ->whereHas('order', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->where('rejected_qty', '>', 0)
            ->where('updated_at', '>=', Carbon::parse($from)->startOfDay())
            ->get(['id', 'sales_order_id', 'product_id', 'rejected_qty', 'rate'])
            ->each(function (SalesOrderLine $line) use (&$out): void {
                $id = (int) $line->product_id;
                $out[$id] = bcadd($out[$id] ?? '0', bcmul((string) $line->rejected_qty, (string) $line->rate, 4), 4);
            });

        return $out;
    }

    public function fillRate(string $from, string $to): ?string
    {
        $s = app(DeliveryPerformance::class)->summary($from, $to);

        return (int) $s['due'] === 0 ? null : bcdiv(bcmul((string) $s['in_full'], '100', 4), (string) $s['due'], 0);
    }

    public function grossProfit(string $from, string $to): string
    {
        return (string) (SalesInvoice::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(subtotal - COALESCE(discount, 0) - COALESCE(cost_of_goods, 0)), 0) as gp')
            ->value('gp') ?? '0');
    }
}
