<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PrintedPriceCeiling;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ডেলিভারি চালান — মাল বেরিয়ে গেছে।
 *
 * ── দুইটা কাজ একই চলাচলে ──────────────────────────────────────────────
 * মাল তাক থেকে নামে (Floor কমে), আর অর্ডারে ধরা থাকলে সেই ধরাটাও ছাড়ে
 * (Reserved কমে) — একটাই সারিতে, একই ট্রানজেকশনে।
 *
 * আলাদা দুইটা সারিতে লিখলে একদিন একটা বসত আর অন্যটা বসত না, আর তখন মালটা
 * একইসাথে "চলে গেছে" ও "অর্ডারে ধরা আছে" দেখাত — অর্থাৎ Available দুইবার
 * কমত, একবার মাল যাওয়ার জন্য আর একবার ধরা থাকার জন্য।
 *
 * খতিয়ানে কিছু বসে না। মাল বেরোনো মানে বিক্রি নয় — ফেরত আসতে পারে। আয়
 * ও খরচ দুটোই বসে বিলের দিনে।
 */
final class DeliveryChallanService
{
    use CalculatesSalesLines;
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,

        // ছাপা দামের সীমা — কোম্পানি বন্ধ করতে পারে না, তাই কোনো সুইচ নেই
        private readonly PrintedPriceCeiling $ceiling,

        private readonly SettingsService $settings,

