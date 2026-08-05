<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReceiptLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মাল বুঝে নেওয়া — কী সত্যিই এসেছে।
 *
 * ── এই ফাইলের একটাই কেন্দ্রীয় সিদ্ধান্ত ──────────────────────────────
 * স্টক আর হিসাব একই ট্রানজেকশনে বসে (প্ল্যান WP-0.3)। ইভেন্টে বা কিউতে
 * করলে একটা বসে অন্যটা ব্যর্থ হতে পারত, আর তখন গুদামে মাল থাকত অথচ খাতায়
 * থাকত না — কোনো ভুল বার্তা ছাড়াই। ওই অমিলটা ধরা পড়ত মাস শেষে, যখন আর
 * মনে নেই কোন চালানটা গোলমাল করেছিল।
 *
 * দায়টা এখনো সরবরাহকারীর নামে বসে না:
 *
 *     Dr  মজুদ পণ্য (1120)
 *     Cr  প্রাপ্ত মাল, বিল আসেনি (2160)
 *
 * কারণ ট্রাক আসে সোমবার আর বিল আসে বৃহস্পতিবার। ওই তিন দিন মালটা গুদামে
 * আছে, তার দাম আছে — কিন্তু কত টাকার বিল আসবে তা এখনো কাগজে নেই। বিলের
 * দিনে হিসাব বসালে ওই তিন দিন ব্যালেন্স শিট মিথ্যা বলত।
 */
