<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderCancelled;
use App\Modules\Sales\Events\SalesOrderClosed;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\SalesOrderStatus;
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
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly DocumentApproval $approvals,
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

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * মালিকের কথা: *"এখন সব জায়গায় এপ্রুভাল দিয়ে টেস্ট কর, পরে যে
         * যে জায়গায় লাগবে না তাও উঠিয়ে দিব"*।
         *
         * ⚠️ সারিটা কারো আজকের কাজ থামায় না: ছক না বসানো পর্যন্ত
         * `assertClear()` চুপচাপ ফিরে যায়, আর কাজ আগের মতোই চলে।
         * ⓘ কত টাকার উপরে সই লাগবে সেটা প্রতিটা কোম্পানি নিজে বসায় —
         * এক ডিপোর "বড় কাজ" আরেকটার রোজকার কাজ।
         */
        $this->approvals->assertClear(
            document: $order,
            module: 'sales',
            action: 'order',
            field: 'status',
            amount: (string) $order->total,
            reason: $order->narration,
        );

        /*
         * ⓘ বাকির সীমা এখানে **দেখা হয় না** — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * *"customer ba SR order kikore dibe, seta DO/delivery order theke
         * suro hobe"* — আদেশ আন্দাজের জিনিস, মাল তখনো গুদামে। ⛔ এখানে
         * আটকালে গ্রাহক বা বিক্রয়কর্মী আদেশই দিতে পারতেন না।
         *
         * ⭐ দেয়ালটা এখন চালানে আর বিলে ([[CreditExposure::assertRoom()]]),
         * যেখানে মাল গেট পার হয়। ⚠️ আর আদেশ কোনো সীমা আটকায়ও না —
         * আটকায় কেবল বিল না হওয়া চালান আর খসড়া বিল।
         */
        return DB::transaction(function () use ($order) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_confirms', ['no' => $order->document_no]),
                ]);
            }

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
        $this->assertNotClosed($order);

        if ($order->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing(['lines.product', 'warehouse']);

        return DB::transaction(function () use ($order, $reason) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.already_cancelled', ['no' => $order->document_no]),
                ]);
            }

            // ⛔ বন্ধ আদেশের ধরা মাল বন্ধেই ছাড়া হয়েছে — বাতিলে আবার ছাড়লে Reserved ঋণাত্মক হত
            $this->assertNotClosed($order);

            if ($order->status === DocumentStatus::CONFIRMED && $order->warehouse) {
                // ⛔ কেবল এই আদেশ নিজে যা ধরেছিল — অন্য কাগজের ধরা মাল নয় ([[heldByThisOrder()]], ৪ অক্টোবর ২০২৬)
                $own = $this->heldByThisOrder($order);

                foreach ($order->lines as $line) {
                    $stillReserved = $this->ownShare($own, $line);

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

            $fresh = $order->fresh(['lines']);

            // ⭐ abos-86-এর হোল্ড ছাড়ার জন্য — লেনদেন পাকা হলে তবেই ([[SalesOrderCancelled]])
            DB::afterCommit(fn () => event(SalesOrderCancelled::from($fresh, $reason)));

            return $fresh;
        });
    }

    /**
     * ⭐ বন্ধ — পুরো বিলের পরে, বা ইচ্ছা করে কম রেখে (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; পরিকল্পনার §৫.১-এর শেষ ধাপ)।
     *
     * ⓘ পুরো বিল হলে কারণ লাগে না — কাজ শেষ। ⛔ বাকি রেখে বন্ধ ("short close") করলে কারণ বাধ্যতামূলক; প্রতিটা লাইনের
     * বাকিটা "আর দেওয়া হবে না" হয় (`rejected_qty`, `reject_reason` — SAP-এর reason for rejection, নকশার §১.৪), আর যে মাল
     * এখনো এই আদেশের নামে ধরা তা ছাড়া হয় — নাহলে বন্ধ আদেশ চিরকাল তাকের মাল আটকে রাখত।
     * ⛔ কিছুই না গিয়ে থাকলে বন্ধ নয়, বাতিল — বন্ধ মানে "যা হওয়ার হয়েছে", আর কিছু না হওয়া আদেশের নাম বাতিল।
     *
     * ⓘ ছাড়ার নিয়ম [[cancel()]]-এর হুবহু (লাইনের `pendingQty()`), যাতে খসড়া চালান পরে নিশ্চিত হলে সে নিজের অংশটুকুই ছাড়ে
     * ([[DeliveryChallanService::releasableQty()]]) — দুইবার নয়। ⚠️ কেবল `ledger` আদেশে আর সুইচ চালু থাকলে (মাল ধরা
     * হয়েছিল কেবল তখনই, [[confirm()]])। `holds` আদেশের হোল্ড ছাড়ে abos-86-এর সেবা (নকশার ধাপ ৪, `ReleaseTheOrderStock`)।
     */
    public function close(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        $reason = trim((string) $reason);

        $order->loadMissing(['lines.product', 'warehouse']);

        return DB::transaction(function () use ($order, $reason) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.only_confirmed_closes', ['no' => $order->document_no]),
                ]);
            }

            $progress = app(OrderProgress::class)->of($order);

            if ($progress['billing'] !== SalesOrderStatus::FULL && $reason === '') {
                throw ValidationException::withMessages([
                    'close_reason' => __('sales::order_status.short_close_needs_reason', ['no' => $order->document_no]),
                ]);
            }

            if ($progress['delivery'] === SalesOrderStatus::NONE) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.nothing_went_cancel_instead', ['no' => $order->document_no]),
                ]);
            }

            $ledger = (string) ($order->hold_mode ?? SalesOrderStatus::HOLD_LEDGER) === SalesOrderStatus::HOLD_LEDGER;

            // ⛔ কেবল এই আদেশ নিজে যা ধরেছিল — সুইচ বন্ধে নিশ্চিত হওয়া আদেশ কিছুই ধরেনি, তাই কিছুই ছাড়ে না
            $own = $ledger && $order->warehouse ? $this->heldByThisOrder($order) : [];

            foreach ($order->lines as $line) {
                if ($ledger && $order->warehouse) {
                    $stillReserved = $this->ownShare($own, $line);

                    if (bccomp($stillReserved, '0', 4) > 0) {
                        $this->stock->move(
                            product: $line->product,
                            warehouse: $order->warehouse,
                            sourceType: SalesOrder::STOCK_SOURCE.':close',
                            sourceId: $order->id,
                            reserved: bcmul($stillReserved, '-1', 4),
                            date: now(),
                            documentNo: $order->document_no,
                            narration: $reason !== '' ? $reason : null,
                        );
                    }
                }

                // ⓘ যা যায়নি, তা "আর দেওয়া হবে না" — চূড়ান্ত পরিমাণ = যা গেছে
                $open = (string) $progress['lines'][(int) $line->id]['open'];

                $line->forceFill(array_filter([
                    'line_status' => SalesOrderStatus::LINE_CLOSED,
                    'rejected_qty' => bccomp($open, '0', 4) > 0 ? bcadd((string) $line->rejected_qty, $open, 4) : null,
                    'reject_reason' => bccomp($open, '0', 4) > 0 ? mb_substr($reason, 0, 255) : null,
                ], fn ($v) => $v !== null))->save();
            }

            $order->update([
                'status' => SalesOrderStatus::CLOSED,
                'closed_at' => now(),
                'closed_by' => Actor::userId(),
                'close_reason' => $reason !== '' ? $reason : null,
            ]);

            // ⭐ অগ্রগতির ঘর — একমাত্র লেখকের হাতে ([[OrderProgress::refresh()]])
            app(OrderProgress::class)->refresh($order->fresh(['lines']));

            $fresh = $order->fresh(['lines']);

            // ⭐ abos-86-এর হোল্ড ছাড়ার জন্য — লেনদেন পাকা হলে তবেই ([[SalesOrderClosed]])
            DB::afterCommit(fn () => event(SalesOrderClosed::from($fresh, $reason)));

            return $fresh;
        });
    }

    /** ⛔ বন্ধ আদেশ বাতিল হয় না — বন্ধের দিনেই তার বাকি মাল ছাড়া হয়েছে। */
    private function assertNotClosed(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => __('sales::order_status.closed_cannot_cancel', ['no' => $order->document_no]),
            ]);
        }
    }

    /**
     * ⛔ এই আদেশ নিজে কতটা ধরেছিল — পণ্যপ্রতি, আদেশের গুদামে, মজুদের খাতা থেকে (উৎস `sales_order`, এই আদেশের আইডি)।
     *
     * ── কেন খাতা থেকে, সুইচ থেকে নয় (সমন্বয়কের নির্দেশ, ৪ অক্টোবর ২০২৬) ────────────────────────────
     * ⓘ বাতিল আর বন্ধ আগে প্রতিটা লাইনের বাকি (`pendingQty()`) ছাড়ত, আদেশ সত্যিই কিছু ধরেছিল কি না না দেখে। ⛔ সংরক্ষণের
     * সুইচ (`sales.reserve_on_order`) বন্ধ থাকতে নিশ্চিত হওয়া আদেশ কিছুই ধরেনি — অথচ বাতিলে সে **অন্য কাগজের** ধরা মাল ছেড়ে
     * দিত, আর সেই মাল আবার বেচা যেত। ⚠️ আজকের সুইচ দেখলেও ভুল হত: নিশ্চিতের দিন চালু, বাতিলের দিন বন্ধ হলে আদেশের ধরা মাল
     * চিরকাল আটকে থাকত। ⭐ সত্যিটা খাতায়: এই আদেশ নিজের নামে কতটা বসিয়েছিল।
     *
     * ⭐ চালান যতটা ছেড়েছে তা-ও যোগ হয় (abos-86, ৪ অক্টোবর ২০২৬): ছাড়াটা বসে চালানের নিজের উৎসে (`delivery_challan`,
     * ঋণাত্মক), আর চালান বাতিলের ফেরত `delivery_challan:cancel`-এ — তাই যোগফলই আসল অবশিষ্ট ধরা। ⛔ শুধু `sales_order`
     * গুনলে ১০ ধরে ৬ ছাড়ার পরেও ১০ দেখাত, আর বাতিল অন্য কাগজের ৬ খেয়ে ফেলত।
     *
     * @return array<int, string> পণ্য → এই আদেশের নিজের বসানো Reserved
     */
    public function heldByThisOrder(SalesOrder $order): array
    {
        $challan = \App\Modules\Sales\Models\DeliveryChallan::class;
        $challans = $challan::query()
            ->where('sales_order_id', $order->id)
            ->where('warehouse_id', $order->warehouse_id)
            ->select('id');

        return StockMovement::query()
            ->where('warehouse_id', $order->warehouse_id)
            ->where(fn ($q) => $q
                ->where(fn ($own) => $own->where('source_type', SalesOrder::STOCK_SOURCE)->where('source_id', $order->id))
                ->orWhere(fn ($out) => $out->whereIn('source_type', [$challan::STOCK_SOURCE, $challan::STOCK_SOURCE.':cancel'])
                    ->whereIn('source_id', $challans)))
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(reserved_change), 0) as held')
            ->pluck('held', 'product_id')
            ->mapWithKeys(fn ($held, $product) => [(int) $product => bcadd((string) $held, '0', 4)])
            ->all();
    }

    /**
     * একটা লাইনের ছাড়ার অংশ — লাইনের বাকি, কিন্তু এই আদেশ নিজে যতটা ধরেছিল তার বেশি নয়।
     *
     * ⓘ একই পণ্য দুই লাইনে থাকলে ভাগটা একবারই খরচ হয় — দেওয়া অংশ হিসাব থেকে কমে।
     *
     * @param  array<int, string>  $own
     */
    private function ownShare(array &$own, SalesOrderLine $line): string
    {
        $pending = $line->pendingQty();
        $left = $own[(int) $line->product_id] ?? '0';
        $take = bccomp($pending, $left, 4) > 0 ? $left : $pending;

        if (bccomp($take, '0', 4) <= 0) {
            return '0';
        }

        $own[(int) $line->product_id] = bcsub($left, $take, 4);

        return $take;
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

            $product = Product::query()->find($productId);

            if ($productId <= 0 || $product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            // "২ বাক্স @ ৮০০" — পরিমাণ আর দর একসাথে পণ্যের এককে নামে
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            // ভ্যাট না পাঠালে পণ্যের নিজের হার থেকে গোনা — `?? '0'` লিখলে
            // "পাঠায়নি" আর "শূন্য বসিয়েছে" এক হয়ে যেত
            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);

            SalesOrderLine::create([
                'sales_order_id' => $order->id,
                'product_id' => $productId,
                'ordered_qty' => $qty,
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,
                'discount' => $figures['discount'],
                'tax' => $figures['tax'],
                'tax_variance' => $figures['tax_variance'],
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

    /**
     * ⛔ দুই ক্লিক, একই আদেশ — চূড়ান্ত অডিট (abos-8f-এর পড়া), ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ নিশ্চিত/বাতিলের অবস্থা দেখা হত হাতের পুরনো মডেলে, লেনদেনের বাইরে: দুই ক্লিকে প্রতিটা সারির মাল
     * দুইবার ধরা হত, আর বাতিলে দুইবার ছাড়া (Reserved ঋণাত্মক)। ⭐ এখন লেনদেনের প্রথম কাজ সারিতে তালা আর
     * অবস্থা তাজা পড়া ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
     */
    private function lockAndReread(SalesOrder $order): void
    {
        $fresh = SalesOrder::query()
            ->withoutGlobalScopes()
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $order->setRawAttributes($fresh->getAttributes(), true);
    }
}
