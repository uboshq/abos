<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মাল গোনা — খাতায় যা লেখা, তাকে যা সত্যিই আছে।
 *
 * নগদ গণনার ([[CashCountService]]) হুবহু যমজ, শুধু টাকার বদলে মাল আর এক
 * নোটের বদলে বহু পণ্য। এই সার্ভিস কেবল গণনা লেখে ও পার্থক্য বের করে —
 * খসড়া অবস্থায় খাতা এক চুলও নড়ে না।
 *
 * ── ⭐ দুই ধাপ, আর দ্বিতীয়টা আজ বসল — ১৮ সেপ্টেম্বর ২০২৬ ───────────
 * এই ফাইলের মাথায় আগে লেখা ছিল: *"সেই ধাপটা আলাদা সার্ভিস-মেথডে বসবে,
 * নিজের অনুমোদন-পারমিশন ও পরীক্ষা নিয়ে।"*
 *
 * ⛔ কিন্তু বসেনি। ফল: গণনা লেখা হত, পার্থক্য পর্দায় দেখা যেত, আর
 * **কোনোদিন কিছুই ঠিক হত না** — খাতার সংখ্যা যা ছিল তা-ই থেকে যেত।
 * ⚠️ অর্থাৎ গোটা কাজটার দ্বিতীয় অর্ধেক অনুপস্থিত ছিল, আর পর্দা দেখে
 * বোঝার কোনো উপায় ছিল না: গণনাটা সেভ হত, সবুজ বার্তা আসত।
 *
 * ⓘ এখন [[approve()]] আছে: ওটাই পার্থক্যকে সত্যিকারের সমন্বয়ে পরিণত
 * করে ([[StockAdjustmentService]] দিয়ে, তাক-দাম-খতিয়ান একসাথে), আর
 * ঠিক ওই মুহূর্তেই সই চায়।
 *
 * ── ⚠️ কেন সই এখানে, `record()`-এ নয় ───────────────────────────────
 * গোনা কোনো সিদ্ধান্ত নয়, একটা পর্যবেক্ষণ — ওটা আটকানোর মানে নেই।
 * ⛔ সিদ্ধান্তটা হলো **পার্থক্যটা মেনে নেওয়া**, কারণ তখনই মাল খাতা
 * থেকে উবে যায় (বা বিনা টাকায় জন্ম নেয়)। ⓘ নগদ গণনাতেও হুবহু এই
 * ভাগ — `record()` তারপর `approve()`।
 *
 * ── সবচেয়ে বিপজ্জনক নিয়ম: গোনা-হয়নি ≠ শূন্য ────────────────────────
 * লাইন বসে কেবল যে পণ্য গণনাকারী সত্যিই দিয়েছেন। তালিকায় নেই মানে "গোনা
 * হয়নি", "নেই" নয় — তাই পরে অনুমোদন কেবল এই লাইনগুলোকেই ছোঁবে, গোটা
 * গুদামকে নয়।
 */
