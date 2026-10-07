<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ডেলিভারি অর্ডারের হিসাবের অনুমোদন — সফটওয়্যার নিজে, কেউ হাতে নয়। বিক্রয়ের কাজের ধারা, ধাপ গ (৩ অক্টোবর ২০২৬)।
 *
 * ── কখন ────────────────────────────────────────────────────────────────
 * সুপারভাইজারের শেষ অনুমোদনের পরে ([[HoldAndCheckTheDeliveryOrder]]), আর গ্রাহকের টাকা এলে — আদায় পাকা, রসিদ ভাউচার,
 * চেক পাশ ([[RecheckTheHeldDeliveryOrders]]) — তাঁর সব "টাকার জন্য আটকে" DO, পুরনোটা আগে।
 *
 * ── সূত্র — দেয়ালের হুবহু ([[CreditExposure::check()]]) ──────────────────
 * বকেয়া + আটকে থাকা (চালান, খসড়া বিল, অন্য অনুমোদিত DO) + ক্লিয়ার না হওয়া চেক + এই DO ≤ সীমা। সীমা ০ হলেও অগ্রিম
 * কুলোলে চলে।
 *   · কুলোলে → `accounts_approved`, মাল বিল পর্যন্ত কড়া ([[DeliveryOrderStock::holdForGood()]]), সতর্কবার্তা থাকলে লেখা।
 *   · না কুলোলে → `accounts_held`, কত কম লেখা; মালের ঘড়ি চলতে থাকে (২৪ ঘণ্টা কড়া, ৭২-এ ছাড়)।
 *
 * ── সতর্কবার্তা — মালিকের তালিকা (৩ অক্টোবর ২০২৬) ─────────────────────
 * `stock_short` ("অর্ডার কমান"), `rate_vs_lot` (দর লটের MRP-র সাথে মেলে না), `qty_changed` (সুপারভাইজার বদলেছেন),
 * `limit_near` (সীমার ৯০%-এর বেশি), `lot_expiring` (যে লট আগে বেরোবে তার মেয়াদ শিগগির শেষ)।
 *
 * ⚠️ তালা: আগে গ্রাহক ([[CreditExposure::lockCustomer()]]), তারপর DO — দুই রসিদ একসাথে এলে একই DO দুইবার নয়, আর
 * দুই DO একসাথে একই জায়গা খায় না।
 */
final class DeliveryOrderAccounts
{
    /** যে অবস্থায় থাকলে যাচাই চলে — অন্য কোথাও নয় */
    private const CHECKABLE = [DeliveryOrderStatus::SUPERVISOR_APPROVED, DeliveryOrderStatus::ACCOUNTS_HELD];

    public function __construct(
        private readonly CreditExposure $credit,
        private readonly DeliveryOrderStock $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * একটা DO যাচাই। ফেরত: নতুন অবস্থা, বা `null` যদি যাচাইয়ের অবস্থায় ছিল না।
     */
    public function check(DeliveryOrder $order): ?string
    {
        return DB::transaction(function () use ($order): ?string {
            $customer = $this->credit->lockCustomer((int) $order->customer_id);

            $order = DeliveryOrder::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null || ! in_array($order->status, self::CHECKABLE, true)) {
                return null;
            }

            $result = $this->credit->check($customer, (string) $order->total, (int) $order->id);

            if (! $result['fits']) {
                $order->forceFill([
                    'status' => DeliveryOrderStatus::ACCOUNTS_HELD,
                    'accounts_short' => $result['short'],
                    // ⓘ প্রথমবার আটকানোর মুহূর্তই থাকে — বারবার যাচাইয়ে ঘড়ি নতুন করে শুরু হয় না
                    'accounts_held_at' => $order->accounts_held_at ?? Carbon::now(),
                    'accounts_checked_at' => Carbon::now(),
                ])->save();

                return DeliveryOrderStatus::ACCOUNTS_HELD;
            }

            $short = $this->stock->holdForGood($order);

            $order->forceFill([
                'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED,
                'accounts_short' => null,
                'accounts_checked_at' => Carbon::now(),
                'accounts_warnings' => $this->warnings($order, $short, $result['used_percent']) ?: null,
            ])->save();

            return DeliveryOrderStatus::ACCOUNTS_APPROVED;
        });
    }

