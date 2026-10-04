<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় ফেরত — মাল গ্রাহকের কাছ থেকে ফিরে এসেছে।
 *
 * ── চারটা দাখিলা, দুই জোড়ায় ─────────────────────────────────────────
 *     Dr  বিক্রয় ফেরত (4110)      ← আয় কমে, কিন্তু আলাদা খাতে
 *     Dr  ভ্যাট (2120)             ← সরবরাহ ভ্যাটও ফেরত
 *     Cr  প্রাপ্য হিসাব (1110)     ← গ্রাহকের পাওনা কমে
 *
 *     Dr  মজুদ পণ্য (1120)         ← মাল গুদামে ফিরল
 *     Cr  বিক্রীত পণ্যের ব্যয় (5100)
 *
 * ── কেন বিক্রয় খাতে সরাসরি ডেবিট নয় ────────────────────────────────
 * ৪১০০-এ ডেবিট বসালে মোট বিক্রয়ের অঙ্কটাই ছোট হয়ে যেত, আর "এই মাসে
 * কত বেচলাম, তার কতটা ফেরত এল" প্রশ্নের উত্তর হারাত। ফেরত আলাদা খাতে
 * থাকলে দুইটাই দেখা যায় — আর ফেরতের হার বেড়ে গেলে সেটা চোখে পড়ে।
 *
 * ── নষ্ট মাল আবার বিক্রি হয়ে যাবে না ────────────────────────────────
 * লাইনে "to_hold" থাকলে মালটা গুদামে ঢোকে কিন্তু একই সাথে Hold-এ যায়,
 * তাই বিক্রয়যোগ্য হয় না। এটা না থাকলে ফেরত আসা নষ্ট মাল পরদিন আবার
 * কারও কাছে চলে যেত।
 */
final class SalesReturnService
{
    use ReadsPackedQuantities;
    // ⓘ বিল ছাড়া ফেরতের ভ্যাট বিলের একই নিয়মে — হাতে লেখা নয় ([[replaceLines()]])
    use CalculatesSalesLines;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly DocumentApproval $approvals,

        // ⭐ ফেরতের কারণ — NEXUS §২৪; সব পথ (পর্দা, কাউন্টার, সেবা) এই এক দরজায়
        private readonly SalesReturnReasonGuard $reasons,