final class StockCountService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly StockAdjustmentService $adjustments,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * গণনা সংরক্ষণ — এখনো কোনো সমন্বয় হয় না।
     *
     * প্রতিটা লাইনের book_qty গণনার মুহূর্তে খাতার সংখ্যার snapshot; পরে
     * অনুমোদন পরদিন হলেও "গণনার সময় কত পার্থক্য ছিল" জানা যায়।
     *
     * @param  array<string, mixed>  $data  count_date · warehouse_id · narration · counted_by
     * @param  list<array{product_id: int|string, counted_qty: int|string}>  $lines
     */
    public function record(array $data, array $lines): StockCount
    {
        return DB::transaction(function () use ($data, $lines) {
            $warehouse = Warehouse::query()->find($data['warehouse_id'] ?? null);

            if ($warehouse === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('inventory::validation.count_warehouse_required'),
                ]);
            }

            $clean = $this->cleanLines($lines);

            if ($clean === []) {
                throw ValidationException::withMessages([
                    'lines' => __('inventory::validation.count_needs_lines'),
                ]);
            }

            // ⓘ এক গুদামের গণনা একটা একটা করে — দুইজন একসাথে একই পণ্য লিখলে দুজনেই "অপেক্ষায় কিছু নেই" দেখতেন (গ৭)
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->first();

            $countDate = Carbon::parse($data['count_date'] ?? now());

            // ⭐ কোন ভাণ্ডার গোনা হচ্ছে — দামি মাল, না ফ্রি (মজুদ ⚠️৬ক); অচেনা লেখা মানে দামি, আগের মতো
            $kind = ($data['kind'] ?? null) === StockCount::KIND_FREE ? StockCount::KIND_FREE : StockCount::KIND_COUNT;

            $count = StockCount::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('SC'),
                'kind' => $kind,
                'count_date' => $countDate->toDateString(),
                'warehouse_id' => $warehouse->id,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'counted_by' => $data['counted_by'] ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);

            foreach ($clean as $line) {
                $product = Product::query()->find($line['product_id']);

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('inventory::validation.count_product_missing'),
                    ]);
                }

                /*
                 * ⛔ পিস-বাক্সে আধা গোনা যায় না, কেজি-লিটারে যায় — কাগজের লাইনের একই নিয়ম ([[PackConversion::toStockQty()]];
                 * মালিক, ৬ অক্টোবর ২০২৬)। পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️১২: গণনা এই দরজা দিয়ে যেত না, তাই "২.৫ পিস"
                 * মেনে নিলে আধা পিসের ঘাটতি বা বাড়তি খাতায় বসত ([[AHalfPieceIsNeitherCountedNorAdjustedTest]])।
                 */
                app(PackConversion::class)->toStockQty($product, $line['counted_qty']);

                /*
                 * খাতার সংখ্যা — গণনার মুহূর্তের floor, ওই গুদামে।
                 * ⛔ লট ধরে গোনা হলে সেই **লটের** সংখ্যা — ২৯ সেপ্টেম্বর ২০২৬। ⚠️ আগে পুরো
                 * পণ্যের সংখ্যা বসত, তাই লট A-র ৩ গুনলে পার্থক্য হত "১০ থেকে −৭", আর
                 * কেউ-না-গোনা লট খালি হত ([[TheCountWasSettledAtTheWrongMomentTest]])।
                 */
                $lot = $this->lotFor($product, $line);

                /*
                 * ⛔ একই পণ্যের দ্বিতীয় খসড়া নয় — Inventory অডিট গ৭, ৪ অক্টোবর ২০২৬।
                 * ⚠️ সমন্বয় সইয়ে আটকালে খসড়াটা পড়ে থাকত, আবার চাপলে আরেকটা; দুটোই একই খাতার সংখ্যা ধরে, তাই
                 * অনুমোদনকারী পরে দুটো মানলে একই ঘাটতি দুইবার বসত। ⓘ আগেরটা মেনে নিন বা বাতিল করুন ([[cancel()]])।
                 */
                $waiting = $this->sameGoods((int) $product->id, (int) $warehouse->id, $lot?->id, $kind)
                    ->where('status', DocumentStatus::DRAFT)
                    ->value('document_no');

                if ($waiting !== null) {
                    throw ValidationException::withMessages([
                        'lines' => __('inventory::validation.count_already_waiting', [
                            'product' => $product->name(),
                            'document' => $waiting,
                        ]),
                    ]);
                }

                /*
                 * ⛔ পথে থাকা বদলির মাল গোনা যায় না — পুরো ERP অডিট ⛔৭, ৬ অক্টোবর ২০২৬।
                 * ⓘ পাঠানোয় তাক কমে না (মাল আটকে থাকে, [[StockTransferService::dispatch()]]), অথচ মালটা ট্রাকে। তখন গুনলে
                 * খাতা বলত তাকে আছে, হাতে মিলত না — মিথ্যা ঘাটতি, আর মেনে নিলে সেটা খরচে। ⚠️ কোন লট গেছে তা জানা যায় কেবল
                 * পৌঁছানোর দিন, তাই বাদ দিয়ে গোনা যায় না — পৌঁছানো পর্যন্ত থামা।
                 */
                /*
                 * ⛔ খোঁজা দেয়াল ছাড়া — বদলি লেখা হয় পাঠকের শাখায়, তাই অন্য শাখার মানুষ পাঠালে এই গুদামের কেরানি সেটা
                 * দেখতেনই না, আর মিথ্যা ঘাটতি আবার বসত। ⓘ গন্তব্যেও থামা: মাল গুদামে নামলেও গ্রহণ পর্যন্ত খাতায় নেই — তখন
                 * গুনলে মিথ্যা বাড়তি, মেনে নিলে পরে গ্রহণে দ্বিগুণ (পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬, মজুদের নতুন ⚠️ আর ⓘ;
                 * [[ACountWaitsForATransferFromAnyBranchTest]])।
                 */
                $onTheWay = \App\Modules\Inventory\Models\StockTransfer::query()
                    ->withoutGlobalScopes(StockService::VIEW_WALLS)
                    ->where(fn ($q) => $q->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id))
                    ->where('status', DocumentStatus::CONFIRMED)
                    ->whereHas('lines', fn ($q) => $q->where('product_id', $product->id))
                    ->first(['document_no', 'to_warehouse_id']);

                if ($onTheWay !== null) {
                    $arriving = (int) $onTheWay->to_warehouse_id === (int) $warehouse->id;

                    throw ValidationException::withMessages([
                        'lines' => __($arriving ? 'inventory::validation.count_while_arriving' : 'inventory::validation.count_while_on_the_way', [
                            'product' => $product->name(),
                            'transfer' => $onTheWay->document_no,
                        ]),
                    ]);
                }

                $free = $kind === StockCount::KIND_FREE;

                // ⓘ ফ্রি কাগজে খাতার সংখ্যা ফ্রি ভাণ্ডারের — লট ধরে গোনা হলে সেই লটের ফ্রি (মজুদ ⚠️৬ক)
                $bookQty = match (true) {
                    $free && $lot !== null => $lot->freeBalance($warehouse),
                    $free => $this->stock->freeQty($product, $warehouse),
                    $lot !== null => $this->adjustments->lotFloor($lot, $warehouse),
                    default => $this->stock->floorQty($product, $warehouse),
                };

                $count->lines()->create([
                    'company_id' => CompanyContext::id(),
                    'product_id' => $product->id,
                    'batch_id' => $lot?->id,
                    'book_qty' => $bookQty,
                    'counted_qty' => $line['counted_qty'],
                    'difference' => bcsub($line['counted_qty'], $bookQty, 4),
                    /*
                     * ⭐ লেখা দর আগে, না থাকলে গড় — Inventory অডিট ম১, ৫ অক্টোবর ২০২৬।
                     * ⛔ আগে সবসময় গড় বসত: মানুষ বাড়তির দর লিখলেও ফেলে দেওয়া হত, আর স্তর না থাকলে গড়ও নেই, তাই বাড়তি
                     * খাতায় তোলাই যেত না ("দর লাগবে")। ⓘ মেনে নেওয়ার দিন বাড়তির স্তর এই দরেই বসে ([[StockAdjustmentService::settle()]])।
                     */
                    // ⓘ ফ্রি মালের দাম নেই — দর বসে না, সইয়ের টাকার অঙ্কেও ধরা হয় না
                    'unit_cost' => $free ? null : ($line['unit_cost'] ?? $this->averageCost($product)),
                    // reason_code_id অনুমোদনের সময় বসবে
                ]);
            }

            return $count->load('lines');
        });
    }

    /**
     * গণনা মেনে নেওয়া — পার্থক্যটা এখন সত্যিই খাতায় বসে।
     *
     * ── ⛔ এই মেথডটাই অনুপস্থিত ছিল, ১৮ সেপ্টেম্বর ২০২৬ ───────────────
     * মালিক বললেন *"সব জায়গায় এপ্রুভাল বসাও"*, আর বসাতে গিয়ে দেখা গেল
     * মজুদ গণনায় বসানোর **জায়গাই নেই** — কারণ মেনে নেওয়ার ধাপটাই লেখা
     * হয়নি। ⚠️ গণনা সেভ হত, পার্থক্য দেখা যেত, খাতা অটুট থাকত।
     *
     * ── ⓘ কী ঘটে ──────────────────────────────────────────────────
     *   ১. সই লাগে কি না দেখা (ছক না বসানো থাকলে চুপচাপ এগোয়)
     *   ২. প্রতিটা লাইনের পার্থক্য [[StockAdjustmentService::adjust()]]-এ
     *   ৩. গণনাটা নিশ্চিত হিসেবে দাগানো, কে ও কখন সহ
     *
     * ⚠️ পার্থক্য শূন্য হলে ওই লাইনে কিছুই হয় না — `adjust()` নিজেই
     * `null` ফেরায়, আর শূন্য সারি খতিয়ানে কেবল ভিড় বাড়াত।
     *
     * ── ⛔ কেন কারণ-কোড বাধ্যতামূলক ────────────────────────────────
     * মাল কম পাওয়া গেছে — চুরি, ভাঙা, মেয়াদ, নাকি গোনার ভুল? ⓘ উত্তরটা
     * ছাড়া সংখ্যাটা কেবল একটা ক্ষতি; উত্তর থাকলে ওটা একটা তথ্য, আর
     * মাস শেষে "কোন কারণে কত গেল" প্রশ্নের জবাব দেওয়া যায়।
     */
    public function approve(StockCount $count, ReasonCode $reason): StockCount
    {
        if ($count->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.count_not_draft'),
            ]);
        }

        /*
         * ⛔ গণনার কারণ কেবল সমন্বয়ের কারণ — Inventory অডিট গ৮, ৪ অক্টোবর ২০২৬।
         * ⚠️ আগে যেকোনো কারণ চলত: ঘাটতিতে "মালিকের ব্যবহার" বাছলে টাকা মালিকের উত্তোলনে যেত, "বিক্রয় ফেরত"
         * বাছলে ফেরতের খাতে — ঘাটতির খাতে (৫১৬০) নয় ([[StockAdjustmentService::postToLedger()]] খাত নেয় কারণ থেকে)।
         */
        $this->assertReasonFits($reason, ReasonCode::STOCK_ADJUSTMENT);

        // ⛔ বের করার কাগজ গণনার পথে মানা যায় না — তার কারণ ও সই আলাদা, শেষ হয় [[finishIssue()]]-এ (গ৫)
        if ($count->isIssue()) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.issue_paper_is_not_a_count', ['document' => $count->document_no]),
            ]);
        }

        $count->loadMissing(['lines.product', 'warehouse']);

        if ($count->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('inventory::validation.count_needs_lines'),
            ]);
        }

        /*
         * ⓘ অঙ্ক হিসেবে পার্থক্যের **টাকা** যায়, সংখ্যা নয় — একশো
         * পিস সাবানের ঘাটতি আর একশো পিস ওষুধের ঘাটতি এক জিনিস নয়।
         *
         * ⚠️ যে লাইনে দর জানা নেই (স্তর খালি) সেটা যোগে ধরা হয় না;
         * ধরে-নেওয়া দর বসালে সীমাটাই মিথ্যা হয়ে যেত।
         */
        $atStake = '0';

        foreach ($count->lines as $line) {
            if ($line->unit_cost === null) {
                continue;
            }

            $atStake = bcadd($atStake, bcmul(
                ltrim((string) $line->difference, '-'),
                (string) $line->unit_cost,
                4,
            ), 4);
        }

        $this->approvals->assertClear(
            document: $count,
            module: 'inventory',
            action: 'count',
            field: 'status',
            amount: $atStake,
            reason: $count->narration,
        );

        return DB::transaction(function () use ($count, $reason) {
            /*
             * ⛔ সারি আটকে অবস্থা আবার পড়া — চূড়ান্ত অডিট ⛔১৩, ৩০ সেপ্টেম্বর ২০২৬ — [[StockTransferService]]-এর সেই একই সারাই।
             * ওপরের পরখটা হাতে ধরা মডেল দেখে; দুইবার চাপ দিলে দুইটা অনুরোধই "খসড়া" দেখত, আর গোনার প্রতিটা
             * পার্থক্য দুইবার সমন্বয় হয়ে খতিয়ানে দুইবার উঠত। ⓘ দ্বিতীয়টা এখানে অপেক্ষা করে, তারপর "নিশ্চিত" পড়ে ফেরে।
             */
            if ($this->lockedStatus($count) !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.count_not_draft'),
                ]);
            }

            foreach ($count->lines as $line) {
                if (bccomp((string) $line->difference, '0', 4) === 0) {
                    continue;
                }

                /*
                 * ⛔ এই গণনার পরে একই মালের আরেকটা গণনা মেনে নেওয়া হয়ে গেলে, এটা বাসি — গ৭।
                 * ⓘ দুটোই একই খাতার সংখ্যা দেখে লেখা, তাই পার্থক্যটা ওটাই বসিয়ে দিয়েছে; এটা মানলে দ্বিতীয়বার।
                 * ⚠️ নতুন খসড়ায় এমন জোড়া হয়ই না ([[record()]]) — এটা আগের দিনের পড়ে থাকা জোড়ার জন্য।
                 */
                $settledSince = $this->sameGoods((int) $line->product_id, (int) $count->warehouse_id, $line->batch_id, (string) $count->kind)
                    ->where('status', DocumentStatus::CONFIRMED)
                    ->whereKeyNot($count->id)
                    ->where('approved_at', '>', $count->created_at)
                    ->value('document_no');

                if ($settledSince !== null) {
                    throw ValidationException::withMessages([
                        'status' => __('inventory::validation.count_settled_by_another', [
                            'product' => $line->product?->name(),
                            'document' => $settledSince,
                        ]),
                    ]);
                }

                /*
                 * ⛔ গণনার নিজের পার্থক্য — অনুমোদনের মুহূর্তে আবার মাপা নয় (২৯ সেপ্টেম্বর
                 * ২০২৬, অডিটে প্রমাণিত): মাঝের বিক্রি উদ্বৃত্ত হয়ে ফিরত ([[StockAdjustmentService::settle()]])।
                 */
                // ⭐ ফ্রি কাগজ ফ্রি ভাণ্ডারে বসে, খাতায় কিছু যায় না (মজুদ ⚠️৬ক)
                $count->isFree()
                    ? $this->adjustments->settleFree(
                        product: $line->product,
                        warehouse: $count->warehouse,
                        difference: (string) $line->difference,
                        reason: $reason,
                        date: $count->count_date,
                        narration: __('inventory::label.free_adjustment_narration', ['document' => $count->narration ?: $count->document_no]),
                        batch: $line->batch,
                    )
                    : $this->adjustments->settle(
                        product: $line->product,
                        warehouse: $count->warehouse,
                        difference: (string) $line->difference,
                        reason: $reason,
                        date: $count->count_date,
                        narration: $count->narration ?: $count->document_no,
                        unitCost: $line->unit_cost === null ? null : (string) $line->unit_cost,
                        batch: $line->batch,
                    );

                $line->update(['reason_code_id' => $reason->id]);
            }

            $count->update([
                'status' => DocumentStatus::CONFIRMED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            // ⭐ অডিটে লেখা থাকে এটা ফ্রি মালের সমন্বয় — কে, কোন কাগজ (মজুদ ⚠️৬ক; fe-র শর্ত ৩)
            if ($count->isFree()) {
                app(\App\Core\Engines\Audit\AuditEngine::class)->recordAction($count, 'free_stock_adjusted', $count->document_no);
            }

            return $count->fresh(['lines']);
        });
    }

    /**
     * ⭐ বিনা বিক্রয়ে মাল বের করা — একটা অপেক্ষমাণ কাগজ, সইয়ের ধারায় (Inventory অডিট গ৫, ৪ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে আপ্যায়ন, উপহার বা মালিকের ব্যবহারে মাল বের করলে সাথে সাথে খরচের খাতে টাকা উঠত, কোনো সই ছাড়াই —
     * একজন গুদামের লোক তাকের সব মাল "উপহার" দেখিয়ে খাতা থেকে বের করে দিতে পারতেন।
     *
     * ⓘ এখন একটা এক-সারির কাগজ (`kind = issue`), কারণটা কাগজে লেখা। `inventory.issue` ছক চালু থাকলে কাগজটা খসড়া
     * থাকে আর শেষ সইয়ে নিজেই শেষ হয় ([[FinishTheIssueOnTheLastSignature]]); ছক বন্ধে আগের মতো এখনই।
     *
     * @return array{0: StockCount, 1: bool} কাগজ, আর সইয়ের অপেক্ষায় কি না
     */
    public function issue(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): array {
        $this->assertReasonFits($reason, ReasonCode::STOCK_ISSUE);

        if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.issue_needs_qty'),
            ]);
        }

        $paper = DB::transaction(function () use ($product, $warehouse, $qty, $reason, $date, $narration) {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->first();

            /*
             * ⛔ একই মালের আগের বের-করা সইয়ের অপেক্ষায় থাকলে আরেকটা নয় — সই আটকালে আবার চাপায় দুটো কাগজ হত,
             * আর সইকারী দুটোয় সই দিলে মাল দুইবার বেরোত (গ৭-এর একই ফাঁদ)।
             */
            $waiting = StockCount::query()->withoutGlobalScopes()
                ->where('company_id', CompanyContext::id())->whereNull('deleted_at')
                ->where('kind', StockCount::KIND_ISSUE)->where('status', DocumentStatus::DRAFT)
                ->where('warehouse_id', $warehouse->id)
                ->whereHas('lines', fn ($lines) => $lines->where('product_id', $product->id))
                ->value('document_no');

            if ($waiting !== null) {
                throw ValidationException::withMessages([
                    'qty' => __('inventory::validation.issue_already_waiting', ['product' => $product->name(), 'document' => $waiting]),
                ]);
            }

            // ⓘ খাতার সংখ্যা তাকের — কাগজের "আগে/পরে" সেটাই বলে
            $onHand = $this->stock->floorQty($product, $warehouse);

            // ⭐ মাপা হয় "পাওয়া যায়" দিয়ে — অডিট ম৬ ([[assertIssuable()]])
            $this->assertIssuable($product, $warehouse, $qty);

            $paper = StockCount::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('SC'),
                'kind' => StockCount::KIND_ISSUE,
                'count_date' => Carbon::parse($date ?? now())->toDateString(),
                'warehouse_id' => $warehouse->id,
                'narration' => $narration,
                'reason_code_id' => $reason->id,
                'status' => DocumentStatus::DRAFT,
                'counted_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $paper->lines()->create([
                'company_id' => CompanyContext::id(),
                'product_id' => $product->id,
                'book_qty' => $onHand,
                'counted_qty' => bcsub($onHand, $qty, 4),
                'difference' => bcmul($qty, '-1', 4),
                'unit_cost' => $this->averageCost($product),
                'reason_code_id' => $reason->id,
            ]);

            return $paper->load('lines');
        });

        $line = $paper->lines->first();
        $atStake = $line->unit_cost === null ? '0' : bcmul($qty, (string) $line->unit_cost, 4);

        $held = $this->approvals->stopping(
            document: $paper,
            module: 'inventory',
            action: 'issue',
            amount: $atStake,
            reason: $narration ?: $reason->name(),
        ) !== null;

        return [$held ? $paper : $this->finishIssue($paper), $held];
    }

    /**
     * ⭐ বের করার কাগজ শেষ — মাল তাক থেকে, টাকা কাগজের কারণের খাতে (গ৫)। ছক বন্ধে [[issue()]] এখনই ডাকে, ছক চালু
     * থাকলে শেষ সইয়ে [[FinishTheIssueOnTheLastSignature]]।
     *
     * ⓘ সারি আটকে অবস্থা আবার পড়া — একই সইয়ের ঘটনা দুইবার এলে বা হাতে আগেই শেষ হলে দ্বিতীয়বার কিছু হয় না।
     */
    public function finishIssue(StockCount $paper): StockCount
    {
        return DB::transaction(function () use ($paper) {
            if (! $paper->isIssue() || $this->lockedStatus($paper) !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.count_not_draft'),
                ]);
            }

            $paper->loadMissing(['lines.product', 'warehouse', 'reason']);
            $line = $paper->lines->firstOrFail();

            // ⭐ শেষ সইয়ে আবার — সইয়ের অপেক্ষার মাঝে মাল অন্যের আদেশে সংরক্ষিত হয়ে থাকতে পারে (অডিট ম৬)
            Warehouse::query()->whereKey($paper->warehouse_id)->lockForUpdate()->first();
            $this->assertIssuable($line->product, $paper->warehouse, bcmul((string) $line->difference, '-1', 4));

            $this->adjustments->settle(
                product: $line->product,
                warehouse: $paper->warehouse,
                difference: (string) $line->difference,
                reason: $paper->reason,
                date: $paper->count_date,
                narration: $paper->narration ?: $paper->document_no,
            );

            $paper->update([
                'status' => DocumentStatus::CONFIRMED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            return $paper->fresh(['lines']);
        });
    }

    /**
     * ⭐ বের করা যায় কেবল যা "পাওয়া যায়" (তাক − সংরক্ষিত − আটকানো) — Inventory অডিট ম৬, ৫ অক্টোবর ২০২৬।
     *
     * ⛔ আগে মাপা হত কেবল তাক: তাকে ১০-এর ৬টা অন্যের আদেশে সংরক্ষিত, তবু ৫টা "উপহার" বেরোত, আর আদেশ খালি হাতে দাঁড়াত।
     * ⓘ ডাকা হয় গুদামের তালার ভিতরে — কাগজ খোলায় আর শেষ সইয়ে ([[issue()]], [[finishIssue()]])। ⚠️ পরিদর্শনের বিনাশ এই
     * পথে আসে না: সে আটকানো মালই নেয় ([[StockAdjustmentService::issue()]]), আর "পাওয়া যায়" বসালে সেটা ভাঙত।
     */
    private function assertIssuable(Product $product, Warehouse $warehouse, string $qty): void
    {
        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($qty, $available, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.issue_more_than_stock', ['have' => rtrim(rtrim(bcadd($available, '0', 4), '0'), '.') ?: '0']),
            ]);
        }
    }

    /**
     * ⭐ পড়ে থাকা খসড়া বাতিল — কারণসহ (Inventory অডিট গ৭, ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ খসড়ায় খাতা নড়েনি, তাই বাতিলে ফেরানোর কিছু নেই — কেবল কাগজটা বন্ধ হয়, যাতে একই পণ্যের নতুন গণনা লেখা যায়।
     */
    public function cancel(StockCount $count, string $reason): StockCount
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('inventory::validation.count_cancel_needs_reason'),
            ]);
        }

        return DB::transaction(function () use ($count, $reason) {
            if ($this->lockedStatus($count) !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.count_not_draft'),
                ]);
            }

            $count->update([
                'status' => DocumentStatus::CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $count->fresh();
        });
    }

    /**
     * এক গুদামে একই মাল ছোঁয়া গণনাগুলো — একই পণ্য, আর লট মেলে বা কোনো একটায় লট বলা নেই।
     *
     * ⓘ লট ছাড়া ঘাটতি বেরোয় আগে-মেয়াদ নিয়মে, যেকোনো লট থেকে — তাই লটহীন সারি সব লটের সাথেই মেলে।
     * ⛔ শাখার দেয়াল ছাড়া (কোম্পানির ভিতরে): অন্য শাখার কেউ লিখে রাখা খসড়া না দেখলে জোড়াটা আবার হত।
     */
    private function sameGoods(int $productId, int $warehouseId, ?int $batchId, string $kind = StockCount::KIND_COUNT): \Illuminate\Database\Eloquent\Builder
    {
        return StockCount::query()
            ->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereNull('deleted_at')
            // ⓘ কেবল একই ভাণ্ডারের গণনা — বের করার কাগজ খাতার সংখ্যার ছবি নয় (গ৫); দামি আর ফ্রি দুই আলাদা খাতা (⚠️৬ক)
            ->where('kind', $kind)
            ->where('warehouse_id', $warehouseId)
            ->whereHas('lines', function ($lines) use ($productId, $batchId) {
                $lines->where('product_id', $productId);

                if ($batchId !== null) {
                    $lines->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', $batchId));
                }
            });
    }

    /**
     * এই পণ্যের গড় একক-খরচ — স্তরে যা পড়ে আছে তার মোট মূল্য ÷ পরিমাণ।
     *
     * মাল না থাকলে (স্তর খালি) দর বলা যায় না, তখন null — পার্থক্যের টাকা
     * তখন দেখানো হবে না, সংখ্যাটা দেখানো হবে। ধরে-নেওয়া কোনো দর বসানো
     * হয় না; ঠিক সেই ভুলটাই সারাতে FIFO স্তর বসানো হয়েছিল।
     */
    private function averageCost(Product $product): ?string
    {
        $qty = $this->costs->qtyOnHand($product);

        if (bccomp($qty, '0', 4) <= 0) {
            return null;
        }

        return bcdiv($this->costs->valueOnHand($product), $qty, 4);
    }

    /**
     * খালি ও অসম্পূর্ণ লাইন বাদ, পরিমাণ যাচাই, একই পণ্য দুইবার আটকানো।
     *
     * ── একই পণ্য দুইবার কেন আটকানো ──────────────────────────────────
     * টেবিলে ইউনিক শর্ত আছে (এক গণনায় এক পণ্য একবার), কিন্তু সেটা
     * ছুঁড়লে ব্যবহারকারী একটা SQL ত্রুটি দেখতেন। এখানে ধরলে বাংলা বার্তা
     * পান, আর কোন পণ্যটা দুইবার সেটাও বলা যায়।
     *
     * @param  list<array{product_id?: int|string, counted_qty?: int|string}>  $lines
     * @return list<array{product_id: int|string, counted_qty: string}>
     */
    /**
     * এই সারির লট — লট ধরা পণ্য না হলে কিছুই না।
     *
     * ⓘ নম্বরটা খালি থাকলেও কিছুই না — ঘাটতির সারিতে লট লাগে না,
     * আর মিলে যাওয়া সারিতে কোনো চলাচলই হয় না। ⚠️ বাধ্যতামূলক
     * করার জায়গাটা [[StockAdjustmentService]], কারণ পার্থক্যটা ওখানেই
     * জানা — বাড়তি না ঘাটতি।
     *
     * @param  array<string, mixed>  $line
     */
    private function lotFor(Product $product, array $line): ?Batch
    {
        if (! $product->track_batch || blank($line['batch_no'] ?? null)) {
            return null;
        }

        return app(BatchService::class)->receive(
            product: $product,
            batchNo: (string) $line['batch_no'],
            expiry: filled($line['expiry_date'] ?? null)
                ? Carbon::parse($line['expiry_date'])->toDateString()
                : null,
        );
    }

    /** লেখা দর — খালি হলে null (তখন গড়), নাহলে শূন্য বা বেশি একটা সংখ্যা */
    private function typedRate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('inventory::validation.surplus_needs_rate'),
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function cleanLines(array $lines): array
    {
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $counted = $line['counted_qty'] ?? null;

            // পণ্য বা সংখ্যা কিছুই না দিলে সারিটা কেবল ফাঁকা ঘর — বাদ
            if (blank($productId) || $counted === null || $counted === '') {
                continue;
            }

            $counted = (string) $counted;

            if (! is_numeric($counted) || bccomp($counted, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('inventory::validation.count_qty_negative'),
                ]);
            }

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'lines' => __('inventory::validation.count_duplicate_product'),
                ]);
            }

            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'counted_qty' => $counted,
                /*
                 * ⓘ লট নম্বরটা এখানে কেবল বহন করা হয়, যাচাই নয়।
                 *
                 * ⚠️ লট লাগবে কি না তা নির্ভর করে খাতা আর তাকের
                 * পার্থক্যের উপর, আর সেটা এখনো গোনাই হয়নি। ⛔ এখানে
                 * চাইলে ঘাটতির সারিতেও লট চাওয়া হত, অথচ ওখানে কোন
                 * লট যাবে তা FEFO বলে, মানুষ নয়।
                 */
                'batch_no' => $line['batch_no'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                // ⓘ বাড়তির দর — যাচাই এখানে, কারণ ঋণাত্মক বা লেখা-নয় দর কাগজে বসলে মেনে নেওয়ার দিন স্তরটাই মিথ্যা হত
                'unit_cost' => $this->typedRate($line['unit_cost'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * ⛔ কারণটা এই কাজের প্রসঙ্গের, এই কোম্পানির, আর চালু — অডিট গ৮। ⓘ মান-পরীক্ষার বিনাশও এটাই ডাকে
     * ([[QualityInspectionService::dispose()]])।
     */
    public function assertReasonFits(ReasonCode $reason, string $context): void
    {
        if ($reason->context !== $context
            || (int) $reason->company_id !== (int) CompanyContext::id()
            || ! $reason->is_active) {
            throw ValidationException::withMessages([
                'reason_code_id' => __('inventory::validation.reason_not_for_this', ['reason' => $reason->name()]),
            ]);
        }
    }

    /** কাগজের অবস্থা, সারি আটকে — [[approve()]]-এর দ্বিতীয় ক্লিক (⛔১৩) */
    private function lockedStatus(StockCount $count): string
    {
        return (string) StockCount::query()
            ->whereKey($count->id)
            ->lockForUpdate()
            ->value('status');
    }
}
