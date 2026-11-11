<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\StockSupply;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ক্রয়ের দিক থেকে মালের যোগান — মজুদের ড্যাশবোর্ড ([[StockSupply]])।
 *
 * ⓘ সংজ্ঞা ক্রয়ের নিজের রিপোর্টের হুবহু: আসা বাকি = আদেশ − (গ্রহণ + আদেশের ওপর সরাসরি বিল), বাতিল বাদ
 * ([[PurchaseReports::pendingOrders()]]); বিলহীন = গ্রহণ − বসানো বিল ([[PurchaseReports::uninvoiced()]])।
 * ⓘ কোম্পানি আর শাখার দেয়াল মাথার মডেলে (Eloquent) চলে, তারপর কেবল সেই আইডিগুলোর লাইন।
 */
final class PurchaseStockSupply implements StockSupply
{
    public function onOrderByProduct(): array
    {
        $orders = PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)->pluck('expected_on', 'id');

        if ($orders->isEmpty()) {
            return [];
        }

        $cancelled = DocumentStatus::CANCELLED;
        $received = "((select COALESCE(SUM(rl2.received_qty), 0) from pur_receipt_lines rl2
                join pur_receipts r2 on r2.id = rl2.purchase_receipt_id
                where rl2.purchase_order_line_id = ol.id and r2.status <> '{$cancelled}')
            + (select COALESCE(SUM(bl2.qty), 0) from pur_bill_lines bl2
                join pur_bills b2 on b2.id = bl2.purchase_bill_id
                where bl2.purchase_order_line_id = ol.id and b2.status <> '{$cancelled}'))";

        $out = [];

        DB::table('pur_order_lines as ol')
            ->whereIn('ol.purchase_order_id', $orders->keys()->all())
            ->whereRaw("ol.ordered_qty > {$received}")
            ->select(['ol.product_id', 'ol.purchase_order_id', DB::raw("ol.ordered_qty - {$received} as left_qty")])
            ->get()
            ->each(function ($row) use (&$out, $orders): void {
                $id = (int) $row->product_id;
                $expected = $orders[(int) $row->purchase_order_id] ?? null;
                $expected = $expected === null ? null : substr((string) $expected, 0, 10);
                $out[$id] ??= ['qty' => '0', 'expected_on' => null];
                $out[$id]['qty'] = bcadd($out[$id]['qty'], (string) $row->left_qty, 4);

                if ($expected !== null && ($out[$id]['expected_on'] === null || $expected < $out[$id]['expected_on'])) {
                    $out[$id]['expected_on'] = $expected;
                }
            });

        return $out;
    }

    public function unbilledReceipts(): array
    {
        $receipts = PurchaseReceipt::query()->where('status', DocumentStatus::CONFIRMED)->pluck('supplier_id', 'id');

        if ($receipts->isEmpty()) {
            return ['receipts' => 0, 'value' => '0', 'supplier' => ''];
        }

        $posted = "'".implode("','", DocumentStatus::POSTED)."'";
        $billed = "(select COALESCE(SUM(bl.qty), 0) from pur_bill_lines bl
                join pur_bills b on b.id = bl.purchase_bill_id
                where bl.purchase_receipt_line_id = rl.id and b.deleted_at is null and b.status in ({$posted}))";

        $rows = DB::table('pur_receipt_lines as rl')
            ->whereIn('rl.purchase_receipt_id', $receipts->keys()->all())
            ->whereRaw("rl.received_qty > {$billed}")
            ->groupBy('rl.purchase_receipt_id')
            ->select(['rl.purchase_receipt_id', DB::raw("SUM((rl.received_qty - {$billed}) * rl.rate) as value")])
            ->get();

        if ($rows->isEmpty()) {
            return ['receipts' => 0, 'value' => '0', 'supplier' => ''];
        }

        $bySupplier = [];
        $total = '0';

        foreach ($rows as $row) {
            $value = (string) $row->value;
            $total = bcadd($total, $value, 4);
            $supplier = (int) $receipts[(int) $row->purchase_receipt_id];
            $bySupplier[$supplier] = bcadd($bySupplier[$supplier] ?? '0', $value, 4);
        }

        uasort($bySupplier, fn (string $a, string $b) => bccomp($b, $a, 4));
        $top = PurchaseReceipt::query()->with('supplier')->where('supplier_id', array_key_first($bySupplier))->first()?->supplier?->name() ?? '';

        return ['receipts' => $rows->count(), 'value' => $total, 'supplier' => $top];
    }

    /**
     * ⓘ OTIF = কথা দেওয়া দিনে বা আগে এসেছে আর আদেশের পুরোটা এসেছে (এ পর্যন্ত গ্রহণ ≥ আদেশ) — যে গ্রহণের আদেশে দিন লেখা ছিল তার শতাংশ।
     * ⓘ লিড টাইম = আদেশের দিন থেকে গ্রহণের দিন; টাকা = গ্রহণের লাইনের পরিমাণ × দর।
     */
    public function suppliers(string $from, string $to): array
    {
        $receipts = PurchaseReceipt::query()->with(['supplier', 'lines', 'order.lines'])
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereBetween('trx_date', [$from, $to])->get();

        return $receipts->groupBy('supplier_id')->map(function ($rs) {
            $promised = 0;
            $good = 0;
            $leads = [];
            $value = '0';

            foreach ($rs as $r) {
                $value = bcadd($value, $r->lines->reduce(fn (string $c, $l) => bcadd($c, bcmul((string) $l->received_qty, (string) $l->rate, 4), 4), '0'), 4);
                $order = $r->order;

                if ($order === null) {
                    continue;
                }

                $leads[] = \Illuminate\Support\Carbon::parse($order->trx_date)->diffInDays(\Illuminate\Support\Carbon::parse($r->trx_date));

                if ($order->expected_on === null) {
                    continue;
                }

                $promised++;
                $onTime = substr((string) $r->trx_date, 0, 10) <= substr((string) $order->expected_on, 0, 10);
                $ordered = $order->lines->reduce(fn (string $c, $l) => bcadd($c, (string) $l->ordered_qty, 4), '0');
                $got = (string) DB::table('pur_receipt_lines as rl')->join('pur_receipts as r2', 'r2.id', '=', 'rl.purchase_receipt_id')
                    ->where('r2.purchase_order_id', $order->id)->where('r2.status', '<>', DocumentStatus::CANCELLED)
                    ->where('r2.trx_date', '<=', $r->trx_date)->sum('rl.received_qty');
                $good += $onTime && bccomp($got, $ordered, 4) >= 0 ? 1 : 0;
            }

            return [
                'name' => (string) ($rs->first()->supplier?->name() ?? ''),
                'otif' => $promised === 0 ? null : (int) round($good * 100 / $promised),
                'lead' => $leads === [] ? null : (int) round(array_sum($leads) / count($leads)),
                'value' => $value,
            ];
        })->sort(fn (array $a, array $b) => bccomp($b['value'], $a['value'], 4))->take(6)->values()->all();
    }

    public function receivingFunnel(string $from, string $to): array
    {
        $orders = PurchaseOrder::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from, $to])->pluck('id');
        $receipts = PurchaseReceipt::query()->where('status', '<>', DocumentStatus::CANCELLED)->whereIn('purchase_order_id', $orders->all() ?: [0])->get(['id', 'purchase_order_id']);
        $failed = \App\Modules\Inventory\Models\QualityInspection::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE)->whereIn('source_id', $receipts->pluck('id')->all() ?: [0])
            ->whereIn('status', [\App\Modules\Inventory\Models\QualityInspection::PENDING, \App\Modules\Inventory\Models\QualityInspection::QUARANTINE, \App\Modules\Inventory\Models\QualityInspection::REJECTED])
            ->distinct()->pluck('source_id')->flip();
        $passed = $receipts->reject(fn ($r) => $failed->has($r->id));
        $matched = DB::table('pur_bill_lines as bl')->join('pur_bills as b', 'b.id', '=', 'bl.purchase_bill_id')
            ->join('pur_receipt_lines as rl', 'rl.id', '=', 'bl.purchase_receipt_line_id')
            ->whereIn('rl.purchase_receipt_id', $passed->pluck('id')->all() ?: [0])
            ->whereNull('b.deleted_at')->where('b.match_state', \App\Modules\Purchase\Models\PurchaseBill::MATCH_MATCHED)
            ->distinct()->count('rl.purchase_receipt_id');

        return [
            'orders' => $orders->count(),
            'received' => $receipts->pluck('purchase_order_id')->unique()->count(),
            'passed' => $passed->count(),
            'matched' => $matched,
        ];
    }

    public function returnSource(): ?string
    {
        return \App\Modules\Purchase\Models\PurchaseReturn::STOCK_SOURCE;
    }
}
