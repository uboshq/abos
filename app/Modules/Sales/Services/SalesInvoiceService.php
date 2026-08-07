<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় বিল — টাকা পাওনা হলো।
 *
 * ── দুইটা দাখিলা, একই সাথে ────────────────────────────────────────────
 *     Dr  প্রাপ্য হিসাব (1110, গ্রাহকের নামে)
 *     Cr  বিক্রয় (4100)  ও  প্রদেয় ভ্যাট (2120)
 *
 *     Dr  বিক্রীত পণ্যের ব্যয় (5100)
 *     Cr  মজুদ পণ্য (1120)
 *
 * দ্বিতীয়টা বাদ দিলে লাভ-ক্ষতির হিসাবে আয় থাকত কিন্তু তার পেছনের খরচ
 * থাকত না, আর মুনাফা পুরো বিক্রয়মূল্যের সমান দেখাত। আর ব্যালেন্স শিটে
 * মজুদ কমত না — অর্থাৎ যে মাল বেচা হয়ে গেছে সেটাও সম্পদ হিসেবে থাকত।
 *
 * ── স্টক এখানে নড়ে কি না ──────────────────────────────────────────────
 * চালান ধরে বিল হলে মাল আগেই বেরিয়ে গেছে, তাই স্টক নড়ে না। কিন্তু
 * কাউন্টার বিক্রিতে চালান থাকে না — তখন বিলই মাল নামায়। দুইটা পথ, একই
 * ফল: বিলের সময় মালটা গুদামে নেই।
 */
