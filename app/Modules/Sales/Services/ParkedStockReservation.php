<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * ধরে রাখা বিলের মাল আটকে রাখা।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * মালিকের বর্ণনা (৩ সেপ্টেম্বর ২০২৬): *"এক পার্টির বিল ৯০% হয়ে গেছে,
 * আরেকজন দাঁড়িয়ে — প্রথমটা হোল্ডে রেখে দ্বিতীয়টা করতে হবে।"* আর সাথে
 * একটা শর্ত: **মাল আটকে থাকবে**।
 *
 * ⚠️ ── আজ পার্ক করলে মাল আটকায় না ─────────────────────────────────
 * `PosService::park()` কেবল `parked_at` বসায়; স্টকের একটাও নড়াচড়া নেই।
 * তাই দুইটা কাউন্টার একই শেষ কার্টনটা একই সাথে বিক্রি করতে পারত —
 * একজনের বিল ঝুলে আছে, অন্যজন বেচে দিলেন, আর প্রথমজন ফিরে এসে দেখেন
 * মাল নেই।
 *
 * ── ⭐ কেন `Hold` নয়, `Reserved` ───────────────────────────────────
 * দুইটাই `available` থেকে বাদ যায় (`floor − reserved − hold`), তাই
 * ব্যবসার ফল এক। কিন্তু `StockService::hold()` একটা **ReasonCode**
 * দাবি করে যার `context` হতে হবে `HOLD` — ওটা *কারণসহ হাতে আটকানোর*
 * জন্য: নষ্ট প্যালেট, দামের হোল্ড।
 *
 * **একটা ধরে রাখা বিল কোনো "কারণ" নয়, ওটা একটা সংরক্ষণ।** ওখানে
 * কারণ-কোড দাবি করলে কাউন্টারের কর্মীকে প্রতিবার একটা কারণ বাছতে হত,
 * আর *"বিল হোল্ডে আছে"* নামে একটা কৃত্রিম কারণ-কোড বানাতে হত — সেটাই
 * হত নতুন ধারণা।
 *
 * ── কতক্ষণ আটকে থাকবে ───────────────────────────────────────────────
 * মালিকের উত্তর: **"যতক্ষণ না cancel করছি।"** কোনো মেয়াদ নেই, কোনো
 * ক্রন নেই, কোনো "সাত দিন পরে নিজে থেকে" নেই। মাল ছাড়া পায় কেবল
 * মানুষ বিলটা বাতিল বা নিশ্চিত করলে।
 *
 * ⚠️ তাই **ভুলে যাওয়াই একমাত্র ঝুঁকি**, আর তার একমাত্র ওষুধ ধরে-রাখা
 * বিলের তালিকা — পুরনোটা উপরে ([[PosService::parked()]] ওই ক্রমেই দেয়)।
 */
final class ParkedStockReservation
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * বিলের প্রতিটা সারির মাল আটকানো।
     *
     * ⓘ `reason` পাঠানো হয় না — `StockService::move()`-এ ওটা nullable,
     * আর এখানে কারণটা কাগজটাই: `source_type` = বিল, `source_id` = তার
     * আইডি। কেউ পরে জিজ্ঞেস করলে সংরক্ষণটা নিজের কাগজ দেখাতে পারে।
     */
    public function reserve(SalesInvoice $invoice): void
    {
        $this->apply($invoice, hold: true);
    }

    /**
     * আটকানো ছেড়ে দেওয়া — বাতিল হলে, বা বিল খাতায় বসে গেলে।
     *
     * ⚠️ ── নিশ্চিত করার সময় এটা না ডাকলে যা হত ────────────────────
     * `SalesInvoiceService::confirm()` সরাসরি `floor` কমায়। সংরক্ষণটা
     * তখনো রয়ে গেলে:
     *
     *   floor      −১০   বিক্রি হয়ে গেছে
     *   reserved   +১০   রয়ে গেছে
     *   available  −২০   ← যা কোনোদিন সত্যি ছিল না
     *
     * **মাল বিক্রি হয়ে যাওয়ার পরেও আটকে থাকত, চিরকাল** — আর কেউ
     * ধরতে পারত না, কারণ কোথাও কিছু ভাঙত না।
     */
    public function release(SalesInvoice $invoice): void
    {
        $this->apply($invoice, hold: false);
    }

    /**
     * একই কাজ, দুই দিকে — তাই এক জায়গায়।
     *
     * ⓘ দুইটা আলাদা পদ্ধতি লিখলে একদিন একটায় ব্যাচের হিসাব যোগ হত আর
     * অন্যটায় না, আর তখন ছাড়ার সংখ্যা আটকানোর সংখ্যার সমান হত না।
     */
    private function apply(SalesInvoice $invoice, bool $hold): void
    {
        DB::transaction(function () use ($invoice, $hold): void {
            $invoice->loadMissing('lines.product');

            $warehouse = $invoice->warehouse_id !== null
                ? Warehouse::query()->withoutGlobalScopes()->find($invoice->warehouse_id)
                : null;

            /*
             * গুদাম না জানলে আটকানোরও উপায় নেই।
             *
             * ⚠️ চুপচাপ ফিরে যাওয়া হয়, ব্যতিক্রম নয় — কারণ গুদামহীন বিল
             * এই রিপোতে বৈধ (সেবা বা খরচের বিল, যাতে মাল নেই)। ওখানে
             * আটকানোর মতো কিছুই থাকে না।
             */
            if ($warehouse === null) {
                return;
            }

            foreach ($invoice->lines as $line) {
                if ($line->product === null) {
                    continue;
                }

                $qty = (string) $line->qty;

                if (bccomp($qty, '0', 4) <= 0) {
                    continue;
                }

                $this->stock->move(
                    product: $line->product,
                    warehouse: $warehouse,
                    sourceType: SalesInvoice::STOCK_SOURCE,
                    sourceId: $invoice->id,
                    reserved: $hold ? $qty : bcmul($qty, '-1', 4),
                    date: $invoice->trx_date,
                    documentNo: $invoice->document_no,
                );
            }
        });
    }
}
