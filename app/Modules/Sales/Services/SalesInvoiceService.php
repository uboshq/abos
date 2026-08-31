<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\PricingRule;
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
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly RecipeService $recipes,
        private readonly CostLayerService $costs,
        private readonly SettingsService $settings,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * ধারের সীমা ছাড়িয়ে গেলে বিলটা বসে না।
     *
     * ── কেন নগদ বিক্রি আটকায় না ─────────────────────────────────────
     * সীমাটা **বাকির** সীমা। টাকা হাতে দিয়ে মাল নিলে কারও ধার বাড়ে
     * না, তাই ওখানে সীমার কোনো ভূমিকা নেই। বকেয়া থেকে বিলের অঙ্কটা
     * ধরেই দেখা হয়, আর আদায়টা বসে বিলের পরে — তাই নগদ বিক্রিতেও
     * এক মুহূর্তের জন্য পাওনা জন্মায়। সেটা সামলাতে সেটিংটা
     * (`block_over_limit`) বন্ধ রাখার পথ খোলা আছে।
     */
    private function assertWithinCreditLimit(SalesInvoice $invoice): void
    {
        if (! $this->settings->enabled('customer.credit_limit_enabled')) {
            return;
        }

        if (! $this->settings->enabled('customer.block_over_limit')) {
            return;
        }

        $customer = $invoice->customer;

        if ($customer === null || ! $customer->wouldExceedCreditLimit((string) $invoice->total)) {
            return;
        }

        if (auth()->user()?->can('customer.credit_limit.override') === true) {
            return;
        }

        throw ValidationException::withMessages([
            'customer_id' => __('sales::validation.over_credit_limit', [
                'customer' => $customer->name(),
                'limit' => Money::format($customer->credit_limit),
            ]),
        ]);
    }

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
     *
     * ── কেন public ─────────────────────────────────────────────────
     * কাউন্টার (`PosService`) বিল তৈরি ও নিশ্চিত করা একসাথে করে, আর
     * সেটা তার নিজের লেনদেনে। ভেতর থেকে ডাকলে এই পাহারার ব্যতিক্রমটা
     * ওই বাইরের লেনদেনকে ফিরিয়ে নিত — অর্থাৎ উপরে যে সাবধানতাটা নেওয়া
     * হয়েছে, সেটা এক স্তর উপরে গিয়ে অকেজো হয়ে যেত। তাই কাউন্টার
     * লেনদেন খোলার **আগেই** নিজে ডেকে নেয়।
     */
    public function assertDiscountApproved(SalesInvoice $invoice): void
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
        /*
         * ধারের সীমা — মাল বেরোনোর ঠিক আগে।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * সীমাটা দেখা হত **কেবল বিক্রয় আদেশে**। কিন্তু ডিপোতে বেশিরভাগ
         * বিল আদেশ ছাড়াই হয় — কাউন্টারে, সরাসরি বিক্রয়ে, বা চালান
         * থেকে। ফলে যে গ্রাহকের সীমা পেরিয়ে গেছে তাঁকেও দিব্যি বাকিতে
         * মাল দেওয়া যেত, আর সীমাটা কেবল ওই এক পথেই সত্যি ছিল।
         *
         * এখানেই দেখা হয়, কারণ **এখানেই মাল বেরোয় আর পাওনা জন্মায়** —
         * খসড়া বিলে কারও কিছু যায় আসে না।
         */
        $this->assertWithinCreditLimit($invoice);

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

                /*
                 * রান্না করা খাবার — পণ্যটা নয়, তার উপকরণগুলো কমে।
                 *
                 * ── কেন এখানে, আর কেন `continue` ─────────────────────
                 * "চিকেন বিরিয়ানি" নামে কিছু গুদামে ঢোকে না, তাই তার
                 * নিজের কোনো স্টক নেই। তার সারিতে সাধারণ নিয়মে স্টক
                 * কমাতে গেলে ঋণাত্মক স্টক তৈরি হত — একটা জিনিসের, যা
                 * কোনোদিন কেনাই হয়নি।
                 *
                 * ── হাঁড়িতে-রান্না এখানে আসে না ──────────────────────
                 * `consumesOnSale()` কেবল `to_order`-এ সত্যি। হাঁড়ির
                 * খাবার একটা সত্যিকারের স্টক-আইটেম — উৎপাদনের সময় সে
                 * গুদামে ঢোকে — তাই তার বিক্রি নিচের সাধারণ পথেই যায়,
                 * আর সেটাই ঠিক। দুই জায়গায় কমালে চাল দুইবার খরচ হত।
                 */
                if ($this->recipes->consumesOnSale($line->product)) {
                    $recipe = $this->recipes->forProduct($line->product);

                    $this->assertRecipeCanBeCooked($line->product, $recipe, $warehouse, (string) $line->qty);

                    $this->recipes->consume(
                        recipe: $recipe,
                        servings: (string) $line->qty,
                        warehouse: $warehouse,
                        sourceType: SalesInvoice::STOCK_SOURCE,
                        sourceId: $invoice->id,
                        date: $invoice->trx_date,
                        documentNo: $invoice->document_no,
                    );

                    continue;
                }

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

            $confirmed = $invoice->fresh(['lines']);

            /*
             * ঘটনাটা লেনদেনের **পরে**, ভেতরে নয়।
             *
             * ── কেন ─────────────────────────────────────────────────
             * ভেতরে ছুড়লে শ্রোতা এমন একটা বিল দেখত যা এখনো কমিট হয়নি।
             * শ্রোতা SMS পাঠালে আর তারপর লেনদেনটা রোল-ব্যাক করলে গ্রাহক
             * একটা বিলের খবর পেতেন যেটার অস্তিত্বই নেই — আর সেটা ফেরত
             * নেওয়ার কোনো উপায় নেই।
             *
             * afterCommit ব্যবহার করা হয়েছে, নিজে হাতে লেনদেনের বাইরে
             * সরিয়ে নয়: confirm() নিজেই নেস্টেড লেনদেনে ডাকা হতে পারে
             * (POS-এ বিল ও আদায় একসাথে), আর তখন "বাইরে" মানে ভুল
             * জায়গা — সবচেয়ে বাইরের লেনদেনটা তখনো খোলা।
             */
            DB::afterCommit(fn () => event(InvoiceConfirmed::from($confirmed)));

            return $confirmed;
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

        /*
         * দামের নীতিটা একবার পড়া হয়, প্রতিটা সারিতে নয়।
         *
         * সারি ধরে ধরে পড়লে পঞ্চাশ সারির বিলে পঞ্চাশটা প্রশ্ন যেত,
         * আর উত্তরটা প্রতিবার একই।
         */
        $pricing = PricingRule::current();

        /** @var list<string> $warnings */
        $warnings = [];
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

            /*
             * "২ বাক্স @ ৮০০" — পরিমাণ আর দর দুইটাই পণ্যের এককে নামে।
             *
             * শুধু পরিমাণ নামালে বিলটা ২০০ × ৮০০ = ১,৬০,০০০ হত। নিচের
             * সবকিছু — মজুদ, স্তর, চালানের মিল — এখান থেকে পণ্যের
             * এককেই চলে, তাই কাউকে আর একক নিয়ে ভাবতে হয় না।
             */
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate, $line);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            $challanLine = $this->resolveChallanLine($invoice, $line['delivery_challan_line_id'] ?? null, $productId, $qty);

            // ভ্যাট না পাঠালে পণ্যের নিজের হার থেকে গোনা — কাউন্টারের পর্দা
            // ভ্যাট দেখাত কিন্তু কখনো পাঠাত না, আর বিলে বসত শূন্য
            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);

            /*
             * দরটা মান দাম থেকে কতটা সরে আছে — নীতিটা যা বলে।
             *
             * ---- কেন এখানে, ছাড়ের অনুমোদনের পাশে নয় ----
             * ছাড়ের অনুমোদন দেখে **বিলের** ছাড়ের ঘরটা। কিন্তু দর কমিয়ে
             * লেখলে ছাড়ের ঘর খালিই থাকে, আর পাহারাটা কিছুই দেখে না —
             * অথচ টাকাটা একইভাবে যায়। ওটাই আজকের ভোঁতা নিয়মের ফাঁক:
             * লোকে ছাড় না দিয়ে দর কমিয়ে লেখে, আর খাতায় ছাড়টা আর
             * দেখাই যায় না।
             *
             * তাই সরে যাওয়াটা মাপা হয় **সারির দরে**, মান দামের সাথে।
             */
            $drift = $pricing->verdictOn($rate, (string) ($product->sale_price ?? '0'));

            if ($drift === PricingRule::BLOCK) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.price_out_of_range', [
                        'product' => $product->name(),
                        'tolerance' => rtrim(rtrim((string) $pricing->tolerance, '0'), '.'),
                    ]),
                ]);
            }

            if ($drift === PricingRule::WARN) {
                /*
                 * সতর্কতা বিল আটকায় না, কিন্তু হারিয়েও যায় না।
                 *
                 * পর্দায় দেখিয়ে ফেলে দিলে কেউ পরে বলতে পারত না কোন
                 * সারিতে সতর্কতা এসেছিল। সারির বিবরণে লেখা থাকলে
                 * ছয় মাস পরেও কাগজ দেখেই বোঝা যায়।
                 */
                $warnings[] = __('sales::validation.price_out_of_range', [
                    'product' => $product->name(),
                    'tolerance' => rtrim(rtrim((string) $pricing->tolerance, '0'), '.'),
                ]);
            }

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
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
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

        /*
         * সতর্কতাগুলো সেশনে — বিলটা বসেছে, কিন্তু কথাটা বলা দরকার।
         *
         * ব্যতিক্রম ছুঁড়লে বিলটা বসত না, আর সেটা "সতর্কতা" নয়,
         * "আটকানো" — ওটার জন্য আলাদা নীতি আছে।
         */
        if ($warnings !== []) {
            session()->flash('price_warnings', array_values(array_unique($warnings)));
        }
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
            /*
             * রান্না করা খাবারের খরচ তার **উপকরণের** স্তর থেকে।
             *
             * ── কেন খাবারটার নিজের স্তর নেই ─────────────────────────
             * FIFO স্তর জন্মায় মাল কেনার সময়। "চিকেন বিরিয়ানি" কোনোদিন
             * কেনা হয়নি, তাই তার কোনো স্তরও নেই — আর না থাকাটাই ঠিক।
             *
             * এই যাচাইটা না বসিয়ে প্রথমবার চালাতে গিয়ে বিলটাই আটকে
             * গিয়েছিল: "ক্রয়মূল্যের হিসাব নেই"। পাহারাটা ঠিকই বলেছিল —
             * শুধু প্রশ্নটা ভুল পণ্যকে করা হচ্ছিল।
             *
             * ── কেন নতুন কোনো দর হিসাব করা হয়নি ─────────────────────
             * উপকরণগুলো একটু আগেই `RecipeService` দিয়ে বেরিয়েছে, আর
             * ওদের স্তর টানলে দর ওখান থেকেই আসে। রেসিপিতে আলাদা দর
             * রাখলে ৭ আগস্টের সেই ভুলটাই ফিরত — মাল ঢুকত এক দামে,
             * বেরোত আরেক দামে।
             */
            if ($this->recipes->consumesOnSale($line->product)) {
                $cost = bcadd($cost, $this->cookedCost($invoice, $line), 4);

                continue;
            }

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

    /**
     * রান্না করা খাবারের এক সারির খরচ — উপকরণের FIFO স্তর টেনে।
     *
     * ── কেন প্রতিটা উপকরণে আলাদা করে ডাকা হয় ────────────────────────
     * FIFO মানে "যেটা আগে ঢুকেছে সেটা আগে বেরোয়", আর প্রতিটা উপকরণের
     * নিজের ঢোকার ইতিহাস আলাদা। চাল তিন দামে তিনবার কেনা হতে পারে,
     * মাংস একবার। একটা গড় দর বসালে ওই ইতিহাসটাই হারাত।
     *
     * ── সারির `unit_cost` কেন প্লেট ধরে ─────────────────────────────
     * উপকরণগুলোর খরচ যোগ হয়ে দাঁড়ায় গোটা সারির খরচ; তাকে প্লেটের
     * সংখ্যা দিয়ে ভাগ করলে পাওয়া যায় **এক প্লেটে কত টাকার মাল গেল**।
     * ওটাই খাদ্য-খরচের রিপোর্টের মূল সংখ্যা, আর ওটা সারিতেই লেখা
     * থাকলে রিপোর্টকে আর হিসাব কষতে হয় না।
     */
    private function cookedCost(SalesInvoice $invoice, SalesInvoiceLine $line): string
    {
        $recipe = $this->recipes->forProduct($line->product);

        if ($recipe === null) {
            return '0';
        }

        $cost = '0';

        foreach ($this->recipes->needsFor($recipe, (string) $line->qty) as $need) {
            $taken = $this->costs->issue(
                product: $need['product'],
                qty: $need['qty'],
                sourceType: SalesInvoice::STOCK_SOURCE,
                sourceId: $invoice->id,
                documentNo: $invoice->document_no,
                date: $invoice->trx_date,
            );

            $cost = bcadd($cost, $taken['cost'], 4);
        }

        $line->update([
            'unit_cost' => bccomp((string) $line->qty, '0', 4) > 0
                ? bcdiv($cost, (string) $line->qty, 4)
                : '0',
        ]);

        return $cost;
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

    /**
     * রেসিপিটা সত্যিই রান্না করা যায় কি না — বিক্রির আগেই।
     *
     * ── কেন নীরবে বিক্রি হতে দেওয়া যায় না ────────────────────────────
     * পরিকল্পনায় এটাই ধাপ ১-এর প্রথম শর্ত: "অসম্পূর্ণ রেসিপির খাবার
     * বিক্রি হলে বলতে হবে"।
     *
     * উপকরণ ছাড়া রেসিপি মানে বিক্রিতে **কিছুই কমে না**। বিল ছাপে, টাকা
     * আসে, পর্দায় কোনো ভুল দেখায় না — আর গুদামের হিসাব নীরবে ভুল হতে
     * থাকে। ধরা পড়ে মাসের শেষে, যখন আর বলা যায় না কোন দিনের কোন
     * বিক্রিতে গোলমাল হয়েছিল।
     *
     * ── কেন উপকরণের স্টকও এখানেই দেখা হয় ────────────────────────────
     * `RecipeService::consume()` ডাকলে `StockService` প্রতিটা উপকরণে
     * আলাদা করে বাধা দিত, আর বার্তাটা হত "চাল কম" — অথচ কাউন্টারে
     * বসা মানুষ চাল বেচছেন না, বিরিয়ানি বেচছেন।
     *
     * এখানে দেখায় বার্তাটা হয় "বিরিয়ানি বানানোর মতো চাল নেই", আর
     * সেটাই তাঁর কাজের ভাষা।
     */
    private function assertRecipeCanBeCooked(
        Product $dish,
        ?Recipe $recipe,
        ?Warehouse $warehouse,
        string $servings,
    ): void {
        if ($recipe === null || $recipe->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.recipe_incomplete', [
                    'product' => $dish->name(),
                ]),
            ]);
        }

        if ($warehouse === null) {
            return;
        }

        foreach ($this->recipes->needsFor($recipe, $servings) as $need) {
            $available = $this->stock->availableQty($need['product'], $warehouse);

            if (bccomp($available, $need['qty'], 4) < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.not_enough_to_cook', [
                        'product' => $dish->name(),
                        'ingredient' => $need['product']->name(),
                        'available' => rtrim(rtrim($available, '0'), '.'),
                    ]),
                ]);
            }
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