final class SalesInvoiceService
{
    use CalculatesSalesLines;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly SettingsService $settings,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * ছাড়ের অনুমোদন — নিশ্চিত করার আগে।
     *
     * ── কেন নিশ্চিত করার সময়ে, তৈরির সময়ে নয় ───────────────────────
     * খসড়া বিল কোনো হিসাবে নেই; ওটা বদলানো যায়, মুছেও ফেলা যায়। ছাড়
     * তখনো কারও ক্ষতি করেনি। ক্ষতিটা হয় নিশ্চিত করার মুহূর্তে — তখনই
     * আয় কমে বসে। তাই পাহারাটা ঠিক সেখানেই।
     *
     * ── কেন এখানে ব্যতিক্রম ছোঁড়া হয় ───────────────────────────────
     * অনুরোধটা তৈরি হয় আর বিলটা খসড়া হয়েই থাকে। অনুমোদনকারী "হ্যাঁ"
     * বললে ব্যবহারকারী আবার নিশ্চিত চাপেন, আর এবার পথটা খোলা থাকে।
     * নিজে থেকে নিশ্চিত হয়ে যাওয়াটা ভুল হত: অনুমোদনের পর মালটা তখনো
     * গুদামে আছে কি না সেটা আবার দেখা দরকার।
     */
    private function assertDiscountApproved(SalesInvoice $invoice): void
    {
        $discount = (string) ($invoice->discount ?? '0');

        if (bccomp($discount, '0', 4) <= 0) {
            return;
        }

        /*
         * সিদ্ধান্ত হয়ে থাকলে সেটাই মানা হয়, নতুন অনুরোধ নয়।
         *
         * এটা না দেখলে অনুমোদনের পরেও পরের "নিশ্চিত"-এ আরেকটা নতুন
         * অনুরোধ তৈরি হত — বিলটা কোনোদিন খাতায় বসত না, আর অনুমোদনকারী
         * একই ছাড় বারবার অনুমোদন করে যেতেন।
         */
        $decided = $this->approvals->latestFor($invoice, 'discount');

        if ($decided?->status === Approval::APPROVED) {
            return;
        }

        if ($decided?->status === Approval::REJECTED) {
            throw ValidationException::withMessages([
                'discount' => __('sales::validation.discount_rejected'),
            ]);
        }

        $approval = $this->approvals->request(
            document: $invoice,
            module: 'sales',
            action: 'discount',
            amount: $discount,
            reason: $invoice->narration,
        );

        if ($approval === null) {
            return;
        }

        throw ValidationException::withMessages([
            'discount' => __('sales::validation.discount_awaiting'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): SalesInvoice
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('INV');

            $invoice = SalesInvoice::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'] ?? $this->defaultWarehouse()?->id,
                'trx_date' => $trxDate->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($invoice, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => SalesInvoice::drillSourceType(),
                    'source_id' => $invoice->id,
                ]);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesInvoice $invoice, array $data, array $lines): SalesInvoice
    {
        $this->assertEditable($invoice);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($invoice, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $invoice->trx_date);

            $invoice->update([
                'trx_date' => $trxDate->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($invoice, $lines);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * বিলটা খাতায় বসানো — আয়, প্রাপ্য, আর বিক্রীত পণ্যের ব্যয়।
     */
    public function confirm(SalesInvoice $invoice): SalesInvoice
    {
        if ($invoice->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $invoice->document_no]),
            ]);
        }

        $invoice->loadMissing(['lines.product', 'lines.challanLine', 'warehouse', 'customer']);

        if ($invoice->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        /*
         * লেনদেনের বাইরে, ইচ্ছাকৃতভাবে।
         *
         * ভেতরে রাখলে ব্যতিক্রমটা অনুরোধের সারিটাও রোল-ব্যাক করত —
         * অর্থাৎ প্রতিবার নিশ্চিত চাপলে একটা নতুন অনুরোধ তৈরি হয়ে
         * সাথে সাথে মুছে যেত, আর অনুমোদনকারীর তালিকায় কোনোদিন কিছু
         * আসত না।
         */
        $this->assertDiscountApproved($invoice);

        return DB::transaction(function () use ($invoice) {
            /*
             * চালান ছাড়া লাইনের মাল এখনই বেরোয়।
             *
             * কাউন্টার বিক্রিতে চালান কাটা হয় না — গ্রাহক টাকা দিয়ে মাল
             * নিয়ে চলে যান। তখন বিলই একমাত্র জায়গা যেখানে স্টক নামতে
             * পারে; না নামালে বেচা মাল গুদামে থেকে যেত।
             */
            foreach ($invoice->lines as $line) {
                if ($line->challanLine !== null) {
                    continue;
                }

                $warehouse = $invoice->warehouse ?? $this->defaultWarehouse();

                $this->assertEnoughToSell($line->product, $warehouse, (string) $line->qty);

                $this->stock->move(
                    product: $line->product,
                    warehouse: $warehouse,
                    sourceType: SalesInvoice::STOCK_SOURCE,
                    sourceId: $invoice->id,
                    floor: bcmul((string) $line->qty, '-1', 4),
                    date: $invoice->trx_date,
                    documentNo: $invoice->document_no,
                );
            }

            $this->takeCostFromLayers($invoice);
            $this->postToLedger($invoice);

            $invoice->update(['status' => DocumentStatus::CONFIRMED]);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * বাতিল — উল্টো এন্ট্রি, আর চালান ছাড়া বেরোনো মাল স্টকে ফেরে।
     */
    public function cancel(SalesInvoice $invoice, string $reason, Carbon|string|null $onDate = null): SalesInvoice
    {
        if ($invoice->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $invoice->document_no]),
            ]);
        }

        $invoice->loadMissing(['lines.product', 'lines.challanLine', 'warehouse']);

        $this->assertNotCollected($invoice);

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($invoice, $reason, $date) {
            if ($invoice->status === DocumentStatus::CONFIRMED) {
                foreach ($invoice->lines as $line) {
                    if ($line->challanLine !== null) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $invoice->warehouse ?? $this->defaultWarehouse(),
                        sourceType: SalesInvoice::STOCK_SOURCE.':cancel',
                        sourceId: $invoice->id,
                        floor: (string) $line->qty,
                        date: $date,
                        documentNo: $invoice->document_no,
                        narration: $reason,
                    );
                }

                $this->posting->reverse(
                    sourceType: SalesInvoice::drillSourceType(),
                    sourceId: $invoice->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $invoice->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $invoice->fresh(['lines']);
        });
    }

