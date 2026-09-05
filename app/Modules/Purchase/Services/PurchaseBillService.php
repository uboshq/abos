<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use App\Modules\Purchase\Models\PurchaseReceiptLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় বিল — কী দিতে হবে।
 *
 * ── এই ফাইলের কেন্দ্রীয় সিদ্ধান্ত ────────────────────────────────────
 * বিলটা নতুন করে মজুদ বাড়ায় না। মজুদ আগেই বেড়েছে, মাল বুঝে নেওয়ার দিন।
 * বিলের কাজ শুধু দায়টা সরানো:
 *
 *     Dr  প্রাপ্ত মাল, বিল আসেনি (2160)
 *     Cr  প্রদেয় হিসাব (2110, সরবরাহকারীর নামে)
 *
 * বিলে আবার মজুদ ডেবিট করলে একই মাল দুইবার সম্পদ হয়ে বসত, আর ব্যালেন্স
 * শিট ঠিক ততটাই বেশি দেখাত।
 *
 * ── ২১৬০ থেকে যা সরে তা চালানের দাম, বিলের দাম নয় ───────────────────
 * সরবরাহকারী প্রায়ই চালানের চেয়ে অন্য দরে বিল পাঠান। মাল নেওয়ার দিন
 * ২১৬০-এ যে টাকাটা বসেছিল সেটা চালানের দর ধরে, তাই সরাতেও হবে ঠিক সেই
 * টাকাটাই — নাহলে খাতটায় একটা অবশিষ্ট পড়ে থাকত যা কোনো চালানের নয়,
 * কোনো বিলেরও নয়, আর কেউ কোনোদিন খুঁজে পেত না।
 *
 * পার্থক্যটা তাই আলাদা করে দেখা যায়, আর সেটিংস চাইলে বিলটা আটকেও দেয়।
 */
final class PurchaseBillService
{
    use BringsInLots;
    use CalculatesLineTotals;
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly SettingsService $settings,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseBill
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $supplierId = (int) $data['supplier_id'];
            $this->assertBillNoIsFree($supplierId, $data['supplier_bill_no'] ?? null);

            $documentNo = $this->numbers->next('PBL');

            $bill = PurchaseBill::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $supplierId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'supplier_bill_no' => $data['supplier_bill_no'] ?? null,

