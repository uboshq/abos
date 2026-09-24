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
