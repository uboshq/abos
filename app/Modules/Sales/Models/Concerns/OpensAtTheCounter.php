<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models\Concerns;

use App\Core\Support\Actor;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ বিক্রয় আদেশ কাউন্টারের উৎস — চাবি `so` (নকশা "DO বিক্রয় আদেশে মেশানো" §৩.৪, ধাপ ৬; [[CounterSaleSource]])।
 *
 * ── DO থেকে তফাত ─────────────────────────────────────────────────────────────────────────────────────
 * ⓘ DO একবারেই বিল হয়; আদেশ **অংশে অংশে** — ডিপো কম দিলে বাকিটা আদেশে খোলা থাকে (ব্যাক অর্ডার; সমন্বয়কের উত্তর ৩),
 * আর পরের বার আবার কাউন্টারে খোলে। তাই:
 *   · কাউন্টারের পর্দায় প্রতিটা লাইনের **খোলা পরিমাণ** (চূড়ান্ত − যা গেছে − "আর দেওয়া হবে না") — [[OrderProgress]];
 *   · "বিল হয়েছে" মানে আদেশ বন্ধ নয় — ডিপোর চিহ্ন মোছে আর অগ্রগতি তাজা হয় ([[markInvoiced()]]);
 *   · চালান আদেশের সাথে বাঁধা ([[orderLink()]]) — বেশি-চালানের পাহারা, ট্যাব আর অগ্রগতি চালানটা দেখে।
 *
 * ⓘ ডিপো যাচাই অবস্থা নয়, চিহ্ন (`depot_check_at`) — আংশিক চালানের পরে একই আদেশ আবার ডিপোতে যায় (নকশার §১.১)।
 * ⓘ মজুদ: নতুন ধারার আদেশের হোল্ড চালানের ধাপেই ওঠে (abos-86, নকশার ধাপ ৫) — কাউন্টার এখানে কিছু ডাকে না।
 * ⛔ কেবল নতুন ধারার (`hold_mode = holds`) সংরক্ষিত আদেশ — আজকের নিয়মের আদেশ চালান কাটে নিজের পর্দা থেকে।
 */
trait OpensAtTheCounter
{
    public static function counterSourceKey(): string
    {
        return 'so';
    }

