<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanGiftLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Validation\ValidationException;

/**
 * ফেরত তার বিলের সাথে বাঁধা — গ্রাহক, সারি, লট আর ফ্রি মাল। ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী খোলা ছিল (প্রমাণ: TheReturnCameBackAndTheBooksStillAgreedTest) ───
 *   ১. অন্য গ্রাহকের নামে ফেরত — ক-কে বেচা বিলের ফেরতে খ-এর খাতায় −৫০০
 *   ২. বিল ধরে ফেরত, কিন্তু বিলের সারি না বলে হাতে দর — ৩টা ১০০-র মাল
 *      ৫০০ দরে ফিরে গ্রাহকের পাওনা ১,৫০০ কমেছে
 *   ৩. লট ধরা পণ্য লট ছাড়া তাকে উঠেছে — লটের যোগফল আর পণ্যের মজুদ আলাদা
 *   ৪. যে লট বিলে বেরোয়নি সেই লটে ফেরত ঢুকেছে — রিকলের তালিকা মিথ্যা
 *   ৫. ফ্রি/উপহারের মাল ফেরতের কোনো পথ ছিল না (মালিকের সিদ্ধান্ত: ফেরে,
 *      ফ্রি ভাণ্ডারে, শূন্য দামে, পাওনা না কমিয়ে)
 *
 * ── ⭐ নিয়ম ─────────────────────────────────────────────────────────────
 * বিল বলা থাকলে: বিলটা নিশ্চিত বিক্রয়, গ্রাহক একই, প্রতিটা দামি সারি
 * বিলের একটা সারিতে বাঁধা (একটাই থাকলে নিজে থেকে বাঁধে — তাই দর আসে
 * বিল থেকে), লট ধরা পণ্যের লট ঐ বিলে বেরোনো লটের একটা (একটাই হলে
 * নিজে বসে), আর প্রতিটা লট/ফ্রি পরিমাণ বিলে যা বেরিয়েছিল তার বেশি নয়।
 *
 * ⓘ খসড়া বানানো আর নিশ্চিত করা — দুই জায়গাতেই চলে: নিয়মের আগের খসড়া
 * যেন সরাসরি খাতায় না বসে (কারণের পাহারার মতোই)।
 */