    private function postToLedger(SalesInvoice $invoice): void
    {
        $total = (string) $invoice->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.zero_value_invoice'),
            ]);
        }

        $net = bcsub(bcsub($total, (string) $invoice->tax, 4), '0', 4);
        $cost = (string) $invoice->cost_of_goods;

        $lines = [
            [
                'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
                'debit' => $total,
                'party_type' => 'customer',
                'party_id' => $invoice->customer_id,
                'narration' => __('sales::message.receivable', ['no' => $invoice->document_no]),
            ],
            [
                'account_id' => $this->account(StandardChart::SALES)->id,
                'credit' => $net,
                'narration' => __('sales::message.sale', ['no' => $invoice->document_no]),
            ],
        ];

        if (bccomp((string) $invoice->tax, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'credit' => (string) $invoice->tax,
                'narration' => __('sales::message.output_vat', ['no' => $invoice->document_no]),
            ];
        }

        /*
         * বিক্রীত পণ্যের ব্যয় — একই পোস্টিং-এ, আলাদা ভাউচারে নয়।
         *
         * আলাদা করলে একটা বসে অন্যটা ব্যর্থ হতে পারত, আর তখন আয় থাকত
         * অথচ খরচ থাকত না — মুনাফা বাস্তবের চেয়ে বেশি দেখাত, আর কেউ
         * টের পেত না।
         */
        if (bccomp($cost, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::COST_OF_GOODS_SOLD)->id,
                'debit' => $cost,
                'narration' => __('sales::message.cost_of_goods', ['no' => $invoice->document_no]),
            ];
            $lines[] = [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,
                'credit' => $cost,
                'narration' => __('sales::message.stock_out', ['no' => $invoice->document_no]),
            ];
        }

        $this->posting->post(
            sourceType: SalesInvoice::drillSourceType(),
            sourceId: $invoice->id,
            trxDate: $invoice->trx_date,
            lines: $lines,
            documentNo: $invoice->document_no,
            branchId: $invoice->branch_id,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesInvoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $cost = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['qty'] ?? null, 'qty');
            $rate = $this->money($line['rate'] ?? null);

            $product = Product::query()->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            $challanLine = $this->resolveChallanLine($invoice, $line['delivery_challan_line_id'] ?? null, $productId, $qty);

            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? '0');

            /*
             * খসড়ায় দরটা শূন্য — আসলটা বসে confirm-এ, স্তর থেকে টেনে।
             *
             * ── কেন এখানে একটা আন্দাজ বসানো হয় না ───────────────────
             * আগে এখানে পণ্য-মাস্টারের ক্রয়মূল্য বসত, আর সেটাই ছিল পুরো
             * গোলমালের উৎস: মাল খতিয়ানে ঢুকত চালানের আসল দরে, বেরোত
             * মাস্টারের দরে, আর দুইটা কখনো এক হত না। ১,০০০ টাকার মালের
             * ৪০% বেচে মজুদ খাত থেকে ১৩,৬০০ বেরিয়ে গিয়েছিল।
             *
             * একটা আন্দাজ বসিয়ে রাখলে খসড়ার পর্দায় সেটা "খরচ" বলে
             * দেখা যেত, আর confirm-এর পর অন্য সংখ্যা — কেউ বিশ্বাস করত
             * না কোনটা সত্যি। শূন্য থাকা মানে "এখনো জানা যায়নি", আর
             * সেটাই সত্যি: কোন চালানের মাল যাবে তা তো মাল বেরোনোর আগে
             * জানা যায় না।
             */
            $unitCost = '0';

            SalesInvoiceLine::create([
                'sales_invoice_id' => $invoice->id,
                'product_id' => $productId,
                'delivery_challan_line_id' => $challanLine?->id,
                'qty' => $qty,
                'rate' => $rate,
                'discount' => $figures['discount'],
                'tax' => $figures['tax'],
                'amount' => $figures['amount'],
                'unit_cost' => $unitCost,
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $totals = $this->addToTotals($totals, $figures);
            $cost = bcadd($cost, bcmul($qty, $unitCost, 4), 4);
        }

        $invoice->update([...$totals, 'cost_of_goods' => $cost]);
    }

    /**
     * খরচের দর — যে চালানে মালটা ঢুকেছিল সেটার দর, স্তর থেকে টেনে।
     *
     * ── কেন এটা confirm-এ, লাইন তৈরির সময়ে নয় ───────────────────────
     * খসড়া বিল দিনের পর দিন পড়ে থাকতে পারে, আর ততক্ষণে ওই মাল অন্য
     * বিলে বেরিয়ে যেতে পারে। খসড়ার সময় স্তর টানলে মালটা আটকে থাকত
     * অথচ কেউ সেটা বিক্রিই করেনি। স্টক যে মুহূর্তে নামে, দামও ঠিক সেই
     * মুহূর্তেই টানা হয় — দুইটা একই লেনদেনে, তাই কখনো আলাদা হয় না।
     *
     * ── আগে যা ছিল, আর কেন সেটা ভুল ─────────────────────────────────
     * দর আসত পণ্য-মাস্টারে লেখা ক্রয়মূল্য থেকে, আর মাল খতিয়ানে ঢুকত
     * চালানের আসল দরে। জীবন্ত পর্দায় চালিয়ে ধরা পড়েছে: ১,০০০ টাকার ১০
     * বস্তার ৪টা বেচে মজুদ খাত থেকে ১৩,৬০০ বেরিয়ে গিয়েছিল, আর খাতটা
     * ঋণাত্মক হয়ে বসেছিল। বিস্তারিত:
     * docs/Finding — Inventory is valued two different ways.md
     *
     * চালানে-বাঁধা লাইনও এখানে আসে: মালটা চালানের দিন গুদাম থেকে
     * নেমেছে বটে, কিন্তু বিক্রয় হিসেবে খরচ বসে বিলের দিনেই।
     */
    private function takeCostFromLayers(SalesInvoice $invoice): void
    {
        $cost = '0';

        foreach ($invoice->lines as $line) {
            $taken = $this->costs->issue(
                product: $line->product,
                qty: (string) $line->qty,
                sourceType: SalesInvoice::STOCK_SOURCE,
                sourceId: $invoice->id,
                documentNo: $invoice->document_no,
                date: $invoice->trx_date,
            );

            /*
             * লাইনের দরটা গড়, কারণ একটা লাইন একাধিক চালান থেকে আসতে
             * পারে — ১৫ বস্তার ১০টা পুরনো দরে, ৫টা নতুন দরে। সারিতে
             * একটাই সংখ্যা দেখানো যায়, তাই গড়ই দেখানো হয়। ভাঙা
             * হিসাবটা হারায় না: প্রতিটা টান আলাদা সারিতে লেখা থাকে,
             * আর ওখান থেকেই চালানে পৌঁছানো যায়।
             */
            $line->update([
                'unit_cost' => bccomp((string) $line->qty, '0', 4) > 0
                    ? bcdiv($taken['cost'], (string) $line->qty, 4)
                    : '0',
            ]);

            $cost = bcadd($cost, $taken['cost'], 4);
        }

        $invoice->update(['cost_of_goods' => $cost]);
        $invoice->refresh();
    }

    private function resolveChallanLine(
        SalesInvoice $invoice,
        mixed $challanLineId,
        int $productId,
        string $qty,
    ): ?DeliveryChallanLine {
        if (blank($challanLineId)) {
            if ($this->settings->get('sales.invoice_needs_challan', false)) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.challan_required'),
                ]);
            }

            return null;
        }

        $challanLine = DeliveryChallanLine::query()
            ->with('challan')
            ->whereKey((int) $challanLineId)
            ->first();

        if ($challanLine === null || $challanLine->challan === null) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_challan_line')]);
        }

        // সন্তান-টেবিলে গ্লোবাল স্কোপ নেই, বাবার উপর আছে — তাই এখানে হাতে
        if ((int) $challanLine->challan->company_id !== (int) CompanyContext::id()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_challan_line')]);
        }

        /*
         * তুলনাটা int-এ, স্ট্রিক্ট !== দিয়ে নয়।
         *
         * ফর্ম থেকে আসা id একটা স্ট্রিং ("2"), আর ডাটাবেজ থেকে আসাটা int।
         * স্ট্রিক্ট তুলনায় দুইটা কখনো সমান হত না, তাই বৈধ চালানও "অন্য
         * গ্রাহকের" বলে ফেরত যেত — অথচ টেস্টে ধরা পড়ত না, কারণ টেস্ট
         * সরাসরি int পাঠায়। এটা ধরা পড়েছে ব্রাউজারে ফর্মটা ভরে।
         */
        if ((int) $challanLine->challan->customer_id !== (int) $invoice->customer_id) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.challan_other_customer')]);
        }

        if ($challanLine->challan->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.challan_not_confirmed', [
                    'no' => $challanLine->challan->document_no,
                ]),
            ]);
        }

        if ((int) $challanLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.line_product_mismatch')]);
        }

        // একই চালানের লাইন দুইবার বিল করলে গ্রাহককে একই মালের দাম দুইবার
        // ধরানো হত
        $alreadyInvoiced = $challanLine->invoiceLines()
            ->where('sales_invoice_id', '<>', $invoice->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('qty');

        $wouldBe = bcadd((string) ($alreadyInvoiced ?: '0'), $qty, 4);

        if (bccomp($wouldBe, (string) $challanLine->delivered_qty, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.over_invoiced', [
                    'no' => $challanLine->challan->document_no,
                    'delivered' => rtrim(rtrim((string) $challanLine->delivered_qty, '0'), '.'),
                ]),
            ]);
        }

        return $challanLine;
    }

    /**
     * আদায় হয়ে যাওয়া বিল বাতিল করা যায় না।
     *
     * করলে গ্রাহকের নামে এমন একটা জমা পড়ে থাকত যার কোনো বিল নেই, আর
     * প্রাপ্য ঋণাত্মক হয়ে যেত। আগে আদায়টা বাতিল করতে হবে।
     */
    private function assertNotCollected(SalesInvoice $invoice): void
    {
        if (bccomp($invoice->collectedAmount(), '0', 4) > 0) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.invoice_already_collected', ['no' => $invoice->document_no]),
            ]);
        }
    }

    /**
     * বিক্রয়যোগ্য মালের বেশি বেচা যায় না — কাউন্টারেও নয়।
     *
     * এখানে দেখা হয় Available, শুধু Floor নয়। Floor দেখলে অর্ডারে ধরা বা
     * আটকানো মালও বেচা যেত: কেউ ১০ বস্তা অর্ডার দিয়ে রেখেছেন, আর কাউন্টার
     * সেগুলোই বেচে দিল। তখন ধরে রাখার প্রতিশ্রুতিটার আর কোনো মানে থাকত না,
     * আর ভুলটা ধরা পড়ত মাল দিতে গিয়ে — অর্ডার দেওয়া গ্রাহকের সামনে।
     *
     * StockService নিজে Floor পাহারা দেয়, কিন্তু ওটা ভিন্ন প্রশ্নের উত্তর:
     * "তাকে আছে কি না"। "বেচা যাবে কি না" প্রশ্নটা বিক্রয়ের, তাই উত্তরটাও
     * এখানে।
     */
    private function assertEnoughToSell(Product $product, ?Warehouse $warehouse, string $qty): void
    {
        if ($warehouse === null || $this->settings->get('sales.allow_negative_stock', false)) {
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

    private function assertEditable(SalesInvoice $invoice): void
    {
        if ($invoice->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $invoice->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

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