                /*
                 * ── যে গাড়িটা মাল নিয়ে এল ────────────────────────────
                 *
                 * ⓘ চারটাই ঐচ্ছিক — নিজের গাড়িতে মাল এলে ভাড়াও নেই,
                 * বাহকও নেই।
                 *
                 * ⚠️ `carrier_id` আর `carrier_name` দুইটাই রাখা হয়:
                 * নিয়মিত পরিবহনকারী একটা পক্ষ (তার খাতায় দেনা জমে),
                 * কিন্তু একবারের ভাড়া গাড়িকে পক্ষ বানালে মাস্টার
                 * তালিকা আবর্জনায় ভরে যেত — তখন নামটাই একমাত্র তথ্য।
                 *
                 * ⛔ ভাড়াটা এখানে **কেবল রাখা হয়**, এখনো ক্রয়মূল্যে
                 * ঢোকে না। ওটা আলাদা কাজ (নকশা: *"আনার খরচ ও
                 * ক্রয়মূল্য"*), আর ততদিন সংখ্যাটা কেবল কাগজে থাকে।
                 * ⓘ অর্ধেক হিসাব বসিয়ে রাখার চেয়ে ঘরটা সৎভাবে খালি
                 * থাকা ভালো — নাহলে কেউ ধরে নিতেন লাভের অঙ্কে ওটা ধরা
                 * হয়েছে।
                 */
                'carrier_id' => $data['carrier_id'] ?? null,
                'carrier_name' => $data['carrier_name'] ?? null,
                'transport_cost' => $data['transport_cost'] ?? 0,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,

                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($bill, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => PurchaseBill::drillSourceType(),
                    'source_id' => $bill->id,
                ]);

            return $bill->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(PurchaseBill $bill, array $data, array $lines): PurchaseBill
    {
        $this->assertEditable($bill);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($bill, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $bill->trx_date);

            $billNo = $data['supplier_bill_no'] ?? null;

            if ($billNo !== $bill->supplier_bill_no) {
                $this->assertBillNoIsFree($bill->supplier_id, $billNo, $bill->id);
            }

            $bill->update([
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'supplier_bill_no' => $billNo,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($bill, $lines);

            return $bill->fresh(['lines']);
        });
    }

    /**
     * বিলটা খাতায় বসানো — দায় সরবরাহকারীর নামে যায়।
     */
    public function confirm(PurchaseBill $bill): PurchaseBill
    {
        if ($bill->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $bill->document_no]),
            ]);
        }

        $bill->loadMissing('lines.receiptLine');

        if ($bill->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        // ছক বসানো থাকলে সই আগে, খতিয়ান পরে — লেনদেনের বাইরে, কারণ
        // অপেক্ষা করা মানে কিছুই না বসা, আধা-বসা নয়।
        $this->approvals->assertClear(
            document: $bill,
            module: 'purchase',
            action: 'bill',
            field: 'status',
            amount: (string) $bill->total,
            reason: $bill->narration,
        );

        return DB::transaction(function () use ($bill) {
            $this->bringInDirectLines($bill);
            $this->postToLedger($bill);
            $this->applySalesPrices($bill);

            $bill->update(['status' => DocumentStatus::CONFIRMED]);

            return $bill->fresh(['lines']);
        });
    }

    /**
     * চালান ছাড়া আসা মাল — গুদামে ঢোকে, আর দামটাও সাথে ঢোকে।
     *
     * ── কেন বিলও মাল ঢোকায় ──────────────────────────────────────────
     * মালিকের সিদ্ধান্ত: ডিপোতে অনেক সময় মাল আর বিল একসাথেই আসে, তখন
     * আলাদা করে "মাল বুঝে নেওয়া"র কাগজ বানানো বাড়তি কাজ। কিন্তু তাহলে
     * বিলকেই মালটা ঢোকাতে হবে — নইলে খতিয়ানে মজুদ বাড়ত আর গুদামে
     * কিছুই ঢুকত না, যেটা পর্দা চালিয়ে দেখতে গিয়ে ধরা পড়েছিল।
     *
     * যে লাইনের পেছনে চালান আছে সেটা এখানে বাদ — ওই মাল আগেই ঢুকেছে,
     * আর তার দামও আগেই স্তরে বসেছে। দুইবার ঢোকালে গুদামে দ্বিগুণ মাল
     * দেখাত।
     */
    private function bringInDirectLines(PurchaseBill $bill): void
    {
        $direct = $bill->lines->filter(fn (PurchaseBillLine $line) => $line->receiptLine === null);

        if ($direct->isEmpty()) {
            return;
        }

        $warehouse = $this->warehouseFor($bill);

        foreach ($direct as $line) {
            // লট ধরা পণ্যে লটটা এখানেই জন্মায় — মালের সাথে একসাথে
            $batch = $this->lotFor($line, $bill->supplier_bill_no ?: $bill->document_no);

            $this->stock->move(
                product: $line->product,
                warehouse: $warehouse,
                sourceType: PurchaseBill::STOCK_SOURCE,
                sourceId: $bill->id,

                /*
                 * ⚠️ `floor` নয়, `unplaced` — Stock Placement (৪ সেপ্টেম্বর ২০২৬)।
                 *
                 * মালিকের নিয়ম: *"স্টক প্লেসমেন্ট করার আগ পর্যন্ত কোনো
                 * বিল করা যাবে না, মানে সেল করা যাবে না।"* গাড়ি থেকে
                 * নামা আর গুদামে বুঝে নেওয়া এক ঘটনা নয়।
                 *
                 * ⓘ মালটা তাকেই আছে, আর *"গুদামে মোট কত"* প্রশ্নে গোনাও
                 * হয় ([[StockService::statesFor()]]-এর `on_hand`) — কেবল
                 * `floor`-এ নেই, তাই বিক্রয়যোগ্যও নয়।
                 */
                unplaced: (string) $line->qty,
                date: $bill->trx_date,
                documentNo: $bill->document_no,
                batch: $batch,
            );

            $this->bringInFree($bill, $line, $warehouse, $batch);

            /*
             * দর হিসাব করা হয় ছাড়ের পরে, করের আগে।
             *
             * ছাড় বাদ না দিলে মালটা যত টাকায় সত্যিই পাওয়া গেছে তার
             * চেয়ে দামি দেখাত, আর বেচার সময় মুনাফা কম দেখাত। আর কর
             * যোগ করলে উল্টোটা: ভ্যাট ফেরতযোগ্য, ওটা মালের দাম নয়।
             */
            $unitCost = bccomp((string) $line->qty, '0', 4) > 0
                ? bcdiv((string) $line->amount, (string) $line->qty, 4)
                : '0';

            $this->costs->receive(
                product: $line->product,
                qty: (string) $line->qty,
                unitCost: $unitCost,
                sourceType: PurchaseBill::STOCK_SOURCE,
                sourceId: $bill->id,
                documentNo: $bill->document_no,
                date: $bill->trx_date,
            );
        }
    }

    /**
     * বাতিলে মালটাও ফেরে — খতিয়ানের সাথে সাথেই।
     *
     * ── কী ভেঙেছিল ──────────────────────────────────────────────────
     * `confirm()` চালান-ছাড়া লাইনের মাল গুদামে ঢোকাত আর ক্রয়মূল্য
     * ব্যয়-স্তরে বসাত, কিন্তু `cancel()` কেবল খতিয়ান ফেরাত। ফলে বাতিল
     * করা বিলের মাল গুদামে থেকে যেত আর তার দামও স্তরে বসে থাকত —
     * **মজুদের খাতা আর হিসাবের খাতা আলাদা হয়ে যেত**, অথচ কোনো ভুল
     * দেখাত না।
     *
     * `PurchaseReceiptService::cancel()` দুইটাই ফেরাত, অর্থাৎ চালানের
     * পথ ঠিক ছিল আর বিলের পথ নয় — একই ঘটনার দুই আচরণ।
     *
     * ── কেন দুইবার reverse ──────────────────────────────────────────
     * বিক্রয়ের মাল আর ফ্রি মাল আলাদা উৎস-নামে বসে (`:free`), যাতে
     * "কত ফ্রি এল" প্রশ্নের উত্তর আলাদা করে দেওয়া যায়। উল্টাতেও তাই
     * দুইবার — একই কল দুইটা উৎস ধরতে পারে না।
     *
     * ── মাল বেরিয়ে গেলে কী ─────────────────────────────────────────
     * তখন `StockService` নিজেই আটকায় ("তাকে যা নেই তা বের করা যায়
     * না"), আর সেটাই ঠিক: যে মাল বেচা হয়ে গেছে তার বিল বাতিল করা যায়
     * না — আগে বিক্রয়টা ফেরাতে হবে।
     */
    private function takeBackDirectLines(PurchaseBill $bill, Carbon $date, string $reason): void
    {
        $this->costs->withdraw(PurchaseBill::STOCK_SOURCE, $bill->id);

        foreach ([PurchaseBill::STOCK_SOURCE, PurchaseBill::STOCK_SOURCE.':free'] as $source) {
            $this->stock->reverse(
                sourceType: $source,
                sourceId: $bill->id,
                reversedType: $source.':cancel',
                date: $date,
                narration: $reason,
            );
        }
    }

    /**
     * ফ্রি মাল নিজের ভাণ্ডারে ঢোকে — বিক্রয়ের মজুদে নয়।
     *
     * ── কেন ব্যয়-স্তরে কিছু যায় না ──────────────────────────────────
     * "১০ কার্টন কিনলে ১ কার্টন ফ্রি" — ওই এক কার্টন কোম্পানি কেনেনি,
     * তার কোনো ক্রয়মূল্য নেই। গড় দরে মিশিয়ে দিলে প্রতিটা বিক্রির খরচ
     * একটু করে কমে যেত, আর মুনাফা বেশি দেখাত। ভাণ্ডারটা আলাদা ঠিক এই
     * কারণেই (৮ আগস্টের মাইগ্রেশন)।
     *
     * ── কেন এটা তার নিজের উৎস-নাম নিয়ে চলে ──────────────────────────
     * `:free` আলাদা করে লেখা, যাতে বাতিলের সময় ফ্রি সারিটা চেনা যায়
     * আর "কত ফ্রি এল, কত ফ্রি গেল" প্রশ্নের উত্তর দেওয়া যায় — ওই
     * সংখ্যাটাই প্রস্তুতকারকের কাছে হিসাব দিতে লাগে।
     */
    private function bringInFree(
        PurchaseBill $bill,
        PurchaseBillLine $line,
        Warehouse $warehouse,
        ?Batch $batch = null,
    ): void {
        $free = (string) $line->free_qty;

        if (bccomp($free, '0', 4) <= 0) {
            return;
        }

        $this->stock->move(
            product: $line->product,
            warehouse: $warehouse,
            sourceType: PurchaseBill::STOCK_SOURCE.':free',
            sourceId: $bill->id,
            date: $bill->trx_date,
            documentNo: $bill->document_no,

            /* ⚠️ `free` নয়, `unplacedFree` — ফ্রি কার্টনটাও একই গাড়িতে
               এসেছে, আর কেউ ওটাও বুঝে নেয়নি। একই লরিতে দুই নিয়ম চলে না। */
            unplacedFree: $free,

            // ফ্রি কার্টনেও একই লট নম্বর ছাপা — মেয়াদোত্তীর্ণ ফ্রি
            // ওষুধ বিক্রির চেয়ে কম বিপজ্জনক নয়, আর রিকলেও ধরা পড়তে হবে
            batch: $batch,
        );
    }

    /**
     * নতুন বিক্রয়মূল্য — যে লাইনে বলা আছে, কেবল সেখানে।
     *
     * ── কেন ক্রয়ের কাগজে বিক্রয়ের দাম ───────────────────────────────
     * মালিকের কথা: "direct purchase-এর সময়েই sales price দেব।" ট্রাক
     * গেটে দাঁড়িয়ে, নতুন দরে মাল এসেছে, আর ওই দর দেখেই ঠিক হয় আজ কত
     * দামে বেচা হবে। আলাদা পর্দায় পাঠালে মাঝের সময়টুকু পুরনো দামে
     * বিক্রি চলত — নতুন দরে কেনা মাল পুরনো দরের মুনাফায়।
     *
     * null মানে "দাম বদলাব না", শূন্য মানে "বিনামূল্যে"। দুইটা এক করে
     * ফেললে দাম না বদলাতে চাওয়া প্রতিটা লাইন পণ্যটার দাম শূন্য করে দিত।
     */
    private function applySalesPrices(PurchaseBill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->sales_price === null) {
                continue;
            }

            $line->product->update(['sale_price' => $line->sales_price]);
        }
    }

    /**
     * কোন গুদামে — বিলে বলা থাকলে সেটা, নইলে প্রধান গুদাম।
     *
     * প্রধান গুদামও না থাকলে থামতে হয়। "যেকোনো একটা" বেছে নিলে মাল
     * এমন জায়গায় ঢুকত যেখানে কেউ খুঁজতে যাবে না, আর গণনার দিনে
     * পার্থক্যটা কোথা থেকে এল তার উত্তর থাকত না।
     *
     * ⓘ `public`, কারণ [[DirectPurchaseService]]-এর উপহারগুলোও ঠিক এই
     * একই গুদামে ঢোকে। নিয়মটা ওখানে আবার লিখলে একদিন একটা কপি বদলাত
     * আর অন্যটা বদলাত না — তখন বিলের মাল এক গুদামে আর তার উপহার আরেক
     * গুদামে বসত, আর কারণটা কোথাও লেখা থাকত না।
     */
    public function warehouseFor(PurchaseBill $bill): Warehouse
    {
        $warehouse = $bill->warehouse_id !== null
            ? Warehouse::query()->find($bill->warehouse_id)
            : Warehouse::query()->where('is_default', true)->active()->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('purchase::validation.bill_needs_warehouse'),
            ]);
        }

        return $warehouse;
    }

    /**
     * বাতিল — উল্টো এন্ট্রি, সারি মোছা নয় (নিয়ম ৫)।
     *
     * বাতিল হলে দায়টা সরবরাহকারীর নাম থেকে ২১৬০-এ ফিরে যায়, অর্থাৎ মালটা
     * আবার "বিল আসেনি" অবস্থায় ফেরে। সেটাই ঠিক: মাল তো ফেরত যায়নি, শুধু
     * বিলটা ভুল ছিল।
     */
    public function cancel(PurchaseBill $bill, string $reason, Carbon|string|null $onDate = null): PurchaseBill
    {
        if ($bill->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $bill->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($bill, $reason, $date) {
            if ($bill->status === DocumentStatus::CONFIRMED) {
                $this->takeBackDirectLines($bill, $date, $reason);

                $this->posting->reverse(
                    sourceType: PurchaseBill::drillSourceType(),
                    sourceId: $bill->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $bill->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $bill->fresh(['lines']);
        });
    }

    /**
     * খতিয়ানে বসানো।
     *
     * তিন রকম লাইন হতে পারে, আর তিনটাই এক পোস্টিং-এ যায়:
     *
     *   ১. ২১৬০ ডেবিট — চালানে যা বসেছিল, ঠিক ততটুকু
     *   ২. মজুদ ডেবিট — চালান ছাড়া সরাসরি বিল হলে (মাল আগে ঢোকেনি)
     *   ৩. ভ্যাট ডেবিট — সরকারের কাছ থেকে ফেরতযোগ্য অংশ
     *
     * আর ক্রেডিটে একটাই: সরবরাহকারীর প্রদেয়।
     */
    private function postToLedger(PurchaseBill $bill): void
    {
        $total = (string) $bill->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.zero_value_bill'),
            ]);
        }

        $pendingAmount = '0';   // ২১৬০ থেকে যা সরবে
        $directAmount = '0';    // চালান ছাড়া সরাসরি বিল

        foreach ($bill->lines as $line) {
            $receiptLine = $line->receiptLine;

            if ($receiptLine === null) {
                // চালান নেই মানে মালটা এই বিলেই প্রথম খাতায় এল
                $directAmount = bcadd($directAmount, (string) $line->amount, 4);

                continue;
            }

            /*
             * চালানের দর ধরে, বিলের দর ধরে নয় — ফাইলের মাথার ব্যাখ্যা।
             *
             * বিলে যতটুকু পরিমাণ, ঠিক ততটুকুর চালান-মূল্য সরে। বিলে ৪০
             * বস্তার দাম থাকলেও চালানে ছিল ৫০, তাই ৪০ বস্তার চালান-মূল্যই
             * সরবে — বাকি ১০ বস্তা ২১৬০-এ থাকবে, আর সেটাই ঠিক: ওগুলোর
             * বিল এখনো আসেনি।
             */
            $atReceiptRate = bcmul((string) $line->qty, (string) $receiptLine->rate, 4);
            $pendingAmount = bcadd($pendingAmount, $atReceiptRate, 4);
        }

        $lines = [];

        if (bccomp($pendingAmount, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id,
                'debit' => $pendingAmount,
                'party_type' => 'supplier',
                'party_id' => $bill->supplier_id,
                'narration' => __('purchase::message.bill_clears_pending', ['no' => $bill->document_no]),
            ];
        }

        if (bccomp($directAmount, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,
                'debit' => $directAmount,
                'narration' => __('purchase::message.stock_in', ['no' => $bill->document_no]),
            ];
        }

        $tax = (string) $bill->tax;

        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'debit' => $tax,
                'narration' => __('purchase::message.input_vat', ['no' => $bill->document_no]),
            ];
        }

        /*
         * ডেবিটের যোগফল আর বিলের মোট এক না-ও হতে পারে, আর সেটাই স্বাভাবিক:
         * সরবরাহকারী অন্য দরে বিল পাঠালে পার্থক্যটা কোথাও যেতে হবে।
         *
         * ওটা মূল্য-পার্থক্য, আর ওটা খরচ — মজুদে ঢোকালে গুদামের মালের দাম
         * আসল দামের চেয়ে আলাদা হয়ে যেত, অথচ মালটা একই।
         */
        $debits = array_reduce($lines, fn ($sum, $l) => bcadd($sum, $l['debit'], 4), '0');
        $difference = bcsub($total, $debits, 4);

        if (bccomp($difference, '0', 4) !== 0) {
            $this->assertDifferenceAllowed($bill, $difference);

            $variance = $this->account(StandardChart::PURCHASE_PRICE_VARIANCE);

            $lines[] = bccomp($difference, '0', 4) > 0
                ? ['account_id' => $variance->id, 'debit' => $difference,
                    'narration' => __('purchase::message.price_variance', ['no' => $bill->document_no])]
                : ['account_id' => $variance->id, 'credit' => bcmul($difference, '-1', 4),
                    'narration' => __('purchase::message.price_variance', ['no' => $bill->document_no])];
        }

        $lines[] = [
            'account_id' => $this->account(StandardChart::PAYABLE)->id,
            'credit' => $total,
            'party_type' => 'supplier',
            'party_id' => $bill->supplier_id,
            'narration' => __('purchase::message.payable_to_supplier', ['no' => $bill->document_no]),
        ];

        $this->posting->post(
            sourceType: PurchaseBill::drillSourceType(),
            sourceId: $bill->id,
            trxDate: $bill->trx_date,
            lines: $lines,
            documentNo: $bill->document_no,
            branchId: $bill->branch_id,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseBill $bill, array $lines): void
    {
        $bill->lines()->delete();

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['qty'] ?? null, 'qty');
            $rate = $this->money($line['rate'] ?? null);

            $product = Product::query()->find($productId);

            if ($productId <= 0 || $product === null) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_product')]);
            }

            // "২ বাক্স @ ৮০০" — পরিমাণ আর দর একসাথে পণ্যের এককে নামে
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            $receiptLine = $this->resolveReceiptLine($bill, $line['purchase_receipt_line_id'] ?? null, $productId, $qty);

            /*
             * চালান না থাকলে আদেশের সারির সাথে জোড়া।
             *
             * চালান থাকলে সেটাই জেতে — চালানই বেশি নির্দিষ্ট (কতটা
             * সত্যিই এসেছে সে জানে), আর দুইটা জোড়া একসাথে থাকার কোনো
             * অর্থ নেই।
             */
            $orderLine = $receiptLine === null
                ? $this->resolveOrderLine($bill, $line['purchase_order_line_id'] ?? null, $productId)
                : null;

            // ভ্যাট না পাঠালে পণ্যের নিজের হার থেকে গোনা
            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);

            /*
             * ফ্রি পরিমাণ — একই সারির একই এককে, তাই একই রূপান্তরে।
             *
             * "২ বাক্স, সাথে ১ বাক্স ফ্রি" — ফ্রিটাও বাক্সেই লেখা হয়,
             * পিসে নয়। আলাদা একক ধরলে একই সারিতে দুইটা একক থাকত।
             *
             * দর লাগে না: ফ্রি মালের ক্রয়মূল্য নেই, আর সেটাই আলাদা
             * ভাণ্ডার রাখার মূল কারণ।
             */
            $free = $this->packed(
                $product,
                $this->zeroOrMore($line['free_qty'] ?? null, 'free_qty'),
                $line['unit_id'] ?? null,
            )['qty'];

            PurchaseBillLine::create([
                'purchase_bill_id' => $bill->id,
                'product_id' => $productId,
                'purchase_receipt_line_id' => $receiptLine?->id,
                'purchase_order_line_id' => $orderLine?->id,
                'qty' => $qty,
                'free_qty' => $free,

                /*
                 * লট, মেয়াদ ও ছাপা দাম — লেখা থাকে, লট জন্মায় নিশ্চিত
                 * করার মুহূর্তে। খসড়া বিল কখনো নিশ্চিত না হলে একটা খালি
                 * লট তালিকায় বসে থাকত।
                 */
                'batch_no' => filled($line['batch_no'] ?? null) ? trim((string) $line['batch_no']) : null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'mrp' => filled($line['mrp'] ?? null) ? (string) $line['mrp'] : null,

                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,

                /*
                 * খালি ঘর আর শূন্য আলাদা রাখা হয়।
                 *
                 * '' এলে null — "দাম বদলাব না"। '0' এলে শূন্য — "আজ থেকে
                 * বিনামূল্যে"। দুইটা এক করে ফেললে যে লাইনে কেউ দাম
                 * লেখেননি সেটাও পণ্যটার দাম মুছে দিত, আর পরদিন কাউন্টারে
                 * সবকিছু শূন্য টাকায় বেরিয়ে যেত।
                 *
                 * ক্রয়দরের মতো এটাও এন্ট্রির একক থেকে নামে। না নামালে
                 * বাক্সে বিল তোলার দিন পণ্যের বিক্রয়মূল্য ১০০ গুণ হয়ে
                 * মাস্টারে বসত, আর পরদিন কাউন্টারে প্রতিটা পিস বাক্সের
                 * দামে বিক্রি হত।
                 */
                'sales_price' => ($line['sales_price'] ?? '') === '' || ($line['sales_price'] ?? null) === null
                    ? null
                    : $this->packed($product, '1', $pack['entered_unit_id'], $this->money($line['sales_price']))['rate'],

                'discount' => $figures['discount'],
                'tax' => $figures['tax'],
                'tax_variance' => $figures['tax_variance'],
                'amount' => $figures['amount'],
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $totals = $this->addToTotals($totals, $figures);
        }

        $bill->update($totals);
    }

    /**
     * চালানের লাইনটা এই সরবরাহকারীর, আর তার এখনো বিল না-হওয়া অংশ যথেষ্ট।
     */
    private function resolveReceiptLine(
        PurchaseBill $bill,
        mixed $receiptLineId,
        int $productId,
        string $qty,
    ): ?PurchaseReceiptLine {
        if (blank($receiptLineId)) {
            return null;
        }

        $receiptLine = PurchaseReceiptLine::query()
            ->with('receipt')
            ->whereKey((int) $receiptLineId)
            ->first();

        if ($receiptLine === null || $receiptLine->receipt === null) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_receipt_line')]);
        }

        // অন্য কোম্পানির চালানের id পাঠিয়ে দেওয়া আটকায় — গ্লোবাল স্কোপ
        // সন্তান-টেবিলে নেই, বাবার উপর আছে
        if ((int) $receiptLine->receipt->company_id !== (int) CompanyContext::id()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_receipt_line')]);
        }

        if ((int) $receiptLine->receipt->supplier_id !== (int) $bill->supplier_id) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.receipt_other_supplier')]);
        }

        if ($receiptLine->receipt->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.receipt_not_confirmed', [
                    'no' => $receiptLine->receipt->document_no,
                ]),
            ]);
        }

        if ((int) $receiptLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.line_product_mismatch')]);
        }

        /*
         * একই চালানের লাইন দুইবার বিল করা যায় না।
         *
         * করলে ২১৬০ খাতটা ঋণাত্মক হয়ে যেত — এমন একটা দায় যা কেউ কোনোদিন
         * বসায়নি, অথচ সরানো হয়েছে। আর সরবরাহকারীকে একই মালের দাম দুইবার
         * দেওয়া হত।
         */
        $alreadyBilled = $receiptLine->billLines()
            ->where('purchase_bill_id', '<>', $bill->id)
            ->whereHas('bill', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('qty');

        $wouldBe = bcadd((string) ($alreadyBilled ?: '0'), $qty, 4);

        if (bccomp($wouldBe, (string) $receiptLine->received_qty, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.over_billed', [
                    'no' => $receiptLine->receipt->document_no,
                    'received' => rtrim(rtrim((string) $receiptLine->received_qty, '0'), '.'),
                ]),
            ]);
        }

        return $receiptLine;
    }

    /**
     * আদেশের সারি — চালান ছাড়া সরাসরি বিল করার পথ।
     *
     * ── কেন এই পথটা আছে ─────────────────────────────────────────────
     * ছোট ডিপো মাল গ্রহণের কাগজ লেখে না; গাড়ি আসে, মাল নামে, চালান হাতে।
     * Control Panel-এ GRN-এর পর্দাটা বন্ধও করা যায় — আর তখন আদেশ থেকে
     * বিলে পৌঁছানোর কোনো পথই থাকত না, আদেশটা চিরকাল ঝুলে থাকত।
     *
     * চালানের যাচাইগুলোর সবকটাই এখানেও, একটা বাদে: "কতটা এসেছে তার
     * বেশি বিল নয়" — আদেশে মাল তো এখনো আসেইনি, তাই ওখানে মাপকাঠি
     * আদেশের পরিমাণ, প্রাপ্তির নয়।
     */
    private function resolveOrderLine(
        PurchaseBill $bill,
        mixed $orderLineId,
        int $productId,
    ): ?PurchaseOrderLine {
        if (blank($orderLineId)) {
            return null;
        }

        $orderLine = PurchaseOrderLine::query()
            ->with('order')
            ->whereKey((int) $orderLineId)
            ->first();

        if ($orderLine === null || $orderLine->order === null) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_order_line')]);
        }

        // অন্য কোম্পানির আদেশের id পাঠিয়ে দেওয়া আটকায় — গ্লোবাল স্কোপ
        // সন্তান-টেবিলে নেই, বাবার উপর আছে
        if ((int) $orderLine->order->company_id !== (int) CompanyContext::id()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_order_line')]);
        }

        if ((int) $orderLine->order->supplier_id !== (int) $bill->supplier_id) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.order_other_supplier')]);
        }

        if ($orderLine->order->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.order_not_confirmed', [
                    'no' => $orderLine->order->document_no,
                ]),
            ]);
        }

        if ((int) $orderLine->product_id !== $productId) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.line_product_mismatch')]);
        }

        return $orderLine;
    }

    /**
     * দামের পার্থক্য মেনে নেওয়া হবে কি না — সেটিংস (নিয়ম ৭)।
     */
    private function assertDifferenceAllowed(PurchaseBill $bill, string $difference): void
    {
        if (! $this->settings->get('purchase.block_price_mismatch', true)) {
            return;
        }

        throw ValidationException::withMessages([
            'lines' => __('purchase::validation.price_mismatch', [
                'no' => $bill->document_no,
                'difference' => Money::format($difference),
            ]),
        ]);
    }

    /**
     * একই সরবরাহকারীর একই বিল নম্বর দুইবার নয়।
     *
     * সরবরাহকারী ভুল করে দুইবার একই বিল পাঠালে ধরা না পড়লে একই মালের দাম
     * দুইবার শোধ হয়ে যেত, আর সেটা ধরা পড়ত অনেক পরে — যদি আদৌ পড়ত।
     */
    private function assertBillNoIsFree(int $supplierId, mixed $billNo, ?int $exceptId = null): void
    {
        $billNo = trim((string) ($billNo ?? ''));

        if ($billNo === '') {
            return;
        }

        $exists = PurchaseBill::query()
            ->where('supplier_id', $supplierId)
            ->where('supplier_bill_no', $billNo)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'supplier_bill_no' => __('purchase::validation.duplicate_bill_no', ['no' => $billNo]),
            ]);
        }
    }

    private function assertEditable(PurchaseBill $bill): void
    {
        if ($bill->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $bill->document_no]),
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
