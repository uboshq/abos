<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Models\Quotation;
use App\Modules\Purchase\Models\Rfq;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * দরপত্রের অনুরোধ — কী চাই, কাকে জিজ্ঞেস করব, আর কে কী বলল।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"RFQ তৈরি করে একাধিক supplier-এর কাছে quotation request পাঠানো যাবে"*।
 *
 * ── ⚠️ তিনটা ধাপ ────────────────────────────────────────────────────
 *   ১. **লেখা** — কী চাই, আর কাকে কাকে জিজ্ঞেস করব
 *   ২. **পাঠানো** — এরপর আর পণ্য বা সরবরাহকারী বদলানো যায় না
 *   ৩. **দর লেখা** — যাঁরা জবাব দিলেন
 *
 * ⛔ দ্বিতীয় ধাপের পর তালিকা বদলানো নিষেধ, আর কারণটা সোজা: ⚠️ পাঠানোর
 * পর কেউ একজন সরবরাহকারী **বাদ দিলে** ইতিহাস বলত তাঁকে জিজ্ঞেসই করা
 * হয়নি — অথচ তাঁর জবাবটা হয়তো সবচেয়ে কম দরের ছিল।
 */
final class RfqService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * অনুরোধ লেখা — এখনো কাউকে পাঠানো হয়নি।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<int|string>  $supplierIds
     */
    public function create(array $data, array $lines, array $supplierIds): Rfq
    {
        $clean = $this->cleanLines($lines);
        $suppliers = $this->cleanSuppliers($supplierIds);

        return DB::transaction(function () use ($data, $clean, $suppliers) {
            // ⛔ মাল যে গুদামে নামবে তার শাখায়, নম্বরও সেই শাখার সিরিজে — RCV-0143 / মালিক: *"কোনোভাবেই অন্য শাখায় নয়"* (ec, ১৯ অক্টোবর ২০২৬)
            $branchId = (filled($data['warehouse_id'] ?? null) ? \App\Modules\Inventory\Models\Warehouse::query()->withoutGlobalScopes()
                ->where('company_id', CompanyContext::id())->whereKey((int) $data['warehouse_id'])->value('branch_id') : null)
                ?? CompanyContext::branchId();
            $branchId = $branchId === null ? null : (int) $branchId;

            $rfq = Rfq::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'document_no' => $this->numbers->next('RFQ', $branchId),
                'trx_date' => Carbon::parse($data['trx_date'] ?? now())->toDateString(),
                'respond_by' => filled($data['respond_by'] ?? null)
                    ? Carbon::parse($data['respond_by'])->toDateString()
                    : null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'terms' => $data['terms'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($clean as $i => $line) {
                $rfq->lines()->create([
                    'company_id' => CompanyContext::id(),
                    'line_no' => $i + 1,
                    'product_id' => $line['product_id'],
                    'qty' => $line['qty'],
                    'specification' => $line['specification'] ?? null,
                ]);
            }

            /*
             * ⛔ পিভটেও `company_id` — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ `sync()` কেবল দুইটা বিদেশি চাবি বসায়, আর
             * `pur_rfq_suppliers.company_id` NOT NULL ⓘ (এই রিপোর
             * প্রতিটা টেবিলের মতোই — কোম্পানির সীমানা ছকেই বাঁধা)।
             * ⛔ ফল: *"কাকে কাকে জিজ্ঞেস করেছি"* সারিটা বসতই না, আর
             * প্রতিটা RFQ তৈরি ৫০০ দিত।
             *
             * ⓘ কোম্পানিটা RFQ-রটাই — প্রসঙ্গ থেকে নয়: প্রসঙ্গ বদলে
             * যেতে পারে, কিন্তু সারিটা যে কাগজের সাথে বাঁধা সেটা নয়।
             */
            $rfq->suppliers()->sync(
                collect($suppliers)
                    ->mapWithKeys(fn ($id) => [(int) $id => ['company_id' => $rfq->company_id]])
                    ->all(),
            );

            return $rfq->load(['lines', 'suppliers']);
        });
    }

    /**
     * ⭐ পাঠানো — আর এরপর তালিকা বদলানো যায় না।
     *
     * ⛔ পাঠানোর পর একজন সরবরাহকারী বাদ দিলে ইতিহাস বলত তাঁকে
     * জিজ্ঞেসই করা হয়নি — ⚠️ অথচ তাঁর জবাবটা হয়তো সবচেয়ে কম দরের
     * ছিল, আর তখন *"সবচেয়ে কম দর নেওয়া হয়েছিল"* দাবিটা মিথ্যা হত।
     */
    public function send(Rfq $rfq): Rfq
    {
        if ($rfq->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.rfq_already_sent'),
            ]);
        }

        $rfq->loadMissing(['lines', 'suppliers']);

        if ($rfq->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.rfq_needs_lines'),
            ]);
        }

        if ($rfq->suppliers->isEmpty()) {
            throw ValidationException::withMessages([
                'suppliers' => __('purchase::validation.rfq_needs_suppliers'),
            ]);
        }

        return DB::transaction(function () use ($rfq) {
            $today = now()->toDateString();

            foreach ($rfq->suppliers as $supplier) {
                $rfq->suppliers()->updateExistingPivot($supplier->id, ['sent_on' => $today]);
            }

            $rfq->update(['status' => DocumentStatus::CONFIRMED]);

            return $rfq->fresh(['lines', 'suppliers']);
        });
    }

    /**
     * ⭐ একজন সরবরাহকারীর দর লেখা।
     *
     * ── ⚠️ একই RFQ-তে একজনের একটাই দর ─────────────────────────────────
     * ⛔ দুইটা থাকলে তুলনায় একজনই দুইবার বসতেন, আর *"সবচেয়ে কম"*
     * হিসাবটা তাঁর দুইটা দরের মধ্যেই আটকে যেত। ⓘ দর বদলালে পুরনোটা
     * বাতিল করে নতুন লেখা হয় — আর সেটাই দর-কষাকষির ইতিহাস।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function quote(array $data, array $lines): Quotation
    {
        $clean = $this->cleanQuoteLines($lines);

        $rfqId = $data['rfq_id'] ?? null;
        $supplierId = $data['supplier_id'] ?? null;

        if ($rfqId !== null && $this->alreadyQuoted((int) $rfqId, (int) $supplierId)) {
            throw ValidationException::withMessages([
                'supplier_id' => __('purchase::validation.quotation_already_given'),
            ]);
        }

        /*
         * ⛔ দরপত্রের দর কেবল আমন্ত্রিতের, আর কেবল দরপত্রের পণ্যে — fe, ১৩ অক্টোবর ২০২৬ ([[TheCheapestQuoteWasNotForTheWholeListTest]])।
         * ⚠️ আগে যে কোনো সরবরাহকারীর দর বসত, যে কোনো পণ্যে — তুলনায় অনিমন্ত্রিত বা অন্য মালের দরও "সবচেয়ে কম" হতে পারত।
         */
        if ($rfqId !== null) {
            $rfq = Rfq::acrossBranches()->with(['lines', 'suppliers'])->find((int) $rfqId);

            if ($rfq !== null && $rfq->suppliers->isNotEmpty() && ! $rfq->suppliers->contains('id', (int) $supplierId)) {
                throw ValidationException::withMessages(['supplier_id' => __('purchase::validation.quotation_not_invited')]);
            }

            $asked = $rfq?->lines->pluck('product_id')->map(fn ($id) => (int) $id)->all() ?? [];

            foreach ($clean as $line) {
                if ($asked !== [] && ! in_array((int) $line['product_id'], $asked, true)) {
                    throw ValidationException::withMessages(['lines' => __('purchase::validation.quotation_product_not_asked')]);
                }
            }
        }

        return DB::transaction(function () use ($data, $clean, $rfqId, $supplierId) {
            /*
             * ⛔ দরপত্রের সারিতে তালা, তারপর "আগেই দর দিয়েছেন?" আবার — Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[TwoQuotesFromOneSupplierTest]])।
             * ⚠️ উপরের দেখা তালা ছাড়া: দুই ক্লিকে দুজনেই "দেননি" দেখতেন, একজনের দুটো দর বসত — ঠিক যা উপরের নিয়ম নিষেধ করে।
             */
            if ($rfqId !== null) {
                Rfq::acrossBranches()->whereKey((int) $rfqId)->lockForUpdate()->first();

                if ($this->alreadyQuoted((int) $rfqId, (int) $supplierId)) {
                    throw ValidationException::withMessages([
                        'supplier_id' => __('purchase::validation.quotation_already_given'),
                    ]);
                }
            }

            // ⛔ দর তার দরপত্রের শাখায়, নম্বরও সেই শাখার সিরিজে (RCV-0143, ec, ১৯ অক্টোবর ২০২৬); দরপত্র ছাড়া দরে আগের মতো
            $branchId = ($rfqId === null ? null : Rfq::acrossBranches()->whereKey((int) $rfqId)->value('branch_id'))
                ?? CompanyContext::branchId();
            $branchId = $branchId === null ? null : (int) $branchId;

            $quotation = Quotation::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'document_no' => $this->numbers->next('QT', $branchId),
                'rfq_id' => $rfqId,
                'supplier_id' => $supplierId,
                'supplier_quote_no' => $data['supplier_quote_no'] ?? null,
                'quoted_on' => Carbon::parse($data['quoted_on'] ?? now())->toDateString(),
                'valid_until' => filled($data['valid_until'] ?? null)
                    ? Carbon::parse($data['valid_until'])->toDateString()
                    : null,
                'delivery_days' => $data['delivery_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'freight' => $data['freight'] ?? '0',
                'other_charges' => $data['other_charges'] ?? '0',
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::CONFIRMED,
                'created_by' => auth()->id(),
            ]);

            foreach ($clean as $i => $line) {
                $quotation->lines()->create([
                    'company_id' => CompanyContext::id(),
                    'line_no' => $i + 1,
                    'product_id' => $line['product_id'],
                    'qty' => $line['qty'],
                    'rate' => $line['rate'],
                    'discount' => $line['discount'] ?? '0',
                    'tax' => $line['tax'] ?? '0',
                    'narration' => $line['narration'] ?? null,
                ]);
            }

            return $quotation->load(['lines', 'supplier']);
        });
    }

    /**
     * ⭐ তুলনা — প্রত্যেকের মোট, দিন আর শর্ত পাশাপাশি।
     *
     * ── ⛔ এই পদ্ধতিটা কাউকে বাছে না, আর বাছবেও না ────────────────────
     * ⚠️ সবচেয়ে কম দরটা চিহ্নিত করা হয়, কিন্তু *"এটাই নেওয়া হোক"*
     * বলা হয় না। ⓘ কারণ সস্তা মানেই সেরা নয়: একজন কম দর বলেন আর
     * ত্রিশ দিনে মাল দেন, আরেকজন একটু বেশি বলেন আর তিন দিনে দেন।
     *
     * ⭐ সংখ্যাগুলো পাশাপাশি রাখা আমাদের কাজ; সিদ্ধান্তটা মানুষের।
     *
     * @return list<array<string, mixed>>
     */
    public function compare(Rfq $rfq): array
    {
        $rfq->loadMissing(['lines', 'quotations.lines', 'quotations.supplier']);

        $rows = $rfq->quotations
            ->map(fn (Quotation $quotation) => [
                'quotation' => $quotation,
                'supplier' => $quotation->supplier,
                'goods' => $quotation->goodsTotal(),
                'freight' => (string) $quotation->freight,
                'other' => (string) $quotation->other_charges,
                'total' => $quotation->grandTotal(),
                'delivery_days' => $quotation->delivery_days,
                'payment_terms' => $quotation->payment_terms,

                /*
                 * ⚠️ মেয়াদ পেরোনো দর তালিকা থেকে **বাদ যায় না**,
                 * কেবল দাগানো হয়। ⛔ বাদ দিলে ইতিহাসটাই অসম্পূর্ণ হত,
                 * আর মেয়াদ পেরোনো একটা দরও দরাদরির ভিত্তি হতে পারে।
                 */
                'expired' => ! $quotation->isStillGood(),

                /*
                 * ⛔ দরপত্রের সব পণ্যে দর দিয়েছেন কি না — fe, ১৩ অক্টোবর ২০২৬ ([[TheCheapestQuoteWasNotForTheWholeListTest]])। ⚠️ দশ পণ্যের
                 * দরপত্রে একটা সস্তা লাইন দিলে মোট সবচেয়ে কম দেখাত, আর তিনিই "সেরা" দাগ পেতেন। ⓘ অসম্পূর্ণ দর তালিকায় থাকে, দাগ পায় না।
                 */
                'complete' => $rfq->lines->pluck('product_id')->map(fn ($id) => (int) $id)->unique()
                    ->diff($quotation->lines->pluck('product_id')->map(fn ($id) => (int) $id))->isEmpty(),
            ])
            /*
             * ⛔ `(float)` নয় — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ এখানে সংখ্যাটা কেবল **সাজানোর** জন্য লাগত, যোগ-বিয়োগে
             * নয়, তাই float-টা নিরীহ মনে হয়েছিল। ⚠️ কিন্তু পাহারাটা
             * ([[MoneyIsNeverAFloatTest]]) ঠিক এই যুক্তিটাই আটকায়:
             * প্রতিটা float-ই কারও কাছে একবার নিরীহ ছিল, আর পরেরজন
             * ঐ লাইনটা নকল করে যোগ করতে বসেন।
             *
             * ⛔ বড় দরে পার্থক্যটা কাল্পনিক নয়: দুইটা দর ১২,৩৪,৫৬৭.৮৯
             * আর ১২,৩৪,৫৬৭.৮৮ হলে float-এ ওরা সমান হয়ে যেতে পারে, আর
             * তখন "সবচেয়ে কম কে" প্রশ্নের উত্তর ক্রম ধরে বদলাত।
             */
            ->sort(fn (array $a, array $b) => bccomp($a['total'], $b['total'], 4))
            ->values()
            ->all();

        /*
         * ⓘ সবচেয়ে কমটা দাগানো — কিন্তু কেবল **টেকা** দরগুলোর মধ্যে।
         * ⚠️ মেয়াদ পেরোনো একটা দর সবচেয়ে কম হলে ওটাকে "সেরা" দাগালে
         * মানুষ ওটাই নিতেন, আর সরবরাহকারী বলতেন *"ওটা তো পুরনো দর"*।
         */
        $cheapest = null;

        foreach ($rows as $i => $row) {
            if ($row['expired'] || ! $row['complete']) {
                continue;
            }

            if ($cheapest === null || bccomp($row['total'], $rows[$cheapest]['total'], 4) < 0) {
                $cheapest = $i;
            }
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['lowest'] = $i === $cheapest;
        }

        return $rows;
    }

    /**
     * অনুমোদিত চাহিদা থেকে RFQ — সারিগুলো নিয়ে।
     *
     * ⓘ আন্দাজি দর আসে না, ⚠️ কারণ RFQ-র পুরো কথাই হলো দর **জিজ্ঞেস
     * করা**। ⛔ আন্দাজটা পাঠিয়ে দিলে সরবরাহকারী ওটাই বলতেন।
     */
    public function fromRequisition(PurchaseRequisition $requisition, array $supplierIds): Rfq
    {
        $requisition->loadMissing('lines');

        return $this->create(
            [
                'trx_date' => now()->toDateString(),
                'respond_by' => $requisition->needed_by?->toDateString(),
                'purchase_requisition_id' => $requisition->id,
                'narration' => __('purchase::message.rfq_from_requisition', [
                    'no' => $requisition->document_no,
                ]),
            ],
            $requisition->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'qty' => (string) $line->qty,
            ])->all(),
            $supplierIds,
        );
    }

    private function alreadyQuoted(int $rfqId, int $supplierId): bool
    {
        return Quotation::query()
            ->where('rfq_id', $rfqId)
            ->where('supplier_id', $supplierId)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();
    }

    /**
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
                    'lines' => __('purchase::validation.rfq_qty_positive'),
                ]);
            }

            /*
             * ⛔ একই পণ্য দুইবার নয় — ⚠️ সরবরাহকারী তখন বুঝতেন না
             * কোন সারির জন্য কোন দর দিতে হবে, আর তুলনাটাও ভাঙত।
             */
            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.rfq_duplicate_product'),
                ]);
            }

            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'specification' => $line['specification'] ?? null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.rfq_needs_lines'),
            ]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function cleanQuoteLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $qty = (string) ($line['qty'] ?? '');
            $rate = (string) ($line['rate'] ?? '');

            if (blank($productId) || $qty === '' || $rate === '') {
                continue;
            }

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.rfq_qty_positive'),
                ]);
            }

            /*
             * ⓘ দর শূন্য হতে পারে — ⚠️ সরবরাহকারী কখনো একটা পণ্য
             * ফ্রি দেন, আর সেটা একটা বৈধ দর। ⛔ ঋণাত্মক নয়।
             */
            if (! is_numeric($rate) || bccomp($rate, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.quotation_rate_negative'),
                ]);
            }

            /*
             * ⛔ ছাড় ঋণাত্মক নয়, আর লাইনের মোটের বেশি নয় — fe, ১৩ অক্টোবর ২০২৬ ([[TheCheapestQuoteWasNotForTheWholeListTest]])। ⚠️ আগে
             * ১ × ১০০-তে ৫০০ ছাড় বসত, নিট −৪০০, আর ঐ দরই "সবচেয়ে কম"।
             */
            $discount = (string) ($line['discount'] ?? '0');
            $discount = $discount === '' ? '0' : $discount;

            if (! is_numeric($discount) || bccomp($discount, '0', 4) < 0 || bccomp($discount, bcmul($qty, $rate, 4), 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.quotation_discount_beyond_line'),
                ]);
            }

            $out[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'rate' => $rate,
                'discount' => $discount,
                'tax' => $line['tax'] ?? '0',
                'narration' => $line['narration'] ?? null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.quotation_needs_lines'),
            ]);
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function cleanSuppliers(array $ids): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0,
        )));

        if ($clean === []) {
            throw ValidationException::withMessages([
                'suppliers' => __('purchase::validation.rfq_needs_suppliers'),
            ]);
        }

        return $clean;
    }
}
