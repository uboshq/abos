<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় আদেশ — কী আনতে বলেছি।
 *
 * এই সেবাটা ইচ্ছাকৃতভাবে সবচেয়ে সরল: কোনো স্টক নড়ে না, কোনো খতিয়ান বসে
 * না। আদেশ একটা অভিপ্রায়, আর অভিপ্রায়ের হিসাব হয় না। মাল আসার আগেই দায়
 * বসালে সরবরাহকারী মাল না পাঠালে ওই দায়টা কেউ সরাত না — আর মাসের শেষে
 * প্রদেয়ের তালিকায় এমন টাকা থাকত যা কেউ কোনোদিন চায়নি।
 */
final class PurchaseOrderService
{
    use CalculatesLineTotals;
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseOrder
    {
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            // নম্বরটা ট্রানজেকশনের ভেতরে — সেভ ব্যর্থ হলে নম্বরও ফিরে যায়,
            // নাহলে প্রতিটা ব্যর্থ চেষ্টায় সিরিজে একটা ফাঁক থাকত
            $documentNo = $this->numbers->next('PO');

            $order = PurchaseOrder::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'expected_on' => $data['expected_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($order, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => PurchaseOrder::drillSourceType(),
                    'source_id' => $order->id,
                ]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(PurchaseOrder $order, array $data, array $lines): PurchaseOrder
    {
        $this->assertEditable($order);
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($order, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $order->trx_date);

            $order->update([
                'supplier_id' => $data['supplier_id'] ?? $order->supplier_id,
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id,
                'trx_date' => $trxDate->toDateString(),
                'expected_on' => $data['expected_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($order, $lines);

            return $order->fresh(['lines']);
        });
    }

    /**
     * আদেশটা পাঠানো হলো — এখন এর বিপরীতে মাল নেওয়া যাবে।
     *
     * খতিয়ানে কিছুই বসে না। "নিশ্চিত" মানে শুধু এটুকু: খসড়া নয়, তাই আর
     * মুক্তভাবে বদলানো যাবে না, আর গুদামের লোক এর বিপরীতে মাল বুঝে নিতে
     * পারবেন।
     */
    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $order->document_no]),
            ]);
        }

        if ($order->lines()->count() === 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.no_lines'),
            ]);
        }

        /*
         * অনুমোদন লাগে কি না — ছক বসানো না থাকলে কিছুই বদলায় না।
         *
         * ⚠️ পাহারাটা সেবার ভেতরে, কন্ট্রোলারে নয়: এই মেথডটা পর্দা ছাড়াও
         * ডাকা হতে পারে (ইমপোর্ট · কমান্ড · ভবিষ্যতের API), আর কেবল
         * একটা দরজায় পাহারা বসানো মানে বাকিগুলো খোলা রাখা।
         */
        $this->approvals->assertClear(
            document: $order,
            module: 'purchase',
            action: 'order',
            field: 'status',
            amount: (string) $order->total,
            reason: $order->narration,
        );

        $order->update(['status' => DocumentStatus::CONFIRMED]);

        return $order->fresh(['lines']);
    }

    /**
     * বাতিল — সারিটা থাকে, শুধু অবস্থা বদলায় (নিয়ম ৫)।
     *
     * যে আদেশের মাল আংশিক এসে গেছে সেটাও বাতিল করা যায়: বাকিটা আর আসবে
     * না, এটুকুই বলা হচ্ছে। যা এসে গেছে তা স্টকে ও খাতায় আছেই, আর সেটা
     * এখানে ছোঁয়া হয় না — ছুঁলে গুদামের মাল কাগজে-কলমে উবে যেত।
     */
    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if ($order->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $order->document_no]),
            ]);
        }

        $order->update([
            'status' => DocumentStatus::CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $order->fresh(['lines']);
    }

    /**
     * সব লাইন নতুন করে বসানো।
     *
     * পুরনোগুলো মুছে নতুন লেখা হয় — খসড়ায় এটা নিরাপদ, কারণ ওই লাইনগুলোর
     * উপর কোনো চালান বা খতিয়ান ভর করে নেই। নিশ্চিত হওয়ার পর আর বদলানো
     * যায় না, আর সেটাই assertEditable() আটকায়।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseOrder $order, array $lines): void
    {
        $order->lines()->delete();

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['ordered_qty'] ?? null, 'ordered_qty');
            $rate = $this->money($line['rate'] ?? null);

            $this->assertProductExists($productId);

            $product = Product::query()->findOrFail($productId);

            // "২ বাক্স @ ৮০০" — পরিমাণ আর দর একসাথে পণ্যের এককে নামে
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            // ভ্যাট না পাঠালে পণ্যের নিজের হার থেকে গোনা
            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);

            PurchaseOrderLine::create([
                'purchase_order_id' => $order->id,
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

    private function assertEditable(PurchaseOrder $order): void
    {
        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $order->document_no]),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertHasLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.no_lines'),
            ]);
        }
    }

    private function assertProductExists(int $productId): void
    {
        // গ্লোবাল স্কোপ থাকায় এই কোয়েরিটা নিজের কোম্পানির বাইরে যায় না —
        // অন্য কোম্পানির পণ্যের id পাঠালে এখানেই আটকায়
        if ($productId <= 0 || ! Product::query()->whereKey($productId)->exists()) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.unknown_product'),
            ]);
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
                'trx_date' => __('purchase::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
