<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PrintedPriceCeiling;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\SellableHere;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesOrder;
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

        /*
         * ── এখানে `SettingsService` নেই, আর সেটা ইচ্ছাকৃত ──────────────
         *
         * একটা `SettingsService` এখানে ইনজেক্ট করা ছিল আর কোনোদিন পড়া
         * হয়নি। দেখে স্বাভাবিক সন্দেহ হয় — "বিল তো `sales.allow_negative_
         * stock` জিজ্ঞেস করে, চালান করে না কেন?" ⛔ সন্দেহটা ভুল, আর
         * উত্তরটা এখানে লেখা থাকল যাতে পরের জন আবার একই পথে না হাঁটেন।
         *
         * ১. ভৌত মজুদ ঋণাত্মক হতে **কোনো পথেই** পারে না:
         *    `StockService::move()` শর্তহীনভাবে `assertEnoughOnFloor()`
         *    ডাকে — কোনো সেটিং সে পড়ে না, কোনো বাইপাস প্যারামিটার নেই।
         *    `issue()` নিজেই `move()`-এ নামে, তাই চালানও ঐ পাহারার নিচে।
         *
         * ২. `sales.allow_negative_stock` তাই "শূন্যের নিচে বেচা" নয়।
         *    `assertEnoughToSell()` মাপে `available = floor − reserved −
         *    hold`, অর্থাৎ সেটিংটা **সংরক্ষিত বা হোল্ড করা মালে হাত দিতে**
         *    দেয়। ওটা বিক্রয়ের প্রশ্ন, ভৌত মজুদের নয়।
         *
         * ৩. আর চালান সংরক্ষণ নিয়ে অন্ধ নয়: মাল বেরোনোর সাথে সে নিজের
         *    রিজার্ভেশনটা ছেড়ে দেয় (নিচে `reserved: bcmul($release, '-1')`)।
         *    অন্যের জন্য রাখা মালে সে হাত দেয় না।
         *
         * ⚠️ তাই এখানে "পাহারা" বসালে সেটা বাড়তি নিরাপত্তা দিত না, বরং
         * ডিপোর চালান রিজার্ভেশনের কারণে আটকে যেত — যে মাল ইতিমধ্যেই এই
         * চালানের জন্যই ধরা আছে, তার জন্যই।
         */

        // গাড়ির ভাড়া খাতায় বসানোর জন্য — নিচে postTransportCost()
        private readonly PostingEngine $posting,

        /*
         * ⚠️ নগদ ভাড়ার জন্য — আর এটাই ৫ সেপ্টেম্বর ২০২৬-এর সারাই।
         *
         * আগে লেখা ছিল `StandardChart::find(CASH_IN_HAND)`, অর্থাৎ
         * খাত ১১০১। ⛔ কিন্তু ১১০১ ছকে একটা **দল** — আসল ক্যাশবাক্সগুলো
         * তার সন্তান (`MONEY_PARENTS`-এ ওটা মা হিসেবেই লেখা)।
         *
         * ⛔ ফল: গাড়ি নিজের হলে ভাড়ার টাকা একটা দলের খাতে বসত, আর
         * দলের নিজের সারি কোনো যোগফলে আসে না — খতিয়ানে সারিটা থাকত,
         * প্রতিটা রিপোর্ট ততটাই কম দেখাত, আর কিছুই লাল হত না।
         *
         * ⓘ আদায় ও পরিশোধ অনেক আগেই এই পথে গেছে (`ensurePrimaryTill`)
         * — কেবল এই একটা জায়গা বাদ পড়েছিল। ⭐ ধরা পড়েছে নতুন
         * [[PostingEngine]] পাহারায়, প্রথম দিনেই।
         */
        private readonly CashTillService $tills,
        private readonly DocumentApproval $approvals,
        private readonly CreditExposure $credit,
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

            /*
             * ⭐ একটা বিক্রির একটাই নম্বর (মালিক, ২৯ সেপ্টেম্বর ২০২৬) — DO-তেই জন্ম। ⓘ একই আদেশের
             * আগের DO থাকলে সেই বিক্রিরই নম্বর (দ্বিতীয় চালান S-0012/2); নাহলে নতুন বিক্রি।
             */
            $given = trim((string) ($data['document_no'] ?? ''));

            /*
             * ⭐ ২ অক্টোবর ২০২৬ থেকে খসড়া DRF নম্বরে জন্মায়; আসল CHA নম্বর নিশ্চিতে ([[giveTheSaleItsNumber()]])।
             * ⓘ হাতে লেখা নম্বর জন্মেই চূড়ান্ত — পুরনো কাগজের বই থেকে বসানো।
             */
            $numbers = app(SaleNumber::class);
            $saleNo = $numbers->isHandWritten($given) ? $this->saleNumberFor($order?->id, $given) : null;
            $documentNo = $saleNo !== null
                ? $numbers->forPaper(DeliveryChallan::class, $saleNo)
                : $numbers->draft();

            $challan = DeliveryChallan::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'sale_no' => $saleNo,
                'customer_id' => $order?->customer_id ?? $data['customer_id'],
                'warehouse_id' => $warehouse->id,
                'sales_order_id' => $order?->id,
                'trx_date' => $trxDate->toDateString(),
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                /*
                 * ⭐ গেট পাসে মাল বেরোনো আর বিল — সব চালানে, কেবল কাউন্টারের নয় (সমন্বয়ক, ৬ অক্টোবর ২০২৬; মালিকের পরিকল্পনা §৩
                 * ধাপ ৫–৬)। ⓘ আদেশ, DO বা অফিস থেকে লেখা চালান কোম্পানির সুইচ নিজেই পড়ে; কাউন্টার "এখনই নিয়ে যাবেন" হলে নিজে
                 * বন্ধ করে দেয় ([[DirectSaleService::issuesAtGate()]])। সুইচ বন্ধ থাকলে আগের মতো — চালান নিশ্চিত হলেই মাল নামে।
                 */
                'issue_at_gate' => array_key_exists('issue_at_gate', $data)
                    ? (bool) $data['issue_at_gate']
                    : (bool) app(\App\Core\Services\SettingsService::class)->get('sales.invoice_at_goods_issue', false),
                'driver_name' => $data['driver_name'] ?? null,
                // ⓘ কাউন্টার পাঠায়, আগে এখানে চুপচাপ হারাত — নিশ্চিতকরণের পাতায় চালকের ফোন আসত না
                'driver_phone' => $data['driver_phone'] ?? null,
                /*
                 * ⭐ বাহক আর ভাড়াও — মালিক, ২ অক্টোবর ২০২৬: *"Gate Pass e transport driver details nai"*।
                 * ⛔ আগে কেবল কাউন্টার (সরাসরি বিক্রয়) এগুলো রাখত; অফিসের চালানে চুপচাপ হারাত, তাই গেট পাসে কখনো আসত না।
                 */
                'carrier_id' => $data['carrier_id'] ?? null,
                'carrier_name' => $data['carrier_name'] ?? null,
                'transport_cost' => $data['transport_cost'] ?? null,
                'own_transport' => (bool) ($data['own_transport'] ?? false), // ⓘ ধাপ ৫ — [[TransportRule]]
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
                // ⓘ কাউন্টার পাঠায়, আগে এখানে চুপচাপ হারাত — নিশ্চিতকরণের পাতায় চালকের ফোন আসত না
                'driver_phone' => $data['driver_phone'] ?? null,
                /*
                 * ⭐ বাহক আর ভাড়াও — মালিক, ২ অক্টোবর ২০২৬: *"Gate Pass e transport driver details nai"*।
                 * ⛔ আগে কেবল কাউন্টার (সরাসরি বিক্রয়) এগুলো রাখত; অফিসের চালানে চুপচাপ হারাত, তাই গেট পাসে কখনো আসত না।
                 */
                'carrier_id' => $data['carrier_id'] ?? null,
                'carrier_name' => $data['carrier_name'] ?? null,
                'transport_cost' => $data['transport_cost'] ?? null,
                'own_transport' => (bool) ($data['own_transport'] ?? false), // ⓘ ধাপ ৫ — [[TransportRule]]
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            /*
             * ⭐ সারি নতুন করে বসার আগে অফারগুলো ওঠে — অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬।
             * ⓘ নিচের replaceLines() সারিগুলো মুছে নতুন বসায়; বসানো অফার পুরনো সারির আইডিতে
             * অনাথ হত আর বাজেট ঐ টাকা খরচ ধরে রাখত ([[ChallanOffers::reverseAll()]])।
             */
            app(\App\Modules\Sales\Services\ChallanOffers::class)->reverseAll($challan);

            $this->replaceLines($challan, $lines);

            return $challan->fresh(['lines']);
        });
    }

    /**
     * মাল বেরিয়ে গেল — স্টক নামে, ধরা ছাড়ে।
     */
    /**
     * @param  string  $payingNow  এই মালের জন্য **এখনই** গোনা টাকা — কেবল
     *                             কাউন্টার পাঠায়। ⓘ অফিসের ডিও-তে শূন্য:
     *                             মাল যায়, টাকা আসে পরে।
     * @param  list<array{0: string, 1: int}>  $ownReservations  এই বিক্রিরই অন্য সংরক্ষণ (যেমন DO-র কড়া
     *                             আটকানো, [[DeliveryOrderStock::reservationsOf()]]) — "পাওয়া যায়"-এর পাহারায় এগুলো
     *                             এই চালানের জন্যই রাখা বলে গোনা হয় (অডিট গ১১, ৪ অক্টোবর ২০২৬)।
     */
    public function confirm(DeliveryChallan $challan, string $payingNow = '0', array $ownReservations = []): DeliveryChallan
    {
        if ($challan->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $challan->document_no]),
            ]);
        }

        // ⓘ `lines.batch`-ও — অফিসের চালানও এখন গেটের পথে যায় (৬ অক্টোবর ২০২৬), আর সেই পথ লট পড়ে; নইলে আলাদা-আলাদা টানা (লোকালে ৫০০)
        $challan->loadMissing(['lines.product', 'lines.orderLine', 'lines.batch', 'warehouse']);

        if ($challan->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        /*
         * ⛔ বাকির সীমা — অনুমোদনের **আগে**, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ মালিক যা ধরেছেন ──────────────────────────────────────
         * ৫০,০০০ সীমার গ্রাহকে ৮৯,৭২০ টাকার কাগজ *"অনুমোদনের জন্য
         * পাঠানো হয়েছে"* বলে দাঁড়িয়ে ছিল। ⚠️ সীমা পার করেছে বলে নয় —
         * **অঙ্কটা বড় বলে**। নিচের `assertClear()` কাগজ অনুমোদনে পাঠিয়ে
         * থেমে যায়, আর সীমার প্রশ্ন কখনো আসত না। ⭐ মালিকের নিয়ম:
         * *"eta অনুমোদনের জন্য পাঠানো hobena, bill komiye nite hobe ba taka
         * joma dite hobe"*।
         *
         * ── ⓘ কেন চালানে, বিলের আগে ───────────────────────────────
         * মালিক: *"DO/delivery order theke suro hobe"*। মাল গেট পার হলেই
         * টাকা ঝুঁকিতে — বিল হোক বা না হোক।
         *
         * ⚠️ `exceptChallanId`: কাউন্টারে ধরে রাখা বিক্রয়ে এই চালানের
         * একটা খসড়া বিল আগে থেকেই সীমা আটকে রেখেছে — একই মাল, একই টাকা।
         * ⛔ বাদ না দিলে দুইবার গোনা হত।
         */
        if ($challan->customer !== null) {
            $this->credit->assertRoom(
                customer: $challan->customer,
                // ⛔ বিলের মাপে, ছাড়ের পরে — চালানের মোটে ছাড় নেই ([[CreditExposure::billedAs()]])
                adding: $this->credit->billedAs($challan),
                payingNow: $payingNow,
                exceptChallanId: (int) $challan->id,
            );
        }

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * মালিকের কথা: *"এখন সব জায়গায় এপ্রুভাল দিয়ে টেস্ট কর, পরে যে
         * যে জায়গায় লাগবে না তাও উঠিয়ে দিব"*।
         *
         * ⚠️ সারিটা কারো আজকের কাজ থামায় না: ছক না বসানো পর্যন্ত
         * `assertClear()` চুপচাপ ফিরে যায়, আর কাজ আগের মতোই চলে।
         * ⓘ কত টাকার উপরে সই লাগবে সেটা প্রতিটা কোম্পানি নিজে বসায় —
         * এক ডিপোর "বড় কাজ" আরেকটার রোজকার কাজ।
         */
        $this->approvals->assertClear(
            document: $challan,
            module: 'sales',
            action: 'challan',
            field: 'status',

            /*
             * ⭐ অঙ্কটাও যায় — ২৩ সেপ্টেম্বর ২০২৬, মালিকের প্রশ্নে।
             *
             * ⓘ তিনি একটা আটকে থাকা বিল দেখিয়ে বললেন বার্তাটা **কেন**
             * আটকেছে সেটা বলে না। ⚠️ অঙ্ক না পাঠালে সইকারীর ইনবক্সেও
             * সারিটা টাকাহীন বসত, আর তিনি কাগজটা না খুলে বুঝতে পারতেন
             * না এটা পাঁচশো টাকার না নব্বই হাজারের।
             *
             * ⛔ আর অঙ্কটা আরেকটা কাজ করে: [[Approval::covers()]] সইটা
             * কোন অঙ্কের উপর দেওয়া হয়েছিল তা মনে রাখে, তাই পরে কাগজ
             * বদলে বড় করলে পুরনো সই আর খাটে না।
             */
            amount: (string) $challan->total,
            reason: $challan->narration,
        );

        /*
         * ⛔ মার্জিনের দেয়াল — NEXUS §৩২, মালিক: "baki kaj complate koro" (২৮ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ চালানে মাল বেরোয়, তাই প্রশ্নটা এখানে — মাল নড়ার আগে। কোম্পানির সীমা
         * (`sales.margin.floor_percent`, ডিফল্ট ০%) আর তার নিচে কী হবে (`sales.margin.action`:
         * সতর্ক / অনুমোদন / আটকানো, ডিফল্ট সতর্ক) — [[MarginGuard::assertMargin()]]।
         * ⚠️ অনুমোদনে ওপরের চালানের সইয়ের মতোই থামে — কাগজ খসড়া, কিছুই নড়ে না।
         */
        app(MarginGuard::class)->assertMargin($challan);

        return DB::transaction(function () use ($challan, $payingNow, $ownReservations) {
            /*
             * ⛔ একই দেয়াল আবার — এবার গ্রাহকের সারিতে তালা দিয়ে, ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ উপরের দেয়াল লেনদেনের বাইরে: দুই কাউন্টার একই মুহূর্তে একই
             * গ্রাহকের চালান নিশ্চিত করলে দুইজনেই "জায়গা আছে" দেখত। ⭐ এখানে
             * তালা পড়ে, দ্বিতীয়জন অপেক্ষা করে, আর প্রথমজনের কমিটের পরের অঙ্ক
             * দেখে ([[CreditExposure::assertRoomLocked()]])।
             *
             * ⚠️ লেনদেনের **প্রথম** কাজ — আগে কিছু পড়লে InnoDB-র ছবি পুরনো
             * থাকত। ⛔ যুক্তিগুলো হুবহু উপরের মতো, `exceptChallanId` সহ —
             * নইলে ধরে রাখা বিক্রয়ের খসড়া বিল দুইবার গোনা হত।
             */
            if ($challan->customer !== null) {
                $this->credit->assertRoomLocked(
                    customer: $challan->customer,
                    adding: $this->credit->billedAs($challan),
                    payingNow: $payingNow,
                    exceptChallanId: (int) $challan->id,
                );
            }

            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($challan);

            if ($challan->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_confirms', ['no' => $challan->document_no]),
                ]);
            }

            // ⭐ আসল নম্বর এখন — মাল আর খাতা নিচে এই নম্বরেই লেখা হয়
            $this->giveTheSaleItsNumber($challan);

            /*
             * ⭐ আদেশ নিজে যতটা ধরে আছে — মজুদের খাতা থেকে, এক কোয়েরিতে (SO+DO নকশার ধাপ ৫, abos-bb, ৪ অক্টোবর ২০২৬;
             * [[SalesOrderService::heldByThisOrder()]])।
             *
             * ⛔ আগে ধরে নেওয়া হত আদেশের "অর্ডার − আগে ডেলিভার" পুরোটাই ধরা আছে। অথচ সুইচ বন্ধে নিশ্চিত হওয়া আদেশ
             * কিছুই ধরে না — তার চালান তখন অন্য কাগজের ধরা মাল ছেড়ে দিত।
             */
            $own = $challan->sales_order_id !== null && $challan->order !== null
                ? app(SalesOrderService::class)->heldByThisOrder($challan->order)
                : [];

            foreach ($challan->lines->sortBy('line_no') as $line) {
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
                    ? $this->ownShare($own, (int) $line->product_id, $qty)
                    : '0';

                /*
                 * ⭐ গেট পাসে মাল বেরোনো (সুইচ `sales.invoice_at_goods_issue`, মালিক, ৪ অক্টোবর ২০২৬; SAP-এর Post Goods Issue)।
                 * ⓘ এই চালানে মাল এখন কেবল **আটকায়** — তাক থেকে কমে না; বেরোয় গেট পাসে ([[GoodsIssue::issue()]])।
                 * আটকানো = পরিমাণ − আদেশের যতটা এখানে ছাড়া হলো (আদেশের ধরা এই চালানের হয়ে যায়), "পাওয়া যায়" থেকে, তালাসহ।
                 * ⚠️ লট আর ছাপা দামের যাচাই গেট পাসে — মাল তখনই সত্যিই বেরোয়।
                 */
                if ($challan->issue_at_gate) {
                    $hold = bcsub($qty, $release, 4);

                    if (bccomp($hold, '0', 4) !== 0) {
                        $this->stock->move(
                            product: $line->product,
                            warehouse: $challan->warehouse,
                            sourceType: DeliveryChallan::STOCK_SOURCE,
                            sourceId: $challan->id,
                            reserved: $hold,
                            date: $challan->trx_date,
                            documentNo: $challan->document_no,
                            narration: __('sales::message.held_for_gate', ['no' => $challan->document_no]),
                            batch: $line->batch,
                            fromAvailable: true,
                            ownReservations: $ownReservations,
                        );
                    }

                    continue;
                }

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

                    /*
                     * ⭐ বিক্রেতা লট বাছলে সেটাই যায় — ২৫ সেপ্টেম্বর ২০২৬।
                     *
                     * ⓘ `null` হলে আগের আচরণ হুবহু: FEFO। ⚠️ সাধারণ চালান
                     * (অর্ডার থেকে, পোর্টাল থেকে) লট বাছে না, আর তাদের
                     * সারিতে ঘরটা খালি — তাই তাদের কিছুই বদলায় না।
                     */
                    batch: $line->batch,

                    /*
                     * ⭐ "পাওয়া যায়" থেকে, তাক থেকে নয় — অডিট গ১১, ৪ অক্টোবর ২০২৬।
                     * ⛔ আগে কেবল তাক দেখা হত: অন্য ডিলারের DO-র সংরক্ষিত মাল, পরিদর্শনে বাতিল মাল, স্থানান্তরের
                     * ট্রাকের মাল — সবই কাউন্টারে বিক্রি হয়ে যেত। ⓘ আদেশের যতটা উপরে ছাড়া হচ্ছে, সেটুকু নিজেই
                     * ফেরে; মাপা হয় কেবল তার বাইরেরটা, তালাসহ ([[StockService::move()]])।
                     */
                    fromAvailable: true,
                    ownReservations: $ownReservations,
                );

                $this->assertWithinPrintedPrice($line, $movements);
            }

            $this->postTransportCost($challan);

            $challan->update(['status' => DocumentStatus::CONFIRMED]);

            // ⭐ আদেশের চালানের অগ্রগতি — মাল আর খাতা বসার পরে, একই লেনদেনে (SO+DO ধাপ ৫; [[OrderProgress::refreshOrders()]])
            app(OrderProgress::class)->refreshOrders($this->ordersOf($challan));

            return $challan->fresh(['lines']);
        });
    }

    /**
     * ⭐ গেট পাসে মাল বেরোনো — আটকানো চালানের (মালিক, ৪ অক্টোবর ২০২৬; SAP-এর Post Goods Issue; [[GoodsIssue::issue()]])।
     *
     * ⓘ প্রতিটা সারির পুরো পরিমাণ আটকানো থেকে ছাড়ে আর তাক থেকে বেরোয় — একই উৎস-নামে, তাই বিলের খরচ ([[SalesInvoiceService]]
     * `lotsThatLeft`) আর বাতিলের উল্টানো আগের পথেই চলে। লটের তালা, "লটে যা আছে তার বেশি নয়" আর ছাপা দামের সীমা — সব এখানে,
     * কারণ মাল এখনই সত্যিই বেরোচ্ছে। ⓘ বেরোনোর তারিখ আজ — খরচ ওঠে মাল বেরোনোর দিনে (IFRS ১৫)।
     */
    public function issueHeldGoods(DeliveryChallan $challan): void
    {
        $challan->loadMissing(['lines.product', 'lines.batch', 'warehouse']);

        foreach ($challan->lines->sortBy('line_no') as $line) {
            $qty = (string) $line->delivered_qty;

            $movements = $this->stock->issue(
                product: $line->product,
                warehouse: $challan->warehouse,
                sourceType: DeliveryChallan::STOCK_SOURCE,
                sourceId: $challan->id,
                qty: $qty,
                reserved: bcmul($qty, '-1', 4),
                date: now()->toDateString(),
                documentNo: $challan->document_no,
                batch: $line->batch,
            );

            $this->assertWithinPrintedPrice($line, $movements);
        }
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
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার, আর এর মধ্যে বিল হয়ে গেল কি না ([[lockAndReread()]])
            $this->lockAndReread($challan);

            if ($challan->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.already_cancelled', ['no' => $challan->document_no]),
                ]);
            }

            $this->assertNotInvoiced($challan);

            /*
             * ⭐ বাতিলে অফারও ফেরে — ছাড়, বাজেট, কুপন, পয়েন্ট ([[PromotionReversal::forSource()]])।
             * ⓘ খসড়া বা পাকা দুই অবস্থাতেই; বসানো না থাকলে কিছুই হয় না।
             */
            app(\App\Modules\Sales\Services\ChallanOffers::class)->reverseAll($challan);

            if ($challan->status === DocumentStatus::CONFIRMED) {
                $this->unpost($challan, $date, $reason);
            }

            $challan->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            // ⭐ বাতিলে অগ্রগতি আবার নামে — partial থেকে none (SO+DO ধাপ ৫)
            app(OrderProgress::class)->refreshOrders($this->ordersOf($challan));

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

            $orderLine = $this->resolveOrderLine($challan, $line['sales_order_line_id'] ?? null, $productId, $qty);

            // ⭐ আদেশ ছাড়া নতুন বাছা পণ্য সক্রিয় আর এই শাখার (Inventory অডিট ম২৩) — কাউন্টার, ফোন আর চালানের পর্দা সবাই এখানে আসে
            if ($orderLine === null) {
                app(SellableHere::class)->assert($product, $challan->branch_id === null ? null : (int) $challan->branch_id);
            }

            $amount = bcmul($qty, $rate, 4);

            DeliveryChallanLine::create([
                'delivery_challan_id' => $challan->id,
                'product_id' => $productId,

                /*
                 * ⭐ বিক্রেতার বাছা লট — ২৫ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⛔ এখানেই শিকলটা ছিঁড়ে ছিল ─────────────────────────
                 * ⓘ এই পদ্ধতিটা ঘরগুলো **হাতে বেছে** নেয়, তাই তালিকায়
                 * না থাকা যেকোনো ঘর নীরবে পড়ে যায় — কোনো ত্রুটি নয়।
                 * ⚠️ `fillable`-এ `batch_id` বসানো ছিল, সেবা লট চাইত আর
                 * যাচাইও করত, তবু সারিটা চালানে বসত **লট ছাড়া**।
                 *
                 * ⛔ আর ফলটা নিখুঁতভাবে নীরব: মাল বেরোত FEFO ধরে,
                 * অর্থাৎ সম্ভবত অন্য লট থেকে। কাগজে এক লট, গুদামে
                 * আরেকটা, আর ধরা পড়ত কেবল রিকলের দিন।
                 *
                 * ⓘ সাধারণ চালানে (অর্ডার, পোর্টাল) ঘরটা আসেই না, আর
                 * তখন `null` — আগের আচরণ হুবহু।
                 */
                'batch_id' => $line['batch_id'] ?? null,

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

        /*
         * ⭐ ভাড়া কে দেবে — মালিক, ৪ অক্টোবর ২০২৬ (আন্তর্জাতিক freight terms)। ⓘ "ক্রেতা দেবেন" (Collect) মানে ক্রেতা
         * চালককে সরাসরি দেন — আমাদের খাতায় কিছু নয়, কাগজে কেবল তথ্য; "নেই" মানে ভাড়াই নেই। খালি, "আমরা" আর
         * "আমরা, বিলে যোগ" — খরচটা আমাদের, আগের মতো (বিলের আদায় আলাদা, [[SalesInvoiceService::postToLedger()]])।
         */
        if (in_array($challan->fare_paid_by, ['customer', 'none'], true)) {
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
            : $this->tills->ensurePrimaryTill()->account;

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

    /**
     * এই চালান-সারি আদেশের ধরা থেকে কতটা ছাড়বে — সারির পরিমাণ আর ঐ পণ্যের অবশিষ্ট ধরার ছোটটা; যতটা দেওয়া হলো
     * ভাগ থেকে কমে, যাতে একই পণ্যের পরের সারি বাকিটুকুই পায় ([[SalesOrderService::ownShare()]]-এর একই নিয়ম)।
     *
     * @param  array<int, string>  $own  পণ্য → আদেশের অবশিষ্ট ধরা
     */
    private function ownShare(array &$own, int $productId, string $qty): string
    {
        $left = $own[$productId] ?? '0';
        $take = bccomp($qty, $left, 4) > 0 ? $left : $qty;

        if (bccomp($take, '0', 4) <= 0) {
            return '0';
        }

        $own[$productId] = bcsub($left, $take, 4);

        return $take;
    }

    private function resolveOrderLine(
        DeliveryChallan $challan,
        mixed $orderLineId,
        int $productId,
        string $qty,
    ): ?SalesOrderLine {
        if ($challan->sales_order_id === null || blank($orderLineId)) {
            return null;
        }

        /*
         * ⚠️ `with('order')` — বার্তায় আদেশের নম্বরটা লাগে, আর
         * `Model::preventLazyLoading()` local-এ চালু
         * ([[AppServiceProvider:179]])। লেজি পড়লে ঠিক যে মুহূর্তে
         * ব্যবহারকারীর বাংলা বার্তাটা দেখানোর কথা, সেই মুহূর্তেই একটা
         * ৫০০ আসত — অর্থাৎ পাহারাটা নিজেই পর্দা ভাঙত।
         */
        $orderLine = SalesOrderLine::query()
            ->with('order')
            ->where('sales_order_id', $challan->sales_order_id)
            ->whereKey((int) $orderLineId)
            ->first();

        if ($orderLine === null) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.line_not_in_order')]);
        }

        if ((int) $orderLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.line_product_mismatch')]);
        }

        /*
         * ── আদেশের চেয়ে বেশি ডেলিভারি নয় (৬ সেপ্টেম্বর ২০২৬) ─────────
         *
         * আংশিক চালান চলবে — ১০০ কার্টনের আদেশে ৬০ আজ, ৪০ পরে।
         * ⛔ কিন্তু **৬০ + ৫০ = ১১০ চলবে না**।
         *
         * ── ⚠️ কেন এতদিন ধরা পড়েনি ──────────────────────────────────
         * এই ফাইলে তখন `releasableQty()` ছিল (৪ অক্টোবর ২০২৬ থেকে [[ownShare()]]) আর সে-ও `$alreadyDelivered`
         * যোগ করত — পড়ে মনে হত পাহারা আছে। কিন্তু সে সীমা দেয়
         * **রিজার্ভেশন ছাড়ার** উপর, ডেলিভারির উপর নয়: মাল বেরোনো
         * আটকাত না, কেবল রিজার্ভ ঋণাত্মক হতে দিত না।
         * ⓘ *যোগফলটা আছে* আর *পাহারা আছে* এক কথা নয়।
         *
         * ⭐ সাতটা draw-down সংযোগের ছয়টায় এই সীমা আগে থেকেই ছিল;
         * এটাই ছিল একমাত্র ফাঁক। ছাঁচটা [[PurchaseBillService]]-এর
         * হুবহু নকল, যাতে দুই পাশে দুই নিয়ম না দাঁড়ায়।
         *
         * ── কেন গোনায় খসড়াও থাকে, রিজার্ভ ছাড়ার মতো কেবল
         *    নিশ্চিত করাগুলো নয় ────────────────────────────────────
         * দুইটা প্রশ্ন আলাদা। *"রিজার্ভ কতটা ছাড়ব"* — কেবল যা সত্যিই
         * গেছে। *"আর কতটা পাঠানো যায়"* — যা যাওয়ার পথে, তা-ও।
         * খসড়া বাদ দিলে একই আদেশে দুইটা খসড়া চালান কেটে দুইজনে মিলে
         * আদেশ ছাড়িয়ে যেতে পারতেন, আর দুইটাই নিশ্চিত হওয়ার সময়
         * কোনোটাই একা নিয়ম ভাঙত না।
         *
         * ⚠️ যোগফলটা **এই চালানটা বাদ দিয়ে** — নাহলে একটা চালান
         * সম্পাদনা করতে গেলে সে নিজেকেই গুনত।
         */
        $alreadyDelivered = $orderLine->challanLines()
            ->where('delivery_challan_id', '<>', $challan->id)
            ->whereHas('challan', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('delivered_qty');

        $wouldBe = bcadd((string) ($alreadyDelivered ?: '0'), $qty, 4);

        // ⭐ "আর দেওয়া হবে না" অংশ বাদ — সেটুকু আর পাঠানো যায় না (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৭)
        $deliverable = bcsub((string) $orderLine->ordered_qty, (string) ($orderLine->rejected_qty ?? '0'), 4);

        if (bccomp($wouldBe, $deliverable, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.over_delivered_order', [
                    'no' => $orderLine->order->document_no,
                    'ordered' => rtrim(rtrim($deliverable, '0'), '.'),
                    'delivered' => rtrim(rtrim((string) ($alreadyDelivered ?: '0'), '0'), '.'),
                ]),
            ]);
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
    /**
     * ⛔ দুই ক্লিক, একই চালান — চূড়ান্ত অডিট ⛔৪, ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ নিশ্চিত/বাতিলের "এখনো খসড়া কি না" দেখা হত লেনদেনের বাইরে, হাতের পুরনো মডেলে। দুইটা অনুরোধ
     * একসাথে এলে দুইটাই "খসড়া" দেখত, আর মাল দুইবার বেরোত (বা বাতিলে দুইবার ফিরত)। ⭐ এখন লেনদেনের
     * ভিতরে সারিতে তালা, আর অবস্থাটা তাজা পড়া — দ্বিতীয়জন প্রথমজনের কমিটের পরের অবস্থা দেখে
     * ([[DepositClaimService::lockPending()]]-এর ছাঁচ)। ⓘ কাউকে আটকায় না — কেবল একই কাজ দ্বিতীয়বার।
     */
    private function lockAndReread(DeliveryChallan $challan): void
    {
        $fresh = DeliveryChallan::query()
            ->withoutGlobalScopes()
            ->whereKey($challan->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $challan->setRawAttributes($fresh->getAttributes(), true);
    }

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

    /**
     * খসড়া চালানের আসল নম্বর — নিশ্চিতের লেনদেনের ভিতরে (মালিক, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ একই আদেশের আগের নিশ্চিত চালান থাকলে সেই বিক্রিরই নম্বর (CHA-0154-2), নাহলে নতুন বিক্রি। খসড়ার DRF
     * নম্বরটা ইস্যুর খাতায় থেকে যায়; নতুন নম্বর এই চালানের নামে লেখা হয়।
     */
    private function giveTheSaleItsNumber(DeliveryChallan $challan): void
    {
        if ($challan->sale_no !== null) {
            return;
        }

        $saleNo = $this->saleNumberFor($challan->sales_order_id, '');
        $documentNo = app(SaleNumber::class)->forPaper(DeliveryChallan::class, $saleNo);

        $challan->update(['sale_no' => $saleNo, 'document_no' => $documentNo]);

        IssuedNumber::query()
            ->whereIn('document_no', [$saleNo, $documentNo])
            ->whereNull('source_id')
            ->update(['source_type' => DeliveryChallan::drillSourceType(), 'source_id' => $challan->id]);
    }

    /**
     * ⭐ নিশ্চিত বিক্রি সম্পাদনার প্রথম ধাপ — মালিক, ২ অক্টোবর ২০২৬ ([[SaleEditor]])।
     *
     * ⓘ বাতিলের হুবহু উল্টো ([[unpost()]]) — ভাড়ার দাখিলা, মাল (ফ্রি আর উপহারসহ) একই লটে, আদেশের ধরা — তারপর
     * চালান খসড়ায়, নম্বর অক্ষত। ⛔ একা ডাকার জন্য নয়: বিক্রি-সম্পাদকের লেনদেনের ভিতরে, যেখানে পরের ধাপ আবার
     * নিশ্চিত করে; বাইরে ডাকলে মাল-ফেরা খসড়া চালান পড়ে থাকত।
     */
    public function takeBackForEdit(DeliveryChallan $challan, Carbon $date, string $reason): void
    {
        if ($challan->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.edit_only_confirmed', ['no' => $challan->document_no]),
            ]);
        }

        $challan->loadMissing(['lines.product', 'lines.orderLine.order', 'warehouse']);

        $this->unpost($challan, $date, $reason);

        // ⓘ অফার আর ভাড়ার নতুন হিসাব কাউন্টারের হালনাগাদেই বসে ([[update()]])
        $challan->update(['status' => DocumentStatus::DRAFT]);
    }

    /**
     * ⭐ বাতিল-ইনভয়েসের পথ — গেট পাসের আগের চালান; মাল গুদামে ফেরে, চালান বাতিল (মালিক, ৪ অক্টোবর ২০২৬;
     * [[SalesInvoiceCancellationService]])। ⓘ সম্পাদনার একই উল্টানো ([[unpost()]]); উল্টো সারি বাতিল-ইনভয়েসের নম্বরে।
     * ⛔ একা ডাকার জন্য নয় — গেট পাস না হওয়ার পাহারা বাতিল-ইনভয়েসের লেনদেনে আগেই।
     */
    public function reverseForCancellation(DeliveryChallan $challan, Carbon $date, string $reason, string $paperNo, ?int $userId): void
    {
        if ($challan->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.edit_only_confirmed', ['no' => $challan->document_no]),
            ]);
        }

        $challan->loadMissing(['lines.product', 'lines.orderLine.order', 'warehouse']);

        // ⭐ অফার, উপহার, কুপন আর পয়েন্টও ফেরে — চালানের সাধারণ বাতিলের মতো (পুরো ERP অডিট, প্রমোশন ⛔১, ৬ অক্টোবর ২০২৬)
        app(\App\Modules\Sales\Services\ChallanOffers::class)->reverseAll($challan);

        $this->unpost($challan, $date, $reason, $paperNo);

        $challan->update([
            'status' => DocumentStatus::CANCELLED,
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        // ⭐ বাতিল-ইনভয়েসের পথেও (SO+DO ধাপ ৫)
        app(OrderProgress::class)->refreshOrders($this->ordersOf($challan));
    }

    /**
     * চালানটা যে আদেশ(গুলো)র — মাথার `sales_order_id` আর সারির আদেশ-সারি দুই দিক থেকেই।
     *
     * @return list<int>
     */
    private function ordersOf(DeliveryChallan $challan): array
    {
        // ⓘ মডেল দিয়ে — কোম্পানির ছাঁকনি নিজেই বসে ([[EveryRawQueryNamesItsCompanyTest]])
        $fromLines = SalesOrderLine::query()
            ->whereIn('id', $challan->lines()->whereNotNull('sales_order_line_id')->pluck('sales_order_line_id'))
            ->pluck('sales_order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $challan->sales_order_id === null ? $fromLines : [...$fromLines, (int) $challan->sales_order_id];
    }

    /**
     * নিশ্চিত চালানের সব ছাপ উল্টো — বাতিল আর সম্পাদনা, দুই পথের একই অংশ।
     *
     * @param  string|null  $paperNo  উল্টো সারির কাগজ-নম্বর — বাতিল-ইনভয়েস হলে তারটা
     */
    private function unpost(DeliveryChallan $challan, Carbon $date, string $reason, ?string $paperNo = null): void
    {
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
                documentNo: $paperNo,
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
         * ⭐ ফ্রি আর উপহারের মালও ফ্রি ভাণ্ডারে ফেরে, একই লটে — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ কাউন্টার বিক্রিতে ওগুলো বেরোয় আলাদা উৎস-নামে
         * ([[DirectSaleService::moveFreeStock()]]: `:free`, `:gift`),
         * আর উপরের উল্টানো কেবল দামের মাল ধরত। ⓘ হাতে গোনা: বিস্কুট
         * ফ্রি ১০, ১টা উপহার দিয়ে চালান বাতিল → ফ্রি ১০ হওয়ার কথা,
         * থাকত ৯ ([[TheCancelledChallanKeptTheFreeGoodsTest]])।
         *
         * ⓘ কিছু না থাকলে `reverse()` খালি ফেরে — সাধারণ চালানে কিছু বদলায় না।
         */
        foreach ([':free', ':gift'] as $kind) {
            /*
             * ⭐ নিজের মাল থেকে দেওয়া ফ্রি (সুইচ `sales.free_beyond_pool`, ৪ অক্টোবর ২০২৬; [[DirectSaleService::giveFromStock()]])
             * — মাল নিচের উল্টানোয় তাকে ফেরে (একই উৎস-নাম), খরচ এখানে: স্তরে ফেরত আর প্রচারের খরচের দাখিলা উল্টো।
             * ⓘ ফ্রি-ভাণ্ডার থেকে দেওয়া ফ্রিতে তাকের মাল নেই — তখন এখানে কিছুই হয় না।
             */
            $this->takeBackFreeFromStock($challan, DeliveryChallan::STOCK_SOURCE.$kind, $date, $reason, $paperNo);

            $this->stock->reverse(
                sourceType: DeliveryChallan::STOCK_SOURCE.$kind,
                sourceId: $challan->id,
                reversedType: DeliveryChallan::STOCK_SOURCE.$kind.':cancel',
                date: $date,
                narration: $reason,
            );
        }

        /*
         * ধরাটা আলাদা সারিতে ফেরে — লট ধরে নয়, লাইন ধরে।
         *
         * Reserved পণ্য ও গুদামের সংখ্যা, লটের নয়। আর অর্ডারটা
         * এখনো খোলা থাকলেই কেবল ফেরে; বাতিল অর্ডারে ফেরালে ধরা
         * থেকে যেত যা কেউ কোনোদিন ছাড়ত না।
         */
        /*
         * ⭐ ঠিক যতটা এই চালান ছেড়েছিল ততটাই — পণ্য ধরে, খাতা থেকে (SO+DO নকশার ধাপ ৫, ৪ অক্টোবর ২০২৬)।
         *
         * ⛔ আগে ফিরত পুরো `delivered_qty`। যে চালান কিছুই ছাড়েনি (আদেশ কিছু ধরেনি), তার বাতিলে মাল নতুন করে ধরা
         * পড়ত, আর আদেশের পাঠক ([[SalesOrderService::heldByThisOrder()]]) এমন ধরা দেখাত যা কেউ কোনোদিন বসায়নি।
         */
        /*
         * ⭐ গেট পাসের আগে বাতিল (সুইচ `sales.invoice_at_goods_issue`) — মাল কখনো বেরোয়নি, কেবল আটকেছিল; সেই আটকানোই ছাড়া।
         * ⓘ উপরের উল্টানো কেবল তাকের মাল ফেরায়, আটকানো নয় ([[StockService::reverse()]])। এই চালানের নিজের আটকানো (আদেশের
         * ছাড়ার পরের নিট) ফেরত গেলে মোট আটকানো আদেশের আগের অবস্থায় ফেরে; নিচের লুপ তখন কিছুই ধরে না (নিট ধনাত্মক)।
         */
        if ($challan->issue_at_gate && $challan->goods_issued_at === null) {
            $this->releaseTheHold($challan, $date, $reason, $paperNo);
        }

        $released = StockMovement::query()
            ->where('source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('source_id', $challan->id)
            ->where('warehouse_id', $challan->warehouse_id)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(reserved_change), 0) as moved')
            ->pluck('moved', 'product_id')
            ->map(fn ($moved) => bcmul((string) $moved, '-1', 4))
            ->all();

        foreach ($challan->lines->unique('product_id') as $line) {
            $reserve = $line->orderLine?->order?->status === DocumentStatus::CONFIRMED
                ? ($released[(int) $line->product_id] ?? '0')
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
                documentNo: $paperNo ?? $challan->document_no,
                narration: $reason,
            );
        }
    }

    /**
     * আটকানো চালানের আটকানো ছাড়া — পণ্য ধরে এখনো যতটা আটকে আছে (উৎস + তার ফেরত সারি), ততটাই ([[unpost()]]-এর অংশ)।
     */
    private function releaseTheHold(DeliveryChallan $challan, Carbon $date, string $reason, ?string $paperNo): void
    {
        $held = StockMovement::query()
            ->whereIn('source_type', [DeliveryChallan::STOCK_SOURCE, DeliveryChallan::STOCK_SOURCE.':cancel'])
            ->where('source_id', $challan->id)
            ->where('warehouse_id', $challan->warehouse_id)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(reserved_change), 0) as held')
            ->pluck('held', 'product_id');

        foreach ($held as $productId => $qty) {
            if (bccomp((string) $qty, '0', 4) <= 0) {
                continue;
            }

            $this->stock->move(
                product: Product::query()->findOrFail($productId),
                warehouse: $challan->warehouse,
                sourceType: DeliveryChallan::STOCK_SOURCE.':cancel',
                sourceId: $challan->id,
                reserved: bcmul((string) $qty, '-1', 4),
                date: $date,
                documentNo: $paperNo ?? $challan->document_no,
                narration: $reason,
            );
        }
    }

    /**
     * নিজের মাল থেকে দেওয়া ফ্রির খরচ ফেরত — স্তরে, আর প্রচারের খরচের দাখিলা উল্টো ([[unpost()]]-এর অংশ, ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ কতটা ফিরবে তা মাপা হয় **এখন বাইরে থাকা** তাকের মাল দিয়ে (উৎস + তার ফেরত সারি), তাই বারবার সম্পাদনায় দুইবার ফেরে না।
     */
    private function takeBackFreeFromStock(DeliveryChallan $challan, string $sourceType, Carbon $date, string $reason, ?string $paperNo): void
    {
        $out = StockMovement::query()
            ->whereIn('source_type', [$sourceType, $sourceType.':cancel'])
            ->where('source_id', $challan->id)
            ->selectRaw('product_id, COALESCE(SUM(floor_change), 0) as floor')
            ->groupBy('product_id')
            ->pluck('floor', 'product_id');

        foreach ($out as $productId => $floor) {
            $back = bcmul((string) $floor, '-1', 4);

            if (bccomp($back, '0', 4) <= 0) {
                continue;
            }

            $drew = \App\Modules\Inventory\Models\CostLayerUse::query()
                ->where('source_type', $sourceType)->where('source_id', $challan->id)
                ->where('product_id', $productId)->where('qty', '>', 0)->exists();

            if ($drew) {
                app(\App\Modules\Inventory\Services\CostLayerService::class)->returnToLayers(
                    product: Product::query()->findOrFail($productId),
                    qty: $back,
                    issuedSourceType: $sourceType,
                    issuedSourceId: (int) $challan->id,
                    sourceType: $sourceType.':cancel',
                    sourceId: (int) $challan->id,
                    documentNo: $paperNo ?? $challan->document_no,
                    date: $date,
                    returnedBy: [(int) $challan->id],
                );
            }
        }

        // ⓘ খোলা দাখিলা — শেষ উল্টো সারির পরে বসা ([[RevisionKeeper::openLedgerRows()]]-এর একই নিয়ম); বারবার সম্পাদনায় একটাই উল্টানো
        $lastReversal = LedgerEntry::query()->where('source_type', $sourceType.':reversal')->where('source_id', $challan->id)->max('id');
        $open = LedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $challan->id)
            ->when($lastReversal !== null, fn ($q) => $q->where('id', '>', (int) $lastReversal))
            ->exists();

        if ($open) {
            $this->posting->reverse(
                sourceType: $sourceType,
                sourceId: $challan->id,
                reversalDate: $date,
                reason: $reason,
                documentNo: $paperNo,
            );
        }
    }

    /** এই DO-র বিক্রির নম্বর — আদেশের আগের DO-র নম্বর, নাহলে নতুন ([[SaleNumber::begin()]])। */
    private function saleNumberFor(?int $orderId, string $given): string
    {
        if ($orderId !== null) {
            $earlier = DeliveryChallan::query()->withoutGlobalScopes()
                ->where('company_id', CompanyContext::id())
                ->where('sales_order_id', $orderId)
                ->whereNotNull('sale_no')
                ->orderBy('id')
                ->value('sale_no');

            if ($earlier !== null) {
                return (string) $earlier;
            }
        }

        return app(SaleNumber::class)->begin(DeliveryChallan::class, $given);
    }
}
