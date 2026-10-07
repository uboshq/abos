<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Listeners;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Purchase\Events\GoodsReceived;

/**
 * ⭐ মাল এল, আর যেগুলোর পরিদর্শন লাগে তাদের কাগজ নিজেই খোলে।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * পণ্যের গায়ে `qc_required` ঘরটা ছিল, পরিদর্শনের পর্দাও ছিল, আর পর্দাটা
 * ঐ ঘর ধরে পণ্যের তালিকা ছাঁকতও। ⚠️ কিন্তু **মাল এলে কিছুই হত না** —
 * গুদামের লোককে মনে করে কাগজটা খুলতে হত।
 *
 * ⓘ অর্থাৎ ঘরটা কার্যত সাজসজ্জা: টিক দেওয়া থাক বা না থাক, মাল একইভাবে
 * গুদামে উঠত। ⛔ আর এটাই এই কোডবেসের চেনা রোগ — যন্ত্রটা তৈরি, জোড়াটা
 * নেই, আর কোথাও লাল হয় না।
 *
 * ── ⭐ কেন ইভেন্ট, সরাসরি ডাক নয় ────────────────────────────────────
 * ⛔ [[PurchaseReceiptService]] থেকে সরাসরি ডাকলে ক্রয় মডিউল মজুদের
 * গুণমান-সেবার নাম জানত, আর কাল যখন গ্রহণের কাগজ Inventory-তে সরবে
 * (মালিকের সীমানার টেবিল) তখন জোড়াটা উল্টো দিকে লিখতে হত।
 *
 * ⓘ ইভেন্টে ক্রয় কেবল বলে *"মাল নেমে গেছে"*, আর কে সাড়া দেবে সেটা
 * তার জানার কথা নয়।
 *
 * ── ⚠️ কী কত এল, সেটা **মজুদের নিজের টেবিল** থেকে ────────────────────
 * পেলোডে সারি নেই, আছে চলাচলের জোড়ার চাবি। ⓘ তাই এই শ্রোতা ক্রয়ের
 * একটাও কলামের নাম জানে না — সে `inv_stock_movements` পড়ে, আর ওটা
 * তারই ঘর।
 *
 * ── ⛔ কাগজ খোলে, মাল আটকায় না ───────────────────────────────────────
 * ⚠️ এখানে মাল আটকালে (`hold`) গ্রহণের লেনদেনের **বাইরে** একটা চলাচল
 * বসত, আর ব্যর্থ হলে মাল ঢুকে যেত অথচ আটকাত না। ⓘ আটকানোটা রায়ের কাজ
 * ([[QualityInspectionService::decide()]]), আর সেখানে ওটা একই লেনদেনে।
 *
 * ⭐ তবু কাগজটা থাকাই আসল বদল: *"কোন মালগুলো এসেছে অথচ দেখা হয়নি"*
 * প্রশ্নের উত্তর এখন একটা তালিকা, কারও স্মৃতি নয়।
 */
final class OpenInspectionsForGoodsThatNeedThem
{
    public function __construct(private readonly QualityInspectionService $inspections) {}

    public function handle(GoodsReceived $event): void
    {
        $sourceType = (string) ($event->payload['source_type'] ?? '');
        $sourceId = (int) ($event->payload['source_id'] ?? 0);

        if ($sourceType === '' || $sourceId <= 0) {
            return;
        }

        /*
         * ⓘ পণ্য · লট · গুদাম ধরে যোগফল — এক চালানে একই পণ্য দুই দরে
         * এলে দুইটা সারি, কিন্তু পরিদর্শনের কাগজ **একটাই**: পরিদর্শক
         * দরের হিসাব করেন না, মালটা দেখেন।
         *
         * ⚠️ `unplaced_change` ধরা হয়, `floor_change` নয় — গ্রহণে মাল
         * ওখানেই বসে (গাড়ি থেকে নামল, বুঝে নেওয়া আলাদা ধাপ)। ⛔ কেবল
         * `floor` দেখলে যোগফল শূন্য আসত, আর একটাও কাগজ খুলত না — আর
         * ব্যর্থতাটা সম্পূর্ণ নীরব হত।
         */
        $rows = StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->groupBy('product_id', 'batch_id', 'warehouse_id')
            ->selectRaw('product_id, batch_id, warehouse_id,
                COALESCE(SUM(unplaced_change), 0) + COALESCE(SUM(floor_change), 0) as came_in')
            ->havingRaw('COALESCE(SUM(unplaced_change), 0) + COALESCE(SUM(floor_change), 0) > 0')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $needInspection = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->unique()->all())
            ->where('qc_required', true)
            ->pluck('id')
            ->all();

        if ($needInspection === []) {
            return;
        }

        foreach ($rows as $row) {
            if (! in_array((int) $row->product_id, $needInspection, true)) {
                continue;
            }

            /*
             * ⛔ একই চালানের একই মালের দুইটা কাগজ নয়।
             *
             * ⚠️ আজ ইভেন্ট একবারই আসে, কিন্তু কিউ এলে *"at least once"*
             * পৌঁছানোই স্বাভাবিক ([[DomainEvent]]-এর `eventId`-র কারণ)।
             * ⓘ তখন এই শর্তটা না থাকলে প্রতিটা পুনঃচেষ্টায় একটা করে
             * নতুন QC নম্বর পুড়ত, আর তালিকাটা একই মালে ভরে যেত।
             */
            $already = QualityInspection::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('product_id', $row->product_id)
                ->when($row->batch_id === null,
                    fn ($q) => $q->whereNull('batch_id'),
                    fn ($q) => $q->where('batch_id', $row->batch_id))
                ->exists();

            if ($already) {
                continue;
            }

            $this->inspections->open([
                'product_id' => (int) $row->product_id,
                'batch_id' => $row->batch_id === null ? null : (int) $row->batch_id,
                'warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
                'inspected_qty' => (string) $row->came_in,
                'inspected_on' => $event->payload['received_on'] ?? null,

                /*
                 * ⓘ উৎসটা বসে, যাতে কাগজ থেকে চালানে ফেরত যাওয়া যায় —
                 * আর উপরের "দুইবার নয়" শর্তটাও এই দুইটা ঘরের উপরেই
                 * দাঁড়ানো।
                 */
                'source_type' => $sourceType,
                'source_id' => $sourceId,

                'remarks' => $event->payload['document_no'] ?? null,
            ]);
        }
    }
}
