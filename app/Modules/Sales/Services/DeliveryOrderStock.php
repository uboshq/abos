<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\DeliveryOrderStockHold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ডেলিভারি অর্ডারের মাল আটকানো — বিক্রয়ের কাজের ধারা, ধাপ ঘ (৩ অক্টোবর ২০২৬)।
 *
 * ── মালিকের নিয়ম ────────────────────────────────────────────────────────
 * *"সুপারভাইজার অনুমোদন দিলে মাল সংরক্ষিত হবে — 24h er jonno korakori atkabe, baki 2din dekhabe but bikroy cholbe"*:
 *   · সুপারভাইজারের অনুমোদন → [[holdForSupervisor()]]: কড়া (`reserved`), ঘড়ি দুইটা — `firm_until` = +২৪ ঘণ্টা,
 *     `expires_at` = +৩ দিন (সেটিং `sales.do_hard_hold_hours`, `sales.do_hold_days`)।
 *   · ২৪ ঘণ্টা পেরোলে [[expireOld()]] কড়াটা নামায় — `reserved` ফেরে, সারি `soft` (কেবল দেখানো, বিক্রি চলে)।
 *   · ৭২ ঘণ্টায় টাকা না এলে [[expireOld()]] ছাড়ে।
 *   · হিসাবে অনুমোদিত → [[holdForGood()]]: আবার কড়া, কোনো ঘড়ি নেই — বিল না হওয়া পর্যন্ত।
 *   · মজুদ কম হলে যতটা আছে ততটা (মালিক: *"za ache ta atkabe"*) — বাকিটা সতর্কবার্তায় ([[holdForSupervisor()]]-এর ফেরত)।
 *
 * ── ⚠️ কড়ার জোর মজুদের খাতায় ───────────────────────────────────────────
 * এই সেবা কেবল `reserved_change` লেখে, উৎস `delivery_order` ([[StockService::move()]]); available = floor − reserved
 * − hold, তাই অন্য বিক্রি নিজে থেকেই থামে। ⛔ প্রতিটা নামা/ছাড় ঠিক ততটাই ফেরত দেয় যতটা বসেছিল — সারিতে লেখা
 * `qty` থেকে, আবার মেপে নয়; নইলে মাঝের বিক্রিতে অঙ্ক সরে গিয়ে খাতায় চিরস্থায়ী "সংরক্ষিত" জমত।
 *
 * ⓘ প্রতিটা কাজ নিজের লেনদেনে, DO-র সারিতে তালা দিয়ে — একই DO-তে দুই ঘটনা একসাথে এলে দ্বিতীয়টা প্রথমটার পরে দেখে।
 * দুইবার ডাকলে ফল একই: আগের খোলা আটকানো আগে ফেরে, তারপর নতুন বসে।
 */