    /** ⛔ সংরক্ষিত, নতুন ধারার, আর অন্তত একটা লাইনে কিছু খোলা — তালা দিয়ে আবার পড়ে। */
    public function assertReadyForCounter(): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== SalesOrderStatus::CONFIRMED
            || (string) ($locked->hold_mode ?? SalesOrderStatus::HOLD_LEDGER) !== SalesOrderStatus::HOLD_HOLDS) {
            throw ValidationException::withMessages([
                'source' => __('sales::counter_source.order_not_ready', [
                    'no' => (string) $locked->document_no,
                    'status' => SalesOrderStatus::label((string) $locked->status),
                ]),
            ]);
        }

        $open = array_filter(
            app(OrderProgress::class)->of($locked)['lines'],
            fn (array $line) => bccomp((string) $line['open'], '0', 4) > 0,
        );

        if ($open === []) {
            throw ValidationException::withMessages([
                'source' => __('sales::counter_source.order_nothing_open', ['no' => (string) $locked->document_no]),
            ]);
        }

        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * কাউন্টারের পর্দা — প্রতিটা খোলা লাইন, খোলা পরিমাণে; লট ছাড়া (লট বাছা কাউন্টারের নিয়মে)।
     *
     * ⓘ ফ্রি: লাইনের ফ্রি থেকে যা আগের চালানে গেছে তা বাদ। ছাড় টাকায় লেখা, পর্দা চায় শতাংশ — লাইনের মূল দামের অনুপাতে।
     */
    public function counterScreen(): array
    {
        $progress = app(OrderProgress::class)->of($this);
        $lines = [];

        foreach ($this->lines()->get() as $line) {
            $open = (string) ($progress['lines'][(int) $line->id]['open'] ?? '0');

            if (bccomp($open, '0', 4) <= 0) {
                continue;
            }

            $freeGone = (string) (DeliveryChallanLine::query()
                ->where('sales_order_line_id', $line->id)
                ->whereHas('challan', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED))
                ->sum('free_qty') ?: '0');
            $free = bcsub((string) ($line->free_qty ?? '0'), $freeGone, 4);

            $lines[] = [
                'product_id' => (int) $line->product_id,
                'qty' => bcadd($open, '0', 4),
                'free_qty' => bccomp($free, '0', 4) > 0 ? $free : '0.0000',
                'rate' => (string) $line->rate,
                'discount_percent' => $this->discountPercentOf($line),
                'source_line_id' => (int) $line->id,
            ];
        }

        return [
            'ref' => (string) $this->document_no,
            'customer_id' => (int) $this->customer_id,
            'warehouse_id' => $this->warehouse_id === null ? null : (int) $this->warehouse_id,
            'lines' => $lines,
        ];
    }

    /** ⭐ ডিপোর চিহ্ন — অবস্থা বদলায় না; দুইবার ডাকলে প্রথম মুহূর্তই থাকে। */
    public function enterDepotCheck(): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->depot_check_at !== null) {
            $this->setRawAttributes($locked->getAttributes(), true);

            return;
        }

        $locked->forceFill(['depot_check_at' => now(), 'depot_check_by' => Actor::userId()])->save();
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /** ডিপোর চিহ্ন মোছা — কাউন্টারের রাখা খসড়া বাদ গেলে আদেশ আবার ডিপোর তালিকায়। */
    public function leaveDepotCheck(): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->depot_check_at === null) {
            return;
        }

        $locked->forceFill(['depot_check_at' => null, 'depot_check_by' => null])->save();
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * ⭐ বিক্রয় নিশ্চিতের একই লেনদেনে — আদেশ অংশে অংশে বিল হয়, তাই এটা আদেশ বন্ধ করে না: ডিপোর চিহ্ন মোছে আর অগ্রগতি
     * তাজা করে ([[OrderProgress::refresh()]])। ⓘ একই বিলে দুইবার ডাকলে ফল একই। ⛔ আদেশ আর সংরক্ষিত না থাকলে (বাতিল,
     * বন্ধ) পুরো বিক্রি ফেরে।
     */
    public function markInvoiced(SalesInvoice $invoice): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== SalesOrderStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'source' => __('sales::counter_source.order_not_ready', [
                    'no' => (string) $locked->document_no,
                    'status' => SalesOrderStatus::label((string) $locked->status),
                ]),
            ]);
        }

        $locked->forceFill(['depot_check_at' => null, 'depot_check_by' => null])->save();
        app(OrderProgress::class)->refresh($locked->fresh(['lines']));
        $this->setRawAttributes($locked->fresh()->getAttributes(), true);
    }

    /** ⭐ চালান এই আদেশে বাঁধা — কাউন্টার চালানে `sales_order_id` আর প্রতিটা লাইনে `sales_order_line_id` বসায়। */
    public function orderLink(): ?int
    {
        return (int) $this->getKey();
    }

    /** ⓘ ডিপো কম দিলে কিছু ছোট হয় না — বাকিটা আদেশে খোলা থাকে, হোল্ডসহ (নকশার §৩.৩; সমন্বয়কের উত্তর ৩)। */
    public function resizeCounterStock(array $less): void {}

    /** ⓘ মাল ওঠে চালানের ধাপে, কাউন্টারের নয় (abos-86, নকশার ধাপ ৫) — দুইবার নয়। */
    public function consumeCounterStock(DeliveryChallan $challan): void {}

    /** লাইনের ছাড় — টাকা থেকে মূল দামের শতাংশে, পর্দার ঘরের মতো। */
    private function discountPercentOf(SalesOrderLine $line): string
    {
        $base = bcmul((string) $line->ordered_qty, (string) $line->rate, 4);

        if (bccomp($base, '0', 4) <= 0 || bccomp((string) $line->discount, '0', 4) <= 0) {
            return '0.0000';
        }

        return bcdiv(bcmul((string) $line->discount, '100', 8), $base, 4);
    }
}
