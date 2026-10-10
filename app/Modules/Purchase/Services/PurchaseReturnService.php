<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফেরত যাচ্ছে।
 *
 *     Dr  প্রদেয় হিসাব (2110, সরবরাহকারীর নামে)   ← দায় কমে
 *     Cr  মজুদ পণ্য (1120)                        ← মাল গুদাম ছাড়ে
 *     Cr  ভ্যাট (2120)                            ← উপকরণ ভ্যাটও ফেরত
 *
 * ── কেন বিলটা বাতিল করলেই হত না ─────────────────────────────────────
 * বিলে দশ বস্তা ছিল, তার দুইটা নষ্ট বেরিয়েছে। বিল বাতিল করলে বাকি
 * আটটার ক্রয়ও খাতা থেকে মুছে যেত — অথচ সেগুলো গুদামেই আছে আর টাকাও
 * দিতে হবে। ফেরত একটা আলাদা ঘটনা, তাই আলাদা কাগজ।
 *
 * ── স্টক ও খাতা একই লেনদেনে ─────────────────────────────────────────
 * ইভেন্টে নয় (প্ল্যান WP-0.3)। মাঝপথে কিছু ভাঙলে দুইটাই ফিরে যায় —
 * নাহলে মাল গুদাম ছাড়ত অথচ দায় কমত না।
 */
