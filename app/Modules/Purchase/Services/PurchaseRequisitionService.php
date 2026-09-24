<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseRequisition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * চাহিদা — লেখা, অনুমোদন, আর আদেশে রূপান্তর।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Approved PR থেকে RFQ অথবা PO তৈরি করা যাবে"*।
 *
 * ── ⚠️ তিনটা ধাপ, আর তিনটাই আলাদা সিদ্ধান্ত ─────────────────────────
 *   ১. **লেখা** — যিনি চান তিনি লেখেন; কিছুই প্রতিশ্রুত হয় না
 *   ২. **অনুমোদন** — এখানে সই লাগে, আর এখানেই বাজেটের আগে থামা যায়
 *   ৩. **রূপান্তর** — অনুমোদিত চাহিদা একটা ক্রয়াদেশ হয়ে যায়
 *
 * ⛔ আগে তিনটাই এক ছিল: ক্রয়াদেশ সরাসরি লেখা হত। ⓘ ফলে থামার কোনো
 * জায়গা ছিল না — যে মুহূর্তে কাগজটা লেখা হত, সেটাই প্রতিশ্রুতি।
 *
 * ── ⓘ সরবরাহকারীর নাম এখানে নেই, আর সেটা ইচ্ছাকৃত ──────────────────
 * ⚠️ যিনি চান তিনি জানেন **কী** লাগবে, ⛔ কিন্তু **কার কাছ থেকে** সেটা
 * ক্রয় বিভাগের সিদ্ধান্ত। ⓘ চাহিদায় সরবরাহকারীর ঘর রাখলে ঐ সিদ্ধান্তটা
 * নীরবে বিভাগের হাত থেকে বেরিয়ে যেত।
 */