final class SalesReturnBillGuard
{
    /**
     * সারিগুলো বিলের সাথে মিলিয়ে বাঁধা — বাঁধা সারি ফেরত দেয়।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function bind(array $data, array $lines, ?int $exceptReturnId = null): array
    {
        $invoiceId = (int) ($data['sales_invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            foreach ($lines as $line) {
                if (bccomp($this->qty($line['free_qty'] ?? null), '0', 4) > 0) {
                    throw ValidationException::withMessages(['sales_invoice_id' => __('sales::validation.return_free_needs_bill')]);
                }
            }

            return $lines;
        }

        $invoice = SalesInvoice::query()->with(['lines.challanLine', 'customer'])->find($invoiceId);

        if ($invoice === null || ! in_array($invoice->status, DocumentStatus::POSTED, true)) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => __('sales::validation.return_bill_not_posted', ['no' => $invoice?->document_no ?? (string) $invoiceId]),
            ]);
        }

        if ((int) $invoice->customer_id !== (int) ($data['customer_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'customer_id' => __('sales::validation.return_bill_other_customer', [
                    'no' => $invoice->document_no,
                    'customer' => $invoice->customer?->name() ?? '',
                ]),
            ]);
        }

        $challanIds = $invoice->lines->pluck('challanLine.delivery_challan_id')->filter()->unique()->values()->all();
        $number = 0;
        $lotUse = ['paid' => [], 'free' => []];
        $lineUse = ['paid' => [], 'free' => [], 'gift' => []];

        foreach ($lines as $index => $line) {
            $qty = $this->qty($line['qty'] ?? null);
            $free = $this->qty($line['free_qty'] ?? null);

            if (bccomp($qty, '0', 4) <= 0 && bccomp($free, '0', 4) <= 0) {
                continue;
            }

            $number++;
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));

            if ($product === null) {
                continue; // সেবা নিজেই "অজানা পণ্য" বলে থামায়
            }

            $billLine = $this->billLineFor($invoice, $line, $product, $qty, $free, $challanIds, $number);
            $lines[$index]['sales_invoice_line_id'] = $billLine?->id;

            if ($billLine !== null) {
                $lineUse['paid'][$billLine->id] = bcadd($lineUse['paid'][$billLine->id] ?? '0', $qty, 4);
                $lineUse['free'][$billLine->id] = bcadd($lineUse['free'][$billLine->id] ?? '0', $free, 4);
            } else {
                $lineUse['gift'][$product->id] = bcadd($lineUse['gift'][$product->id] ?? '0', $free, 4);
            }

            $lot = $this->lotFor($invoice, $challanIds, $product, $line, $qty, $free, $number);
            $lines[$index]['batch_id'] = $lot?->id;

            if ($lot !== null) {
                $lotUse['paid'][$lot->id] = bcadd($lotUse['paid'][$lot->id] ?? '0', $qty, 4);
                $lotUse['free'][$lot->id] = bcadd($lotUse['free'][$lot->id] ?? '0', $free, 4);
                $this->assertLotRoom($invoice, $challanIds, $product, $lot, $lotUse, $exceptReturnId, $number);
            }

            $this->assertFreeRoom($invoice, $challanIds, $product, $billLine, $lineUse, $exceptReturnId, $number);
        }

        return $lines;
    }

    /** রাখা খসড়া আবার যাচাই — নিশ্চিত করার ঠিক আগে। */
    public function checkDocument(SalesReturn $return): void
    {
        $return->loadMissing(['lines.product', 'invoice']);

        if ($return->sales_invoice_id === null) {
            // বিল ছাড়া ফেরত সেবা নিজেই থামায় (return_needs_invoice); ফ্রি থাকলে এখানেই
            $this->bind(['customer_id' => $return->customer_id], $return->lines->map(
                fn (SalesReturnLine $l) => ['free_qty' => (string) $l->free_qty],
            )->all());

            return;
        }

        $stored = $return->lines->values();

        $bound = $this->bind(
            ['sales_invoice_id' => $return->sales_invoice_id, 'customer_id' => $return->customer_id],
            $stored->map(fn (SalesReturnLine $l) => [
                'product_id' => $l->product_id,
                'sales_invoice_line_id' => $l->sales_invoice_line_id,
                'qty' => (string) $l->qty,
                'free_qty' => (string) $l->free_qty,
                'batch_id' => $l->batch_id,
            ])->all(),
            $return->id,
        );

        /*
         * ⚠️ নিশ্চিত করার সময় সারি বদলানো হয় না — কেবল যাচাই। ⛔ রাখা খসড়ায়
         * বাঁধন বা লট না থাকলে (নিয়মের আগের কাগজ) থামা: নিজে বসিয়ে দিলে দর
         * থাকত হাতে লেখা, আর লট বসত খাতায় না লিখেই।
         */
        foreach ($stored as $position => $line) {
            $key = match (true) {
                (int) ($bound[$position]['sales_invoice_line_id'] ?? 0) !== (int) ($line->sales_invoice_line_id ?? 0) => 'return_line_not_on_bill',
                (int) ($bound[$position]['batch_id'] ?? 0) !== (int) ($line->batch_id ?? 0) => 'return_lot_required',
                default => null,
            };

            if ($key !== null) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.'.$key, [
                        'line' => $position + 1,
                        'product' => $line->product?->name() ?? '',
                        'no' => $return->invoice?->document_no ?? '',
                    ]),
                ]);
            }
        }
    }

    /**
     * দামি সারি বিলের কোন সারিতে বাঁধা — বলা থাকলে যাচাই, না থাকলে একটাই হলে বাঁধা।
     *
     * @param  array<string, mixed>  $line
     * @param  list<int>  $challanIds
     */
    private function billLineFor(SalesInvoice $invoice, array $line, Product $product, string $qty, string $free, array $challanIds, int $number): ?SalesInvoiceLine
    {
        $named = (int) ($line['sales_invoice_line_id'] ?? 0);

        if ($named > 0) {
            $billLine = $invoice->lines->firstWhere('id', $named);

            if ($billLine === null || (int) $billLine->product_id !== (int) $product->id) {
                throw $this->refuse('return_line_not_on_bill', $number, $product, $invoice);
            }

            return $billLine;
        }

        $candidates = $invoice->lines->where('product_id', $product->id)->values();

        /*
         * ⓘ কেবল ফ্রি পরিমাণ, আর পণ্যটা বিলে উপহার হিসেবে গেছে — উপহার
         * কোনো বিলের সারিতে বাঁধা থাকে না, তাই সারি খালিই থাকে।
         */
        if (bccomp($qty, '0', 4) <= 0 && $this->giftGiven($challanIds, $product) !== '0') {
            $withFree = $candidates->filter(fn (SalesInvoiceLine $l) => bccomp((string) ($l->challanLine?->free_qty ?? '0'), '0', 4) > 0);

            if ($withFree->isEmpty()) {
                return null;
            }
        }

        if ($candidates->isEmpty()) {
            throw $this->refuse('return_line_not_on_bill', $number, $product, $invoice);
        }

        if ($candidates->count() > 1) {
            throw $this->refuse('return_line_ambiguous', $number, $product, $invoice);
        }

        return $candidates->first();
    }

    /**
     * লট ধরা পণ্যের লট — বিলে বেরোনো লটের একটা; একটাই হলে নিজে বসে।
     *
     * @param  array<string, mixed>  $line
     * @param  list<int>  $challanIds
     */
    private function lotFor(SalesInvoice $invoice, array $challanIds, Product $product, array $line, string $qty, string $free, int $number): ?Batch
    {
        if (! $product->track_batch) {
            return null;
        }

        $left = [];

        if (bccomp($qty, '0', 4) > 0) {
            $left[] = array_keys($this->paidLotsLeft($invoice, $challanIds, $product));
        }

        if (bccomp($free, '0', 4) > 0) {
            $left[] = array_keys($this->freeLotsLeft($challanIds, $product));
        }

        // দামি আর ফ্রি একই সারিতে থাকলে লটটা দুই দিকেই বেরোনো হতে হবে
        $possible = count($left) === 1 ? $left[0] : array_values(array_intersect(...$left));
        $named = (int) ($line['batch_id'] ?? 0);

        if ($named <= 0) {
            if (count($possible) !== 1) {
                throw $this->refuse('return_lot_required', $number, $product, $invoice);
            }

            return Batch::query()->find($possible[0]);
        }

        $lot = Batch::query()->whereKey($named)->where('product_id', $product->id)->first();

        if ($lot === null || ! in_array($lot->id, $possible, true)) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.return_lot_not_on_bill', [
                    'line' => $number,
                    'lot' => $lot?->batch_no ?? (string) $named,
                    'product' => $product->name(),
                    'no' => $invoice->document_no,
                ]),
            ]);
        }

        return $lot;
    }

    /**
     * @param  list<int>  $challanIds
     * @param  array{paid: array<int, string>, free: array<int, string>}  $lotUse  এই ফেরতে লট ধরে যোগফল
     */
    private function assertLotRoom(SalesInvoice $invoice, array $challanIds, Product $product, Batch $lot, array $lotUse, ?int $exceptReturnId, int $number): void
    {
        foreach (['paid', 'free'] as $kind) {
            $wanted = $lotUse[$kind][$lot->id] ?? '0';

            if (bccomp($wanted, '0', 4) <= 0) {
                continue;
            }

            $left = $kind === 'paid'
                ? ($this->paidLotsLeft($invoice, $challanIds, $product)[$lot->id] ?? '0')
                : ($this->freeLotsLeft($challanIds, $product)[$lot->id] ?? '0');

            $back = $this->alreadyBack($invoice, $exceptReturnId)
                ->where('product_id', $product->id)
                ->where('batch_id', $lot->id)
                ->sum($kind === 'paid' ? 'qty' : 'free_qty');

            $room = bcsub($left, (string) ($back ?: '0'), 4);

            if (bccomp($wanted, $room, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.return_lot_over', [
                        'line' => $number,
                        'room' => $this->plain($room),
                        'lot' => $lot->batch_no,
                        'no' => $invoice->document_no,
                    ]),
                ]);
            }
        }
    }

    /**
     * ফ্রি পরিমাণ — বিলের সারিতে যত ফ্রি গেছে (বা উপহার হিসেবে), তার বেশি নয়।
     *
     * @param  list<int>  $challanIds
     * @param  array{paid: array<int, string>, free: array<int, string>, gift: array<int, string>}  $lineUse
     */
    private function assertFreeRoom(SalesInvoice $invoice, array $challanIds, Product $product, ?SalesInvoiceLine $billLine, array $lineUse, ?int $exceptReturnId, int $number): void
    {
        if ($billLine !== null) {
            $wanted = $lineUse['free'][$billLine->id] ?? '0';
            $given = (string) ($billLine->challanLine?->free_qty ?? '0');
            $back = $this->alreadyBack($invoice, $exceptReturnId)->where('sales_invoice_line_id', $billLine->id)->sum('free_qty');
        } else {
            $wanted = $lineUse['gift'][$product->id] ?? '0';
            $given = $this->giftGiven($challanIds, $product);
            $back = $this->alreadyBack($invoice, $exceptReturnId)
                ->where('product_id', $product->id)
                ->whereNull('sales_invoice_line_id')
                ->sum('free_qty');
        }

        if (bccomp($wanted, '0', 4) <= 0) {
            return;
        }

        $room = bcsub($given, (string) ($back ?: '0'), 4);

        if (bccomp($wanted, $room, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.return_free_over', [
                    'line' => $number,
                    'room' => $this->plain($room),
                    'product' => $product->name(),
                    'no' => $invoice->document_no,
                ]),
            ]);
        }
    }

    /**
     * বিলে কোন লট থেকে কতটা দামি মাল বেরিয়েছিল — চলাচলের সারি ধরে।
     *
     * ⓘ সরাসরি বিক্রয়ে মাল নামে চালানে; কাউন্টারের বিলে বিল নিজেই নামায়।
     *
     * @param  list<int>  $challanIds
     * @return array<int, string>
     */
    private function paidLotsLeft(SalesInvoice $invoice, array $challanIds, Product $product): array
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->whereNotNull('batch_id')
            ->where(function ($q) use ($invoice, $challanIds) {
                $q->where(fn ($c) => $c->where('source_type', DeliveryChallan::STOCK_SOURCE)->whereIn('source_id', $challanIds ?: [0]))
                    ->orWhere(fn ($i) => $i->where('source_type', SalesInvoice::STOCK_SOURCE)->where('source_id', $invoice->id));
            })
            ->groupBy('batch_id')
            ->selectRaw('batch_id, -SUM(floor_change) as out_qty')
            ->havingRaw('-SUM(floor_change) > 0')
            ->pluck('out_qty', 'batch_id')
            ->map(fn ($q) => bcadd((string) $q, '0', 4))
            ->all();
    }

    /**
     * @param  list<int>  $challanIds
     * @return array<int, string>
     */
    private function freeLotsLeft(array $challanIds, Product $product): array
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->whereNotNull('batch_id')
            ->whereIn('source_type', [DeliveryChallan::STOCK_SOURCE.':free', DeliveryChallan::STOCK_SOURCE.':gift'])
            ->whereIn('source_id', $challanIds ?: [0])
            ->groupBy('batch_id')
            ->selectRaw('batch_id, -SUM(free_change) as out_qty')
            ->havingRaw('-SUM(free_change) > 0')
            ->pluck('out_qty', 'batch_id')
            ->map(fn ($q) => bcadd((string) $q, '0', 4))
            ->all();
    }

    /** @param list<int> $challanIds */
    private function giftGiven(array $challanIds, Product $product): string
    {
        if ($challanIds === []) {
            return '0';
        }

        $sum = DeliveryChallanGiftLine::query()
            ->whereIn('delivery_challan_id', $challanIds)
            ->where('product_id', $product->id)
            ->sum('qty');

        return bccomp((string) ($sum ?: '0'), '0', 4) > 0 ? bcadd((string) $sum, '0', 4) : '0';
    }

    /** এই বিলের অন্য নিশ্চিত ফেরতের সারিগুলো। */
    private function alreadyBack(SalesInvoice $invoice, ?int $exceptReturnId)
    {
        return SalesReturnLine::query()
            ->whereHas('return', fn ($q) => $q->posted()
                ->where('sales_invoice_id', $invoice->id)
                ->when($exceptReturnId !== null, fn ($r) => $r->whereKeyNot($exceptReturnId)));
    }

    private function refuse(string $key, int $number, Product $product, SalesInvoice $invoice): ValidationException
    {
        return ValidationException::withMessages([
            'lines' => __('sales::validation.'.$key, [
                'line' => $number,
                'product' => $product->name(),
                'no' => $invoice->document_no,
            ]),
        ]);
    }

    private function qty(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return is_numeric($value) && bccomp($value, '0', 4) > 0 ? bcadd($value, '0', 4) : '0';
    }

    private function plain(string $qty): string
    {
        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }
}