final class DeliveryOrderStock
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * সুপারভাইজারের অনুমোদনে — ২৪ ঘণ্টা কড়া, ৭২ ঘণ্টায় ছাড়।
     *
     * @return list<array{line_id: int, product_id: int, wanted: string, held: string}> যেখানে পুরোটা পাওয়া যায়নি
     */
    public function holdForSupervisor(DeliveryOrder $order): array
    {
        $now = Carbon::now();

        return $this->hold(
            $order,
            firmUntil: $now->copy()->addHours($this->hardHours()),
            expiresAt: $now->copy()->addDays($this->holdDays()),
        );
    }

    /**
     * হিসাবে অনুমোদিত — বিল না হওয়া পর্যন্ত কড়া, কোনো ঘড়ি নেই।
     *
     * @return list<array{line_id: int, product_id: int, wanted: string, held: string}>
     */
    public function holdForGood(DeliveryOrder $order): array
    {
        return $this->hold($order, firmUntil: null, expiresAt: null);
    }

    /** বাতিল, ফেরত বা অন্য কোনো কারণে — DO-র সব খোলা আটকানো ফেরে। */
    public function release(DeliveryOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $order = $this->lockOrder($order);
            $this->releaseOpen($order, $reason);
        });
    }

    /**
     * চালান নিশ্চিত হলো (abos-bb, ধাপ চ) — যা বেরোল ততটা "উঠল", আর পুরো আটকানো ফেরে।
     *
     * ⓘ চালান নিজে মেঝে থেকে মাল কাটে; এখানে কেবল `reserved` ফেরত — নইলে বেরোনো মাল আবার আটকানো দেখাত।
     * দুইবার ডাকলে কিছু হয় না — দ্বিতীয়বার খোলা আটকানো থাকে না।
     */
    public function consume(DeliveryOrder $order, DeliveryChallan $challan): void
    {
        DB::transaction(function () use ($order, $challan): void {
            $order = $this->lockOrder($order);

            $out = [];

            foreach ($challan->lines()->get() as $line) {
                $key = (int) $line->product_id;
                $out[$key] = bcadd($out[$key] ?? '0', (string) $line->delivered_qty, 4);
            }

            foreach ($this->openHolds($order) as $hold) {
                $key = (int) $hold->product_id;
                $used = bccomp($out[$key] ?? '0', (string) $hold->qty, 4) < 0 ? ($out[$key] ?? '0') : (string) $hold->qty;
                $out[$key] = bcsub($out[$key] ?? '0', $used, 4);

                $this->close($order, $hold, 'consumed', $used);
            }
        });
    }

    /**
     * ডিপো পরিমাণ কমাল (abos-bb) — আটকানোও কমে। ⛔ বাড়ানো নয় (মালিক: ডিপো কম দিতে পারে, বেশি নয়)।
     *
     * @param  array<int, string>  $qtyByLine  DO-র সারির id → নতুন পরিমাণ
     */
    public function resize(DeliveryOrder $order, array $qtyByLine): void
    {
        DB::transaction(function () use ($order, $qtyByLine): void {
            $order = $this->lockOrder($order);

            foreach ($this->openHolds($order) as $hold) {
                if (! array_key_exists((int) $hold->delivery_order_line_id, $qtyByLine)) {
                    continue;
                }

                $new = bcadd((string) $qtyByLine[(int) $hold->delivery_order_line_id], '0', 4);

                if (bccomp($new, '0', 4) < 0) {
                    throw ValidationException::withMessages(['qty' => __('sales::do_hold.negative')]);
                }

                $less = bcsub((string) $hold->qty, $new, 4);

                if (bccomp($less, '0', 4) <= 0) {
                    continue;
                }

                if ($hold->kind === DeliveryOrderStockHold::FIRM) {
                    $this->reserve($order, $hold, bcmul($less, '-1', 4));
                }

                $hold->update(['qty' => $new]);
            }
        });
    }

    /**
     * ঘড়ির কাজ — `abos:do-holds-expire` প্রতি ঘণ্টায়। দুই ধাপ: কড়া → দেখানো, দেখানো → ছাড়।
     *
     * @return array{softened: int, released: int}
     */
    public function expireOld(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $softened = 0;
        $released = 0;

        $due = DeliveryOrderStockHold::query()->open()
            ->where(fn ($q) => $q->where('expires_at', '<=', $now)
                ->orWhere(fn ($w) => $w->where('kind', DeliveryOrderStockHold::FIRM)->where('firm_until', '<=', $now)))
            ->orderBy('id')
            ->pluck('delivery_order_id')
            ->unique();

        foreach ($due as $orderId) {
            DB::transaction(function () use ($orderId, $now, &$softened, &$released): void {
                $order = DeliveryOrder::query()->whereKey($orderId)->lockForUpdate()->first();

                if ($order === null) {
                    return;
                }

                foreach ($this->openHolds($order) as $hold) {
                    if ($hold->expires_at !== null && $hold->expires_at->lte($now)) {
                        $this->close($order, $hold, 'expired');
                        $released++;

                        continue;
                    }

                    if ($hold->kind === DeliveryOrderStockHold::FIRM && $hold->firm_until !== null && $hold->firm_until->lte($now)) {
                        $this->reserve($order, $hold, bcmul((string) $hold->qty, '-1', 4));
                        $hold->update(['kind' => DeliveryOrderStockHold::SOFT, 'firm_until' => null]);
                        $softened++;
                    }
                }
            });
        }

        return ['softened' => $softened, 'released' => $released];
    }

    // ── ভিতরের ─────────────────────────────────────────────────────────

    /** @return list<array{line_id: int, product_id: int, wanted: string, held: string}> */
    private function hold(DeliveryOrder $order, ?Carbon $firmUntil, ?Carbon $expiresAt): array
    {
        return DB::transaction(function () use ($order, $firmUntil, $expiresAt): array {
            $order = $this->lockOrder($order);

            // ⛔ গোনার আগে পণ্যের সারিতে তালা — দুটো DO একসাথে ধরলে দুটোই একই খালি মাল দেখত (অডিট ম১৮, [[StockLock]])
            \App\Modules\Sales\Support\StockLock::products($order->lines()->pluck('product_id'));

            // ⓘ আগের খোলা আটকানো আগে ফেরে — তারপর মাপা, যাতে নিজের আটকানো নিজেকে কম না দেখায়
            $this->releaseOpen($order, 'rehold');

            $warehouse = $this->warehouseOf($order);
            $short = [];

            foreach ($order->lines()->with('product')->get() as $line) {
                $wanted = bcadd($line->finalQty(), '0', 4);

                if (bccomp($wanted, '0', 4) <= 0 || $line->product === null) {
                    continue;
                }

                $available = $this->stock->availableQty($line->product, $warehouse);
                $available = bccomp($available, '0', 4) > 0 ? $available : '0';
                $held = bccomp($available, $wanted, 4) < 0 ? bcadd($available, '0', 4) : $wanted;

                if (bccomp($held, $wanted, 4) < 0) {
                    $short[] = ['line_id' => (int) $line->id, 'product_id' => (int) $line->product_id, 'wanted' => $wanted, 'held' => $held];
                }

                if (bccomp($held, '0', 4) <= 0) {
                    continue;
                }

                $hold = DeliveryOrderStockHold::query()->create([
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'delivery_order_id' => $order->id,
                    'delivery_order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'warehouse_id' => $warehouse->id,
                    'wanted_qty' => $wanted,
                    'qty' => $held,
                    'kind' => DeliveryOrderStockHold::FIRM,
                    'held_at' => Carbon::now(),
                    'firm_until' => $firmUntil,
                    'expires_at' => $expiresAt,
                ]);

                $this->reserve($order, $hold, $held);
            }

            return $short;
        });
    }

    private function releaseOpen(DeliveryOrder $order, string $reason): void
    {
        foreach ($this->openHolds($order) as $hold) {
            $this->close($order, $hold, $reason);
        }
    }

    private function close(DeliveryOrder $order, DeliveryOrderStockHold $hold, string $reason, string $consumed = '0'): void
    {
        if ($hold->kind === DeliveryOrderStockHold::FIRM && bccomp((string) $hold->qty, '0', 4) > 0) {
            $this->reserve($order, $hold, bcmul((string) $hold->qty, '-1', 4));
        }

        $hold->update([
            'released_at' => Carbon::now(),
            'release_reason' => $reason,
            'consumed_qty' => $consumed,
        ]);
    }

    private function reserve(DeliveryOrder $order, DeliveryOrderStockHold $hold, string $qty): void
    {
        $this->stock->move(
            product: $hold->product()->firstOrFail(),
            warehouse: Warehouse::query()->withoutGlobalScopes()->where('company_id', $order->company_id)->findOrFail($hold->warehouse_id),
            sourceType: DeliveryOrderStockHold::STOCK_SOURCE,
            sourceId: (int) $order->id,
            reserved: $qty,
            date: Carbon::now()->toDateString(),
            documentNo: (string) $order->document_no,
        );
    }

    /** @return \Illuminate\Support\Collection<int, DeliveryOrderStockHold> */
    private function openHolds(DeliveryOrder $order): \Illuminate\Support\Collection
    {
        return DeliveryOrderStockHold::query()->open()
            ->where('delivery_order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockOrder(DeliveryOrder $order): DeliveryOrder
    {
        return DeliveryOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * ⭐ এই উৎসের বিক্রির নিজের সংরক্ষণ — চালানের "পাওয়া যায়" পাহারার জন্য (অডিট গ১১, ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ DO-র কড়া আটকানো ফেরে চালান নিশ্চিত হওয়ার **পরে**, একই লেনদেনে ([[consume()]])। ⛔ তাই নিশ্চিতের মুহূর্তে
     * ওটা না গুনলে DO-র নিজের আটকানো মালই নিজের চালানকে "পাওয়া যায় না" বলত
     * ([[DeliveryChallanService::confirm()]]-এর `ownReservations`)। অন্য কোনো উৎসে খালি তালিকা।
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function reservationsOf(mixed $source): array
    {
        return $source instanceof DeliveryOrder
            ? [[DeliveryOrderStockHold::STOCK_SOURCE, (int) $source->id]]
            : [];
    }

    /**
     * ⚠️ সফটওয়্যারের কাজ, কোনো মানুষের দেখা নয় — তাই "ব্যবহারকারীর গুদাম" ছাঁকনি ছাড়া, কেবল DO-র কোম্পানি ধরে।
     * ⓘ ধরা পড়েছে ৩ অক্টোবর ২০২৬ (abos-2c): ডিলার পোর্টালে জমা দিলে কর্তা গ্রাহক, ব্যবহারকারী নন — ছাঁকনিটা ভেঙে ৫০০।
     */
    public static function warehouseFor(DeliveryOrder $order): ?Warehouse
    {
        $query = fn () => Warehouse::query()->withoutGlobalScopes()->where('company_id', $order->company_id)->whereNull('deleted_at');

        return $order->warehouse_id !== null
            ? $query()->whereKey($order->warehouse_id)->first()
            : $query()->where('is_default', true)->orderBy('id')->first();
    }

    private function warehouseOf(DeliveryOrder $order): Warehouse
    {
        $warehouse = self::warehouseFor($order);

        if ($warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => __('sales::validation.unknown_warehouse')]);
        }

        return $warehouse;
    }

    private function hardHours(): int
    {
        return max(0, (int) $this->settings->get('sales.do_hard_hold_hours', 24));
    }

    private function holdDays(): int
    {
        return max(0, (int) $this->settings->get('sales.do_hold_days', 3));
    }
}