final class PurchaseRequisitionService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * চাহিদা লেখা — কিছুই প্রতিশ্রুত হয় না।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseRequisition
    {
        $clean = $this->cleanLines($lines);

        return DB::transaction(function () use ($data, $clean) {
            $requisition = PurchaseRequisition::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('PR'),
                'trx_date' => Carbon::parse($data['trx_date'] ?? now())->toDateString(),
                'needed_by' => filled($data['needed_by'] ?? null)
                    ? Carbon::parse($data['needed_by'])->toDateString()
                    : null,
                'requested_by' => $data['requested_by'] ?? auth()->id(),
                'department' => $data['department'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($requisition, $clean);

            return $requisition->load('lines');
        });
    }

    /**
     * ⭐ অনুমোদন — আর এখানেই সই লাগে।
     *
     * ── ⚠️ অঙ্কটা আন্দাজি মোট, আর সেটাই ঠিক ─────────────────────────
     * ⓘ অনুমোদনের ছক টাকার অঙ্ক দেখে ধাপ ঠিক করে। ⛔ চাহিদায় আসল দাম
     * বলে কিছু নেই — ওটা জানা যাবে সরবরাহকারীর কাছ থেকে। ⚠️ তবু
     * আন্দাজটাই একমাত্র সংখ্যা যা **সিদ্ধান্তের আগে** পাওয়া যায়, আর
     * অনুমোদনের পুরো কথাই হলো সিদ্ধান্তের আগে থামা।
     *
     * ⓘ দর না বসানো সারিগুলো শূন্য ধরা হয় ([[PurchaseRequisition::estimatedTotal()]]);
     * ধরে-নেওয়া কোনো দর বসালে সীমাটাই মিথ্যা হত।
     */
    public function approve(PurchaseRequisition $requisition): PurchaseRequisition
    {
        if ($requisition->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_not_draft'),
            ]);
        }

        $requisition->loadMissing('lines');

        if ($requisition->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.requisition_needs_lines'),
            ]);
        }

        $this->approvals->assertClear(
            document: $requisition,
            module: 'purchase',
            action: 'requisition',
            field: 'status',
            amount: $requisition->estimatedTotal(),
            reason: $requisition->purpose,
        );

        $requisition->update(['status' => DocumentStatus::CONFIRMED]);

        return $requisition->fresh('lines');
    }

    /**
     * ⭐ অনুমোদিত চাহিদা → ক্রয়াদেশ।
     *
     * ── ⛔ একই চাহিদা থেকে দুইবার নয় ─────────────────────────────────
     * ⚠️ পারলে একই জিনিস **দুইবার কেনা** হত, আর ভুলটা ধরা পড়ত মাল এসে
     * গুদামে জায়গা না পাওয়ার দিনে। ⓘ শর্তটা মডেলেই লেখা
     * ([[PurchaseRequisition::canBecomeAnOrder()]]), কারণ পর্দাও ঐ একই
     * প্রশ্ন করে — বোতামটা দেখাবে কি না।
     *
     * ── ⚠️ আন্দাজি দরটা আদেশে যায় না ────────────────────────────────
     * ⛔ গেলে আন্দাজটাই একদিন দাম হয়ে বসত, আর সরবরাহকারী অন্য দর
     * চাইলে কেউ বুঝত না সংখ্যাটা কোথা থেকে এসেছিল। ⓘ আদেশের ফর্মে
     * দর বসাবেন ক্রয় বিভাগ, সরবরাহকারীর কথা শুনে।
     *
     * @param  array<string, mixed>  $data  supplier_id · warehouse_id · trx_date · expected_on
     */
    public function toOrder(PurchaseRequisition $requisition, array $data): PurchaseRequisition
    {
        if (! $requisition->canBecomeAnOrder()) {
            throw ValidationException::withMessages([
                'status' => $requisition->purchase_order_id !== null
                    ? __('purchase::validation.requisition_already_ordered', [
                        'no' => $requisition->order?->document_no ?? '',
                    ])
                    : __('purchase::validation.requisition_not_approved'),
            ]);
        }

        $requisition->loadMissing('lines.product');

        return DB::transaction(function () use ($requisition, $data) {
            $order = $this->orders->create(
                [
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'expected_on' => $data['expected_on']
                        ?? $requisition->needed_by?->toDateString(),
                    'narration' => __('purchase::message.order_from_requisition', [
                        'no' => $requisition->document_no,
                    ]),
                ],
                /*
                 * ⛔ ঘরটার নাম `ordered_qty`, `qty` নয় — ২৪ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ `qty` পাঠানো হয়েছিল, আর [[PurchaseOrderService]]
                 * পড়ে `$line['ordered_qty'] ?? null` — অর্থাৎ পরিমাণটা
                 * নীরবে `null` হয়ে যেত, আর ব্যবহারকারী দেখতেন
                 * *"সংখ্যার ঘরে সংখ্যা দিন"*, অথচ তিনি কোনো ঘরেই হাত
                 * দেননি: সংখ্যাটা চাহিদা থেকে আপনাআপনি আসার কথা।
                 *
                 * ⓘ আদেশের পর্দায় ঘরটার নাম `ordered_qty` কারণ ওখানে
                 * দুই রকম পরিমাণ থাকে — যতটা চাওয়া হলো আর যতটা এল।
                 */
                $requisition->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'ordered_qty' => (string) $line->qty,

                    /*
                     * ⛔ দর শূন্য — আন্দাজটা এখানে আসে না, আর আসা
                     * উচিতও নয়। ⓘ ক্রয় বিভাগ আদেশের পর্দায় দর বসাবেন।
                     */
                    'rate' => '0',
                ])->all(),
            );

            $requisition->update([
                'status' => DocumentStatus::CLOSED,
                'purchase_order_id' => $order->id,
            ]);

            return $requisition->fresh(['lines', 'order']);
        });
    }

    /**
     * বাতিল — খসড়া ও অনুমোদিত দুইটাই, কিন্তু রূপান্তরিতটা নয়।
     *
     * ⛔ আদেশ হয়ে যাওয়ার পর চাহিদাটা বাতিল করা মানে কাগজের গল্পে একটা
     * ফাঁক: আদেশটা থেকে যায়, অথচ যে কারণে সেটা জন্মেছিল তা নেই।
     * ⓘ তখন বাতিল করার জায়গা আদেশটাই।
     */
    public function cancel(PurchaseRequisition $requisition, string $reason): PurchaseRequisition
    {
        if ($requisition->purchase_order_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_already_ordered', [
                    'no' => $requisition->order?->document_no ?? '',
                ]),
            ]);
        }

        if ($requisition->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_already_cancelled'),
            ]);
        }

        $requisition->update([
            'status' => DocumentStatus::CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $requisition->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(PurchaseRequisition $requisition, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $requisition->lines()->create([
                'company_id' => CompanyContext::id(),

                /*
                 * ⓘ `line_no` একের থেকে গোনা — ⚠️ শূন্য থেকে শুরু করলে
                 * কাগজে "০ নম্বর সারি" ছাপা হত, আর কেউ ওভাবে গোনে না।
                 */
                'line_no' => $i + 1,
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'estimated_rate' => $line['estimated_rate'] ?? null,
                'narration' => $line['narration'] ?? null,
            ]);
        }
    }

    /**
     * খালি সারি বাদ, আর একই পণ্য দুইবার আটকানো।
     *
     * ── ⚠️ একই পণ্য দুইবার কেন আটকানো ───────────────────────────────
     * ⓘ চাহিদায় ইউনিক শর্ত নেই — একই পণ্য দুইটা আলাদা কারণে চাওয়া
     * যেতেই পারে। ⛔ কিন্তু বাস্তবে ওটা প্রায় সবসময়ই একটা ভুল: মানুষ
     * সারিটা দুইবার বসিয়ে ফেলেন, আর পরিমাণটা দ্বিগুণ হয়ে যায়।
     *
     * ⭐ তাই আটকানো হয়, আর বার্তায় পণ্যটার নাম বলা হয় — যাতে মানুষ
     * বুঝতে পারেন কোনটা মেলাতে হবে।
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function cleanLines(array $lines): array
    {
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $qty = (string) ($line['qty'] ?? '');

            if (blank($productId) || $qty === '') {
                continue;
            }

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.requisition_qty_positive'),
                ]);
            }

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.requisition_duplicate_product', [
                        'product' => Product::query()->find($productId)?->name() ?? '',
                    ]),
                ]);
            }

            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'estimated_rate' => filled($line['estimated_rate'] ?? null)
                    ? (string) $line['estimated_rate']
                    : null,
                'narration' => $line['narration'] ?? null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.requisition_needs_lines'),
            ]);
        }

        return $out;
    }
}