        // ⭐ ফেরত তার বিলে বাঁধা — গ্রাহক, সারি, লট, ফ্রি মাল (২৭ সেপ্টেম্বর ২০২৬)
        private readonly SalesReturnBillGuard $bills,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): SalesReturn
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        [$data, $lines] = $this->reasons->check($data, $lines);
        $lines = $this->bills->bind($data, $lines);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            /*
             * ⭐ ফেরতের নিজের নম্বর, বিক্রির নম্বর কেবল সূত্র — মালিক, ২৯ সেপ্টেম্বর ২০২৬:
             * *"Ferote sales id no ref hisebe use hobe but return id alada hobe"*। ⓘ চালান, গেট
             * পাস, বিল এক নম্বর ([[SaleNumber]]); ফেরত আলাদা কাগজ, আলাদা সারি (SR)।
             */
            $saleNo = isset($data['sales_invoice_id'])
                ? SalesInvoice::query()->whereKey($data['sales_invoice_id'])->value('sale_no')
                : null;
            $documentNo = $this->numbers->next('SR');

            $return = SalesReturn::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'sale_no' => $saleNo,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? null)->id,
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'reason_note' => $data['reason_note'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($return, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => SalesReturn::drillSourceType(),
                    'source_id' => $return->id,
                ]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesReturn $return, array $data, array $lines): SalesReturn
    {
        $this->assertEditable($return);

        [$data, $lines] = $this->reasons->check($data, $lines);

        // ⓘ গ্রাহক সম্পাদনায় বদলায় না — তাই কাগজের নিজের গ্রাহক ধরেই মেলানো
        $lines = $this->bills->bind(['customer_id' => $return->customer_id] + $data, $lines, $return->id);

        return DB::transaction(function () use ($return, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $return->trx_date);

            $return->update([
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? $return->warehouse_id)->id,
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'reason_note' => $data['reason_note'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($return, $lines);

            return $return->fresh(['lines']);
        });
    }

    public function confirm(SalesReturn $return): SalesReturn
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $return->document_no]),
            ]);
        }

        $this->assertReadyToConfirm($return);

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কেন ফেরত সই চায় ──────────────────────────────────────
         * ফেরতে **দুইটা জিনিস একসাথে ঘটে**: মাল গুদামে ফেরে আর গ্রাহকের
         * দেনা কমে। ⚠️ অর্থাৎ একটা মিথ্যা ফেরত দিয়ে বিক্রি মুছে ফেলা
         * যায়, আর খাতা দেখে বোঝার উপায় থাকে না — কাগজে সব ঠিক।
         *
         * ⓘ অঙ্কটা পাঠানো হয়, তাই কোম্পানি চাইলে "দুই হাজারের উপরে সই"
         * বসাতে পারে — ছোট ফেরত রোজকার কাজ, ওটা থামানোর মানে নেই।
         *
         * ⚠️ ছক না বসানো পর্যন্ত কিছুই বদলায় না: `assertClear()` চুপচাপ
         * ফিরে যায় আর ফেরত আগের মতোই নিশ্চিত হয়।
         */
        $this->approvals->assertClear(
            document: $return,
            module: 'sales',
            action: 'return',
            field: 'status',
            amount: (string) $return->total,
            reason: $return->narration,
        );

        return DB::transaction(function () use ($return) {
            // ⛔ দ্বিতীয় ক্লিক বা একই বিলে আরেক ফেরত — তালার ভিতরে সব আবার মাপা ([[lockAndReread()]])
            $this->lockAndReread($return, andTheBill: true);

            if ($return->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_confirms', ['no' => $return->document_no]),
                ]);
            }

            $this->bills->checkDocument($return);

            foreach ($return->lines as $line) {
                $this->assertWithinSold($line);
            }

            foreach ($return->lines as $line) {
                $this->freeBack($return, $line, '1');

                if (bccomp((string) $line->qty, '0', 4) <= 0) {
                    continue;
                }

                /*
                 * মাল তাকে ফেরে, আর নষ্ট হলে একই সাথে আটকে যায়।
                 *
                 * দুইটা আলাদা সারি নয়, একটাই: floor বাড়ে, আর hold-ও
                 * বাড়ে। ফলে গুদামে গুনলে মালটা পাওয়া যায় (যা সত্যি),
                 * অথচ বিক্রয়যোগ্য হিসাবে আসে না (যেটাও সত্যি)।
                 */
                $this->stock->move(
                    product: $line->product,
                    warehouse: $return->warehouse,
                    sourceType: SalesReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    floor: (string) $line->qty,
                    hold: $line->to_hold ? (string) $line->qty : '0',
                    // লাইনের নিজের কারণ আগে, তারপর হেডারের
                    reason: $line->reasonCode ?? $return->reasonCode,
                    date: $return->trx_date,
                    documentNo: $return->document_no,

                    // ⭐ কোন লটের মাল ফিরল — NEXUS §২৪; খালি হলে আগের আচরণ হুবহু
                    batch: $line->batch,
                );
            }

            $this->putCostBackInLayers($return);
            $this->postToLedger($return);

            $return->update(['status' => DocumentStatus::CONFIRMED]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * ⭐ নিশ্চিতের দরজা কোন কারণে থামাবে — কিছু না লিখে (৪ অক্টোবর ২০২৬)।
     *
     * ⓘ "নিশ্চিত করুন"-এর আগের সারাংশ ([[SalesPaperOverview::salesReturn()]]) এটাই দেখায়; ভিতরে [[assertReadyToConfirm()]] — দরজার নিজের, তালা ছাড়া
     * পাহারাগুলো, হুবহু একই ক্রমে। সইয়ের পাহারা নয়, কারণ সেটা অনুরোধ লেখে।
     *
     * @return list<string>  থামার কারণগুলো; খালি মানে কিছুই থামাবে না
     */
    public function whatWouldStopTheConfirm(SalesReturn $return): array
    {
        try {
            $this->assertReadyToConfirm($return);
        } catch (ValidationException $e) {
            return array_values($e->validator->errors()->all());
        }

        return [];
    }

    /**
     * নিশ্চিতের আগের পাহারা — [[confirm()]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬), যাতে সারাংশও ঠিক এগুলোই দেখে।
     */
    private function assertReadyToConfirm(SalesReturn $return): void
    {
        $return->loadMissing(['lines.product', 'lines.invoiceLine', 'lines.reasonCode', 'lines.batch', 'warehouse', 'reasonCode']);

        if ($return->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        /*
         * ⛔ নিয়মের আগের খসড়াগুলো কারণ ছাড়াই পড়ে আছে — খাতায় বসার আগে
         * আবার দেখা, নাহলে নিয়ম চালুর পরেও "কারণ নেই" ফেরত জন্মাত।
         */
        $this->reasons->checkDocument($return);
        $this->bills->checkDocument($return);

        foreach ($return->lines as $line) {
            $this->assertWithinSold($line);
        }
    }

    public function cancel(SalesReturn $return, string $reason, Carbon|string|null $onDate = null): SalesReturn
    {
        if ($return->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $return->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($return, $reason, $date) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($return);

            if ($return->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.already_cancelled', ['no' => $return->document_no]),
                ]);
            }

            if ($return->status === DocumentStatus::CONFIRMED) {
                $return->loadMissing(['lines.product', 'lines.batch', 'warehouse']);

                /*
                 * ⭐ স্তরে যা ফিরেছিল, তা আবার তোলা — ২৭ সেপ্টেম্বর ২০২৬।
                 * ⛔ এটা ছাড়া বাতিলের পরে তাক আর খাতা ফিরত, স্তর ফিরত না:
                 * ১০ বেচা, ৪ ফেরত, বাতিল → স্তরে ১৪ একক, খাতায় ১০-এর দাম।
                 */
                $this->costs->undoReturn(SalesReturn::STOCK_SOURCE, $return->id);

                foreach ($return->lines as $line) {
                    $this->freeBack($return, $line, '-1', $date, $reason);

                    if (bccomp((string) $line->qty, '0', 4) <= 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $return->warehouse,
                        sourceType: SalesReturn::STOCK_SOURCE,
                        sourceId: $return->id,
                        floor: bcmul((string) $line->qty, '-1', 4),
                        hold: $line->to_hold ? bcmul((string) $line->qty, '-1', 4) : '0',

                        // যে লটে ঢুকেছিল, সেই লট থেকেই বেরোয়
                        batch: $line->batch,
                        date: $date,
                        documentNo: $return->document_no,
                        narration: $reason,
                    );
                }

                // ⓘ কেবল ফ্রি মালের ফেরত খাতায় কিছুই লেখেনি — উল্টানোর কিছু নেই
                $posted = LedgerEntry::query()
                    ->where('source_type', SalesReturn::drillSourceType())
                    ->where('source_id', $return->id)
                    ->exists();

                if ($posted) {
                    $this->posting->reverse(
                        sourceType: SalesReturn::drillSourceType(),
                        sourceId: $return->id,
                        reversalDate: $date,
                        reason: $reason,
                    );
                }
            }

            $return->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * ফেরত আসা মাল স্তরে ফেরে — যে দামে বেরিয়েছিল, ঠিক সেই দামে।
     *
     * ── কেন আজকের দর নয় ────────────────────────────────────────────
     * গ্রাহক গত মাসের মাল ফেরত দিলে সেটা গত মাসের দামের মাল। আজকের দরে
     * ফিরিয়ে নিলে দাম বাড়লে মুনাফা তৈরি হত শুধু ফেরত নেওয়ার কারণে —
     * কেউ কিছু বেচেনি, তবু খাতায় লাভ বসত।
     *
     * ── কেন মূল বিলটা লাগে ─────────────────────────────────────────
     * "যে দামে বেরিয়েছিল" জানতে হলে জানতে হবে কোন বিলে বেরিয়েছিল।
     * ফেরতের কাগজে মূল বিলটা বাঁধা থাকে, আর সেটাই এখানে ব্যবহার হয়।
     */
    private function putCostBackInLayers(SalesReturn $return): void
    {
        /*
         * কোন বিলের মাল ফিরছে, সেটা না জানলে দামও জানা যায় না।
         *
         * ── কেন এটা confirm-এ আটকায়, ফর্মে নয় ───────────────────────
         * খসড়া বানানোর সময় ব্যবহারকারী হয়তো বিলটা খুঁজছেন। তখনই
         * আটকালে কাগজটা শুরুই করা যেত না। কিন্তু খাতায় বসার আগে
         * প্রশ্নটার উত্তর থাকতেই হবে।
         *
         * ── পুরনো কাগজের মাল ফিরলে কী ───────────────────────────────
         * ABOS-এ নেই এমন বিলের মাল ফিরলে এই কাগজটা ঠিক পথ নয় — তখন
         * দরসহ মজুদ সমন্বয়ই সৎ পথ, কারণ ওখানে দামটা মানুষ নিজে লেখেন,
         * আর কেউ কিছু ধরে নেয় না।
         */
        if ($return->sales_invoice_id === null) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => __('sales::validation.return_needs_invoice'),
            ]);
        }

        $cost = '0';

        /*
         * ⭐ "আগে কতটা ফিরেছে" — কেবল **এই বিলের** ফেরতগুলো (এটাসহ)।
         * ⛔ সব ফেরত গুনলে এক বিলের ফেরত একই স্তরের অন্য বিলের জায়গা খেত।
         */
        $siblings = SalesReturn::query()
            ->where('sales_invoice_id', $return->sales_invoice_id)
            ->where(fn ($q) => $q->posted()->orWhere('id', $return->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($return->lines as $line) {
            // ফ্রি মালের স্তর নেই — শূন্য দামে এসেছিল, শূন্য দামে ফেরে
            if (bccomp((string) $line->qty, '0', 4) <= 0) {
                continue;
            }

            $cost = bcadd($cost, $this->costs->returnToLayers(
                product: $line->product,
                qty: (string) $line->qty,
                issuedSourceType: SalesInvoice::STOCK_SOURCE,
                issuedSourceId: $return->sales_invoice_id,
                sourceType: SalesReturn::STOCK_SOURCE,
                sourceId: $return->id,
                documentNo: $return->document_no,
                date: $return->trx_date,
                returnedBy: $siblings,
            ), 4);
        }

        $return->update(['cost_of_goods' => $cost]);
        $return->refresh();
    }

    private function postToLedger(SalesReturn $return): void
    {
        $total = (string) $return->total;

        if (bccomp($total, '0', 4) <= 0) {
            /*
             * ⭐ কেবল ফ্রি বা উপহারের মাল ফিরল — মালিকের সিদ্ধান্ত: শূন্য
             * দামে, পাওনা না কমিয়ে। ⓘ খাতায় লেখার কিছু নেই, আর সেটাই ঠিক।
             */
            if ($return->lines->contains(fn (SalesReturnLine $l) => bccomp((string) $l->free_qty, '0', 4) > 0)) {
                return;
            }

            throw ValidationException::withMessages([
                'lines' => __('sales::validation.zero_value_return'),
            ]);
        }

        $lines = [
            [
                'account_id' => $this->account(StandardChart::SALES_RETURN)->id,
                'debit' => (string) $return->subtotal,
                'narration' => __('sales::message.return_lowers_sales', ['no' => $return->document_no]),
            ],
            [
                'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
                'credit' => $total,
                'party_type' => 'customer',
                'party_id' => $return->customer_id,
                'narration' => __('sales::message.return_lowers_receivable', ['no' => $return->document_no]),
            ],
        ];

        $tax = (string) $return->tax;

        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'debit' => $tax,
                'narration' => __('sales::message.return_vat', ['no' => $return->document_no]),
            ];
        }

        /*
         * মালের ব্যয়ও একই দাখিলায় ফেরে, আলাদা পোস্টে নয়।
         *
         * ── কেন এক দাখিলায় ──────────────────────────────────────────
         * পোস্টিং ইঞ্জিন একটা ডকুমেন্টের জন্য একবারই খাতায় লেখে —
         * দ্বিতীয়বার ডাকলে "এটা তো আগেই খাতায় আছে" বলে থামিয়ে দেয়।
         * আর সেটাই ঠিক: একটা কাগজের দুইটা আলাদা দাখিলা থাকলে বাতিল
         * করার সময় একটা উল্টে যেত আর অন্যটা থেকে যেত।
         *
         * বিক্রয় বিলও ঠিক এভাবেই আয় ও ব্যয় একসাথে লেখে। দুই দিক
         * মেলে: ডেবিট = ফেরত + ভ্যাট + ব্যয়, ক্রেডিট = পাওনা + মজুদ।
         *
         * বিক্রির সময় মজুদ কমে খরচ বেড়েছিল; মাল ফিরে এলে দুইটাই উল্টো
         * দিকে যায়। না ফেরালে লাভ-ক্ষতিতে খরচটা থেকে যেত অথচ মালটা
         * গুদামেই — অর্থাৎ একই মালের ব্যয় দুইবার গোনা হত।
         */
        $cost = (string) $return->cost_of_goods;

        if (bccomp($cost, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,
                'debit' => $cost,
                'narration' => __('sales::message.return_stock_back', ['no' => $return->document_no]),
            ];

            $lines[] = [
                'account_id' => $this->account(StandardChart::COST_OF_GOODS_SOLD)->id,
                'credit' => $cost,
                'narration' => __('sales::message.return_cost_back', ['no' => $return->document_no]),
            ];
        }

        $this->posting->post(
            sourceType: SalesReturn::drillSourceType(),
            sourceId: $return->id,
            trxDate: $return->trx_date,
            lines: $lines,
            documentNo: $return->document_no,
            branchId: $return->branch_id,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(SalesReturn $return, array $lines): void
    {
        $return->lines()->delete();

        $subtotal = '0';
        $taxTotal = '0';
        $cost = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $qty = $this->money(blank($line['qty'] ?? null) ? '0' : $line['qty']);

            // ⭐ ফ্রি বা উপহারের মাল — দাম নেই, তাই পরিমাণটাই যথেষ্ট (২৭ সেপ্টেম্বর ২০২৬)
            $free = $this->money(blank($line['free_qty'] ?? null) ? '0' : $line['free_qty']);

            if (bccomp($qty, '0', 4) <= 0 && bccomp($free, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            /*
             * প্যাকে ফেরত — "১ পাতা ফেরত" লেখা যায়।
             *
             * শুধু পরিমাণটাই নামে। দর নামে না, কারণ নিচে সেটা মূল বিলের
             * লাইন থেকে আসে — আর ওখানে দর আগেই পণ্যের এককে বসানো।
             */
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null);
            $qty = $pack['qty'];
            $free = $this->packed($product, $free, $line['unit_id'] ?? null)['qty'];

            $invoiceLine = null;

            if (filled($line['sales_invoice_line_id'] ?? null)) {
                $invoiceLine = SalesInvoiceLine::query()->whereKey((int) $line['sales_invoice_line_id'])->first();

                if ($invoiceLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales::validation.unknown_invoice_line'),
                    ]);
                }

                if ((int) $invoiceLine->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales::validation.line_product_mismatch'),
                    ]);
                }
            }

            /*
             * দর বিল থেকে, হাতে লেখা নয় (থাকলে)।
             *
             * ফেরতের দর বিক্রির দরই হওয়া উচিত। হাতে বসাতে দিলে কেউ বেশি
             * দরে ফেরত দেখিয়ে গ্রাহকের পাওনা বেশি কমাতে পারত।
             */
            /*
             * বিল ছাড়া ফেরতে হাতে লেখা দরটা এন্ট্রির এককে — সেটা নামাতে
             * হয়। পণ্য-মাস্টারের দাম নামে না; ওটা আগেই পণ্যের এককে।
             */
            /*
             * ⛔ বিলের সাথে বাঁধা ফেরত বিল যেভাবে খাতায় বসেছিল ঠিক সেভাবে ফেরে — গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬।
             *
             * আগে দর আসত বিলের লাইনের `rate` থেকে (ছাড়ের **আগের** দাম), আর ভ্যাট হাতে লেখা ঘর থেকে — সীমা
             * নেই, ভ্যাট বন্ধ থাকলেও। ফলে ছাড়ের বিল ফেরতে বেশি জমা পড়ত, আর `tax = 999999` লিখে যেকোনো
             * গ্রাহকের বাকি মুছে ফেলা যেত। ⭐ এখন অঙ্ক আর ভ্যাট দুইটাই বিলের লাইন থেকে ([[shareOfTheBill()]]);
             * বিল ছাড়া ফেরতে ভ্যাট পণ্যের হারে, বিলের একই নিয়মে ([[CalculatesSalesLines::lineFigures()]]) —
             * হাতে লেখা ভ্যাট কোথাও নেওয়া হয় না।
             */
            if ($invoiceLine !== null) {
                [$amount, $tax] = $this->shareOfTheBill($invoiceLine, $qty);
                $rate = bccomp($qty, '0', 4) > 0 ? bcdiv($amount, $qty, 4) : (string) $invoiceLine->rate;
            } else {
                $rate = filled($line['rate'] ?? null)
                    // হাতে লেখা দর — যে এককে লেখা, সেখান থেকে নামে
                    ? $this->packed($product, '1', $pack['entered_unit_id'], $this->money($line['rate']))['rate']
                    // মাস্টারের দাম — আগেই পণ্যের এককে, নামানোর কিছু নেই
                    : $this->money($product->sale_price);

                $figures = $this->lineFigures($qty, $rate, '0', null, $product->tax);
                $tax = $figures['tax'];
                // ⓘ ফেরতের `amount` ভ্যাট ছাড়া (মোট = অঙ্ক + ভ্যাট) — দামের ভেতরের ভ্যাটেও দুইবার গোনা নয়
                $amount = bcsub($figures['amount'], $tax, 4);
            }

            SalesReturnLine::create([
                'company_id' => $return->company_id,
                'sales_return_id' => $return->id,
                'product_id' => $product->id,
                'sales_invoice_line_id' => $invoiceLine?->id,
                'qty' => $qty,
                'free_qty' => $free,
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,
                'tax' => $tax,
                'amount' => $amount,
                'to_hold' => (bool) ($line['to_hold'] ?? false),

                // NEXUS §২৪ — যাচাই হয়ে এসেছে [[SalesReturnReasonGuard]] থেকে
                'reason_code_id' => $line['reason_code_id'] ?? null,
                'reason_note' => $line['reason_note'] ?? null,
                'batch_id' => $line['batch_id'] ?? null,

                'line_no' => ++$lineNo,
            ]);

            $subtotal = bcadd($subtotal, $amount, 4);
            $taxTotal = bcadd($taxTotal, $tax, 4);

        }

        $return->update([
            'subtotal' => $subtotal,
            'tax' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 4),

            /*
             * খসড়ায় ব্যয় শূন্য — আসলটা বসে confirm-এ, স্তরে ফেরানোর সময়।
             *
             * ── আগে যা ছিল, আর কেন সেটা ভুল ─────────────────────────
             * ব্যয়টা হিসাব হত পণ্য-মাস্টারের ক্রয়মূল্য ধরে, আর মন্তব্যে
             * লেখা ছিল "বিক্রয় বিলেও ঠিক এভাবেই হয়, তাই দুইটা মেলে"।
             * কথাটা সত্যি ছিল — দুইটা মিলত, কিন্তু দুইটাই ভুল দরে। যে
             * মালটা ১০০ টাকায় ঢুকেছিল সেটা ৩,৪০০ টাকায় বেরোত আর ৩,৪০০
             * টাকায় ফিরত, আর মজুদের খাত ধীরে ধীরে নয়, লাফিয়ে সরত।
             *
             * এখন মালটা ঠিক যে দামে বেরিয়েছিল সেই দামেই ফেরে — মূল
             * বিক্রয়ের টানগুলো ধরে ধরে। তাতে বিক্রি আর ফেরত হুবহু
             * একে অপরকে কাটে, এক পয়সাও পড়ে থাকে না।
             */
            'cost_of_goods' => '0',
        ]);
    }

    /**
     * ফ্রি বা উপহারের মাল ফ্রি ভাণ্ডারে ফেরে (দিক `1`) বা বাতিলে বেরোয় (`-1`)।
     *
     * ⭐ মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬: ফ্রি মাল ফেরত নেওয়া যায় —
     * যে ভাণ্ডার থেকে বেরিয়েছিল সেখানেই, শূন্য দামে। ⓘ উৎসের নাম `:free`,
     * বিক্রয়ের `delivery_challan:free`-এর মতো — "ফ্রি কত এল-গেল" আলাদা থাকে।
     * ⚠️ স্তর নেই, খাতা নেই: দাম ছিল না, তাই পাওনাও কমে না।
     */
    private function freeBack(SalesReturn $return, SalesReturnLine $line, string $direction, Carbon|string|null $date = null, ?string $narration = null): void
    {
        $free = (string) $line->free_qty;

        if (bccomp($free, '0', 4) <= 0) {
            return;
        }

        $this->stock->move(
            product: $line->product,
            warehouse: $return->warehouse,
            sourceType: SalesReturn::STOCK_SOURCE.':free',
            sourceId: $return->id,
            free: bcmul($free, $direction, 4),
            reason: $direction === '1' ? ($line->reasonCode ?? $return->reasonCode) : null,
            date: $date ?? $return->trx_date,
            documentNo: $return->document_no,
            narration: $narration,
            batch: $line->batch,
        );
    }

    /**
     * বিলের এই লাইনের `$qty` পরিমাণের ভাগ — [অঙ্ক (ভ্যাট ছাড়া), ভ্যাট]।
     *
     * ⓘ লাইনের অঙ্কে তার নিজের ছাড় (প্রমোশনসহ) আর ভ্যাট আগেই বসা; বিলের মাথার ছাড় আর রাউন্ডিং বিলের
     * মোটে বসে, তাই লাইনের ভাগকে বিলের মোট ÷ লাইনগুলোর যোগ দিয়ে গুণ করা হয় — পুরো বিল ফেরত দিলে জমা
     * হুবহু বিলের মোট। ভ্যাট বিলে বসে মাথার ছাড়ের আগের দামে, তাই ভ্যাটের ভাগ কেবল পরিমাণের অনুপাতে।
     *
     * @return array{0: string, 1: string}
     */
    private function shareOfTheBill(SalesInvoiceLine $invoiceLine, string $qty): array
    {
        $lineQty = (string) $invoiceLine->qty;

        if (bccomp($qty, '0', 4) <= 0 || bccomp($lineQty, '0', 4) <= 0) {
            return ['0.0000', '0.0000'];
        }

        $gross = bcdiv(bcmul((string) $invoiceLine->amount, $qty, 8), $lineQty, 8);
        $tax = bcdiv(bcmul((string) $invoiceLine->tax, $qty, 8), $lineQty, 4);

        $invoice = $invoiceLine->invoice;
        $linesTotal = $invoice === null ? '0' : $invoice->lines()->pluck('amount')
            ->reduce(fn (string $sum, $amount) => bcadd($sum, (string) $amount, 4), '0');

        $credit = bccomp($linesTotal, '0', 4) > 0
            ? bcdiv(bcmul($gross, (string) $invoice->total, 8), $linesTotal, 4)
            : bcadd($gross, '0', 4);

        return [bcsub($credit, $tax, 4), $tax];
    }

    /**
     * যত বেচা হয়েছে তার বেশি ফেরত নয়।
     */
    /**
     * ⛔ দুই ক্লিক, একই ফেরত বা একই বিল — চূড়ান্ত অডিট ⛔৩, ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ "এখনো খসড়া কি না" আর "বিলে কত বেচা, কত ফিরেছে" দেখা হত লেনদেনের বাইরে। একই বিলের সারিতে
     * দুইটা ফেরত (৬ + ৬, বেচা ১০) একসাথে এলে দুইটাই "১০ জায়গা আছে" দেখে পাকা হত; একই ফেরত দুইবার
     * নিশ্চিত হলে মাল দুইবার তাকে উঠত। ⭐ এখন ফেরতের সারিতে তালা আর অবস্থা তাজা পড়া; বিল থাকলে বিলের
     * সারিতেও তালা — একই বিলের সব ফেরত এক লাইনে দাঁড়ায় ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
     */
    private function lockAndReread(SalesReturn $return, bool $andTheBill = false): void
    {
        $fresh = SalesReturn::query()
            ->withoutGlobalScopes()
            ->whereKey($return->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $return->setRawAttributes($fresh->getAttributes(), true);

        if ($andTheBill && $return->sales_invoice_id !== null) {
            SalesInvoice::query()
                ->withoutGlobalScopes()
                ->whereKey($return->sales_invoice_id)
                ->lockForUpdate()
                ->first();
        }
    }

    private function assertWithinSold(SalesReturnLine $line): void
    {
        $invoiceLine = $line->invoiceLine;

        if ($invoiceLine === null) {
            return;
        }

        $alreadyReturned = SalesReturnLine::query()
            ->where('sales_invoice_line_id', $invoiceLine->id)
            ->whereKeyNot($line->id)
            ->whereHas('return', fn ($q) => $q->posted())
            ->sum('qty');

        $room = bcsub((string) $invoiceLine->qty, (string) ($alreadyReturned ?: '0'), 4);

        if (bccomp((string) $line->qty, $room, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.over_returned', [
                    'no' => $invoiceLine->invoice?->document_no ?? '',
                    'room' => rtrim(rtrim($room, '0'), '.'),
                ]),
            ]);
        }
    }

    private function resolveWarehouse(mixed $warehouseId): Warehouse
    {
        $warehouse = blank($warehouseId)
            ? Warehouse::query()->where('is_default', true)->active()->first()
            : Warehouse::query()->whereKey((int) $warehouseId)->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('sales::validation.unknown_warehouse'),
            ]);
        }

        return $warehouse;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(SalesReturn $return): void
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $return->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.missing_account', ['code' => $code]),
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
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
