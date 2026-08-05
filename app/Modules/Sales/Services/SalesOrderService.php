<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় আদেশ — গ্রাহক কী চেয়েছেন।
 *
 * ── এই ফাইলের কেন্দ্রীয় সিদ্ধান্ত: Reserved ────────────────────────────
 * আদেশ নিশ্চিত হলে মালটা অর্ডারে ধরা পড়ে। মালটা তাকেই থাকে (Floor কমে না),
 * শুধু আর বেচা যায় না (Available কমে)।
 *
 * সরিয়ে ফেললে গুদামে দাঁড়িয়ে গোনা মানুষ ১০০ পেতেন আর খাতা বলত ৮০, অথচ
 * কেউ কিছু সরায়নি। আর একেবারে না ধরলে একই শেষ কার্টনটা দুইজনকে বেচা হয়ে
 * যেত — দুইটা চালান ছাপা হত, আর ভুলটা ধরা পড়ত মাল দিতে গিয়ে, ক্রেতার
 * সামনে।
 *
 * খতিয়ানে কিছুই বসে না। অর্ডার একটা প্রতিশ্রুতি, আর প্রতিশ্রুতির হিসাব হয়
 * না — গ্রাহক অর্ডার বাতিল করলে ওই আয়টা কেউ সরাত না।
 */
final class SalesOrderService
{
    use CalculatesSalesLines;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): SalesOrder
    {
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('SO');

            $order = SalesOrder::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'] ?? $this->defaultWarehouse()?->id,
                'trx_date' => $trxDate->toDateString(),
                'deliver_on' => $data['deliver_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($order, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => SalesOrder::drillSourceType(),
                    'source_id' => $order->id,
                ]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesOrder $order, array $data, array $lines): SalesOrder
    {
        $this->assertEditable($order);
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($order, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $order->trx_date);

            $order->update([
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id,
                'trx_date' => $trxDate->toDateString(),
                'deliver_on' => $data['deliver_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($order, $lines);

            return $order->fresh(['lines']);
        });
    }

    /**
     * আদেশ নিশ্চিত — মাল অর্ডারে ধরা পড়ে।
     */
    public function confirm(SalesOrder $order): SalesOrder
    {
        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing(['lines.product', 'warehouse', 'customer']);

        if ($order->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $this->assertWithinCreditLimit($order);

        return DB::transaction(function () use ($order) {
            if ($this->settings->get('sales.reserve_on_order', true)) {
                $warehouse = $order->warehouse ?? $this->defaultWarehouse();

                if ($warehouse === null) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => __('sales::validation.unknown_warehouse'),
                    ]);
                }

                foreach ($order->lines as $line) {
                    $this->assertEnoughToSell($line->product, $warehouse, (string) $line->ordered_qty);

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE,
                        sourceId: $order->id,
                        reserved: (string) $line->ordered_qty,
                        date: $order->trx_date,
                        documentNo: $order->document_no,
                    );
                }
            }

            $order->update(['status' => DocumentStatus::CONFIRMED]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * বাতিল — ধরে রাখা মাল ছেড়ে দেওয়া হয়।
     *
     * যা ইতিমধ্যে চালান হয়ে বেরিয়ে গেছে তার ধরাটা চালানই ছেড়ে দিয়েছে,
     * তাই এখানে ছাড়া হয় কেবল যেটুকু এখনো ধরা আছে। দুইবার ছাড়লে Reserved
     * ঋণাত্মক হয়ে যেত।
     */
    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        if ($order->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing(['lines.product', 'warehouse']);

        return DB::transaction(function () use ($order, $reason) {
            if ($order->status === DocumentStatus::CONFIRMED && $order->warehouse) {
                foreach ($order->lines as $line) {
                    $stillReserved = $line->pendingQty();

                    if (bccomp($stillReserved, '0', 4) <= 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE.':cancel',
                        sourceId: $order->id,
                        reserved: bcmul($stillReserved, '-1', 4),
                        date: now(),
                        documentNo: $order->document_no,
                        narration: $reason,
                    );
                }
            }

            $order->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesOrder $order, array $lines): void
    {
        $order->lines()->delete();

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['ordered_qty'] ?? null, 'ordered_qty');
            $rate = $this->money($line['rate'] ?? null);

            if ($productId <= 0 || ! Product::query()->whereKey($productId)->exists()) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? '0');

            SalesOrderLine::create([
                'sales_order_id' => $order->id,
                'product_id' => $productId,
                'ordered_qty' => $qty,
                'rate' => $rate,
                'discount' => $figures['discount'],
                'tax' => $figures['tax'],
                'amount' => $figures['amount'],
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $totals = $this->addToTotals($totals, $figures);
        }

        $order->update($totals);
    }

    /**
     * বিক্রয়যোগ্য মালের চেয়ে বেশি অর্ডার নেওয়া যাবে কি না।
     *
     * ডিফল্টে যাবে না। কিন্তু কিছু ডিপোতে মাল রাস্তায় আছে জেনেই অর্ডার
     * নেওয়া হয়, আর তখন আটকে দিলে অর্ডারটাই হাতছাড়া হয় — তাই সুইচ (নিয়ম ৭)।
     */
    private function assertEnoughToSell(Product $product, Warehouse $warehouse, string $qty): void
    {
        if ($this->settings->get('sales.allow_negative_stock', false)) {
            return;
        }

        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($available, $qty, 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.not_enough_available', [
                    'product' => $product->name(),
                    'available' => rtrim(rtrim($available, '0'), '.'),
                ]),
            ]);
        }
    }

    /**
     * ধারের সীমা।
     *
     * সীমাটা গ্রাহকের মাস্টারে, আর সেখানেই থাকা উচিত — বিক্রয়কর্মী প্রতিবার
     * মনে রাখতে পারেন না কার কত সীমা। সীমা পেরোলে অনুমতিওয়ালা কেউ পার
     * করাতে পারেন, আর সেটাই approval-এর জায়গা।
     */
    private function assertWithinCreditLimit(SalesOrder $order): void
    {
        $customer = $order->customer;

        if ($customer === null || ! $customer->wouldExceedCreditLimit((string) $order->total)) {
            return;
        }

        if (auth()->user()?->can('sales.discount.override')) {
            return;
        }

        throw ValidationException::withMessages([
            'customer_id' => __('sales::validation.over_credit_limit', [
                'customer' => $customer->name(),
                'limit' => number_format((float) $customer->credit_limit, 2),
            ]),
        ]);
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()->where('is_default', true)->active()->first();
    }

    private function assertEditable(SalesOrder $order): void
    {
        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $order->document_no]),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertHasLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