final class PurchaseReturnService
{
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseReturn
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('PR');

            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? null);

            $return = PurchaseReturn::create([
                'company_id' => CompanyContext::id(),
                // ⛔ কাগজের শাখা মালের গুদামের শাখা — খাতা এক শাখায়, মাল আরেক শাখায় নয় (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬; চালানের একই নিয়ম)
                'branch_id' => $warehouse->branch_id ?? $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $warehouse->id,
                'purchase_bill_id' => $data['purchase_bill_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
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
                    'source_type' => PurchaseReturn::drillSourceType(),
                    'source_id' => $return->id,
                ]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(PurchaseReturn $return, array $data, array $lines): PurchaseReturn
    {
        $this->assertEditable($return);

        return DB::transaction(function () use ($return, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $return->trx_date);

            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? $return->warehouse_id);

            $return->update([
                'warehouse_id' => $warehouse->id,
                // ⛔ গুদাম বদলালে শাখাও — উপরের create()-এর একই নিয়ম
                'branch_id' => $warehouse->branch_id ?? $return->branch_id,
                'purchase_bill_id' => $data['purchase_bill_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($return, $lines);

            return $return->fresh(['lines']);
        });
    }

    public function confirm(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $return->document_no]),
            ]);
        }

        $this->assertReadyToConfirm($return);

        // মাল ফেরত মানে সরবরাহকারীর কাছে দায় কমে — ছক বসানো থাকলে
        // সেটাও একটা সিদ্ধান্ত, আর সিদ্ধান্তে সই লাগে।
        $this->approvals->assertClear(
            document: $return,
            module: 'purchase',
            action: 'return',
            field: 'status',
            amount: (string) $return->total,
            reason: $return->narration,
        );

        return DB::transaction(function () use ($return) {
            /*
             * ⛔ তালা দিয়ে আবার দেখা — ২৯ সেপ্টেম্বর ২০২৬।
             *
             * উপরের পরীক্ষাটা হাতের কপি দেখে। পুরনো কপি (দুই ট্যাব, দুইবার
             * চাপ) হাতে থাকলে সেটা তখনো "খসড়া" বলে, অথচ ফেরতটা ইতিমধ্যে
             * খাতায় বসে গেছে — দ্বিতীয়বার মাল নড়তে যেত, আর থামত খাতার
             * ইঞ্জিনের কাঁচা ত্রুটিতে। ⭐ তাই সারিটা তালা দিয়ে ডাটাবেজ থেকেই
             * অবস্থাটা পড়া হয়।
             */
            $current = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->value('status');

            if ($current !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('purchase::validation.only_draft_confirms', ['no' => $return->document_no]),
                ]);
            }

            foreach ($return->lines as $line) {
                /*
                 * ⛔ কার্টন না খুলেই ফেরত — ৫ সেপ্টেম্বর ২০২৬।
                 *
                 * ── কী ভাঙা ছিল ─────────────────────────────────────
                 * এখানে আগে কেবল `floor` থেকে বাদ যেত। কিন্তু Stock
                 * Placement আসার পর (৪ সেপ্টেম্বর) আসা মাল আর সরাসরি
                 * তাকে ওঠে না — সে **`unplaced`-এ বসে থাকে**।
                 *
                 * ⚠️ আর ফেরত ঠিক তখনই সবচেয়ে বেশি হয়: গাড়ি থেকে
                 * নামিয়ে দেখা গেল ভুল মাল বা ভাঙা কার্টন — কেউ ওটা
                 * তাকে তোলেনইনি। ফল দুই রকম, দুইটাই খারাপ:
                 *
                 *   তাকে অন্য মাল থাকলে   → **তাক থেকে কেটে নিত**, যা
                 *                            সেখানে যায়ইনি
                 *   তাক খালি থাকলে        → "তাকে এত নেই" বলে থামত,
                 *                            অথচ মালটা হাতের সামনেই
                 *
                 * ⭐ তাই আগে অপেক্ষার ঘর, তারপর তাক — মালটা যেখানে
                 * সত্যিই আছে সেখান থেকেই যায়। ⓘ একটাই সারিতে, তাই
                 * মোট এক মুহূর্তের জন্যও ভুল থাকে না।
                 */
                // ⭐ লট ধরা পণ্য লট ধরেই বেরোয় — অডিট গ৯ ([[lotPlan()]]); প্রতিটা লটে আগে অপেক্ষার ঘর, তারপর তাক
                $plan = $this->lotPlan($line, $return->warehouse);

                if ($plan !== null) {
                    foreach ($plan as $part) {
                        $this->stock->move(
                            product: $line->product,
                            warehouse: $return->warehouse,
                            sourceType: PurchaseReturn::STOCK_SOURCE,
                            sourceId: $return->id,
                            floor: bcmul($part['shelf'], '-1', 4),
                            reason: $return->reasonCode,
                            date: $return->trx_date,
                            documentNo: $return->document_no,
                            batch: $part['batch'],
                            unplaced: bcmul($part['waiting'], '-1', 4),
                        );
                    }

                    continue;
                }

                // ⓘ পাহারা যে ভাগ দেখে পাস করেছে, নেওয়াও ঠিক সেই ভাগ থেকে — [[returnable()]]
                $waiting = $this->returnable($line->product, $return->warehouse)['waiting'];
                $qty = (string) $line->qty;

                $fromWaiting = bccomp($waiting, $qty, 4) >= 0 ? $qty : $waiting;

                $fromFloor = bcsub($qty, $fromWaiting, 4);

                $this->stock->move(
                    product: $line->product,
                    warehouse: $return->warehouse,
                    sourceType: PurchaseReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    floor: bccomp($fromFloor, '0', 4) > 0 ? bcmul($fromFloor, '-1', 4) : '0',
                    reason: $return->reasonCode,
                    date: $return->trx_date,
                    documentNo: $return->document_no,
                    unplaced: bccomp($fromWaiting, '0', 4) > 0 ? bcmul($fromWaiting, '-1', 4) : '0',
                );
            }

            $this->takeCostFromLayers($return);
            $this->postToLedger($return);

            $return->update(['status' => DocumentStatus::CONFIRMED]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * ⭐ নিশ্চিতের দরজা কোন কারণে থামাবে — কিছু না লিখে (৪ অক্টোবর ২০২৬)।
     *
     * ⓘ "নিশ্চিত করুন"-এর আগের সারাংশ ([[PurchaseReturnOverview]]) এটাই দেখায়; ভিতরে [[assertReadyToConfirm()]] — দরজার নিজের, তালা ছাড়া
     * পাহারাগুলো, হুবহু একই ক্রমে। সইয়ের পাহারা নয়, কারণ সেটা অনুরোধ লেখে।
     *
     * @return list<string> থামার কারণগুলো; খালি মানে কিছুই থামাবে না
     */
    public function whatWouldStopTheConfirm(PurchaseReturn $return): array
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
    private function assertReadyToConfirm(PurchaseReturn $return): void
    {
        $return->loadMissing(['lines.product', 'lines.billLine', 'warehouse']);

        if ($return->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        /*
         * খাতায় বসানোর মুহূর্তে আবার পরীক্ষা।
         *
         * খসড়া অবস্থায় লেখার পর অন্য কেউ ওই বিলের বাকিটা ফেরত দিয়ে
         * দিতে পারে, বা মালটা বিক্রি হয়ে যেতে পারে। মাল আর টাকা নড়ে
         * এখানেই, তাই শেষ পাহারাটাও এখানে।
         */
        foreach ($return->lines as $line) {
            $this->assertWithinBilled($line);
            $this->assertEnoughInStock($line->product, $return->warehouse, (string) $line->qty);
            // ⓘ লট ধরা পণ্যে লটেও আছে তো — না থাকলে সারাংশও আগেই বলে ([[lotPlan()]])
            $this->lotPlan($line, $return->warehouse);
        }
    }

    public function cancel(PurchaseReturn $return, string $reason, Carbon|string|null $onDate = null): PurchaseReturn
    {
        if ($return->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $return->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($return, $reason, $date) {
            // ⛔ confirm()-এর মতোই — পুরনো কপি দিয়ে দ্বিতীয় বাতিল মাল আবার ফেরাত। ২৯ সেপ্টেম্বর ২০২৬।
            $current = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->value('status');

            if ($current === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('purchase::validation.already_cancelled', ['no' => $return->document_no]),
                ]);
            }

            if ($current === DocumentStatus::CONFIRMED) {
                $return->loadMissing(['lines.product', 'warehouse']);

                /*
                 * মালটা গুদামে ফিরে আসে — সারি মুছে নয়, উল্টো সারিতে।
                 *
                 * ⚠️ **ঠিক যেখান থেকে গিয়েছিল, সেখানেই** — অর্ধেক
                 * অপেক্ষার ঘর থেকে গেলে অর্ধেক সেখানেই ফেরে।
                 *
                 * ⛔ সবটা `floor`-এ ফেরালে বাতিল করাটা নীরবে একটা
                 * **বসানোর কাজ** হয়ে যেত: যে মাল কেউ কোনোদিন বুঝে
                 * নেয়নি সেটা বিক্রয়যোগ্য হয়ে উঠত, আর মালিকের নিয়ম
                 * ("বসানোর আগে বিক্রি নয়") একটা বাতিল দিয়ে পাশ কাটানো
                 * যেত।
                 *
                 * ⓘ ভাগটা আন্দাজ করা হয় না — এই কাগজের নিজের সারিগুলো
                 * যোগ করে উল্টে দেওয়া হয়, তাই সংখ্যাটা সবসময় হুবহু।
                 */
                /*
                 * ⭐ লট ধরেও — যে লট থেকে গিয়েছিল সেই লটেই ফেরে (অডিট গ৯)। ⓘ পণ্য আর লট ধরে নিট; লটহীন সারির লট null,
                 * তাই আগের আচরণ সেখানে অবিকল।
                 */
                $nets = StockMovement::query()
                    ->where('source_type', PurchaseReturn::STOCK_SOURCE)
                    ->where('source_id', $return->id)
                    ->groupBy('product_id', 'batch_id')
                    ->selectRaw('product_id, batch_id')
                    ->selectRaw('COALESCE(SUM(floor_change), 0) as floor')
                    ->selectRaw('COALESCE(SUM(unplaced_change), 0) as unplaced')
                    ->get();

                foreach ($nets as $row) {
                    $productId = (int) $row->product_id;
                    $net = ['floor' => (string) $row->floor, 'unplaced' => (string) $row->unplaced];

                    /*
                     * ⓘ শূন্য নিট বাদ — [[StockService::move()]] শূন্য
                     * চলাচলে ইচ্ছাকৃতভাবে থামে ("কিছুই নড়ছে না")। এখানে
                     * শূন্য মানে ঐ পণ্যের সারিগুলো আগেই কাটাকাটি হয়ে
                     * গেছে, আর সেটা ত্রুটি নয়।
                     */
                    if (bccomp($net['floor'], '0', 4) === 0 && bccomp($net['unplaced'], '0', 4) === 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: Product::findOrFail($productId),
                        warehouse: $return->warehouse,
                        sourceType: PurchaseReturn::STOCK_SOURCE,
                        sourceId: $return->id,
                        floor: bcmul($net['floor'], '-1', 4),
                        date: $date,
                        documentNo: $return->document_no,
                        narration: $reason,
                        batch: $row->batch_id === null ? null : Batch::query()->findOrFail((int) $row->batch_id),
                        unplaced: bcmul($net['unplaced'], '-1', 4),
                    );
                }

                $this->putCostBackInLayers($return, $date);

                $this->posting->reverse(
                    sourceType: PurchaseReturn::drillSourceType(),
                    sourceId: $return->id,
                    reversalDate: $date,
                    reason: $reason,
                );
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
     * ফেরত যাওয়া মালের আসল দাম — যে বিলে এসেছিল, সেই স্তর থেকেই।
     *
     * ── কেন ওই বিলের স্তর, সাধারণ FIFO নয় ───────────────────────────
     * সরবরাহকারীকে যে দুই বস্তা ফেরত যাচ্ছে সেগুলো **ওই বিলেরই** মাল।
     * প্রথমে সাধারণ FIFO বসানো হয়েছিল, আর টেস্ট ধরিয়ে দিল কেন সেটা
     * ভুল: ৫০ টাকায় কেনা বস্তা তাকের পুরনো ৩,৪০০ দরে বেরিয়ে যাচ্ছিল,
     * আর ৬,৭০০ টাকা মূল্য-পার্থক্য খাতে জমছিল। খাতা ভারসাম্যে ছিল,
     * তবু সংখ্যাটা মিথ্যা বলত।
     *
     * ওই স্তরে মাল না কুলালে বাকিটা FIFO-তে আসে — ততক্ষণে ওই বিলের
     * মাল বিক্রি হয়ে গেলে তাকে যা আছে সেটাই তো যাচ্ছে। তখনকার
     * পার্থক্যটা সত্যিকারের পার্থক্য, আর সেটাই খাতে বসে।
     *
     * বিলের সাথে জোড়া নেই এমন লাইনে সাধারণ FIFO — কোন চালানের মাল তা
     * বলার কিছু নেই বলেই।
     */
    private function takeCostFromLayers(PurchaseReturn $return): void
    {
        $cost = '0';

        foreach ($return->lines as $line) {
            $billLine = $line->billLine;

            $taken = $billLine !== null
                ? $this->costs->issueFromSource(
                    product: $line->product,
                    qty: (string) $line->qty,
                    fromSourceType: $billLine->purchase_receipt_line_id !== null
                        ? PurchaseReceipt::STOCK_SOURCE
                        : PurchaseBill::STOCK_SOURCE,
                    fromSourceId: $billLine->purchase_receipt_line_id !== null
                        ? ($billLine->receiptLine?->purchase_receipt_id ?? 0)
                        : $billLine->purchase_bill_id,
                    sourceType: PurchaseReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    documentNo: $return->document_no,
                    date: $return->trx_date,
                )
                : $this->costs->issue(
                    product: $line->product,
                    qty: (string) $line->qty,
                    sourceType: PurchaseReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    documentNo: $return->document_no,
                    date: $return->trx_date,
                );

            $cost = bcadd($cost, $taken['cost'], 4);
        }

        $return->update(['cost_of_goods' => $cost]);
        $return->refresh();
    }

    /**
     * ⛔ বাতিলে স্তরও ফেরে — ২৯ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * [[takeCostFromLayers()]] স্তর থেকে মাল কমিয়ে টান-সারি লেখে। বাতিলে
     * মাল গুদামে ফিরত আর খাতা উল্টাত, কিন্তু স্তর যেমন ছিল তেমনই থাকত।
     * ⓘ হাতে গোনা: ১০ কেনা, ৪ ফেরত, ফেরত বাতিল → তাকে ১০, খাতায় ৫০০,
     * অথচ স্তরে ৬। ⚠️ পরের বিক্রয়ে ঐ ৪টার দাম স্তরে পাওয়া যেত না —
     * অন্য চালানের দামে বেরোত, বা "স্তরে নেই" বলে থামত।
     *
     * ⭐ যে স্তর থেকে যতটা গিয়েছিল, সেখানেই ততটা ফেরে
     * ([[CostLayerService::returnToLayers()]], বিক্রয় বিল বাতিলের একই পথ)।
     * `returnedBy` কেবল এই কাগজের বাতিল-সারি গোনে।
     */
    private function putCostBackInLayers(PurchaseReturn $return, Carbon $date): void
    {
        $qtyOf = [];
        $productOf = [];

        foreach ($return->lines as $line) {
            $id = (int) $line->product_id;
            $qtyOf[$id] = bcadd($qtyOf[$id] ?? '0', (string) $line->qty, 4);
            $productOf[$id] = $line->product;
        }

        foreach ($qtyOf as $id => $qty) {
            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $this->costs->returnToLayers(
                product: $productOf[$id],
                qty: $qty,
                issuedSourceType: PurchaseReturn::STOCK_SOURCE,
                issuedSourceId: (int) $return->id,
                sourceType: PurchaseReturn::STOCK_SOURCE.':cancel',
                sourceId: (int) $return->id,
                documentNo: $return->document_no,
                date: $date,
                returnedBy: [(int) $return->id],
            );
        }
    }

    private function postToLedger(PurchaseReturn $return): void
    {
        $total = (string) $return->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.zero_value_return'),
            ]);
        }

        $lines = [
            [
                'account_id' => $this->account(StandardChart::PAYABLE)->id,
                'debit' => $total,
                'party_type' => 'supplier',
                'party_id' => $return->supplier_id,
                'narration' => __('purchase::message.return_lowers_payable', ['no' => $return->document_no]),
            ],
            [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,

                /*
                 * মজুদ কমে যত টাকায় মালটা ঢুকেছিল, ঠিক ততটাই।
                 *
                 * ── কেন বিলের দর নয় ────────────────────────────────
                 * প্রদেয় কমে বিলের দরে — সেটাই সরবরাহকারী ফেরত দেবেন।
                 * কিন্তু মজুদ থেকে বেরোয় মালটা যে দামে ঢুকেছিল সেটাই।
                 * দুইটা সচরাচর এক, কারণ ফেরত যাওয়া মাল সাধারণত ওই
                 * বিলেরই। এক না হলে পার্থক্যটা মূল্য-পার্থক্য খাতে যায়,
                 * নিচে।
                 *
                 * বিলের দরে মজুদ কমালে খাতা আর তাক আবার আলাদা হয়ে
                 * যেত — ঠিক যে রোগটা সারাতে স্তর বসানো হয়েছে।
                 */
                'credit' => (string) $return->cost_of_goods,
                'narration' => __('purchase::message.stock_out', ['no' => $return->document_no]),
            ],
        ];

        /*
         * বিলের দর আর মালের আসল দামের পার্থক্য।
         *
         * পুরনো সস্তা চালানের মাল ফেরত গেলে সরবরাহকারী আজকের দরে টাকা
         * ফেরত দেন, অথচ মজুদ থেকে বেরোয় পুরনো দাম। পার্থক্যটা কোথাও
         * যেতে হবে, আর সেটা মুনাফা নয় — ক্রয়ের দামের হেরফের, যার নিজের
         * খাত আগে থেকেই আছে (চালান ও বিলের দর আলাদা হলে ওখানেই যায়)।
         */
        $variance = bcsub((string) $return->subtotal, (string) $return->cost_of_goods, 4);

        if (bccomp($variance, '0', 4) !== 0) {
            $account = $this->account(StandardChart::PURCHASE_PRICE_VARIANCE);

            $lines[] = bccomp($variance, '0', 4) > 0
                ? ['account_id' => $account->id, 'credit' => $variance,
                    'narration' => __('purchase::message.price_variance', ['no' => $return->document_no])]
                : ['account_id' => $account->id, 'debit' => bcmul($variance, '-1', 4),
                    'narration' => __('purchase::message.price_variance', ['no' => $return->document_no])];
        }

        $tax = (string) $return->tax;

        if (bccomp($tax, '0', 4) > 0) {
            /*
             * উপকরণ ভ্যাটও ফেরত যায়।
             *
             * কেনার সময় ওটা দাবি করা হয়েছিল; মালটা ফেরত গেলে দাবিটাও
             * থাকে না। না ফেরালে ভ্যাটের হিসাবে এমন একটা দাবি থেকে যেত
             * যার পেছনে কোনো মাল নেই।
             */
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'credit' => $tax,
                'narration' => __('purchase::message.return_vat', ['no' => $return->document_no]),
            ];
        }

        $this->posting->post(
            sourceType: PurchaseReturn::drillSourceType(),
            sourceId: $return->id,
            trxDate: $return->trx_date,
            lines: $lines,
            documentNo: $return->document_no,
            branchId: $return->branch_id,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(PurchaseReturn $return, array $lines): void
    {
        $return->lines()->delete();

        $subtotal = '0';
        $taxTotal = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $qty = $this->money($line['qty'] ?? null);

            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_product')]);
            }

            /*
             * প্যাকে ফেরত — "১ বাক্স ফেরত" লেখা যায়।
             *
             * শুধু পরিমাণটাই নামে; দর নিচে মূল বিলের লাইন থেকে আসে, আর
             * ওখানে সেটা আগেই পণ্যের এককে বসানো।
             */
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null);
            $qty = $pack['qty'];

            $billLine = null;

            if (filled($line['purchase_bill_line_id'] ?? null)) {
                $billLine = PurchaseBillLine::query()->whereKey((int) $line['purchase_bill_line_id'])->first();

                if ($billLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.unknown_bill_line'),
                    ]);
                }

                if ((int) $billLine->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.line_product_mismatch'),
                    ]);
                }

                /*
                 * ⛔ লাইনটা কার বিলের — ২৯ সেপ্টেম্বর ২০২৬।
                 *
                 * আগে কেবল পণ্য মেলানো হত। ফলে অন্য সরবরাহকারীর বিলের,
                 * বা খসড়া বিলের লাইন ধরে ফেরত লেখা যেত — দর আর "কত ফেরত
                 * দেওয়া যায়" দুইটাই ভুল বিল থেকে আসত, আর দামের স্তরও টানা
                 * হত অন্যের চালান থেকে। ⭐ তাই: খাতায় বসা বিল, এই ফেরতেরই
                 * সরবরাহকারী, আর ফেরতে বিল বলা থাকলে ঠিক সেই বিল।
                 */
                $bill = $billLine->bill;

                if ($bill === null || ! in_array($bill->status, DocumentStatus::POSTED, true)) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.bill_not_confirmed', ['no' => $bill?->document_no ?? '']),
                    ]);
                }

                if ((int) $bill->supplier_id !== (int) $return->supplier_id
                    || ($return->purchase_bill_id !== null && (int) $return->purchase_bill_id !== (int) $bill->id)) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.unknown_bill_line'),
                    ]);
                }
            }

            /*
             * দর বিল থেকে, হাতে লেখা নয় (থাকলে)।
             *
             * ফেরতের দর কেনার দরই হওয়া উচিত। হাতে বসাতে দিলে কেউ বেশি
             * দরে ফেরত দেখিয়ে প্রদেয় বেশি কমাতে পারত, আর মজুদের মূল্যও
             * ভুল হত।
             */
            $rate = match (true) {
                /*
                 * ⭐ বিলের **নিট** দর — ছাড় বাদে (অডিট গ১৮, ৪ অক্টোবর ২০২৬)।
                 *
                 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────
                 * দর আসত বিলের তালিকা-দর থেকে, ছাড়ের আগের। ১০০ টাকার মাল ১০ ছাড়ে ৯০-এ কিনে ফেরত দিলে
                 * সরবরাহকারীর দেনা কমত ১০০ — অথচ তাঁকে দিতে হত ৯০; আর মজুদ বেরোত ৯০-এ, তাই বাকি ১০
                 * "মূল্যপার্থক্য" খাতে ভুয়া লাভ। ⓘ বিলের ছাড় সারিতে ভাগ হয়ে বসে (মালিক, ২৭ সেপ্টেম্বর),
                 * তাই সারির `discount`-ই পুরো ছাড়।
                 */
                $billLine !== null => $this->netRate($billLine),

                // হাতে লেখা দর — যে এককে লেখা, সেখান থেকে নামে
                filled($line['rate'] ?? null) => $this->packed(
                    $product,
                    '1',
                    $pack['entered_unit_id'],
                    $this->money($line['rate']),
                )['rate'],

                // মাস্টারের দাম — আগেই পণ্যের এককে, নামানোর কিছু নেই
                default => $this->money($product->purchase_price),
            };

            $amount = bcmul($qty, $rate, 4);
            $tax = $this->returnTax($line['tax'] ?? null, $qty, $billLine);

            PurchaseReturnLine::create([
                'company_id' => $return->company_id,
                'purchase_return_id' => $return->id,
                'product_id' => $product->id,
                'purchase_bill_line_id' => $billLine?->id,
                'qty' => $qty,
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,
                'tax' => $tax,
                'amount' => $amount,
                'line_no' => ++$lineNo,
            ]);

            $subtotal = bcadd($subtotal, $amount, 4);
            $taxTotal = bcadd($taxTotal, $tax, 4);
        }

        $return->update([
            'subtotal' => $subtotal,
            'tax' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 4),
        ]);
    }

    /** বিলের সারির এককপ্রতি নিট দর — (পরিমাণ × দর − ছাড়) ÷ পরিমাণ। */
    private function netRate(PurchaseBillLine $billLine): string
    {
        $qty = (string) $billLine->qty;

        if (bccomp($qty, '0', 4) <= 0) {
            return (string) $billLine->rate;
        }

        $net = bcsub(bcmul($qty, (string) $billLine->rate, 4), (string) ($billLine->discount ?? '0'), 4);

        return bcdiv($net, $qty, 4);
    }

    /**
     * ফেরতের ভ্যাট — বিলে যতটা দেওয়া হয়েছিল, তার আনুপাতিক অংশের বেশি নয় (অডিট গ১৮, ৪ অক্টোবর ২০২৬)।
     *
     * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────
     * ঘরটা যা লেখা হত তা-ই নিত। ৳১০০-র মাল ফেরতে ভ্যাট ৫ লাখ লিখলে সরবরাহকারীর দেনা ৫ লাখ কমত — কোনো
     * সই বা চিহ্ন ছাড়া। ⭐ এখন:
     * - বিলের সারি ধরে ফেরত: খালি রাখলে আনুপাতিক অংশ নিজে বসে; লেখা অঙ্ক তার বেশি হলে থামে।
     * - ভ্যাট বন্ধ কোম্পানিতে ([[CalculatesLineTotals::lineFigures()]]-এর একই সুইচ) শূন্য ছাড়া কিছু নয়।
     * ⓘ বিল ছাড়া হাতে লেখা ফেরতে মেলানোর কিছু নেই — সেখানে লেখা অঙ্কই থাকে, আর সেই ফেরত সইয়ের পথে যায়।
     */
    private function returnTax(mixed $entered, string $qty, ?PurchaseBillLine $billLine): string
    {
        $blank = $entered === null || trim((string) $entered) === '';
        $tax = $this->money($blank ? '0' : $entered);

        if (! (bool) app(SettingsService::class)->get('purchase.vat_enabled', false)) {
            if (bccomp($tax, '0', 4) !== 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.vat_is_off'),
                ]);
            }

            return '0.0000';
        }

        if ($billLine === null) {
            return $tax;
        }

        $billed = (string) $billLine->qty;
        $share = bccomp($billed, '0', 4) > 0
            ? bcdiv(bcmul((string) ($billLine->tax ?? '0'), $qty, 4), $billed, 4)
            : '0.0000';

        if ($blank) {
            return $share;
        }

        if (bccomp($tax, $share, 2) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.return_tax_over_bill', ['max' => bcadd($share, '0', 2)]),
            ]);
        }

        return $tax;
    }

    /**
     * যত কেনা হয়েছে তার বেশি ফেরত নয়।
     *
     * বিলের লাইন ধরে ফেরত হলে এটা মেলানো যায়। না মেলালে দশ বস্তার
     * বিলে বারো বস্তা ফেরত দেখিয়ে প্রদেয় বেশি কমানো যেত, আর গুদামেও
     * ঋণাত্মক মাল বসত।
     */
    private function assertWithinBilled(PurchaseReturnLine $line): void
    {
        $billLine = $line->billLine;

        if ($billLine === null) {
            return;
        }

        $alreadyReturned = PurchaseReturnLine::query()
            ->where('purchase_bill_line_id', $billLine->id)
            ->whereKeyNot($line->id)
            ->whereHas('return', fn ($q) => $q->posted())
            ->sum('qty');

        $room = bcsub((string) $billLine->qty, (string) ($alreadyReturned ?: '0'), 4);

        if (bccomp((string) $line->qty, $room, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.over_returned', [
                    'no' => $billLine->bill?->document_no ?? '',
                    'room' => rtrim(rtrim($room, '0'), '.'),
                ]),
            ]);
        }
    }

    /**
     * গুদামে মালটা আছে তো।
     *
     * ফেরত পাঠানো মানে গুদাম থেকে বেরোনো — যা নেই তা পাঠানো যায় না।
     * না দেখলে স্টক ঋণাত্মক হয়ে যেত, আর ঋণাত্মক স্টক মানে কোথাও
     * একটা গণনা ভুল, যেটা মাস শেষে ধরা পড়ে।
     */
    private function assertEnoughInStock(Product $product, ?Warehouse $warehouse, string $qty): void
    {
        if ($warehouse === null) {
            return;
        }

        $available = $this->returnable($product, $warehouse)['total'];

        if (bccomp($available, $qty, 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.not_enough_to_return', [
                    'product' => $product->name(),
                    'available' => rtrim(rtrim($available, '0'), '.'),
                ]),
            ]);
        }
    }

    /**
     * ⛔ ফেরতের জন্য কত মাল হাতে আছে — পাহারা আর [[confirm()]] দুইজনেরই
     * একমাত্র উৎস। ২৭ সেপ্টেম্বর ২০২৬, লাইভ-QA/প্রমাণ-পরীক্ষার ধরা।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * [[assertEnoughInStock()]] গুনত `availableQty()` — যা **কেবল তাক**
     * (তাকে − অর্ডারে ধরা − আটকানো)। অথচ [[confirm()]] মালটা নেয় **আগে
     * অপেক্ষার ঘর থেকে**, তারপর তাক থেকে। ফলে গাড়ি থেকে নামা ১০টা
     * সাবানের ৩টা ফেরত দিতে গেলে বলত "গুদামে আছে 0" — মাল হাতের
     * সামনে, অথচ পাহারা সেই ঘরটা দেখতেই পেত না।
     *
     * ⭐ তাই সংখ্যাটা এক জায়গায়, একটা মুহূর্তের ছবি থেকে
     * ([[StockService::statesFor()]], একটা কোয়েরি):
     *
     *     অপেক্ষায় = unplaced              (ঋণাত্মক হলে ০)
     *     তাকে     = তাকে − ধরা − আটকানো   (ঋণাত্মক হলে ০)
     *     মোট      = অপেক্ষায় + তাকে
     *
     * ⚠️ তাকের অংশে ধরা/আটকানো বাদ — অন্যের অর্ডারে ধরা মাল ফেরতে গেলে
     * সেই অর্ডার খালি হাতে দাঁড়াত। অপেক্ষার ঘরে কিছু ধরা যায় না, তাই
     * সেখানে বাদ দেওয়ার কিছু নেই।
     *
     * ⓘ পাহারা যা "আছে" বলে, confirm() ঠিক তা-ই নেয় — দুইটা আলাদা
     * হিসাব থাকলে আবার একদিন একটা হ্যাঁ বলত আর অন্যটা না।
     *
     * @return array{waiting: string, shelf: string, total: string}
     */
    private function returnable(Product $product, ?Warehouse $warehouse): array
    {
        $states = $this->stock->statesFor($product, $warehouse);

        $waiting = bccomp($states['unplaced'], '0', 4) > 0 ? bcadd($states['unplaced'], '0', 4) : '0.0000';
        $shelf = bccomp($states['available'], '0', 4) > 0 ? bcadd($states['available'], '0', 4) : '0.0000';

        return ['waiting' => $waiting, 'shelf' => $shelf, 'total' => bcadd($waiting, $shelf, 4)];
    }

    /**
     * ⭐ লট ধরা পণ্যের ফেরত কোন লট থেকে কতটা — Inventory অডিট গ৯, ৪ অক্টোবর ২০২৬।
     *
     * ⛔ আগে ফেরত মাল বের করত লট ছাড়া: পণ্যের মোট কমত, লট A তবু পুরো দেখাত — পরে লট A থেকে না-থাকা মাল বিক্রি হত, আর
     * রিকলের খাতা ভুল লট দেখাত।
     * ⓘ কোন লট:
     *   · বিলের সারি লট বললে (বিলের, নাহলে তার মাল-গ্রহণ সারির `batch_no`) — কেবল সেই লট; কম থাকলে থামে, অন্য লটে গড়ায় না;
     *   · নাহলে আগে-মেয়াদ ক্রমে সব লট (⚠️ মেয়াদোত্তীর্ণও — মেয়াদ পেরোনো মাল সরবরাহকারীকে ফেরত দেওয়াই স্বাভাবিক), তারপর
     *     লট-ধরা শুরুর আগের লটহীন মাল।
     * ⓘ প্রতিটা লটে আগে অপেক্ষার ঘর, তারপর তাক (আটকানো বাদ) — পণ্য-স্তরের [[returnable()]]-এর একই ক্রম। গোনা তালাসহ।
     *
     * @return list<array{batch: ?Batch, waiting: string, shelf: string}>|null লট ধরা পণ্য না হলে null
     */
    private function lotPlan(PurchaseReturnLine $line, ?Warehouse $warehouse): ?array
    {
        if ($warehouse === null || ! ($line->product?->track_batch ?? false)) {
            return null;
        }

        $named = $this->lotOnTheBill($line);
        $candidates = $named !== null
            ? [$named]
            : [...Batch::query()->where('product_id', $line->product_id)->fefo()->lockForUpdate()->get()->all(), null];

        $plan = [];
        $left = bcadd((string) $line->qty, '0', 4);
        $found = '0';

        foreach ($candidates as $batch) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $has = StockMovement::query()
                ->where('product_id', $line->product_id)
                ->where('warehouse_id', $warehouse->id)
                ->when($batch === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $batch->id))
                ->lockForUpdate()
                ->selectRaw('COALESCE(SUM(unplaced_change), 0) as waiting, COALESCE(SUM(floor_change - hold_change), 0) as shelf')
                ->first();

            $waiting = bccomp((string) $has->waiting, '0', 4) > 0 ? bcadd((string) $has->waiting, '0', 4) : '0';
            $shelf = bccomp((string) $has->shelf, '0', 4) > 0 ? bcadd((string) $has->shelf, '0', 4) : '0';
            $found = bcadd($found, bcadd($waiting, $shelf, 4), 4);

            $fromWaiting = bccomp($waiting, $left, 4) >= 0 ? $left : $waiting;
            $left = bcsub($left, $fromWaiting, 4);
            $fromShelf = bccomp($shelf, $left, 4) >= 0 ? $left : $shelf;
            $left = bcsub($left, $fromShelf, 4);

            if (bccomp(bcadd($fromWaiting, $fromShelf, 4), '0', 4) > 0) {
                $plan[] = ['batch' => $batch, 'waiting' => $fromWaiting, 'shelf' => $fromShelf];
            }
        }

        if (bccomp($left, '0', 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => $named !== null
                    ? __('purchase::validation.return_lot_short', [
                        'product' => $line->product->name(),
                        'lot' => $named->batch_no,
                        'available' => rtrim(rtrim(bcadd($found, '0', 4), '0'), '.') ?: '0',
                    ])
                    : __('purchase::validation.not_enough_to_return', [
                        'product' => $line->product->name(),
                        'available' => rtrim(rtrim(bcadd($found, '0', 4), '0'), '.') ?: '0',
                    ]),
            ]);
        }

        return $plan;
    }

    /** বিলের সারি যে লটে মাল এনেছিল — বিলের সারির, নাহলে তার মাল-গ্রহণ সারির লট নম্বর ধরে */
    private function lotOnTheBill(PurchaseReturnLine $line): ?Batch
    {
        $billLine = $line->billLine;
        $no = trim((string) ($billLine?->batch_no ?: $billLine?->receiptLine?->batch_no));

        if ($no === '') {
            return null;
        }

        return Batch::query()->where('product_id', $line->product_id)->where('batch_no', $no)->first();
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

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(PurchaseReturn $return): void
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $return->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
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
