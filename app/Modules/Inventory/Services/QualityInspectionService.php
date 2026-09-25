<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মাল দেখা — আর দেখার ফলটা মজুদে বসানো।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Received → Inspection → Approved / Quarantine / Rejected"*।
 *
 * ── ⚠️ এই সেবার আসল কাজ দুই ধাপের দ্বিতীয়টা ─────────────────────────
 * কাগজ লেখা সহজ, আর ওটা একাই কিছু করে না। ⓘ আসল কাজ হলো রায়টাকে
 * **মজুদের ভাষায়** অনুবাদ করা:
 *
 *   `approved`   → কিছুই আটকানো নয়, মাল বিক্রয়যোগ্য
 *   `quarantine` → পুরোটা আটকানো, `HOLD-RET` কারণে
 *   `rejected`   → বাতিল অংশটা আটকানো, `HOLD-REJ` কারণে
 *
 * ⛔ অনুবাদটা না করলে পর্দায় লাল লেখা "বাতিল" বসত আর মালটা দিব্যি
 * বিক্রি হয়ে যেত — আর সেটাই সবচেয়ে খারাপ, কারণ তখন সবাই ভাবত
 * ব্যবস্থাটা কাজ করছে।
 *
 * ── ⓘ কেন আটকানো, মজুদ থেকে বাদ নয় ──────────────────────────────────
 * ⚠️ বাতিল মাল গুদামেই থাকে — সরবরাহকারী ফেরত নেবেন, বা ফেলে দেওয়া
 * হবে, আর দুইটাই আলাদা কাগজ। ⛔ এখানে মজুদ কমিয়ে দিলে ঐ মালটা খাতা
 * থেকে উবে যেত, অথচ তাকে সে পড়েই থাকত — আর মাস শেষে গণনা মিলত না।
 *
 * ⭐ আটকানো মানে *"আছে, কিন্তু বেচা যাবে না"*, আর ঠিক সেটাই সত্যি।
 */