    /**
     * গ্রাহকের টাকা এল — তাঁর সব "টাকার জন্য আটকে" DO আবার, পুরনোটা আগে।
     *
     * ⓘ একটা না কুলোলেও পরেরটা দেখা হয় — ছোট DO-টা হয়তো কুলোয়।
     *
     * @return int কয়টা অনুমোদিত হলো
     */
    public function recheckCustomer(int $customerId): int
    {
        $approved = 0;

        $orders = DeliveryOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', DeliveryOrderStatus::ACCOUNTS_HELD)
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if ($this->check($order) === DeliveryOrderStatus::ACCOUNTS_APPROVED) {
                $approved++;
            }
        }

        return $approved;
    }

    /**
     * @param  list<array{line_id: int, product_id: int, wanted: string, held: string}>  $short
     * @return list<array<string, mixed>>
     */
    private function warnings(DeliveryOrder $order, array $short, ?string $usedPercent): array
    {
        $warnings = [];

        foreach ($short as $s) {
            $warnings[] = ['kind' => 'stock_short', 'line_id' => $s['line_id'], 'product_id' => $s['product_id'], 'wanted' => $s['wanted'], 'available' => $s['held']];
        }

        if ($usedPercent !== null && bccomp($usedPercent, '90', 2) > 0) {
            $warnings[] = ['kind' => 'limit_near', 'used_percent' => $usedPercent];
        }

        $alertDays = max(0, (int) $this->settings->get('inventory.expiry_alert_days', 30));
        // ⓘ মজুদের সেবার একই খোঁজ — ব্যবহারকারীর ছাঁকনি ছাড়া ([[DeliveryOrderStock::warehouseFor()]])
        $warehouseId = DeliveryOrderStock::warehouseFor($order)?->id;

        foreach ($order->lines()->get() as $line) {
            if ($line->approved_qty !== null && bccomp((string) $line->approved_qty, (string) $line->qty, 4) !== 0) {
                $warnings[] = ['kind' => 'qty_changed', 'line_id' => (int) $line->id, 'asked' => (string) $line->qty, 'approved' => (string) $line->approved_qty];
            }

            // ⓘ যে লট আগে বেরোবে — FEFO, মেয়াদ না পেরোনো, আর এই গুদামে বেচার মতো মাল আছে; দর আর মেয়াদ দুইটাই ওটার সাথে
            $lot = Batch::query()
                ->where('product_id', $line->product_id)
                ->unexpired()
                ->whereIn('id', DB::table('inv_stock_movements')
                    ->where('company_id', $order->company_id)
                    ->where('product_id', $line->product_id)
                    ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                    ->whereNotNull('batch_id')
                    ->groupBy('batch_id')
                    ->havingRaw('SUM(floor_change - reserved_change - hold_change) > 0')
                    ->select('batch_id'))
                ->fefo()
                ->first();

            if ($lot === null) {
                continue;
            }

            if ($lot->mrp !== null && bccomp((string) $lot->mrp, '0', 4) > 0 && bccomp((string) $lot->mrp, (string) $line->rate, 4) !== 0) {
                $warnings[] = ['kind' => 'rate_vs_lot', 'line_id' => (int) $line->id, 'rate' => (string) $line->rate, 'lot' => (string) $lot->batch_no, 'lot_price' => (string) $lot->mrp];
            }

            $days = $lot->daysLeft();

            if ($days !== null && $days <= $alertDays) {
                $warnings[] = ['kind' => 'lot_expiring', 'line_id' => (int) $line->id, 'lot' => (string) $lot->batch_no, 'days_left' => $days];
            }
        }

        return $warnings;
    }
}
