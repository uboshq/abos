<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
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

            $countDate = Carbon::parse($data['count_date'] ?? now());

            $count = StockCount::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('SC'),
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

                // খাতার সংখ্যা — গণনার মুহূর্তের floor, ওই গুদামে
                $bookQty = $this->stock->floorQty($product, $warehouse);

                $count->lines()->create([
                    'company_id' => CompanyContext::id(),
                    'product_id' => $product->id,
                    'book_qty' => $bookQty,
                    'counted_qty' => $line['counted_qty'],
                    'difference' => bcsub($line['counted_qty'], $bookQty, 4),
                    // পার্থক্যের টাকা দেখাতে গড় একক-খরচ; মাল না থাকলে বলা যায় না
                    'unit_cost' => $this->averageCost($product),
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
            foreach ($count->lines as $line) {
                if (bccomp((string) $line->difference, '0', 4) === 0) {
                    continue;
                }

                $this->adjustments->adjust(
                    product: $line->product,
                    warehouse: $count->warehouse,
                    countedQty: (string) $line->counted_qty,
                    reason: $reason,
                    date: $count->count_date,
                    narration: $count->narration ?: $count->document_no,
                    unitCost: $line->unit_cost === null ? null : (string) $line->unit_cost,
                );

                $line->update(['reason_code_id' => $reason->id]);
            }

            $count->update([
                'status' => DocumentStatus::CONFIRMED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            return $count->fresh(['lines']);
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
            $out[] = ['product_id' => $productId, 'counted_qty' => $counted];
        }

        return $out;
    }
}