final class PurchaseReceiptService
{
    use CalculatesLineTotals;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * চালান তৈরি — খসড়া, কিছুই নড়ে না।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseReceipt
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $order = $this->resolveOrder($data['purchase_order_id'] ?? null);
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? null);

            $documentNo = $this->numbers->next('GRN');

            $receipt = PurchaseReceipt::create([
                'company_id' => CompanyContext::id(),
                // চালানের শাখা গুদামের শাখা — মালটা যেখানে নামল সেখানেই
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $order?->supplier_id ?? $data['supplier_id'],
                'warehouse_id' => $warehouse->id,
                'purchase_order_id' => $order?->id,
                'trx_date' => $trxDate->toDateString(),
                'supplier_challan_no' => $data['supplier_challan_no'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($receipt, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => PurchaseReceipt::drillSourceType(),
                    'source_id' => $receipt->id,
                ]);

            return $receipt->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(PurchaseReceipt $receipt, array $data, array $lines): PurchaseReceipt
    {
        $this->assertEditable($receipt);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($receipt, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $receipt->trx_date);
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? $receipt->warehouse_id);

            $receipt->update([
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id ?? $receipt->branch_id,
                'trx_date' => $trxDate->toDateString(),
                'supplier_challan_no' => $data['supplier_challan_no'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($receipt, $lines);

            return $receipt->fresh(['lines']);
        });
    }

    /**
     * মাল বুঝে নেওয়া হলো — স্টক বাড়ে, দায় জন্মায়, একই ট্রানজেকশনে।
     */
    public function confirm(PurchaseReceipt $receipt): PurchaseReceipt
    {
        if ($receipt->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $receipt->document_no]),
            ]);
        }

        $receipt->loadMissing(['lines', 'warehouse']);

        if ($receipt->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($receipt) {
            foreach ($receipt->lines as $line) {
                /*
                 * প্রতিটা লাইনের জন্য একটা করে চলাচল, এবং source হিসেবে
                 * চালানের লাইনের id — চালানের id নয়।
                 *
                 * তাতে "এই ৪০ বস্তা কোথা থেকে এল" প্রশ্নের উত্তরে ঠিক ওই
                 * লাইনটাই দেখানো যায়, পুরো চালানটা নয়। এক চালানে একই পণ্য
                 * দুইবার থাকলে (দুই দরে) পার্থক্যটা তখনই ধরা পড়ে।
                 */
                $this->stock->move(
                    product: $line->product,
                    warehouse: $receipt->warehouse,
                    sourceType: PurchaseReceipt::STOCK_SOURCE,
                    sourceId: $receipt->id,
                    floor: (string) $line->received_qty,
                    date: $receipt->trx_date,
                    documentNo: $receipt->document_no,
                );
            }

            $this->postToLedger($receipt);

            $receipt->update(['status' => DocumentStatus::CONFIRMED]);

            return $receipt->fresh(['lines']);
        });
    }

    /**
     * বাতিল — স্টক ও খতিয়ান দুটোই উল্টো সারিতে ফেরে, মুছে নয়।
     *
     * "গতকাল স্টক কত ছিল" প্রশ্নের উত্তর থাকতে হবে (প্ল্যান: স্টক চলাচল
     * append-only)। সারি মুছে ফেললে গতকালের রিপোর্ট আজ বদলে যেত, আর কেন
     * বদলাল তার কোনো চিহ্ন থাকত না।
     */
    public function cancel(PurchaseReceipt $receipt, string $reason, Carbon|string|null $onDate = null): PurchaseReceipt
    {
        if ($receipt->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $receipt->document_no]),
            ]);
        }

        $receipt->loadMissing(['lines', 'warehouse']);

        $this->assertNotBilled($receipt);

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($receipt, $reason, $date) {
            if ($receipt->status === DocumentStatus::CONFIRMED) {
                foreach ($receipt->lines as $line) {
                    /*
                     * মাল ফেরত যাওয়ার আগে দেখা হয় সেটা এখনো তাকে আছে কি না।
                     *
                     * বেচা হয়ে গেলে ঋণাত্মক স্টক হত, আর ঋণাত্মক স্টক মানে
                     * এমন একটা গুদাম যেখানে মাইনাস পাঁচ বস্তা চাল আছে —
                     * সেটা দেখে কেউ বুঝত না কী করতে হবে। StockService
                     * নিজেই আটকায়, আর বার্তাটা ওখান থেকেই আসে।
                     */
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $receipt->warehouse,
                        sourceType: PurchaseReceipt::STOCK_SOURCE.':cancel',
                        sourceId: $receipt->id,
                        floor: bcmul((string) $line->received_qty, '-1', 4),
                        date: $date,
                        documentNo: $receipt->document_no,
                        narration: $reason,
                    );
                }

                $this->posting->reverse(
                    sourceType: PurchaseReceipt::drillSourceType(),
                    sourceId: $receipt->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $receipt->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $receipt->fresh(['lines']);
        });
    }

    /**
     * খতিয়ানে বসানো — মজুদ বাড়ল, অপেক্ষমাণ দায় জন্মাল।
     */
    private function postToLedger(PurchaseReceipt $receipt): void
    {
        $total = (string) $receipt->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.zero_value_receipt'),
            ]);
        }

        $inventory = $this->account(StandardChart::INVENTORY);
        $pending = $this->account(StandardChart::GOODS_RECEIVED_NOT_INVOICED);

        $this->posting->post(
            sourceType: PurchaseReceipt::drillSourceType(),
            sourceId: $receipt->id,
            trxDate: $receipt->trx_date,
            lines: [
                [
                    'account_id' => $inventory->id,
                    'debit' => $total,
                    'narration' => __('purchase::message.stock_in', ['no' => $receipt->document_no]),
                ],
                [
                    /*
                     * সরবরাহকারীর নাম এখানেই বসে, যদিও খাতটা তার প্রদেয় নয়।
                     *
                     * নাহলে ২১৬০ খাতটা খুলে দেখা যেত "মোট ৮০,০০০ ঝুলে আছে"
                     * অথচ কার কাছে তা জানা যেত না, আর তাগাদা দিতে হলে
                     * প্রতিটা চালান আলাদা করে খুলতে হত।
                     */
                    'account_id' => $pending->id,
                    'credit' => $total,
                    'party_type' => 'supplier',
                    'party_id' => $receipt->supplier_id,
                    'narration' => __('purchase::message.awaiting_bill', ['no' => $receipt->document_no]),
                ],
            ],
            documentNo: $receipt->document_no,
            branchId: $receipt->branch_id,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        $total = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['received_qty'] ?? null, 'received_qty');
            $rate = $this->money($line['rate'] ?? null);

            if ($productId <= 0 || ! Product::query()->whereKey($productId)->exists()) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_product')]);
            }

            $orderLine = $this->resolveOrderLine($receipt, $line['purchase_order_line_id'] ?? null, $productId, $qty);

            $amount = bcmul($qty, $rate, 4);

            PurchaseReceiptLine::create([
                'purchase_receipt_id' => $receipt->id,
                'product_id' => $productId,
                'purchase_order_line_id' => $orderLine?->id,
                'received_qty' => $qty,
                'rate' => $rate,
                'amount' => $amount,
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $total = bcadd($total, $amount, 4);
        }

        $receipt->update(['total' => $total]);
    }

    /**
     * আদেশের লাইনের সাথে মিলিয়ে দেখা — বেশি মাল নেওয়া যাবে কি না।
     */
    private function resolveOrderLine(
        PurchaseReceipt $receipt,
        mixed $orderLineId,
        int $productId,
        string $qty,
    ): ?PurchaseOrderLine {
        if ($receipt->purchase_order_id === null || blank($orderLineId)) {
            return null;
        }

        $orderLine = PurchaseOrderLine::query()
            ->where('purchase_order_id', $receipt->purchase_order_id)
            ->whereKey((int) $orderLineId)
            ->first();

        if ($orderLine === null) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.line_not_in_order')]);
        }

        if ((int) $orderLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.line_product_mismatch')]);
        }

        /*
         * আদেশের চেয়ে কতটুকু বেশি নেওয়া যাবে — সেটিংস থেকে (নিয়ম ৭)।
         *
         * শূন্য মানে এক কেজিও বেশি নয়। বাস্তবে বস্তায় ভরা মালে দুই-এক
         * শতাংশ এদিক-ওদিক হয়, আর প্রতিবার আদেশ সংশোধন করতে বললে গুদামের
         * লোক শেষে আদেশ ছাড়াই মাল নামাতে শুরু করেন — অর্থাৎ নিয়ন্ত্রণটা
         * বেশি কড়া করলে নিয়ন্ত্রণটাই উঠে যায়।
         */
        $allowance = (string) $this->settings->get('purchase.over_receipt_percent', 0);
        $ordered = (string) $orderLine->ordered_qty;
        $ceiling = bcadd($ordered, bcdiv(bcmul($ordered, $allowance, 4), '100', 4), 4);

        // এই চালানের নিজের লাইনগুলো এখনো লেখা হয়নি, তাই আগেরগুলো + এইটা
        $alreadyReceived = $orderLine->receiptLines()
            ->where('purchase_receipt_id', '<>', $receipt->id)
            ->whereHas('receipt', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('received_qty');

        $wouldBe = bcadd((string) ($alreadyReceived ?: '0'), $qty, 4);

        if (bccomp($wouldBe, $ceiling, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.over_receipt', [
                    'ordered' => rtrim(rtrim($ordered, '0'), '.'),
                    'total' => rtrim(rtrim($wouldBe, '0'), '.'),
                ]),
            ]);
        }

        return $orderLine;
    }

    private function resolveOrder(mixed $orderId): ?PurchaseOrder
    {
        $needsOrder = (bool) $this->settings->get('purchase.receipt_needs_order', false);

        if (blank($orderId)) {
            if ($needsOrder) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => __('purchase::validation.order_required'),
                ]);
            }

            return null;
        }

        $order = PurchaseOrder::query()->whereKey((int) $orderId)->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('purchase::validation.unknown_order'),
            ]);
        }

        // বাতিল বা খসড়া আদেশের বিপরীতে মাল নেওয়া যায় না: একটার মাল আর
        // আসার কথা নয়, অন্যটা এখনো কাউকে পাঠানোই হয়নি
        if ($order->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('purchase::validation.order_not_open', ['no' => $order->document_no]),
            ]);
        }

        return $order;
    }

    private function resolveWarehouse(mixed $warehouseId): Warehouse
    {
        $warehouse = blank($warehouseId)
            ? Warehouse::query()->where('is_default', true)->active()->first()
            : Warehouse::query()->whereKey((int) $warehouseId)->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('purchase::validation.unknown_warehouse'),
            ]);
        }

        return $warehouse;
    }

    /**
     * বিল হয়ে যাওয়া চালান বাতিল করা যায় না।
     *
     * করলে ২১৬০ খাতে একটা ঋণাত্মক অবশিষ্ট পড়ে থাকত: বিলটা ওখান থেকে টাকা
     * সরিয়েছে, অথচ যে সারিটা টাকাটা বসিয়েছিল সেটা আর নেই। আগে বিলটা
     * বাতিল করতে হবে — ক্রমটা উল্টো দিকে।
     */
    private function assertNotBilled(PurchaseReceipt $receipt): void
    {
        $billed = PurchaseReceiptLine::query()
            ->where('purchase_receipt_id', $receipt->id)
            ->whereHas('billLines.bill', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->exists();

        if ($billed) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.receipt_already_billed', ['no' => $receipt->document_no]),
            ]);
        }
    }

    private function assertEditable(PurchaseReceipt $receipt): void
    {
        if ($receipt->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $receipt->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            // ছকটা বসানো হয়নি — চুপচাপ অন্য খাতে বসানোর চেয়ে থেমে যাওয়া ভালো
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.missing_account', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('purchase::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