final class QualityInspectionService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly StockAdjustmentService $adjustments,
    ) {}

    /**
     * পরিদর্শনের কাগজ খোলা — রায় এখনো নয়।
     *
     * ⓘ মাল আসার সাথে সাথেই কাগজটা খোলা যায়, আর তখন সেটা *"দেখা
     * বাকি"* তালিকায় বসে। ⚠️ রায় না হওয়া পর্যন্ত মজুদে কিছুই বদলায়
     * না — কাগজ খোলাটা একটা পর্যবেক্ষণের ঘোষণা, সিদ্ধান্ত নয়।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): QualityInspection
    {
        return DB::transaction(function () use ($data) {
            $product = Product::query()->find($data['product_id'] ?? null);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'product_id' => __('inventory::validation.qc_product_required'),
                ]);
            }

            $qty = (string) ($data['inspected_qty'] ?? '0');

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'inspected_qty' => __('inventory::validation.qc_needs_quantity'),
                ]);
            }

            $warehouse = Warehouse::query()->find($data['warehouse_id'] ?? null);

            return QualityInspection::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('QC'),
                'inspected_on' => Carbon::parse($data['inspected_on'] ?? now())->toDateString(),
                'product_id' => $product->id,
                'batch_id' => $this->lotFor($product, $data)?->id,
                'warehouse_id' => $warehouse?->id,
                'inspected_qty' => $qty,
                'accepted_qty' => '0',
                'rejected_qty' => '0',
                'criteria' => $data['criteria'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'status' => QualityInspection::PENDING,
                'inspected_by' => $data['inspected_by'] ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * ⭐ রায় — আর এখানেই মজুদ নড়ে।
     *
     * ── ⚠️ যোগফল মিলতেই হবে ────────────────────────────────────────
     * গৃহীত + বাতিল = পরিদর্শিত। ⛔ না মিললে বাকিটা কোথায় গেল তার
     * কোনো উত্তর থাকত না, আর ঝুলে থাকা মাল কারও খাতায় নেই বলে সেটাই
     * সবচেয়ে সহজে হারায়।
     *
     * ── ⓘ কারণ-কোডটা কেন সেবার হাতে, পর্দার নয় ──────────────────────
     * ⚠️ কোয়ারেন্টাইন আর বাতিল দুইটা **আলাদা** কারণে আটকায়
     * (`HOLD-RET` বনাম `HOLD-REJ`), আর দুইটার ফেরার পথও আলাদা:
     * কোয়ারেন্টাইনের মাল যাচাইয়ের পর ফিরতে পারে, বাতিলের পারে না
     * (`returns_to_stock => false`)। ⛔ পর্দাকে বাছতে দিলে একদিন
     * ভুলটা বসত, আর বাতিল মাল পরদিন বিক্রি হয়ে যেত।
     */
    public function decide(
        QualityInspection $inspection,
        string $result,
        string $acceptedQty,
        string $rejectedQty,
        ?string $remarks = null,
    ): QualityInspection {
        if (! $inspection->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.qc_already_decided'),
            ]);
        }

        if (! in_array($result, [
            QualityInspection::APPROVED,
            QualityInspection::QUARANTINE,
            QualityInspection::REJECTED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.qc_unknown_result'),
            ]);
        }

        $total = bcadd($acceptedQty, $rejectedQty, 4);

        if (bccomp($total, (string) $inspection->inspected_qty, 4) !== 0) {
            throw ValidationException::withMessages([
                'accepted_qty' => __('inventory::validation.qc_parts_must_add_up', [
                    'total' => rtrim(rtrim((string) $inspection->inspected_qty, '0'), '.'),
                ]),
            ]);
        }

        return DB::transaction(function () use (
            $inspection, $result, $acceptedQty, $rejectedQty, $remarks
        ) {
            $inspection->loadMissing(['product', 'warehouse', 'batch']);

            $held = $this->holdFor($result, $acceptedQty, $rejectedQty);

            /*
             * ⛔ [[StockService::hold()]], সরাসরি `move()` নয়।
             *
             * ── ⚠️ প্রথম চেষ্টায় এখানেই ভুল ছিল ──────────────────────
             * `move()` ডাকলে দুইটা পাহারা এড়িয়ে যেত: কারণটা সত্যিই
             * আটকানোর কারণ কি না, আর **যা বেচা যায় তার বেশি আটকানো
             * হচ্ছে কি না**। ⛔ দ্বিতীয়টা এড়ানো মানে বিক্রয়যোগ্য মজুদ
             * ঋণাত্মক হয়ে যাওয়া।
             *
             * ⓘ লট আর কাগজের নম্বরের জন্য `move()` ডাকা হচ্ছিল; ঘর
             * দুইটা এখন `hold()`-এও আছে, তাই আর দরকার নেই।
             */
            if (bccomp($held, '0', 4) > 0 && $inspection->warehouse !== null) {
                $this->stock->hold(
                    product: $inspection->product,
                    warehouse: $inspection->warehouse,
                    qty: $held,
                    reason: $this->reasonFor($result),
                    date: $inspection->inspected_on,
                    narration: $remarks ?? $inspection->remarks,
                    batch: $inspection->batch,
                    documentNo: $inspection->document_no,
                );
            }

            $inspection->update([
                'status' => $result,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
                'remarks' => $remarks ?? $inspection->remarks,
            ]);

            return $inspection->fresh();
        });
    }

    /**
     * কতটা আটকাতে হবে।
     *
     * ⓘ গৃহীত হলে কিছুই নয়। ⚠️ কোয়ারেন্টাইনে **পুরোটা**, কারণ তখনো
     * কোনটা ভালো কোনটা খারাপ জানা নেই। ⛔ বাতিলে কেবল বাতিল অংশটা —
     * যে চল্লিশ বস্তা ঠিক আছে সেগুলো আটকানোর কোনো কারণ নেই।
     */
    private function holdFor(string $result, string $accepted, string $rejected): string
    {
        return match ($result) {
            QualityInspection::QUARANTINE => bcadd($accepted, $rejected, 4),
            QualityInspection::REJECTED => $rejected,
            default => '0',
        };
    }

    /**
     * কোন কারণে আটকানো।
     *
     * ⚠️ কারণটা না পেলে আটকানোই হয় না, আর কাগজটা তখনো লেখা হয় —
     * ⛔ কিন্তু নীরবে নয়: [[MasterListService::installDefaults()]]
     * দুইটাই বসায়, তাই না পাওয়া মানে কেউ তালিকা থেকে মুছে দিয়েছেন,
     * আর তখন থামা উচিত।
     */
    /**
     * ⭐ বাতিল মাল বিনাশ — খাতা থেকেও যায়, ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন এটা এক ধাপ হতেই হবে ──────────────────────────────────
     * আজ পর্যন্ত এটা করতে হত দুই ধাপে: আগে আটকানো ছেড়ে দেওয়া, তারপর
     * স্টক সমন্বয়ে বাদ দেওয়া। ⚠️ আর ঐ দুই ধাপের **মাঝখানে মালটা
     * বিক্রয়যোগ্য** — কারণ `available = floor − reserved − hold`, আর
     * আটকানো ছেড়ে দেওয়ার সাথে সাথেই সংখ্যাটা ফিরে আসে।
     *
     * ⛔ অর্থাৎ পরিদর্শনে বাতিল হওয়া ওষুধ ঐ কয়েক সেকেন্ডে কাউন্টার
     * থেকে বিক্রি হয়ে যেতে পারত, আর কোথাও কোনো ভুল দেখাত না।
     *
     * ── ⚠️ কেন উল্টো ক্রমে নয় ───────────────────────────────────────
     * আগে তাক থেকে বাদ দিয়ে পরে আটকানো ছাড়লে মাঝখানে `hold > floor`
     * হত, আর বিক্রয়যোগ্য সংখ্যাটা **ঋণাত্মক** দেখাত। ⓘ দুইটাই এক
     * লেনদেনে, তাই কোনো মাঝখানই নেই।
     *
     * ── ⓘ দুইটা কারণ, আর দুইটাই দরকার ──────────────────────────────
     * ছাড়ার সারিতে বসে **কেন আটকানো ছিল** (`HOLD-REJ`), আর বাদ দেওয়ার
     * সারিতে বসে **কোন খাতে ক্ষতিটা যাবে** — দ্বিতীয়টা ব্যবহারকারীর
     * বাছাই, কারণ নষ্ট আর চুরি এক খাতে যায় না।
     *
     * @param  string  $qty  কতটা বিনাশ হবে
     * @param  ReasonCode  $writeOff  ক্ষতির খাত ঠিক করে যে কারণ
     */
    public function dispose(
        QualityInspection $inspection,
        string $qty,
        ReasonCode $writeOff,
        ?string $narration = null,
    ): void {
        /*
         * ⛔ কেবল যে কাগজে রায় হয়ে গেছে, আর রায়টা মাল আটকে রেখেছে।
         * ⚠️ অপেক্ষমাণ কাগজে বিনাশ করতে দিলে পরিদর্শক দেখার আগেই মাল
         * চলে যেত, আর পরিদর্শনটার কোনো মানেই থাকত না।
         */
        if (! in_array($inspection->status, [
            QualityInspection::REJECTED,
            QualityInspection::QUARANTINE,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.qc_dispose_needs_verdict'),
            ]);
        }

        if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.qc_dispose_needs_qty'),
            ]);
        }

        $inspection->loadMissing(['product', 'warehouse']);

        $product = $inspection->product;
        $warehouse = $inspection->warehouse;

        if ($product === null || $warehouse === null) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.qc_dispose_needs_place'),
            ]);
        }

        /*
         * ⚠️ এই কাগজটা যতটা আটকে রেখেছে, তার বেশি নয়।
         *
         * ⛔ গুদামে মোট আটকানো পরিমাণ দেখে সীমা বসালে একটা কাগজ দিয়ে
         * **অন্য কাগজের** আটকানো মাল বিনাশ করা যেত — ⓘ আর দুইটা
         * পরিদর্শনের বাতিল মাল একই তাকে পাশাপাশি থাকাটাই স্বাভাবিক।
         */
        $held = $this->heldBy($inspection);

        if (bccomp($qty, $held, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.qc_dispose_over', ['held' => $held]),
            ]);
        }

        DB::transaction(function () use ($inspection, $product, $warehouse, $qty, $writeOff, $narration) {
            /* ⓘ প্রথমে আটকানো ছাড়া — কারণটা ঐ আটকানোরই */
            $this->stock->release(
                product: $product,
                warehouse: $warehouse,
                qty: $qty,
                reason: $this->reasonFor($inspection->status),
                date: now(),
            );

            /* ⓘ তারপর তাক থেকে বাদ, আর ক্ষতিটা খতিয়ানে */
            $this->adjustments->issue(
                product: $product,
                warehouse: $warehouse,
                qty: $qty,
                reason: $writeOff,
                date: now(),
                narration: $narration ?? $inspection->document_no,
            );

            $inspection->forceFill([
                'disposed_qty' => bcadd((string) ($inspection->disposed_qty ?? '0'), $qty, 4),
            ])->save();
        });
    }

    /**
     * এই কাগজটা এখনো কতটা আটকে রেখেছে।
     *
     * ⓘ রায়ে যতটা আটকানো হয়েছিল, তার থেকে যতটা ইতিমধ্যে বিনাশ হয়েছে।
     * ⚠️ পুনঃকাজে ছেড়ে দেওয়া মাল এখানে গোনা হয় না — ⛔ ওটা ছাড়ার
     * পর্দার কাজ, আর সেখান দিয়ে গেলে এই কাগজের হিসাব বদলায় না।
     */
    private function heldBy(QualityInspection $inspection): string
    {
        $held = $this->holdFor(
            (string) $inspection->status,
            (string) $inspection->accepted_qty,
            (string) $inspection->rejected_qty,
        );

        return bcsub($held, (string) ($inspection->disposed_qty ?? '0'), 4);
    }

    private function reasonFor(string $result): ReasonCode
    {
        $code = $result === QualityInspection::REJECTED ? 'HOLD-REJ' : 'HOLD-RET';

        $reason = ReasonCode::query()->where('code', $code)->first();

        if ($reason === null) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.qc_reason_missing', ['code' => $code]),
            ]);
        }

        return $reason;
    }

    /**
     * ⓘ লট ধরা পণ্যে লট, বাকিতে কিছুই না — [[StockCountService]]-এর যমজ।
     *
     * @param  array<string, mixed>  $data
     */
    private function lotFor(Product $product, array $data): ?Batch
    {
        if (! $product->track_batch) {
            return null;
        }

        /*
         * ⭐ লটটা আগে থেকেই জানা থাকলে সেটাই — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ পর্দা থেকে আসে লটের **নম্বর** (মানুষ নম্বর টাইপ করেন), আর
         * [[OpenInspectionsForGoodsThatNeedThem]] থেকে আসে **লটটাই**:
         * মালটা তখন গুদামে উঠে গেছে, আর তার লট ইতিমধ্যেই জন্মেছে।
         *
         * ⛔ নম্বর ধরে আবার খুঁজলে [[BatchService::receive()]] সেটা
         * find-or-create করত, আর মেয়াদের তারিখ না পাঠালে একটা **দ্বিতীয়
         * লট** জন্মাতে পারত — একই নম্বরের দুইটা লট মানে মজুদ দুই ভাগ,
         * আর রিকলের দিন একটা ভাগ খুঁজেই পাওয়া যেত না।
         */
        if (filled($data['batch_id'] ?? null)) {
            return Batch::query()->find($data['batch_id']);
        }

        if (blank($data['batch_no'] ?? null)) {
            return null;
        }

        return app(BatchService::class)->receive(
            product: $product,
            batchNo: (string) $data['batch_no'],
            expiry: filled($data['expiry_date'] ?? null)
                ? Carbon::parse($data['expiry_date'])->toDateString()
                : null,
        );
    }
}