        // গাড়ির ভাড়া খাতায় বসানোর জন্য — নিচে postTransportCost()
        private readonly PostingEngine $posting,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): DeliveryChallan
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $order = $this->resolveOrder($data['sales_order_id'] ?? null);
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? $order?->warehouse_id);

            $documentNo = $this->numbers->next('DC');

            $challan = DeliveryChallan::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $order?->customer_id ?? $data['customer_id'],
                'warehouse_id' => $warehouse->id,
                'sales_order_id' => $order?->id,
                'trx_date' => $trxDate->toDateString(),
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($challan, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => DeliveryChallan::drillSourceType(),
                    'source_id' => $challan->id,
                ]);

            return $challan->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(DeliveryChallan $challan, array $data, array $lines): DeliveryChallan
    {
        $this->assertEditable($challan);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($challan, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $challan->trx_date);
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? $challan->warehouse_id);

            $challan->update([
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id ?? $challan->branch_id,
                'trx_date' => $trxDate->toDateString(),
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($challan, $lines);

            return $challan->fresh(['lines']);
        });
    }

    /**
     * মাল বেরিয়ে গেল — স্টক নামে, ধরা ছাড়ে।
     */
    public function confirm(DeliveryChallan $challan): DeliveryChallan
    {
        if ($challan->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $challan->document_no]),
            ]);
        }

        $challan->loadMissing(['lines.product', 'lines.orderLine', 'warehouse']);

        if ($challan->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($challan) {
            foreach ($challan->lines as $line) {
                $qty = (string) $line->delivered_qty;

                /*
                 * অর্ডারে যতটুকু ধরা ছিল ঠিক ততটুকুই ছাড়া হয়, বেশি নয়।
                 *
                 * চালানে অর্ডারের চেয়ে বেশি মাল থাকলে (অনুমোদিত অতিরিক্ত)
                 * বাড়তিটুকু কখনো ধরাই ছিল না — ওটুকুও ছাড়তে গেলে Reserved
                 * ঋণাত্মক হয়ে যেত, আর তখন Available স্টকের চেয়ে বেশি
                 * দেখাত।
                 */
                $release = $line->orderLine !== null
                    ? $this->releasableQty($line->orderLine, $qty)
                    : '0';

                /*
                 * issue() — move() নয়, কারণ লট ধরা পণ্যে একটা লাইন
                 * কয়টা চলাচল হবে তা আগে থেকে জানা যায় না।
                 *
                 * যে পণ্যে লট ধরা নেই তার আচরণ অবিকল আগের মতোই: একটাই
                 * সারি, batch_id খালি। ডিপোর চাল-ডাল-সাবান কিছু টের
                 * পায় না।
                 */
                $movements = $this->stock->issue(
                    product: $line->product,
                    warehouse: $challan->warehouse,
                    sourceType: DeliveryChallan::STOCK_SOURCE,
                    sourceId: $challan->id,
                    qty: $qty,
                    reserved: bccomp($release, '0', 4) > 0 ? bcmul($release, '-1', 4) : '0',
                    date: $challan->trx_date,
                    documentNo: $challan->document_no,
                );

                $this->assertWithinPrintedPrice($line, $movements);
            }

            $this->postTransportCost($challan);

            $challan->update(['status' => DocumentStatus::CONFIRMED]);

            return $challan->fresh(['lines']);
        });
    }

    /**
     * বাতিল — মাল স্টকে ফেরে, আর অর্ডারের ধরাটাও ফিরে আসে।
     */
    public function cancel(DeliveryChallan $challan, string $reason, Carbon|string|null $onDate = null): DeliveryChallan
    {
        if ($challan->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $challan->document_no]),
            ]);
        }

        $challan->loadMissing(['lines.product', 'lines.orderLine.order', 'warehouse']);

        $this->assertNotInvoiced($challan);

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($challan, $reason, $date) {
            if ($challan->status === DocumentStatus::CONFIRMED) {
                /*
                 * গাড়ির ভাড়ার দাখিলাও ফেরে।
                 *
                 * ⚠️ না ফিরলে পরিবহনকারীর খাতায় **একটা পাওনা বসে থাকত
                 * যার কোনো চালান নেই** — আর সেটা ধরা পড়ত মাস শেষে
                 * মেলানোর সময়, কারণ ছাড়াই। তিনি টাকা চাইতেন, আমরা
                 * কাগজ খুঁজে পেতাম না।
                 *
                 * ⓘ `reverse()` উল্টো সারি বসায়, মূল সারি মোছে না —
                 * নিয়ম ৫ ও [[NoHardDeleteGuard]] অনুযায়ী। তাই বাতিল
                 * চালানের ইতিহাসও খতিয়ানে থেকে যায়।
                 *
                 * ⚠️ আগে যাচাই করা **বাধ্যতামূলক**: `reverse()` কিছু না
                 * পেলে `PostingException` ছোঁড়ে। বেশিরভাগ চালানে পরিবহন
                 * খরচ থাকেই না, তাই যাচাই ছাড়া ডাকলে **ওই চালানগুলোর
                 * বাতিলই ভেঙে যেত** — আর ভুলটা দেখা দিত কেবল বাতিলের
                 * মুহূর্তে, অর্থাৎ যখন ব্যবহারকারী তাড়াহুড়োয় আছেন।
                 */
                $hasPosting = LedgerEntry::query()
                    ->where('source_type', DeliveryChallan::STOCK_SOURCE)
                    ->where('source_id', $challan->id)
                    ->exists();

                if ($hasPosting) {
                    $this->posting->reverse(
                        sourceType: DeliveryChallan::STOCK_SOURCE,
                        sourceId: $challan->id,
                        reversalDate: $date,
                        reason: $reason,
                    );
                }

                /*
                 * মাল ফেরে যে লট থেকে বেরিয়েছিল সেই লটেই।
                 *
                 * আগে এখানে লাইন ধরে নতুন করে গোনা হত, আর লট না থাকায়
                 * সেটা ঠিকই ছিল। লট আসার পর ওটা ভুল হয়ে যেত: FEFO
                 * আজকের অবস্থা ধরে অন্য লট বাছত, মাল ফিরত এমন বাক্সে
                 * যেখান থেকে কখনো বেরোয়ইনি, আর রিকলের সময় ভুল ক্রেতার
                 * কাছে ফোন যেত।
                 */
                $this->stock->reverse(
                    sourceType: DeliveryChallan::STOCK_SOURCE,
                    sourceId: $challan->id,
                    reversedType: DeliveryChallan::STOCK_SOURCE.':cancel',
                    date: $date,
                    narration: $reason,
                );

                /*
                 * ধরাটা আলাদা সারিতে ফেরে — লট ধরে নয়, লাইন ধরে।
                 *
                 * Reserved পণ্য ও গুদামের সংখ্যা, লটের নয়। আর অর্ডারটা
                 * এখনো খোলা থাকলেই কেবল ফেরে; বাতিল অর্ডারে ফেরালে ধরা
                 * থেকে যেত যা কেউ কোনোদিন ছাড়ত না।
                 */
                foreach ($challan->lines as $line) {
                    $reserve = $line->orderLine?->order?->status === DocumentStatus::CONFIRMED
                        ? (string) $line->delivered_qty
                        : '0';

                    if (bccomp($reserve, '0', 4) <= 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $challan->warehouse,
                        sourceType: DeliveryChallan::STOCK_SOURCE.':cancel',
                        sourceId: $challan->id,
                        floor: '0',
                        reserved: $reserve,
                        date: $date,
                        documentNo: $challan->document_no,
                        narration: $reason,
                    );
                }
            }

            $challan->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $challan->fresh(['lines']);
        });
    }

    /**
     * ছাপা দামের উপরে যাচ্ছে কি না — যে লটগুলো সত্যিই বেরোল, তাদের ধরে।
     *
     * ── কেন মাল বেরোনোর পরে, আগে নয় ─────────────────────────────────
     * কোন লট যাবে সেটা FEFO ঠিক করে, আর সেটা জানা যায় বরাদ্দের পরেই।
     * আগে দেখতে গেলে অনুমান করতে হত — আর অনুমানটা ভুল হলে ভুলের দিকটা
     * সবচেয়ে খারাপ: পুরনো সস্তা লট নতুন দামে বেরিয়ে যেত।
     *
     * পুরোটা একই লেনদেনে, তাই সীমা ভাঙলে চলাচলগুলোও ফিরে যায় — অর্ধেক
     * বেরোনো মাল বলে কিছু থাকে না।
     *
     * @param  list<StockMovement>  $movements
     */
    private function assertWithinPrintedPrice(DeliveryChallanLine $line, array $movements): void
    {
        /*
         * ক্রেতা প্রতি এককে যা দেন — ছাড়ের পরে।
         *
         * ছাড়ের আগের দর দেখলে ২৫ টাকা দর আর ঋণাত্মক ছাড় বসিয়ে সীমাটা
         * পেরোনো যেত, আর কাগজে নিয়মটা টিকে থাকত।
         */
        $percent = (string) ($line->discount_percent ?? '0');
        $rate = (string) $line->rate;

        /*
         * শতাংশটা শূন্য না হলেই হিসাবে ধরা হয় — ধনাত্মক হোক বা ঋণাত্মক।
         *
         * প্রথমে কেবল ধনাত্মক হলে ধরতাম, আর তাতে ঠিক সেই ফাঁকটাই খোলা
         * থেকে যেত যেটা বন্ধ করার কথা: ১২০ দর আর −১০% "ছাড়" মানে ক্রেতা
         * দিচ্ছেন ১৩২, অথচ কোড দেখত ১২০ আর সীমাটা পেরোনো ধরা পড়ত না।
         * টেস্টটা না লিখলে ফাঁকটা কোড পড়েও চোখে পড়ত না — মন্তব্যে তো
         * লেখাই ছিল যে ঋণাত্মক ছাড় আটকানো হয়।
         */
        $net = bccomp($percent, '0', 4) !== 0
            ? bcsub($rate, bcdiv(bcmul($rate, $percent, 6), '100', 6), 6)
            : $rate;

        foreach ($movements as $movement) {
            if ($movement->batch !== null) {
                $this->ceiling->assertWithin($movement->batch, $net);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(DeliveryChallan $challan, array $lines): void
    {
        $challan->lines()->delete();

        $total = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['delivered_qty'] ?? null, 'delivered_qty');
            $rate = $this->money($line['rate'] ?? null);

            $product = Product::query()->find($productId);

            if ($productId <= 0 || $product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            // "২ বাক্স @ ৮০০" — পরিমাণ আর দর একসাথে পণ্যের এককে নামে
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            $orderLine = $this->resolveOrderLine($challan, $line['sales_order_line_id'] ?? null, $productId);

            $amount = bcmul($qty, $rate, 4);

            DeliveryChallanLine::create([
                'delivery_challan_id' => $challan->id,
                'product_id' => $productId,
                'sales_order_line_id' => $orderLine?->id,
                'delivered_qty' => $qty,
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,
                'amount' => $amount,
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $total = bcadd($total, $amount, 4);
        }

        $challan->update(['total' => $total]);
    }

    /**
     * এই লাইনের বিপরীতে কতটুকু ধরা এখনো ছাড়া যায়।
     */
    /**
     * গাড়ির ভাড়া খাতায় বসানো — চালান নিশ্চিত হওয়ার মুহূর্তে।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * `transport_cost` চালানের ঘরে লেখা হত, আর **সেখানেই থেমে যেত** —
     * কোনো ভাউচার নয়, কোনো খাত নয়। অর্থাৎ পরিবহনের খরচ লাভ-ক্ষতিতে
     * আসতই না, আর **মুনাফা ঠিক ওই পরিমাণ বেশি দেখাত**।
     *
     * ⚠️ এটা রিপোর্টের ফাঁক নয়, হিসাবের ভুল।
     *
     * ── দাখিলাটা কেন এই আকারে ───────────────────────────────────────
     * মালিকের কথা (৪ সেপ্টেম্বর ২০২৬): *"চালানে বসালেও সেটা expense-এ
     * যাবে, আর transporter-এর সাথে হিসাব হবে"*।
     *
     *     Dr গাড়ির ভাড়া (৫২১৭)     — খরচটা আজই ঘটেছে
     *     Cr পরিবহনকারীর প্রদেয়      — টাকা আজ দেওয়া হয়নি
     *
     * `Cr নগদ` লিখলে ধরে নেওয়া হত টাকাটা ওই দিনই মিটেছে — যা ডিপোতে
     * সত্যি নয়। **পরিবহনকারীর সাথে হিসাব চলতি**, মাসে একবার মেটে।
     *
     * ── পক্ষ বাছা না থাকলে ──────────────────────────────────────────
     * তখন `Cr নগদ`, আর এটাই "একবারের গাড়ি"র ক্ষেত্র: যে গাড়ি একবার
     * আসে তার সাথে চলতি হিসাব থাকে না, টাকা ওই দিনই মেটে।
     *
     * ⓘ তখন খতিয়ানের সুবিধাটা পাওয়া যায় না — আর সেটাই ব্যবহারকারীকে
     * পক্ষ বাছতে উৎসাহ দেবে, **বাধ্য না করে**।
     *
     * ── কেন `confirm()`-এ, `create()`-এ নয় ──────────────────────────
     * খসড়া চালান বদলায় ও মুছে যায়; খসড়ায় দাখিলা লিখলে খাতায় এমন খরচ
     * বসত যার কোনো চালান নেই। আর সরাসরি বিক্রয়ের পথে `transport_cost`
     * বসে `create()`-এর **পরে** (stampExtras), তাই `confirm()`-ই একমাত্র
     * মুহূর্ত যেখানে সংখ্যাটা নিশ্চিতভাবে আছে।
     */
    private function postTransportCost(DeliveryChallan $challan): void
    {
        $cost = (string) ($challan->transport_cost ?? '0');

        // খালি বা শূন্য হলে কোনো দাখিলা নয় — শূন্য টাকার ভাউচার
        // খতিয়ান ভরিয়ে দিত, আর কিছুই বোঝাত না
        if ($cost === '' || bccomp($cost, '0', 4) <= 0) {
            return;
        }

        $expense = StandardChart::find(StandardChart::VEHICLE_HIRE);

        /*
         * খাতটা না থাকলে চুপচাপ ছেড়ে দেওয়া — চালান আটকানো নয়।
         *
         * ⚠️ এমন হতে পারে কেবল যদি কোম্পানি খাতটা নিজে মুছে ফেলে থাকে।
         * তখন মাল আটকে রাখার চেয়ে খরচটা না লেখা কম ক্ষতি — মাল তো
         * সত্যিই বেরিয়ে যাচ্ছে, আর সেটা আটকানো ব্যবসা থামায়।
         */
        if ($expense === null) {
            return;
        }

        $carrierId = $challan->carrier_id;

        /*
         * ভাড়া বাকি থাকলে **পরিবহনের নিজের ঘরে** (২১১৬), সাধারণ প্রদেয়ে নয়।
         *
         * ── কেন আলাদা ঘর ────────────────────────────────────────────
         * আগে এটা `PAYABLE`-এ যেত, অর্থাৎ ট্রাকের ভাড়া মিলের বিলের সাথে
         * একই সংখ্যায় মিশে থাকত। তখন *"এই মাসে পরিবহনে কত দিতে বাকি"*
         * প্রশ্নের উত্তর বের করার কোনো উপায় ছিল না — অথচ ডিপোতে ওটা
         * রোজকার প্রশ্ন, আর দেনাটা সম্পূর্ণ আলাদা মানুষের কাছে।
         *
         * ⓘ দলটার (২১১০) মোট বদলায় না — খাতের যোগফল গোটা গাছ হাঁটে।
         * বদলায় কেবল এইটুকু: ভেতরে কে কার কাছে দেনা, সেটা এখন পড়া যায়।
         */
        $credit = $carrierId !== null
            ? StandardChart::find(StandardChart::TRANSPORT_PAYABLE)
            : StandardChart::find(StandardChart::CASH_IN_HAND);

        if ($credit === null) {
            return;
        }

        $narration = __('sales::message.transport_for_challan', ['no' => $challan->document_no]);

        $this->posting->post(
            sourceType: DeliveryChallan::STOCK_SOURCE,
            sourceId: $challan->id,
            trxDate: $challan->trx_date,
            lines: [
                [
                    'account_id' => $expense->id,
                    'debit' => $cost,
                    'narration' => $narration,
                ],
                [
                    'account_id' => $credit->id,
                    'credit' => $cost,
                    // পক্ষ থাকলে তাঁর খতিয়ানে বসে; নগদের ক্ষেত্রে পক্ষ নেই
                    'party_type' => $carrierId !== null ? 'supplier' : null,
                    'party_id' => $carrierId,
                    'narration' => $narration,
                ],
            ],
            documentNo: $challan->document_no,
        );
    }

    private function releasableQty(SalesOrderLine $orderLine, string $qty): string
    {
        // এই চালানের নিজের সারিগুলো এখনো স্টকে বসেনি, তাই আগেরগুলো গোনা
        $alreadyDelivered = $orderLine->challanLines()
            ->whereHas('challan', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED))
            ->sum('delivered_qty');

        $stillReserved = bcsub(
            (string) $orderLine->ordered_qty,
            (string) ($alreadyDelivered ?: '0'),
            4,
        );

        if (bccomp($stillReserved, '0', 4) <= 0) {
            return '0';
        }

        return bccomp($qty, $stillReserved, 4) > 0 ? $stillReserved : $qty;
    }

    private function resolveOrderLine(DeliveryChallan $challan, mixed $orderLineId, int $productId): ?SalesOrderLine
    {
        if ($challan->sales_order_id === null || blank($orderLineId)) {
            return null;
        }

        $orderLine = SalesOrderLine::query()
            ->where('sales_order_id', $challan->sales_order_id)
            ->whereKey((int) $orderLineId)
            ->first();

        if ($orderLine === null) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.line_not_in_order')]);
        }

        if ((int) $orderLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.line_product_mismatch')]);
        }

        return $orderLine;
    }

    private function resolveOrder(mixed $orderId): ?SalesOrder
    {
        if (blank($orderId)) {
            return null;
        }

        $order = SalesOrder::query()->whereKey((int) $orderId)->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'sales_order_id' => __('sales::validation.unknown_order'),
            ]);
        }

        if ($order->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'sales_order_id' => __('sales::validation.order_not_open', ['no' => $order->document_no]),
            ]);
        }

        return $order;
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

    /**
     * বিল হয়ে যাওয়া চালান বাতিল করা যায় না — ক্রমটা উল্টো দিকে।
     */
    private function assertNotInvoiced(DeliveryChallan $challan): void
    {
        $invoiced = DeliveryChallanLine::query()
            ->where('delivery_challan_id', $challan->id)
            ->whereHas('invoiceLines.invoice', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->exists();

        if ($invoiced) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.challan_already_invoiced', ['no' => $challan->document_no]),
            ]);
        }
    }

    private function assertEditable(DeliveryChallan $challan): void
    {
        if ($challan->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $challan->document_no]),
            ]);
        }
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
