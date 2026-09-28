<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Services\SettingsService;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchAllocator;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanGiftLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Core\Support\DocumentStatus;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * সরাসরি বিক্রয় — অর্ডার ছাড়াই মাল বেরোয়, আর তখনই বিল হয়।
 *
 * ── এটা কী, আর কী নয় ─────────────────────────────────────────────────
 * এটা নতুন কোনো ডকুমেন্ট নয়, একটা দ্রুত পথ। একটা চাপে যা তৈরি হয়:
 *
 *     ডেলিভারি চালান  — মাল বেরোল (ফ্রি ও উপহার সহ)
 *     বিক্রয় বিল       — টাকা পাওনা হলো
 *     রসিদ ভাউচার      — কাউন্টারে টাকা নিলে, প্রতিটা ডিপোজিটে একটা
 *
 * তিনটাই বিদ্যমান সেবা দিয়ে — DeliveryChallanService, SalesInvoiceService,
 * VoucherService। ⓘ ১৯ সেপ্টেম্বর ২০২৬-এর আগে টাকাটা আদায়ের কাগজ
 * (CollectionService) হত; মালিকের নিয়মে এখন রসিদ ভাউচার — [[complete()]]।
 * নিজে পোস্ট করলে একদিন এখানে বিক্রীত পণ্যের ব্যয় বসত
 * না বা স্টক নামত না, আর অমিলটা ধরা পড়ত মাস শেষে।
 *
 * ── ফ্রি ও উপহার ফ্রি ভাণ্ডার থেকে ───────────────────────────────────
 * বিক্রির পরিমাণ যায় বিক্রির মজুদ থেকে; ফ্রি পরিমাণ ও উপহার যায় ফ্রি
 * ভাণ্ডার থেকে। একই ঘর থেকে কাটলে ফ্রি মালের ক্রয়মূল্য বিক্রির খরচে মিশে
 * যেত, আর "কত ফ্রি দিলাম" প্রশ্নের উত্তর থাকত না।
 */
final class DirectSaleService
{
    use ReadsPackedQuantities;

    /**
     * কাউন্টারের জমায় যে ব্যাংক/মোবাইলের ঘরগুলো ভাউচারে যায় — এক জায়গায় লেখা।
     *
     * ⓘ রসিদ ভাউচারের money-movement পর্দার হুবহু ঘর। ⚠️ নাম আলাদা লিখলে
     * ঘরটা নীরবে হারাত ([[DirectSaleController::store()]]-এর নিয়মের মন্তব্য)।
     */
    public const BANK_DETAIL_FIELDS = [
        'transfer_mode_id', 'from_bank', 'from_branch', 'from_account_name', 'from_account_no',
        'deposit_slip_no', 'charge_amount', 'charge_borne_by', 'lands_on',
        'wallet', 'wallet_medium', 'counterparty_phone',
    ];

    public function __construct(
        private readonly DeliveryChallanService $challans,
        private readonly SalesInvoiceService $invoices,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly BatchAllocator $batches,
        private readonly ChequeService $cheques,
        private readonly ApprovalEngine $approvalEngine,
        private readonly VoucherService $vouchers,
        private readonly VoucherApproval $voucherApproval,
        private readonly CashTillService $tills,
        private readonly CreditExposure $credit,
    ) {}

    /**
     * একটা সরাসরি বিক্রি সম্পূর্ণ করা।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, extra: string, held: list<mixed>, awaiting?: list<Voucher>}
     */
    public function complete(array $data, array $lines, array $gifts = []): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);
        $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? null);

        $this->assertFreeStaysWithinTheRatio($lines, $warehouse);
        $this->assertEveryTrackedLineNamesItsLot($lines);
        $this->assertNoChequeAtTheCounter($data);

        /*
         * ⭐ কাউন্টারের ডিপোজিটে সই লাগলে — সবকিছু খসড়া, ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ── মালিকের নকশা ────────────────────────────────────────────────
         * *"Add Deposit → রসিদ ভাউচার। Invoice confirm করলে approval-এ যাবে,
         * invoice খসড়া থাকবে, কোনো print option আসবে না যতক্ষণ approve
         * হচ্ছে। Deposit approve হলে bill print হবে।"* আর প্রশ্নের উত্তরে:
         * সই না হওয়া পর্যন্ত **সবকিছু** অপেক্ষা করবে — মালও বের হবে না।
         *
         * ⓘ প্রশ্নটা কাগজ বানানোর **আগে**: [[ApprovalEngine::requires()]]।
         * ⚠️ ছক বসানো না থাকলে (বা অঙ্ক সীমার নিচে) নিচের পুরনো পথ অবিকল
         * আগের মতো — মালিকের কথায়, *"আজকের মতো: সাথে সাথে নিশ্চিত + ছাপা"*।
         */
        /*
         * ⭐ "খসড়া রাখুন" — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ কেন কোনো নতুন পথ লাগল না ─────────────────────────────
         * খসড়ার গোটা যন্ত্রটা আগে থেকেই বসানো ([[hold()]]): খসড়া চালান,
         * খসড়া বিল, আর শেষ করার দরজা ([[finishHeld()]])। ⚠️ আজ পর্যন্ত
         * ওটা চালু হত কেবল **অনুমোদনের নিয়মে** — মানুষের হাতে কোনো
         * সুইচ ছিল না।
         *
         * ⛔ দ্বিতীয় একটা খসড়ার পথ লিখলে দুইটা আলাদা আকারের অসমাপ্ত
         * বিল তৈরি হত, আর "শেষ করুন" বোতামটা কোনটায় কাজ করবে তা নিয়ে
         * প্রশ্ন উঠত। ⓘ তাই বোতামটা ঐ একই পথেই যায়।
         *
         * ⚠️ শর্তটা **আগে** দেখা হয়: হাতে খসড়া চাওয়া হলে ডিপোজিটে সই
         * লাগবে কি না সেই প্রশ্নটাই অবান্তর — দুই ক্ষেত্রেই কাগজ খসড়া
         * থাকে, আর সইয়ের অনুরোধ [[hold()]] নিজেই পাঠায়।
         */
        /*
         * ⭐ "খসড়া রাখুন" এখন আলাদা — মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬ (রাতে):
         * *"etokkhon bill kore rakhlo ta save thakbe … sudu challan inv print
         * hobe na approval e zabe na. conf. korte hole abaer ei skinei aste
         * hobe"*।
         *
         * ⓘ খসড়া কোনো সই চায় না — জমার ভাউচারও তৈরি হয় না; জমাগুলো পর্দার
         * ছবিতে থাকে ([[hold()]] `asDraft`)। ⚠️ আগে হাতের খসড়াও ডিপোজিট
         * সইয়ে পাঠাত, আর মালিক ঠিক ওটাই চাননি।
         */
        if ($this->wantsDraft($data)) {
            return $this->hold($data, $lines, $gifts, $customer, $warehouse, asDraft: true);
        }

        if ($this->counterDepositNeedsApproval($data)) {
            return $this->hold($data, $lines, $gifts, $customer, $warehouse, asDraft: false);
        }

        /*
         * ⛔ চালানের সই লাগলে বিক্রিটা হারাত — abos-10-এর ধরা, ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ চালান নিশ্চিত করার সময় সই লাগলে [[DocumentApproval::assertClear()]] অনুরোধ
         * বসিয়ে HeldForApproval ছোড়ে — কিন্তু এখানে সেটা **লেনদেনের ভিতরে**, তাই
         * ফেরত-গড়ানোয় অনুরোধ, চালান, বিল সব মুছে যেত, অথচ পর্দা বলত "অনুমোদনে
         * পাঠানো হয়েছে"। ⭐ এখন: গড়ানোর পরে বিক্রিটা সইয়ের অপেক্ষার খসড়া হয়ে বসে
         * ([[hold()]]), আর চালানের সইয়ের অনুরোধ যায় **লেনদেনের বাইরে** — টিকে থাকে।
         * শেষ সইয়ে বিক্রিটা শেষ হয় [[finishHeld()]] দিয়ে।
         */
        try {
            return $this->sellNow($data, $lines, $gifts, $customer, $warehouse);
        } catch (HeldForApproval) {
            $result = $this->hold($data, $lines, $gifts, $customer, $warehouse, asDraft: false);

            try {
                app(DocumentApproval::class)->assertClear(
                    document: $result['challan'],
                    module: 'sales',
                    action: 'challan',
                    field: 'status',
                    amount: (string) $result['challan']->total,
                    reason: $result['challan']->narration,
                );
            } catch (HeldForApproval $held) {
                $result['challan_held'] = (string) collect($held->errors())->flatten()->first();
            }

            return $result;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     * @return array<string, mixed>
     */
    private function sellNow(array $data, array $lines, array $gifts, Customer $customer, Warehouse $warehouse): array
    {
        return DB::transaction(function () use ($data, $lines, $gifts, $customer, $warehouse) {
            /*
             * ⛔ গ্রাহকের সারিতে তালা — লেনদেনের **প্রথম** কাজ, ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ InnoDB লেনদেনের প্রথম সাধারণ পড়ায় ছবি তোলে; তালার আগে কিছু পড়া
             * হলে ভিতরের [[CreditExposure::assertRoomLocked()]] পুরনো বকেয়া দেখত,
             * আর দুই কাউন্টার একসাথে সীমা পার করত।
             */
            $this->credit->lockCustomer($customer);

            $trxDate = $data['trx_date'] ?? now()->toDateString();

            // ⓘ রাখা খসড়া থেকে এলে সেই কাগজ দুইটাই — নম্বর বদলায় না
            $parked = $this->parkedFor($data, $customer);
            $this->assertNoOtherOpenDraft($customer, $parked);

            $challan = $this->challanFor($parked, $data, $lines, $customer, $warehouse, $trxDate);

            /*
             * ফ্রি পরিমাণ ও কাগজের নিচের ঘরগুলো চালানে বসানো।
             *
             * DeliveryChallanService নিজে এগুলো জানে না — ইচ্ছাকৃত। ওই
             * সেবাটা সাধারণ চালানের, আর সাধারণ চালানে ফ্রি বা খরচের ঘর
             * নেই। এখানে বসালে ওই সেবাটাকে সরাসরি বিক্রয়ের কথা জানতে
             * হয় না।
             */
            $this->stampExtras($challan, $data, $lines);
            $this->writeGifts($challan, $gifts, $warehouse);

            /*
             * ⛔ বাকির সীমা চালানেই — আর গোনা টাকাসহ, ২৬ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ চালান এখানে বিলের **আগে** নিশ্চিত হয়। ⚠️ টাকাটা না পাঠালে
             * দেয়াল পুরো চালানকে বাকি ধরত, আর নগদে পুরো দাম দেওয়া গ্রাহকও
             * আটকে যেতেন — ঠিক ৭ সেপ্টেম্বরের ভুলটা, এবার চালানের দরজায়।
             */
            $deposit = $this->depositTotal($data);

            $invoiceHeader = [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                'due_on' => $this->dueOn($data, $customer),
                // হাতে লেখা নম্বর — খালি হলে সিরিজ নিজেই দেবে
                'document_no' => trim((string) ($data['invoice_no'] ?? '')) ?: null,
                'narration' => $data['narration'] ?? null,
                ...$this->billFigures($data),
            ];

            /*
             * ⛔ রাখা খসড়ার বিলটা চালান নিশ্চিত হওয়ার **আগে** নতুন সারিতে বাঁধা।
             *
             * ⚠️ চালানের সারি হালনাগাদে পুরনো সারি মুছে যায়, আর খসড়া বিলের
             * সারিগুলো তখন কোনো চালানে বাঁধা থাকে না। ⓘ চালানের দেয়াল
             * ([[CreditExposure::draftInvoices()]]) "এই চালানের খসড়া বিল" চেনে
             * সারির বাঁধন দিয়ে — বাঁধন না থাকলে ঐ খসড়া বিলটা **আলাদা ধার**
             * হিসেবে গোনা হত, আর একই টাকা দুইবার: সীমার ভিতরের বিলও ফিরে আসত।
             */
            if ($parked !== null) {
                $parked = $this->invoices->updateForHeldCounterSale(
                    $parked,
                    $invoiceHeader,
                    $this->invoiceLines($challan->fresh(['lines'])),
                    (int) $challan->id,
                );
            }

            $challan = $this->challans->confirm($challan->fresh(['lines']), $deposit);

            // ফ্রি ও উপহার — চালান নিশ্চিত হওয়ার পর, ফ্রি ভাণ্ডার থেকে
            $this->moveFreeStock($challan->fresh(['lines.product', 'giftLines.product']), $warehouse);

            /*
             * ⓘ রাখা খসড়া পাকা হলে বিলটা **সেই একই কাগজ** — নতুন সারি বসে,
             * পর্দার ছবিটা মুছে যায়। ⚠️ ছবি না মুছলে পাকা বিলটা "পেন্ডিং"
             * তালিকায় থেকে যেত, আর আবার খোলা যেত।
             */
            if ($parked !== null) {
                // ⓘ সারি উপরে বসে গেছে; নিশ্চিত চালান থেকে আবার লেখা — দাম-পরিমাণ হুবহু, কেবল নিশ্চিত অবস্থায়
                $invoice = $this->invoices->update($parked, $invoiceHeader, $this->invoiceLines($challan));
                $invoice->update(['counter_draft' => null]);
            } else {
                $invoice = $this->invoices->create($invoiceHeader, $this->invoiceLines($challan));
            }

            /*
             * ⚠️ গোনা টাকাটা **নিশ্চিত করার আগে** জানা দরকার, পরে নয়।
             *
             * ⓘ ৭ সেপ্টেম্বরের সারাই: আগে ধারের সীমার যাচাই বিলের **পুরো**
             * অঙ্ক দেখত — যেন পুরোটাই বাকি — অথচ ক্রেতা তখন কাউন্টারে টাকা
             * গুনে দাঁড়িয়ে।
             */
            $invoice = $this->invoices->confirm($invoice, $deposit);

            /*
             * ⭐ প্রতিটা ডিপোজিট একটা রসিদ ভাউচার, পুরো টাকায় — ১৯ সেপ্টেম্বর ২০২৬।
             *
             * ── মালিকের সিদ্ধান্ত ─────────────────────────────────────────────
             * *"কাউন্টারের সব ডিপোজিট সবসময় রসিদ ভাউচার… অগ্রিম আলাদাভাবে
             * থাকবে না, এতে সমন্বয়ের ঝামেলা থাকে। যা টাকা জমা বা উত্তোলন হয়
             * তা ব্যাংক লেজারের মতো Dr Cr হবে — জমা উত্তোলন, দেনা পাওনা।"*
             * আর: *"অগ্রিম শুধু অফিস ইউজ অনলি।"*
             *
             * ⓘ তাই বিলের চেয়ে বেশি দিলে বাড়তিটা কোথাও "অগ্রিম" হয়ে আলাদা
             * বসে না — গ্রাহকের খাতায় ক্রেডিট হয়ে থাকে, পরের বিলে নিজেই কাটে।
             * বিলের বকেয়া শূন্যের নিচে নামে না ([[SalesInvoice::dueAmount()]])।
             *
             * ⛔ আগে কী ভাঙা ছিল: বাড়তিটা বার্তায় "ফেরত" বলে দেখাত, অথচ
             * খাতায় পুরোটাই বসত — ক্যাশিয়ার ফেরত দিলে টাকা দুইবার গোনা হত।
             *
             * ⓘ এক রকমের কাগজ, তাই ভাউচার তালিকার "Sales Added Deposit"
             * ট্যাবে সব কাউন্টার-ডিপোজিট — সই লাগুক বা না লাগুক।
             */
            foreach ($this->depositRows($data) as $row) {
                $this->postCounterVoucher(
                    $this->counterVoucher($row, $customer, $invoice, $challan, $trxDate),
                );
            }

            $total = (string) $invoice->total;

            return [
                'challan' => $challan->fresh(['lines', 'giftLines']),
                'invoice' => $invoice->fresh(['lines']),

                // ⓘ বিলের চেয়ে যা বেশি জমা পড়ল — গ্রাহকের খাতায় রইল, ফেরত নয়
                'extra' => bccomp($deposit, $total, 4) > 0 ? bcsub($deposit, $total, 4) : '0.0000',

                // ⓘ পুরনো চাবি — আদায়ের কাগজ আর নেই, তাই আটকানোও নেই; সই [[hold()]]-এ
                'held' => [],
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    /**
     * কাউন্টারের কোনো ডিপোজিটে সই লাগবে কি?
     *
     * ⓘ প্রতিটা সারি আলাদা করে — ছকের সীমা একেকটা ভাউচারের অঙ্কে খাটে,
     * মোট অঙ্কে নয়। ⚠️ মোট ধরলে দুইটা ছোট ডিপোজিট মিলে সীমা পেরোত,
     * অথচ কোনো ভাউচারই সই চাইত না, আর বিক্রয় অকারণে আটকে থাকত।
     */
    /**
     * বিক্রেতা নিজে খসড়া চেয়েছেন কি না।
     *
     * ⓘ ঘরটা একটা লুকানো ইনপুট, আর "খসড়া রাখুন" বোতামটা চাপার
     * মুহূর্তে ওটা ভরে যায় — দুইটা বোতাম, একটাই ফর্ম।
     *
     * ⚠️ `'1'` ছাড়া আর কোনো মানকে হ্যাঁ ধরা হয় না। ⛔ `! empty()`
     * লিখলে `'0'`-ও খসড়া বোঝাত, আর তখন "নিশ্চিত করুন" চাপলেও বিলটা
     * খসড়া থেকে যেত — আর সেটা দেখতে হুবহু সফল সংরক্ষণের মতো।
     */
    private function wantsDraft(array $data): bool
    {
        return ($data['save_as_draft'] ?? '') === '1';
    }

    private function counterDepositNeedsApproval(array $data): bool
    {
        foreach ($this->depositRows($data) as $row) {
            /*
             * ⛔ নিজের বাক্সে নগদ সই চায় না — মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬:
             * *"cash e sudu tar nijer cash accounts e taka nite parbe tai app er
             * dorkar nai"* ([[VoucherApproval::stopping()]] `landsInCash`)।
             *
             * ⚠️ এই প্রশ্নটা ছক দেখত, টাকা কোথায় নামছে দেখত না — অথচ ভাউচারের
             * পাহারা নগদকে ছেড়ে দেয়। ⛔ ফল ছিল একটা মরা খসড়া: বিক্রয় আটকে
             * থাকত, অথচ সইয়ের কোনো অনুরোধই যেত না, আর "শেষ করুন" সই ছাড়াই
             * পার হত। ⓘ তাই দুই প্রশ্ন একই খাত দেখে ([[moneyAccountOf()]])।
             */
            if ($this->landsInCash($row)) {
                continue;
            }

            if ($this->approvalEngine->requires(
                VoucherApproval::MODULE,
                VoucherApproval::COUNTER_DEPOSIT,
                (string) $row['amount'],
                class_basename(Voucher::class),
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * এই জমার টাকা কি নগদ খাতে নামবে — [[VoucherApproval::stopping()]]-এর
     * `landsInCash`-এর আগাম উত্তর, একই খাত ধরে ([[moneyAccountOf()]])।
     *
     * @param  array<string, mixed>  $row
     */
    private function landsInCash(array $row): bool
    {
        if (($row['kind'] ?? null) === 'cheque') {
            return false;
        }

        return (bool) Account::query()->find($this->moneyAccountOf($row))?->isCash();
    }

    /**
     * জমার টাকা কোন খাতে নামে — এক জায়গায়।
     *
     * ⓘ চেক হলে ১১০৪ হাতে-চেক খাত, নাহলে সারির খাত, আর খাত না বললে প্রধান
     * টিলের নগদ। ⚠️ ভাউচার বানানো ([[counterVoucher()]]) আর সইয়ের আগাম প্রশ্ন
     * ([[counterDepositNeedsApproval()]]) দুইটাই এটা পড়ে — ⛔ দুই জায়গায় আলাদা
     * লিখলে একদিন প্রশ্নটা এক খাত দেখত আর ভাউচার আরেক খাতে নামত।
     *
     * @param  array<string, mixed>  $row
     */
    private function moneyAccountOf(array $row): int
    {
        return ($row['kind'] ?? null) === 'cheque'
            ? (int) $this->chequesInHandAccount()->id
            : (int) (($row['account_id'] ?? null) ?: $this->tills->ensurePrimaryTill()->account_id);
    }

    /**
     * সই লাগবে, বা বিক্রেতা নিজে খসড়া রাখছেন — চালান আর বিল দুইটাই খসড়া।
     *
     * ── ⚠️ কী হয় আর কী হয় না ─────────────────────────────────────────
     * ✓ চালান ও তার সারি, উপহার, বিল — সব লেখা থাকে, খসড়া অবস্থায়।
     * ✗ মাল বের হয় না, ফ্রি মাল নড়ে না, খাতায় কিছু বসে না, ছাপা হয় না।
     *
     * ── ⭐ দুইটা মুখ ────────────────────────────────────────────────────
     * ⓵ `asDraft` — "খসড়া রাখুন" (মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬)।
     *   ⛔ কোনো সই চাওয়া হয় না, কোনো ভাউচার তৈরি হয় না। পর্দাটা হুবহু
     *   বিলে থাকে (`counter_draft`), আর পাকা হয় **একই পর্দায়** ফিরে এসে
     *   ([[complete()]] `resume_invoice_id`)।
     * ⓶ সই লাগবে — প্রতিটা ডিপোজিট একটা **রসিদ ভাউচার** (খসড়া), বিলের
     *   সাথে বাঁধা, আর অনুমোদনের অনুরোধ। বাকিটা [[finishHeld()]] করে।
     *
     * ⓘ রাখা খসড়া থেকে এলে ([[parkedFor()]]) একই চালান ও বিল হালনাগাদ হয়
     * — নম্বর বদলায় না।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, extra: string, held: list<mixed>, awaiting: list<Voucher>, parked: bool}
     */
    private function hold(array $data, array $lines, array $gifts, Customer $customer, Warehouse $warehouse, bool $asDraft): array
    {
        return DB::transaction(function () use ($data, $lines, $gifts, $customer, $warehouse, $asDraft) {
            $trxDate = $data['trx_date'] ?? now()->toDateString();

            $parked = $this->parkedFor($data, $customer);
            $this->assertNoOtherOpenDraft($customer, $parked);

            $challan = $this->challanFor($parked, $data, $lines, $customer, $warehouse, $trxDate);

            $this->stampExtras($challan, $data, $lines);
            $this->writeGifts($challan, $gifts, $warehouse);

            $invoiceHeader = [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => $trxDate,
                'due_on' => $this->dueOn($data, $customer),
                'document_no' => trim((string) ($data['invoice_no'] ?? '')) ?: null,
                'narration' => $data['narration'] ?? null,
                ...$this->billFigures($data),
            ];

            // ⓘ খসড়া চালানের বিল — কেবল এই চালানের জন্য ছাড় ([[SalesInvoiceService::createForHeldCounterSale()]])
            $invoice = $parked === null
                ? $this->invoices->createForHeldCounterSale(
                    $invoiceHeader,
                    $this->invoiceLines($challan->fresh(['lines'])),
                    (int) $challan->id,
                )
                : $this->invoices->updateForHeldCounterSale(
                    $parked,
                    $invoiceHeader,
                    $this->invoiceLines($challan->fresh(['lines'])),
                    (int) $challan->id,
                );

            /*
             * ⓘ খসড়ায় সীমার দেয়াল নেই — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর
             * ২০২৬ (রাতে): *"bill atkanor kotha cilo conf/নিশ্চিত করুন e
             * kintu খসড়া hobe"*।
             *
             * ⚠️ একই দিন সকালে উল্টোটা লেখা ছিল ("সীমার বাইরে খসড়াও না")।
             * ⭐ এখনকার নিয়ম: খসড়া রাখা যায়, আর রাখা খসড়া **সীমা আটকে
             * রাখে** ([[CreditExposure::pending()]] খসড়া বিল গোনে)। ⛔ দেয়াল
             * পাকা করার মুহূর্তে — চালান নিশ্চিত হয় [[DeliveryChallanService::confirm()]]-এ।
             */
            if ($asDraft) {
                $invoice->update(['counter_draft' => $this->screenOf($data)]);

                return [
                    'challan' => $challan->fresh(['lines', 'giftLines']),
                    'invoice' => $invoice->fresh(['lines']),
                    'extra' => '0.0000',
                    'held' => [],
                    'awaiting' => [],
                    'parked' => true,
                ];
            }

            // ⓘ সইয়ের পথে গেলে আর "পেন্ডিং" নয় — শেষ হবে বিলের পাতার বোতামে
            if ($parked !== null) {
                $invoice->update(['counter_draft' => null]);
            }

            $awaiting = [];

            foreach ($this->depositRows($data) as $row) {
                $voucher = $this->counterVoucher($row, $customer, $invoice, $challan, $trxDate);

                // ⓘ অনুরোধটা এখানেই — সীমার নিচের ভাউচার কিছুই চায় না
                $this->voucherApproval->stopping($voucher);

                $awaiting[] = $voucher;
            }

            return [
                'challan' => $challan->fresh(['lines', 'giftLines']),
                'invoice' => $invoice->fresh(['lines']),
                'extra' => '0.0000',
                'held' => [],
                'awaiting' => $awaiting,
                'parked' => false,
            ];
        });
    }

    /**
     * ⭐ রাখা খসড়াটা — পর্দা যদি সেটা থেকে এসে থাকে।
     *
     * ⛔ চারটা পাহারা, আর প্রতিটা আলাদা ভুল ঠেকায়:
     * ⓵ কোম্পানি ও শাখা — মডেলের নিজের স্কোপ; অন্যের খসড়া "নেই"।
     * ⓶ এখনো খসড়া — দুইবার চাপলে দ্বিতীয়টা পাকা বিল আবার লিখতে পারত না।
     * ⓷ কাউন্টারের রাখা খসড়া (`counter_draft` আছে) — অন্য পথের খসড়া
     *   (সইয়ের অপেক্ষা, সাধারণ বিল) এখান দিয়ে বদলানো যায় না।
     * ⓸ একই ক্রেতা — ⚠️ পর্দায় ক্রেতা বদলে ফেললে অন্যের নামের কাগজে
     *   অন্যের মাল বসত।
     *
     * ⓘ `lockForUpdate` — লেনদেনের ভিতরে ডাকা হয়, তাই দুইটা কাউন্টার একই
     * খসড়া একসাথে পাকা করতে পারে না।
     *
     * @param  array<string, mixed>  $data
     */
    private function parkedFor(array $data, Customer $customer): ?SalesInvoice
    {
        $id = (int) ($data['resume_invoice_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $invoice = SalesInvoice::query()->lockForUpdate()->find($id);

        if ($invoice === null || $invoice->status !== 'draft' || $invoice->counter_draft === null) {
            throw ValidationException::withMessages([
                'resume_invoice_id' => __('sales::validation.parked_draft_gone'),
            ]);
        }

        if ((int) $invoice->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages([
                'customer_id' => __('sales::validation.parked_draft_other_customer', ['no' => $invoice->document_no]),
            ]);
        }

        return $invoice;
    }

    /**
     * ⛔ একজন ক্রেতার একটাই খোলা খসড়া — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬:
     * *"ei khosora bill conf na hole r ekta bill entry nibe na tar name age
     * bill conf korbe noy batil korbe noy edite korbe"*।
     *
     * ⓘ খসড়াটাই খোলা থাকলে (পেন্ডিং থেকে এসেছে) সেটা বাদ — ওটাকেই তো
     * পাকা বা আবার রাখা হচ্ছে। ⚠️ `acrossBranches()`: অন্য শাখার কাউন্টারে
     * রাখা খসড়াও গোনে — ⛔ নাহলে এক শাখায় খসড়া রেখে আরেক শাখায় একই
     * ক্রেতার নতুন বিল হয়ে যেত, আর নিয়মটা কেবল একটা কাউন্টারের হত।
     */
    /**
     * কাউন্টারের খোলা খসড়া — এক নিয়ম, সব জায়গায়।
     *
     * ⭐ মালিকের অভিযোগ, ২৮ সেপ্টেম্বর ২০২৬: লাইভে খসড়া ড্রপডাউনে আসে না, তালিকায়
     * দেখায় না, চালান-ইনভয়েসের তালিকায় বসে থাকে। ⛔ কারণ: নিয়মটা কেবল
     * `counter_draft` দেখত, আর লাইভের চারটা খসড়ার একটাতেও ওটা নেই — কয়েকটা ঐ
     * ঘরের আগের, বাকিগুলো সইয়ের অপেক্ষায় থাকা বিক্রি।
     *
     * ⓘ তাই চিহ্ন নয়, অবস্থা দেখা হয়: বিলটা খসড়া **আর** তার চালানও খসড়া। ⚠️
     * সাধারণ পথে বিল হয় পাকা চালান থেকে, তাই খসড়া-চালানের খসড়া-বিল কেবল কাউন্টারেরই।
     *
     * @param  \Illuminate\Database\Eloquent\Builder<SalesInvoice>|null  $query
     * @return \Illuminate\Database\Eloquent\Builder<SalesInvoice>
     */
    public static function openCounterDrafts($query = null)
    {
        return ($query ?? SalesInvoice::query())
            ->where('sal_invoices.status', DocumentStatus::DRAFT)
            ->whereHas('lines.challanLine.challan', fn ($c) => $c->where('status', DocumentStatus::DRAFT));
    }

    /**
     * খোলা **আর সক্রিয়** খসড়া — নিষ্ক্রিয়গুলো বাদ; Pending ড্রপডাউন আর "একটাই খসড়া"
     * নিয়মের জন্য ([[pauseDraft()]])।
     *
     * @param  \Illuminate\Database\Eloquent\Builder<SalesInvoice>|null  $query
     * @return \Illuminate\Database\Eloquent\Builder<SalesInvoice>
     */
    /**
     * সইয়ের অপেক্ষায় কি না — এক কোয়েরিতে, সারি ধরে ([[isHeldForSignature()]]-এর SQL রূপ)।
     *
     * ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: নিশ্চিত করে সইয়ে পাঠানো বিক্রি খসড়া
     * নয় (*"etato maratok vul"*)। ⓘ তিন জায়গায় সই চাওয়া হতে পারে — চালান, বিল,
     * আর বিলের বিপরীতে কাউন্টারের জমা।
     *
     * @param  \Illuminate\Database\Eloquent\Builder<SalesInvoice>  $query
     * @return \Illuminate\Database\Eloquent\Builder<SalesInvoice>
     */
    public static function whereHeld($query, bool $held = true)
    {
        $exists = fn ($q) => $q->selectRaw('1')->from('approvals as ap')
            ->where('ap.status', \App\Models\Approval::PENDING)
            ->where(fn ($w) => $w
                ->where(fn ($c) => $c->where('ap.approvable_type', DeliveryChallan::class)
                    ->whereIn('ap.approvable_id', fn ($s) => $s->select('cl.delivery_challan_id')
                        ->from('sal_invoice_lines as il')
                        ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                        ->whereColumn('il.sales_invoice_id', 'sal_invoices.id')))
                ->orWhere(fn ($c) => $c->where('ap.approvable_type', SalesInvoice::class)
                    ->whereColumn('ap.approvable_id', 'sal_invoices.id'))
                ->orWhere(fn ($c) => $c->where('ap.approvable_type', \App\Modules\Accounts\Models\Voucher::class)
                    ->whereIn('ap.approvable_id', fn ($s) => $s->select('v.id')->from('vouchers as v')
                        ->where('v.against_type', SalesInvoice::drillSourceType())
                        ->whereColumn('v.against_id', 'sal_invoices.id'))));

        return $held ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }

    /**
     * সত্যিকারের খসড়া — কাউন্টারে রাখা, সইয়ে যায়নি। ⓘ তালিকার "খসড়া" ট্যাব ও Pending-এর
     * "খসড়া" ভাগ।
     *
     * @return \Illuminate\Database\Eloquent\Builder<SalesInvoice>
     */
    public static function trueDrafts($query = null)
    {
        return self::whereHeld(self::openCounterDrafts($query), false);
    }

    /** নিশ্চিত করে সইয়ে পাঠানো — "অনুমোদনের অপেক্ষায়" ট্যাব ও Pending-এর ভাগ। */
    public static function awaitingApproval($query = null)
    {
        return self::whereHeld(self::openCounterDrafts($query), true);
    }

    public static function activeCounterDrafts($query = null)
    {
        return self::openCounterDrafts($query)->whereNull('sal_invoices.draft_paused_at');
    }

    /** এই খসড়া কি সইয়ের অপেক্ষায় — চালান, বিল, বা তার বিপরীতে কাউন্টারের জমা। */
    public static function isHeldForSignature(SalesInvoice $invoice): bool
    {
        $invoice->loadMissing('lines.challanLine');
        $challanId = (int) ($invoice->lines->first()?->challanLine?->delivery_challan_id ?? 0);
        $voucherIds = \App\Modules\Accounts\Models\Voucher::query()
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->id)
            ->pluck('id')->all();

        return \App\Models\Approval::query()
            ->where('status', \App\Models\Approval::PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challanId))
                ->orWhere(fn ($w) => $w->where('approvable_type', SalesInvoice::class)->where('approvable_id', $invoice->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', \App\Modules\Accounts\Models\Voucher::class)
                    ->whereIn('approvable_id', $voucherIds)))
            ->exists();
    }

    /**
     * খসড়া নিষ্ক্রিয় — তালিকায় থাকে, কিন্তু সীমা ধরে রাখে না আর নতুন বিল আটকায় না।
     * ⛔ সইয়ের অপেক্ষায় থাকা খসড়া নয় — ওটা অনুমোদনের পাতায় শেষ হয়।
     */
    public function pauseDraft(SalesInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $draft = self::openCounterDrafts()->lockForUpdate()->find($invoice->getKey());

            if ($draft === null || self::isHeldForSignature($draft)) {
                throw ValidationException::withMessages(['draft' => __('sales::validation.draft_not_settable')]);
            }

            $draft->forceFill(['draft_paused_at' => now()])->save();
        });
    }

    /**
     * খসড়া আবার সক্রিয় — ⛔ দুই পাহারা আবার: ক্রেতার আর কোনো সক্রিয় খসড়া নয়, আর
     * বাকির সীমায় জায়গা ([[CreditExposure::assertRoom()]])।
     */
    public function resumeDraft(SalesInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $draft = self::openCounterDrafts()->lockForUpdate()->find($invoice->getKey());

            if ($draft === null || $draft->draft_paused_at === null) {
                throw ValidationException::withMessages(['draft' => __('sales::validation.draft_not_settable')]);
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($draft->customer_id);

            $this->assertNoOtherOpenDraft($customer, $draft);
            $this->credit->assertRoom($customer, (string) $draft->total, '0', (int) $draft->id);

            $draft->forceFill(['draft_paused_at' => null])->save();
        });
    }

    private function assertNoOtherOpenDraft(Customer $customer, ?SalesInvoice $parked): void
    {
        $open = self::activeCounterDrafts(SalesInvoice::acrossBranches())
            ->where('customer_id', $customer->id)
            ->when($parked !== null, fn ($q) => $q->whereKeyNot($parked->id))
            ->orderBy('id')
            ->first(['id', 'document_no']);

        if ($open !== null) {
            throw ValidationException::withMessages([
                'customer_id' => __('sales::validation.open_draft_blocks_new_bill', ['no' => $open->document_no]),
            ]);
        }
    }

    /**
     * ⭐ রাখা খসড়া বাতিল — মালিকের তিনটা পথের একটা ("conf … batil … edit")।
     *
     * ⓘ ক্রম: আগে বিল, তারপর চালান। ⚠️ উল্টো করলে চালানের পাহারা
     * ([[DeliveryChallanService::assertNotInvoiced()]]) খসড়া বিলটাকে "বিল হয়ে
     * গেছে" ধরে থামাত। খসড়ায় মাল বা খাতার কিছু নড়েনি, তাই বাতিলে ফেরানোরও
     * কিছু নেই — কাগজ দুইটা কেবল "বাতিল" হয়, মোছা নয়, কারণসহ।
     */
    public function discardParked(SalesInvoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($invoice, $reason) {
            $invoice = SalesInvoice::query()->lockForUpdate()->find($invoice->getKey());

            /* ⓘ কাউন্টারের যেকোনো খোলা খসড়া — চিহ্ন থাকুক বা না থাকুক (তালিকার "মুছুন");
               ⛔ সইয়ের অপেক্ষায় থাকলে নয় — ওটা অনুমোদনের পাতায় প্রত্যাহার হয় */
            $open = $invoice !== null && $invoice->status === 'draft'
                && self::openCounterDrafts()->whereKey($invoice->id)->exists();

            if (! $open || self::isHeldForSignature($invoice)) {
                throw ValidationException::withMessages([
                    'resume_invoice_id' => __('sales::validation.parked_draft_gone'),
                ]);
            }

            $invoice->loadMissing('lines.challanLine');
            $challanId = $invoice->lines->first()?->challanLine?->delivery_challan_id;

            $invoice->update(['counter_draft' => null]);
            $this->invoices->cancel($invoice, $reason);

            $challan = $challanId === null ? null : DeliveryChallan::query()->find($challanId);

            if ($challan !== null && $challan->status === DocumentStatus::DRAFT) {
                $this->challans->cancel($challan, $reason);
            }
        });
    }

    /**
     * চালানটা — নতুন, নাহলে রাখা খসড়ার চালান হালনাগাদ।
     *
     * ⚠️ রাখা খসড়ার উপহারগুলো আগে মোছা হয় — [[writeGifts()]] কেবল যোগ করে,
     * ⛔ না মুছলে প্রতিবার খুলে রাখলে উপহার দ্বিগুণ হত।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    private function challanFor(?SalesInvoice $parked, array $data, array $lines, Customer $customer, Warehouse $warehouse, string $trxDate): DeliveryChallan
    {
        $header = [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'trx_date' => $trxDate,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'narration' => $data['narration'] ?? null,
        ];

        if ($parked === null) {
            // ⓘ হাতে লেখা চালান নম্বর — খালি হলে সিরিজ ([[DeliveryChallanService::challanNumber()]])
            $header['document_no'] = trim((string) ($data['challan_no'] ?? '')) ?: null;
            // ⭐ কাউন্টারের নিজের নম্বর-সারি (DS) — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬
            $header['series'] = 'DS';

            return $this->challans->create($header, $this->challanLines($lines));
        }

        $parked->loadMissing('lines.challanLine');
        $challanId = $parked->lines->first()?->challanLine?->delivery_challan_id;

        $challan = $challanId === null ? null : DeliveryChallan::query()->lockForUpdate()->find($challanId);

        if ($challan === null || $challan->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'resume_invoice_id' => __('sales::validation.parked_draft_gone'),
            ]);
        }

        $challan->giftLines()->delete();

        return $this->challans->update($challan, $header, $this->challanLines($lines));
    }

    /**
     * পর্দার ছবি — যা বিলে রাখা হয়।
     *
     * ⓘ দুই ভাগ: `screen` কাউন্টারের নিজের অবস্থা (সারি, জমা, ছাড়…), আর
     * `fields` সাধারণ ঘরগুলো (DO, গাড়ি, মন্তব্য…) — যেগুলো Alpine-এর
     * অবস্থায় থাকে না। ⚠️ কেবল সরল মান রাখা হয়; সারি-জমা-উপহারের তালিকা
     * `screen`-এই আছে, দুইবার রাখলে কোনটা সত্যি সেই প্রশ্ন উঠত।
     *
     * @param  array<string, mixed>  $data
     * @return array{screen: array<mixed>, fields: array<string, scalar|null>}
     */
    private function screenOf(array $data): array
    {
        $screen = json_decode((string) ($data['screen_state'] ?? ''), true);

        $fields = array_filter(
            Arr::except($data, ['screen_state', 'resume_invoice_id', 'save_as_draft', 'lines', 'gifts', 'deposits']),
            fn ($value) => $value === null || is_scalar($value),
        );

        return [
            'screen' => is_array($screen) ? $screen : [],
            'fields' => $fields,
        ];
    }

    /**
     * সই হয়ে গেছে — এবার বিক্রয়টা শেষ করা।
     *
     * ── ⭐ কেন একটা আলাদা ধাপ, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────
     * অনুমোদন কেবল "হ্যাঁ" বলে, কাগজ এগোয় না ([[DocumentApproval::stopping()]]
     * -এর মন্তব্য)। ⓘ তাই বিলের পাতায় একটা বোতাম এটা ডাকে: চালান নিশ্চিত
     * (মাল বের হয়), ফ্রি মাল নড়ে, বিল নিশ্চিত, আর ভাউচারগুলো খাতায়।
     *
     * ⚠️ একটাও ভাউচার সইয়ের অপেক্ষায় বা প্রত্যাখ্যাত থাকলে কিছুই হয় না —
     * অর্ধেক বিক্রয় চেয়ে পুরো অপেক্ষা ভালো। ⛔ আর সবটা একটাই লেনদেনে:
     * মাল বের হলো অথচ বিল খসড়া — এমন অবস্থা কোনো মুহূর্তেও থাকে না।
     */
    public function finishHeld(SalesInvoice $invoice): SalesInvoice
    {
        $invoice->loadMissing(['lines.challanLine']);

        $vouchers = $this->counterVouchers($invoice);

        /*
         * ⚠️ `$vouchers === []` শর্তটা তোলা হলো — ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ ওটা ধরে নিত খসড়া হওয়ার একটাই কারণ: সইয়ের অপেক্ষায় একটা
         * জমা। ⓘ কিন্তু "খসড়া রাখুন" বোতামে বানানো কাগজে কোনো জমা নেই,
         * আর তখন এই দরজাটা **চিরকালের জন্য বন্ধ** থাকত — বিলটা খসড়া
         * অবস্থায় আটকে যেত, আর শেষ করার কোনো পথই থাকত না।
         *
         * ⭐ নিচের সবটা শূন্য ভাউচারে নিরাপদ: জমার যোগফল `'0'` হয়,
         * আর পোস্টের লুপটা একবারও চলে না। ⓘ কাজটা তখন যা হওয়ার তাই —
         * চালান নিশ্চিত, ফ্রি মাল নড়ে, বিল নিশ্চিত।
         */
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.nothing_held_here', ['no' => $invoice->document_no]),
            ]);
        }

        foreach ($vouchers as $voucher) {
            if ($this->voucherApproval->stopping($voucher) !== null) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.deposit_still_waiting', ['no' => $voucher->document_no]),
                ]);
            }
        }

        $challanId = $invoice->lines->first()?->challanLine?->delivery_challan_id;
        $challan = DeliveryChallan::query()->with(['lines', 'warehouse'])->findOrFail($challanId);

        return DB::transaction(function () use ($invoice, $vouchers, $challan) {
            /*
             * ⛔ গ্রাহকের সারিতে তালা — লেনদেনের **প্রথম** কাজ, ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ InnoDB লেনদেনের প্রথম সাধারণ পড়ায় ছবি তোলে; তালার আগে কিছু পড়া
             * হলে ভিতরের [[CreditExposure::assertRoomLocked()]] পুরনো বকেয়া দেখত,
             * আর দুই কাউন্টার একসাথে সীমা পার করত।
             */
            if ($invoice->customer_id !== null) {
                $this->credit->lockCustomer((int) $invoice->customer_id);
            }

            $deposit = array_reduce(
                $vouchers,
                fn (string $sum, Voucher $v) => bcadd($sum, (string) $v->amount, 4),
                '0',
            );

            // ⓘ গোনা টাকাসহ — নইলে নগদে দেওয়া বিক্রয়ও চালানের সীমায় আটকাত
            $challan = $this->challans->confirm($challan, $deposit);

            $this->moveFreeStock($challan->fresh(['lines.product', 'giftLines.product']), $challan->warehouse);

            $invoice = $this->invoices->confirm($invoice->fresh(['lines']), $deposit);

            // ⓘ পাকা হলে আর "রাখা খসড়া" নয় — চিহ্ন থেকে গেলে ভবিষ্যতের কোনো তালিকা এটাকে খোলা খসড়া ভাবত
            if ($invoice->counter_draft !== null) {
                $invoice->update(['counter_draft' => null]);
            }

            // ⓘ চেক হলে রেজিস্টারেও — আগে এই পথের চেক রেজিস্টারে উঠতই না
            foreach ($vouchers as $voucher) {
                $this->postCounterVoucher($voucher->fresh());
            }

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * কাউন্টারের একটা ডিপোজিট-সারি থেকে খসড়া রসিদ ভাউচার।
     *
     * ⓘ দুই পথ — সাথে সাথে ([[complete()]]) আর সইয়ের অপেক্ষায় ([[hold()]])
     * — একই ভাউচার বানায়; তফাত কেবল কখন পোস্ট হয়। ⚠️ তাই বানানোটা এক
     * জায়গায়, নাহলে একদিন দুই পথের ভাউচার দুই রকম হত।
     *
     * ⓘ চেক হলে টাকা ১১০৪ হাতে-চেক খাতে, নাহলে সারির খাতে, আর খাত না
     * বললে প্রধান টিলের নগদে — আগের আদায়ের কাগজের নিয়মই।
     *
     * @param  array<string, mixed>  $row
     */
    private function counterVoucher(
        array $row,
        Customer $customer,
        SalesInvoice $invoice,
        DeliveryChallan $challan,
        string $trxDate,
    ): Voucher {
        $receivable = Account::query()->postable()->where('code', StandardChart::RECEIVABLE)->firstOrFail();

        $moneyAccount = $this->moneyAccountOf($row);

        // ⛔ দ্বিতীয় দরজা — অন্য কোনো পথে সারি এলেও টাকার খাত ছাড়া ভাউচার নয় (নিরীক্ষা §১.১)
        if (($row['kind'] ?? null) !== 'cheque') {
            app(MoneyAccountRule::class)->assert($moneyAccount, false, 'deposits');
        }

        $narration = $row['narration'] ?? __('sales::message.direct_narration', [
            'no' => $challan->document_no,
        ]);

        return $this->vouchers->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => $trxDate,
                'party_type' => 'customer',
                'party_id' => $customer->id,
                'instrument' => $row['instrument'] ?? null,
                'instrument_no' => $row['reference'] ?? null,
                'instrument_date' => $row['ref_date'] ?? null,
                'from_bank' => $row['bank_name'] ?? null,
                'narration' => $narration,

                /*
                 * ⭐ তিনটা ঘর কাউন্টারের জমাতেও — ২৫ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ এটাই শেষ জোড়। ⚠️ যাচাই ও সারি দুইটাতে নাম বসিয়েও
                 * এখানে ভুলে গেলে ঘরগুলো **ভাউচারে পৌঁছাত না**, আর
                 * ফর্ম দিব্যি ৩০২ দিত — ব্যর্থতাটা দেখা যেত কেবল
                 * খতিয়ানের সারিটা পড়লে।
                 */
                'moved_at' => $row['moved_at'] ?? null,
                'carried_by' => $row['carried_by'] ?? null,
                'note_counts' => $row['note_counts'] ?? null,
                ...($row['bank'] ?? []),
                'against_type' => SalesInvoice::drillSourceType(),
                'against_id' => $invoice->id,
                'origin' => Voucher::ORIGIN_COUNTER,
            ],
            $this->vouchers->twoLineEntry(
                Voucher::RECEIPT,
                (int) $receivable->id,
                $moneyAccount,
                (string) $row['amount'],
                $narration,

                // ⓘ ব্যাংকের চার্জ — রসিদ ফর্মের একই পথ ([[VoucherController::linesFrom()]])
                isset($row['bank']['charge_amount']) ? (string) $row['bank']['charge_amount'] : null,
            ),
        );
    }

    /**
     * কাউন্টারের ভাউচার খাতায় — আর চেক হলে রেজিস্টারে একটা সারি।
     *
     * ⓘ রেজিস্টারের সারি অ-পোস্টিং: টাকা ভাউচার বসিয়েছে (Dr ১১০৪ / Cr
     * গ্রাহক)। সারিটা চেকের **জীবন** রাখে — পাশ · ফেরত · PDC · একই চেক
     * দুইবার নয় — আর `voucher_id` দিয়ে বাঁধা, যাতে ফেরত এলে ঠিক এই
     * ভাউচারটাই বাতিল হয় ([[ChequeService::bounce()]])।
     *
     * ⚠️ রেজিস্টারে ওঠে **পোস্টের সময়**, খসড়ায় নয় — সই না পাওয়া
     * ডিপোজিটের চেক রেজিস্টারে "হাতে আছে" বলে বসে থাকত।
     */
    private function postCounterVoucher(Voucher $voucher): Voucher
    {
        $voucher = $this->vouchers->post($voucher);
        $voucher->loadMissing('lines.account');

        $isCheque = $voucher->lines->contains(
            fn ($line) => $line->account?->code === StandardChart::CHEQUES_IN_HAND
                && bccomp((string) $line->debit, '0', 4) > 0,
        );

        if ($isCheque) {
            $this->cheques->record([
                'voucher_id' => $voucher->id,
                'party_type' => 'customer',
                'party_id' => $voucher->party_id,
                'cheque_no' => $voucher->instrument_no,
                'cheque_date' => $voucher->instrument_date?->toDateString(),
                'bank_name' => $voucher->from_bank,
                'amount' => (string) $voucher->amount,
                'received_on' => $voucher->trx_date?->toDateString(),
                'narration' => $voucher->narration,
            ]);
        }

        return $voucher;
    }

    /**
     * এই বিলের কাউন্টারের খসড়া ডিপোজিটগুলো।
     *
     * @return list<Voucher>
     */
    public function counterVouchers(SalesInvoice $invoice): array
    {
        // ⓘ প্রশ্নটা এক জায়গায় — [[SalesInvoice::heldCounterDeposits()]]
        return $invoice->heldCounterDeposits()->orderBy('id')->get()->all();
    }

    private function challanLines(array $lines): array
    {
        return array_values(array_map(fn (array $line) => [
            'product_id' => (int) $line['product_id'],
            'delivered_qty' => (string) $line['qty'],
            'rate' => (string) $line['rate'],

            // প্যাকটা চালান পর্যন্ত যায়, আর সেখানেই একবার নামে
            'unit_id' => $line['unit_id'] ?? null,

            /*
             * ⭐ বিক্রেতার বাছা লট — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ এখানে না বসালে বাছাইটা **নীরবে হারাত**: সেবা লট চাইত,
             * যাচাই করত, আর তারপর চালানে বসত লট ছাড়া। ⛔ মাল বেরোত FEFO
             * ধরে — অর্থাৎ সম্ভবত **অন্য লট থেকে** — আর কাগজে এক লট,
             * গুদামে আরেকটা।
             *
             * ⓘ লট ধরা নয় এমন পণ্যে `null`, আর সেটাই ঠিক।
             */
            'batch_id' => ($line['batch_id'] ?? '') === '' ? null : (int) $line['batch_id'],
        ], $lines));
    }

    /**
     * চালানের লাইন থেকে বিলের লাইন।
     *
     * প্রতিটা বিলের লাইন তার চালানের লাইনের সাথে বাঁধা — নাহলে "মাল গেছে,
     * বিল হয়নি" রিপোর্টটা এই বিক্রিগুলোকে চিরকাল বাকি দেখাত।
     *
     * ফ্রি পরিমাণ বিলে যায় না: ওটার দাম নেই, আর দামহীন সারি বিলে থাকলে
     * গ্রাহক ভাবতেন তার থেকে টাকা নেওয়া হয়েছে।
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(DeliveryChallan $challan): array
    {
        return $challan->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'delivery_challan_line_id' => $line->id,
            'qty' => (string) $line->delivered_qty,
            'rate' => (string) $line->rate,
            'discount' => $this->lineDiscount($line),

            /*
             * প্যাকটা চালান থেকে বিলে যায়, কিন্তু হিসাব আর হয় না।
             *
             * unit_id পাঠালে বিলে দ্বিতীয়বার ভাগ হত — ২ বাক্স ২০০ পিস
             * না হয়ে ২ পিস। তাই কেবল লেখা দুইটা ঘরই যায়, যাতে ক্রেতার
             * হাতের বিলে "২ বাক্স" ছাপা থাকে।
             */
            'entered_qty' => $line->entered_qty,
            'entered_unit_id' => $line->entered_unit_id,
        ])->values()->all();
    }

    /** শতাংশ থেকে টাকা — লাইনের ছাড় নমুনায় শতাংশে বসানো হয়। */
    private function lineDiscount(object $line): string
    {
        $percent = (string) ($line->discount_percent ?? '0');

        if (bccomp($percent, '0', 4) <= 0) {
            return '0';
        }

        $base = bcmul((string) $line->delivered_qty, (string) $line->rate, 4);

        return bcdiv(bcmul($base, $percent, 4), '100', 4);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    private function stampExtras(DeliveryChallan $challan, array $data, array $lines): void
    {
        $challan->update([
            'do_no' => $data['do_no'] ?? null,
            'discount_amount' => $this->money($data['discount_amount'] ?? '0'),
            'expense_amount' => $this->money($data['expense_amount'] ?? '0'),
            /*
             * ⚠️ রাউন্ডিং `money()` দিয়ে নয় — ওটা ঋণাত্মক প্রত্যাখ্যান করে।
             *
             * বাকি সব ঘরে ঋণাত্মক মানে ভুল: ঋণাত্মক ছাড়, ঋণাত্মক খরচ বা
             * ঋণাত্মক জমার কোনো মানে নেই। **রাউন্ডিংই একমাত্র ব্যতিক্রম** —
             * ওটার কাজই দুই দিকে পয়সা মেলানো।
             *
             * সীমাটা কন্ট্রোলারে দেখা হয় (কন্ট্রোল প্যানেলের সেটিং থেকে),
             * তাই এখানে কেবল সংখ্যাটা নেওয়া।
             */
            'rounding_amount' => $this->signedMoney($data['rounding_amount'] ?? '0'),
            'deposit_amount' => $this->depositTotal($data),
            'credit_period_days' => $data['credit_period_days'] ?? null,

            /*
             * ⛐ ধরনটাও খাতায় — কেবল তারিখ আর দিনসংখ্যা নয়।
             *
             * ⚠️ দিনসংখ্যাটা ধরনটা বলে না: নগদ আর COD — দুইটাই
             * শূন্য দিন, অথচ একটায় টাকা ড্রয়ারে আর আরেকটায় ভ্যানে।
             * ℹ একইভাবে ৫ তারিখে "মাস শেষ" আর "২৫ দিনের বাকি" —
             * খাতায় হুবহু এক, ব্যবসায় আলাদা।
             *
             * ⭐ ক্রয়ের কাগজে এই কলামটা ১ সেপ্টেম্বর থেকেই আছে;
             * বিক্রয়ে বসল ৫ সেপ্টেম্বর, মালিকের নির্দেশে।
             */
            'payment_term' => ($data['payment_term'] ?? '') !== ''
                ? (string) $data['payment_term']
                : null,

            /*
             * ছয়টা বোতামের ঘরগুলো — ২৯ আগস্ট ২০২৬।
             *
             * ── কেন `?: null`, `?? null` নয় ──────────────────────────
             * ফর্ম খালি ঘরও পাঠায়, খালি স্ট্রিং হিসেবে। `??` কেবল
             * অনুপস্থিত হলে ধরত, তাই ডাটাবেজে খালি স্ট্রিং বসত — আর
             * তখন "লেখা হয়নি" আর "ফাঁকা লেখা হয়েছে" আলাদা করা যেত না।
             */
            'expense_narration' => ($data['expense_narration'] ?? '') ?: null,
            /*
             * ⓘ দুইটাই রাখা হয়, আর দুইটার কাজ আলাদা।
             *
             * `carrier_id` থাকলে ভাড়াটা **তার খাতায় পাওনা** হয়ে জমে —
             * মাস শেষে মেটানোর জন্য। না থাকলে (একবারের ভাড়ার গাড়ি) নামটাই
             * কাগজে থাকে, আর ভাড়া সাধারণ প্রদেয়তে যায়।
             *
             * ⚠️ নামটা মুছে ফেলা হয় না পক্ষ বাছলেও — কাগজে কী ছাপা হয়েছিল
             * সেটা কাগজেরই কথা, আর পক্ষের নাম পরে বদলাতে পারে।
             */
            'carrier_id' => ($data['carrier_id'] ?? '') ?: null,
            'carrier_name' => ($data['carrier_name'] ?? '') ?: null,
            'transport_cost' => ($data['transport_cost'] ?? '') !== ''
                ? $this->money($data['transport_cost'])
                : null,
            'ship_to' => ($data['ship_to'] ?? '') ?: null,
            'ship_date' => ($data['ship_date'] ?? '') ?: null,

            /*
             * জমার ধরন কেবল টাকা এলেই লেখা হয়।
             *
             * বাছাইয়ের ঘরটার ডিফল্ট "নগদ", তাই টাকা না নিয়েও প্রতিটা
             * চালানে "নগদ" বসে যেত — আর রিপোর্টে হাজারটা শূন্য টাকার
             * নগদ জমা দেখা যেত।
             */
            /*
             * ⚠️ একাধিক জমা এলে চালানের গায়ে একটাই ধরন লেখা যায় না।
             *
             * ঘর দুইটা রয়ে গেছে পুরনো পথের জন্য, কিন্তু **সত্যটা এখন
             * আদায়ের কাগজগুলোতে** — প্রতিটার নিজের উপায়, নিজের খাত,
             * নিজের রেফারেন্স। একটা বিলে নগদ ৫,০০০ আর বিকাশ ১০,০০০ এলে
             * এখানে "নগদ" লিখলে সেটা অর্ধেক মিথ্যা হত।
             */
            'deposit_method' => bccomp($this->depositTotal($data), '0', 4) > 0
                ? (($data['deposit_method'] ?? '') ?: null)
                : null,
            'deposit_ref' => ($data['deposit_ref'] ?? '') ?: null,
        ]);

        // ফ্রি পরিমাণ ও লাইনের ছাড় — ক্রম ধরে, কারণ লাইনগুলো ওই ক্রমেই বসেছে
        foreach ($challan->lines()->orderBy('line_no')->get() as $index => $line) {
            $line->update([
                'free_qty' => $this->money($lines[$index]['free_qty'] ?? '0'),
                'discount_percent' => $this->money($lines[$index]['discount_percent'] ?? '0'),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $gifts
     */
    private function writeGifts(DeliveryChallan $challan, array $gifts, Warehouse $warehouse): void
    {
        $lineNo = 0;

        foreach ($gifts as $gift) {
            $productId = (int) ($gift['product_id'] ?? 0);
            $qty = $this->money($gift['qty'] ?? '0');

            if ($productId <= 0 || bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages(['gifts' => __('sales::validation.unknown_product')]);
            }

            // উপহারও প্যাকে যায় — "১ বাক্স ফ্রি"
            $pack = $this->packed($product, $qty, $gift['unit_id'] ?? null);

            DeliveryChallanGiftLine::create([
                'delivery_challan_id' => $challan->id,
                'product_id' => $productId,
                'against_product_id' => ($gift['against_product_id'] ?? null) ?: null,
                'qty' => $pack['qty'],
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'remarks' => $gift['remarks'] ?? __('sales::message.not_for_sales'),
                'line_no' => ++$lineNo,
            ]);
        }
    }

    /**
     * ফ্রি পরিমাণ ও উপহার ফ্রি ভাণ্ডার থেকে বের করা।
     *
     * চালান নিশ্চিত হওয়ার পরে, কারণ ওই মুহূর্তেই বিক্রির মালটা বেরোয় —
     * ফ্রিটা তার সাথেই যেতে হবে, নাহলে একই গাড়িতে যাওয়া মালের অর্ধেক
     * আজকের খাতায় আর অর্ধেক কালকের খাতায় পড়ত।
     */
    /**
     * ফ্রি মাল অনুপাতের বেশি নয় — মালিকের নিয়ম, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ── ⭐ নিয়মটা ───────────────────────────────────────────────────
     * *"ফ্রি কম দিতে পারবে কিন্তু কোন ভাবেই বেশি দিতে পারবে না।"*
     * ⓘ আর প্রাপ্যটা আসে **যে লটের মাল বেরোচ্ছে** তার অনুপাত থেকে
     * ([[FreeAllowance]])।
     *
     * ── ⚠️ কেন দেয়ালটা এখানে, পর্দায় নয় ────────────────────────────
     * পর্দার ঘরে সীমা বসানো সহজ, কিন্তু বিল এখানে আসতে পারে অন্য পথেও
     * — কাউন্টার, আদেশ থেকে, কিংবা কাল যোগ হওয়া কোনো পর্দা। ⛔ দেয়াল
     * পর্দায় থাকলে **প্রতিটা নতুন পথ একটা করে ফাঁক**।
     *
     * ⓘ পর্দা প্রাপ্যটা আগেই দেখাবে, যাতে কেউ ভুল করে সময় নষ্ট না
     * করেন — কিন্তু সেটা সৌজন্য, দেয়াল নয়।
     *
     * ── ⛔ আর ব্যতিক্রমের কোনো দরজা নেই ─────────────────────────────
     * মালিকের সিদ্ধান্ত: *"কোনো দরজা নেই"*। ⚠️ ম্যানেজারও বাড়াতে
     * পারবেন না। ⓘ একটা খোলা ঘর তিন মাসে অভ্যাস হয়ে যেত, আর তখন
     * নিয়মটা কাগজে থাকত, কাজে নয়।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    /**
     * ⭐ লট ধরা প্রতিটা সারি তার লট বলে, আর একই লট দুইবার নয়।
     *
     * ── ⓘ মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────
     * তাঁকে দুইটা বিকল্প দেওয়া হয়েছিল — না বাছলে FEFO চলবে, নাকি বাছা
     * বাধ্যতামূলক। ⚠️ আমি প্রথমটার সুপারিশ করেছিলাম; তিনি দ্বিতীয়টা
     * বেছেছেন।
     *
     * ── ⛔ দেয়ালটা এখানে, পর্দায় নয় ─────────────────────────────────
     * ⓘ পর্দাও আটকায়, কিন্তু সেটা সুবিধা — দেয়াল নয়। ⚠️ অন্য পথে আসা
     * বিল (API, পুরনো খসড়া, কালকের নতুন পর্দা) পর্দার পাহারা দেখে না।
     * ⛔ লট ছাড়া একটা সারি ঢুকে গেলে ফেরত বা রিকলের সুতোটা ছিঁড়ে
     * যেত, আর সেটা ধরা পড়ত কেবল রিকলের দিন।
     *
     * ── ⚠️ দুইটা আলাদা নিয়ম, দুইটা আলাদা বার্তা ────────────────────
     * ⓘ "লট বাছা হয়নি" আর "একই লট দুইবার" আলাদা ভুল, আর বিক্রেতার
     * করণীয়ও আলাদা। ⛔ এক বার্তায় মিশিয়ে দিলে তিনি বুঝতেন না লট
     * **বাছতে** হবে না **বদলাতে** হবে।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertEveryTrackedLineNamesItsLot(array $lines): void
    {
        $seen = [];

        foreach ($lines as $line) {
            $product = Product::query()->find($line['product_id'] ?? null);

            if ($product === null || ! $product->track_batch) {
                continue;
            }

            $batchId = (string) ($line['batch_id'] ?? '');

            if ($batchId === '') {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.lot_must_be_chosen_for', [
                        'product' => $product->name(),
                    ]),
                ]);
            }

            /*
             * ⓘ চাবিটা পণ্য **আর** লট মিলিয়ে — ⚠️ কেবল লট ধরলে দুইটা
             * আলাদা পণ্যের লট কখনো মিলত না (লট পণ্যের নিজের), তাই
             * পাহারাটা কিছুই ধরত না; আর কেবল পণ্য ধরলে **আলাদা লটের
             * দুইটা সারিও** আটকে যেত — অথচ সেটাই মালিকের চাওয়া।
             */
            $key = $product->id.':'.$batchId;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.lot_twice_in_one_bill', [
                        'product' => $product->name(),
                    ]),
                ]);
            }

            $seen[$key] = true;

            /*
             * ⛔ মেয়াদ পেরোনো লট বাছা যায় না — ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ FEFO নিজে মেয়াদ পেরোনো লট বাদ দেয় ([[BatchAllocator]] `unexpired`)।
             * ⚠️ কিন্তু বাছা লট সরাসরি যায় ([[StockService::issue()]] `batch`) —
             * সেখানে কেবল পরিমাণ দেখা হয়, মেয়াদ নয়। ফ্রি মালও এখন বাছা লট
             * থেকে বেরোয় ([[giveAway()]] `chosen`), তাই ফাঁকটা দুই দিকেই খুলত:
             * পচা মাল বিক্রি, আর পচা মাল "ফ্রি"। ⓘ পর্দা মেয়াদি লট দেখায় না,
             * তবে পর্দা দেয়াল নয় — হাতে বানানো অনুরোধ বা পুরনো ট্যাব আসে।
             * ⭐ ধরা পড়েছে [[FreeGoodsCarryLotsTest]]-এর মেয়াদের দাবিতে।
             */
            $lot = $this->chosenLot($product, $batchId);

            if ($lot !== null && $lot->hasExpired(now())) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.lot_expired_for', [
                        'product' => $product->name(),
                        'lot' => $lot->batch_no,
                    ]),
                ]);
            }
        }
    }

    private function assertFreeStaysWithinTheRatio(array $lines, Warehouse $warehouse): void
    {
        $allowance = app(FreeAllowance::class);

        foreach ($lines as $line) {
            $free = (string) ($line['free_qty'] ?? '0');

            if (bccomp($free, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->find($line['product_id'] ?? null);

            if ($product === null) {
                continue;
            }

            /*
             * ⭐ লট বাছা থাকলে **তারই** অনুপাত — পর্দার প্রশ্নের হুবহু যমজ
             * ([[DirectSaleController::freeAllowed()]] `onLot()`)।
             *
             * ⛔ আগে এখানে সবসময় `on()` — FEFO ধরে প্রথম লটের অনুপাত। ⚠️ লট
             * দুইটার অনুপাত আলাদা হলে পর্দা বলত ৪, দেয়াল থামাত ২-এ (abos-79
             * হাতে গুনে ধরেছেন: লট B ৫০+১০, ২০ বেচলে ৪; FEFO-র লট A ১০০+১০ বলত ২)।
             * ⓘ মালও বেরোয় ঐ লট থেকেই ([[moveFreeStock()]]), তাই প্রাপ্যও তার।
             */
            $lot = $this->chosenLot($product, $line['batch_id'] ?? null);

            $may = $lot !== null
                ? $allowance->onLot($lot, (string) ($line['qty'] ?? '0'))['allowed']
                : $allowance->on($product, $warehouse, (string) ($line['qty'] ?? '0'));

            if (bccomp($free, $may, 4) <= 0) {
                continue;
            }

            throw ValidationException::withMessages([
                'lines' => __('sales::validation.free_beyond_ratio', [
                    'product' => $product->name(),
                    'free' => rtrim(rtrim($free, '0'), '.'),
                    'allowed' => rtrim(rtrim($may, '0'), '.'),
                ]),
            ]);
        }
    }

    private function moveFreeStock(DeliveryChallan $challan, Warehouse $warehouse): void
    {
        foreach ($challan->lines as $line) {
            $free = (string) $line->free_qty;

            if (bccomp($free, '0', 4) <= 0) {
                continue;
            }

            $this->giveAway(
                $challan,
                $warehouse,
                $line->product,
                $free,
                DeliveryChallan::STOCK_SOURCE.':free',
                /*
                 * ⭐ সারির বাছা লট — ফ্রি মালও ঐ লট থেকে। মালিকের নিয়ম: *"যে
                 * স্লট যেভাবে কেনা, সেইভাবে যাবে"*। ⛔ আগে FEFO: কাগজে লট B,
                 * অথচ ফ্রি কার্টন বেরোত লট A থেকে — আর লট A-র রিকলে এমন
                 * গ্রাহকের নাম আসত যিনি ঐ লটের কিছুই পাননি। ⓘ উপহারের কোনো
                 * বাছা লট নেই, তাই সেখানে FEFO-ই থাকে।
                 */
                chosen: $this->chosenLot($line->product, $line->batch_id),
            );
        }

        foreach ($challan->giftLines as $gift) {
            $this->giveAway(
                $challan,
                $warehouse,
                $gift->product,
                (string) $gift->qty,
                DeliveryChallan::STOCK_SOURCE.':gift',
                $gift->remarks,
            );
        }
    }

    /**
     * ফ্রি ভাণ্ডার থেকে মাল বের করা — লট ধরা হলে লট বেছে।
     *
     * ── কেন ফ্রি মালেও লট ─────────────────────────────────────────────
     * আগে এটা সরাসরি `move()` ডাকত, লট ছাড়া। ফলে দুইটা জিনিস ঘটত:
     *
     *   ১. **মেয়াদোত্তীর্ণ মাল ফ্রি হয়ে বেরিয়ে যেত।** বিক্রির লাইনে
     *      মেয়াদ আটকাত, ফ্রি-র লাইনে আটকাত না — অথচ কার্টনটা একই।
     *   ২. **রিকলে ওই ক্রেতারা বাদ পড়তেন।** "এই ব্যাচ কার কাছে গেছে"
     *      প্রশ্নের উত্তরে ফ্রি ও উপহারে যাওয়া অংশটা থাকত না, আর
     *      তালিকাটা দেখে মনে হত সবাই ধরা পড়েছে।
     *
     * লট ধরা নয় এমন পণ্যে (চাল, সাবান) আগের মতোই একটা সারি — বরাদ্দের
     * কিছু নেই, বাছারও কিছু নেই।
     */
    private function giveAway(
        DeliveryChallan $challan,
        Warehouse $warehouse,
        Product $product,
        string $qty,
        string $sourceType,
        ?string $narration = null,
        ?Batch $chosen = null,
    ): void {
        $out = bcmul($qty, '-1', 4);

        /*
         * ⓘ বাছা লট — কেবল ঐ লটের ফ্রি ভাণ্ডার। ⚠️ কম পড়লে থামে, অন্য লট
         * দিয়ে পূরণ করে না: ⛔ পূরণ করলে কাগজের লট আর গুদামের লট আবার
         * আলাদা হত — ঠিক যে ভুলটা সারানো হলো।
         */
        if ($chosen !== null) {
            $have = $chosen->freeBalance($warehouse);

            if (bccomp($have, $qty, 4) < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('inventory::validation.free_batch_short', [
                        'product' => $product->name(),
                        'short' => rtrim(rtrim(bcsub($qty, $have, 4), '0'), '.'),
                    ]),
                ]);
            }

            $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $challan->id,
                date: $challan->trx_date,
                documentNo: $challan->document_no,
                narration: $narration,
                free: $out,
                batch: $chosen,
            );

            return;
        }

        if (! $product->track_batch) {
            $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $challan->id,
                date: $challan->trx_date,
                documentNo: $challan->document_no,
                narration: $narration,
                free: $out,
            );

            return;
        }

        foreach ($this->batches->allocateFree($product, $warehouse, $qty) as $slice) {
            $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $challan->id,
                date: $challan->trx_date,
                documentNo: $challan->document_no,
                narration: $narration,
                free: bcmul($slice['qty'], '-1', 4),
                batch: $slice['batch'],
            );
        }
    }

    /**
     * বিলের ছাড় আর রাউন্ডিং — পর্দা যা নিল, বিলেও তাই।
     *
     * ⛔ আগে দুইটাই কেবল চালানে বসত ([[stampExtras()]]), বিলে নয় — পর্দা নিত
     * ১,২০০, বিল বলত ১,২৪২ (পাঁচ-মিলের পরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬)। ⓘ এক
     * জায়গায়, কারণ দুইটা পথ (পাকা আর খসড়া) একই বিল বানায় — ⚠️ একটায়
     * ভুলে গেলে রাখা খসড়া পাকা করার দিন মোট বদলে যেত।
     *
     * ⚠️ খরচ (`expense_amount`) এখানে নেই, ইচ্ছাকৃত: সেটা গ্রাহকের বিলে যাবে
     * কি না মালিকের সিদ্ধান্তের অপেক্ষায়।
     *
     * @param  array<string, mixed>  $data
     * @return array{bill_discount: string, rounding_amount: string}
     */
    private function billFigures(array $data): array
    {
        return [
            'bill_discount' => $this->money($data['discount_amount'] ?? '0'),
            'rounding_amount' => $this->signedMoney($data['rounding_amount'] ?? '0'),
        ];
    }

    /**
     * সারিতে বাছা লট — কেবল ঐ পণ্যের লট হলে, নাহলে `null`।
     *
     * ⓘ অনুপাতের দেয়াল ([[assertFreeStaysWithinTheRatio()]]) আর ফ্রি মালের
     * বেরোনো ([[moveFreeStock()]]) দুইটাই এটা পড়ে — ⛔ দুই জায়গায় আলাদা
     * লিখলে একদিন প্রাপ্য এক লটের আর মাল আরেক লটের হত।
     */
    private function chosenLot(Product $product, mixed $batchId): ?Batch
    {
        $id = (int) ($batchId ?? 0);

        if ($id <= 0 || ! $product->track_batch) {
            return null;
        }

        return Batch::query()->where('product_id', $product->id)->find($id);
    }

    /**
     * পরিশোধের তারিখ — গ্রাহকের নিজের মেয়াদ থেকে, যদি কেউ আলাদা না বলে।
     *
     * শূন্য আর "বলা হয়নি" এক নয়: শূন্য মানে আজই দিতে হবে, আর বলা না হলে
     * গ্রাহকের সাথে যা কথা আছে সেটাই।
     *
     * @param  array<string, mixed>  $data
     */
    private function dueOn(array $data, Customer $customer): ?string
    {
        /*
         * নির্দিষ্ট তারিখ লেখা থাকলে সেটাই — দিনের সংখ্যা নয়।
         *
         * ── কেন তারিখটা জেতে (৩ সেপ্টেম্বর ২০২৬) ──────────────────────
         * পর্দায় দুইটা ঘর: "কত দিন" আর "কোন তারিখে"। দুইটাই লেখা থাকতে
         * পারে, কারণ একটা লিখলে অন্যটা নিজে থেকে বসে যায়।
         *
         * সংঘর্ষে তারিখটা জেতে, কারণ **ওটাই মানুষটা যা বলেছিলেন**।
         * "১৫ তারিখে দেব" থেকে দিনের সংখ্যা বের করা যায়, কিন্তু ফেরত
         * এসে দিন থেকে তারিখ গুনলে ব্যাক-ডেটেড বিলে ভুল দিনে পড়ত।
         */
        $on = trim((string) ($data['due_on'] ?? ''));

        if ($on !== '') {
            return Carbon::parse($on)->toDateString();
        }

        /*
         * ── ⭐ ধরনটা দিনসংখ্যার আগে ────────────────────────
         *
         * ℹ `month_end` কোনো দিনসংখ্যায় বলা যায় না — ৫ তারিখে সেটা
         * ২৫ দিন, ২৮ তারিখে ২ দিন, আর ফেব্রুয়ারিতে আরও আলাদা।
         * ⛔ `addDays(30)` দিয়ে গুনলে ফেব্রুয়ারির বিল মার্চে গিয়ে পড়ত।
         *
         * ⭐ `endOfMonth()` গোনে **চালানের মাস ধরে**, আজকের মাস নয় —
         * পুরনো তারিখের বিল বসালে ঐ মাসেরই শেষ।
         */
        $from = Carbon::parse($data['trx_date'] ?? now());

        if (($data['payment_term'] ?? '') === 'month_end') {
            return $from->copy()->endOfMonth()->toDateString();
        }

        /*
         * ⭐ নগদ আর COD — দুইটার তারিখই চালানের দিন।
         *
         * ⚠️ তবু দুইটা এক নয়, আর পার্থক্যটা জমার ঘরে: নগদে
         * টাকা ড্রয়ারে ढুকেছে, COD-তে মাল ভ্যানে গেছে আর টাকা
         * ফিরবে ডেলিভারিম্যানের সাথে। ℹ একটা আদায়, আরেকটা পাওনা —
         * তাই ধরনটা আলাদা করে খাতায় বসে (`payment_term`)।
         */
        if (in_array($data['payment_term'] ?? '', ['cash', 'cod'], true)) {
            return $from->toDateString();
        }

        $days = $data['credit_period_days'] ?? null;

        if ($days === null || $days === '') {
            $days = $customer->credit_days;
        }

        $days = (int) $days;

        /*
         * গোনা শুরু হয় **বিলের তারিখ থেকে**, আজ থেকে নয়।
         *
         * আগে `now()` ছিল, আর সেটা কেবল আজকের বিলে ঠিক উত্তর দিত।
         * গতকালের একটা বিল ৩০ দিনের মেয়াদে তুললে মেয়াদটা একদিন বেশি
         * পেত — প্রতিটা ব্যাক-ডেটেড বিলে, নীরবে।
         */
        return $days > 0
            ? $from->copy()->addDays($days)->toDateString()
            : null;
    }

    private function resolveCustomer(mixed $customerId): Customer
    {
        $id = (int) ($customerId ?: $this->settings->get('sales.walkin_customer_id', 0));

        $customer = $id > 0 ? Customer::query()->find($id) : null;

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => __('sales::validation.no_walkin_customer'),
            ]);
        }

        return $customer;
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
     * টাকার অঙ্ক, চিহ্নসহ — কেবল রাউন্ডিংয়ের জন্য।
     *
     * ── কেন আলাদা মেথড, `money()`-তে শর্ত যোগ করে নয় ────────────────────
     * `money()` ছাড়, খরচ, জমা — সবাই ব্যবহার করে, আর ওদের ঋণাত্মক হওয়ার
     * কোনো মানে নেই। ওখানে একটা "কখনো কখনো ঋণাত্মক চলবে" শর্ত বসালে
     * **একদিন কেউ ভুল জায়গায় ওটা চালু করত**, আর ঋণাত্মক ছাড় মানে বিল
     * বেড়ে যাওয়া — নীরবে।
     *
     * তাই ব্যতিক্রমটা নিজের নামেই থাকল: যে ডাকে সে জানে সে কী চাইছে।
     */
    private function signedMoney(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            return '0.0000';
        }

        return bcadd($value, '0', 4);
    }

    /**
     * জমার সারিগুলো — নতুন পথ, আর পুরনোটার সেতু।
     *
     * ── কেন দুইটা পথ ───────────────────────────────────────────────
     * পর্দা এখন `deposits[]` পাঠায়, কিন্তু **পুরনো `deposit` ঘরটা এখনো
     * অন্য জায়গা থেকে আসতে পারে** — এবং টেস্টও ওই আকারে লেখা। একটাকে
     * আরেকটার আকারে অনুবাদ করে দিলে নিচের কোডে আর দুইটা পথ থাকে না,
     * তাই ভুলের জায়গাও একটাই।
     *
     * ⚠️ দুইটা একসাথে এলে `deposits` জেতে — ওটাই বিস্তারিত, আর
     * বিস্তারিতটাই সত্য।
     *
     * ── `kind` আর `bank_name` ডকব্লক থেকে বাদ পড়েছিল ────────────────
     * দুইটাই নিচে বসানো হয়, আর দুইটাই ব্যবহৃত হয় — `kind` দিয়ে চেক
     * শনাক্ত হয় (`$isCheque`), আর `bank_name` চেক-রেজিস্টারে যায়।
     *
     * ⛔ অনুপস্থিতিটা নিরীহ ছিল না: স্ট্যাটিক বিশ্লেষক ডকব্লক বিশ্বাস করে
     * বলত `($row['kind'] ?? null) === 'cheque'` **সবসময় মিথ্যা**, অর্থাৎ
     * চেকের পুরো পথটা মৃত। রানটাইমে সেটা সত্য নয়, কিন্তু যে-ই ঐ অভিযোগটা
     * বিশ্বাস করে `kind` মুছে দিত, তার হাতে দুইটা জিনিস **নীরবে** বন্ধ
     * হত: চেকের টাকা ১১০৪-এ (হাতে চেক) যাওয়া, আর চেক-রেজিস্টারের সারি।
     * ⚠️ দুইটার একটাও কোনো ত্রুটি দেখাত না — কেবল টাকা ভুল খাতে বসত।
     *
     * ⓘ তবে নীরবে নয়: `DirectSaleChequeTest::test_a_cheque_sale_lands_in_
     * cheques_in_hand_and_registers()` ঠিক ঐ দুইটাই মাপে — ১১০৪-এর জের আর
     * রেজিস্টারের সারি। ডকব্লক ভুল ছিল, পাহারা নয়।
     *
     * @param  array<string, mixed>  $data
     * @return list<array{amount: string, account_id: mixed, kind: ?string, instrument: ?string, reference: ?string, ref_date: ?string, bank_name: ?string, narration: ?string}>
     */
    /**
     * নোটের গোনা — কেবল যেগুলো সত্যিই গোনা হয়েছে।
     *
     * ── ⓘ কেন শূন্যগুলো ফেলে দেওয়া হয় ──────────────────────────────
     * পর্দা দশটা ঘরই পাঠায় (১০০০ থেকে ১ পর্যন্ত), আর বিক্রেতা সাধারণত
     * দুই-তিনটা ভরেন। ⛔ সব রেখে দিলে প্রতিটা নগদ জমার সাথে দশটা `0`
     * খতিয়ানে বসত।
     *
     * ⚠️ আর কিছুই গোনা না হলে উত্তর `null`, খালি অ্যারে নয় — কলামটা
     * `json` আর `nullable`, তাই `[]` বসালে "গোনা হয়েছে, কিছু পাওয়া
     * যায়নি" বলে পড়া যেত। ⓘ দুইটা আলাদা কথা।
     *
     * @return array<string, int>|null
     */
    private function notesOf(mixed $notes): ?array
    {
        if (! is_array($notes)) {
            return null;
        }

        $kept = [];

        foreach ($notes as $face => $count) {
            $n = (int) $count;

            if ($n > 0) {
                $kept[(string) $face] = $n;
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * ⛔ কাউন্টারে চেক নেওয়া যায় না — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ তাঁর কথা: *"counter e cheek newar option thakbe na, cheek sudu
     * accounts e nite parbe, taw accounts e joma hole ledger e bosbe, tar
     * age noy"*।
     *
     * ⚠️ কারণটা বাকির সীমা: কাউন্টারে চেককে টাকা ধরলে বিল সীমার ভিতরে
     * দেখাত, মাল বেরিয়ে যেত — আর চেক ফেরত এলে ঠিক সেই আটকে যাওয়া
     * টাকাটাই জন্মাত যা ৩.৮২% মার্জিনে কয়েক বছরের লাভ মুছে দেয়।
     *
     * ⓘ পর্দা চেকের উপায় দেখায়ই না; এটা দ্বিতীয় দরজা — হাতে বানানো
     * অনুরোধ বা পুরনো ট্যাবের জন্য। দুই পথই দেখা হয়: `deposits[]` সারি,
     * আর পুরনো একক-জমার `deposit_method` কোড।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoChequeAtTheCounter(array $data): void
    {
        $cheque = collect($this->depositRows($data))
            ->contains(fn (array $row) => ($row['kind'] ?? null) === 'cheque');

        $legacy = trim((string) ($data['deposit_method'] ?? ''));

        /*
         * ⚠️ পুরনো ঘরে আক্ষরিক `cheque`-ও চেক — abos-20 ধরেছেন, ২৭ সেপ্টেম্বর
         * ২০২৬। ⓘ ডেমোতে চেকের পদ্ধতির কোড `CHQ`, তাই কোড ধরে খুঁজলে
         * `cheque` কিছুই পেত না, আর বিক্রয়টা পরের দরজায় (ভাউচারের পাহারা)
         * গিয়ে এমন ঘরের ভুল নিয়ে ফিরত যেটা কাউন্টারের ফর্মে নেই।
         */
        if (! $cheque && $legacy !== '') {
            $cheque = strtolower($legacy) === 'cheque'
                || PaymentMethod::query()->where('code', $legacy)->value('kind') === 'cheque';
        }

        if ($cheque) {
            throw ValidationException::withMessages([
                'deposits' => __('sales::validation.no_cheque_at_counter'),
            ]);
        }
    }

    /**
     * কাউন্টারের জমার খাত — ফাঁকা হলে ফাঁকাই (ভাউচার তখন প্রধান টিল নেয়), নাহলে
     * কেবল টাকার খাত ([[MoneyAccountRule]])।
     */
    private function depositAccount(mixed $accountId): ?int
    {
        if (blank($accountId)) {
            return null;
        }

        return (int) app(MoneyAccountRule::class)->assert((int) $accountId, false, 'deposits')->id;
    }

    private function depositRows(array $data): array
    {
        $rows = [];

        /*
         * উপায়ের সারিগুলো একবারে তোলা — সারিপ্রতি একটা কোয়েরি নয়।
         *
         * ⚠️ কোড আর খাত **সার্ভারেই** বের করা হয়, পর্দা যা পাঠিয়েছে তা
         * নয়। পর্দার পাঠানো নাম বিশ্বাস করলে যে কেউ অনুরোধ বানিয়ে
         * "নগদ" লিখে বিকাশের খাতে টাকা বসিয়ে দিতে পারত।
         */
        $methods = PaymentMethod::query()
            ->whereIn('id', collect($data['deposits'] ?? [])
                ->pluck('payment_method_id')->filter()->unique()->all())
            ->get(['id', 'code', 'account_id', 'kind'])
            ->keyBy('id');

        foreach ($data['deposits'] ?? [] as $row) {
            $amount = $this->money($row['amount'] ?? '0');

            // খালি সারি পর্দাতেও বাদ যায়; এখানে দ্বিতীয় দরজা
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $method = $methods->get($row['payment_method_id'] ?? null);

            $rows[] = [
                'amount' => $amount,
                /*
                 * খাত বাছা না থাকলে উপায়ের নিজের খাত — আর সেটাও না
                 * থাকলে `null`, যেটা [[counterVoucher()]] প্রধান টিলের নগদে
                 * পাঠায়। ⓘ শেষ ধাপটা কেবল নগদের জন্য ঠিক, তাই উপায়ের
                 * সারিতে খাত বসানো **সেটআপের কাজ**, কোডের নয়।
                 */
                /*
                 * ⛔ কেবল টাকার খাত — পূর্ণ নিরীক্ষা §১.১, ২৭ সেপ্টেম্বর ২০২৬।
                 * ⚠️ আগে যেকোনো খাত "জমা" হতে পারত (খরচের খাতও), আর তাতে বকেয়া
                 * মুছে বাকির সীমার দেয়াল পার হত। ⓘ নিয়মটা আদায়েরটাই ([[MoneyAccountRule]])।
                 */
                'account_id' => $this->depositAccount(($row['account_id'] ?? null) ?: $method?->account_id),
                // ধরন — চেক হলে টাকা ১১০৪-এ যায় ও একটা রেজিস্টার-সারি হয়
                'kind' => $method?->kind,
                'instrument' => $method?->code,
                'reference' => ($row['reference'] ?? '') ?: null,
                'ref_date' => ($row['ref_date'] ?? '') ?: null,
                // চেকের ব্যাংকের নাম — কেবল চেকের সারিতে অর্থপূর্ণ
                'bank_name' => ($row['bank_name'] ?? '') ?: null,
                'narration' => ($row['narration'] ?? '') ?: null,

                /*
                 * ⭐ আদায় ভাউচারের তিনটা ঘর — ২৫ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ এই তালিকাটা **হাতে বাছা**, তাই নাম না বসালে ঘরটা
                 * নীরবে হারায়। ⓘ abos-13 আজ ঠিক এই আকারে একটা ভাঙা
                 * জোড় পেয়েছেন: `batch_id` `fillable`-এ ছিল, যাচাইও
                 * হত, তবু সারিতে বসত না — আর মাল বেরোত অন্য লট থেকে।
                 */
                'moved_at' => ($row['moved_at'] ?? '') ?: null,
                'carried_by' => ($row['carried_by'] ?? null) ?: null,

                /*
                 * ⓘ শূন্য গোনাগুলো ফেলে দেওয়া হয়।
                 *
                 * ⚠️ পর্দা দশটা ঘরই পাঠায়, বেশিরভাগ খালি। ⛔ সব রেখে
                 * দিলে প্রতিটা নগদ জমার সাথে দশটা `0` খতিয়ানে বসত, আর
                 * "৫০০ টাকার নোট কয়টা এসেছিল" প্রশ্নের উত্তর খুঁজতে
                 * গিয়ে শূন্যের সারি পেরোতে হত।
                 */
                'note_counts' => $this->notesOf($row['note_counts'] ?? null),

                // ⭐ ব্যাংক/মোবাইলের বিস্তারিত — রসিদ ভাউচারের হুবহু ঘর (২৭ সেপ্টেম্বর ২০২৬)
                'bank' => array_filter(
                    array_intersect_key($row, array_flip(self::BANK_DETAIL_FIELDS)),
                    fn ($value) => $value !== null && $value !== '',
                ),
            ];
        }

        if ($rows !== []) {
            return $rows;
        }

        $single = $this->money($data['deposit'] ?? '0');

        if (bccomp($single, '0', 4) <= 0) {
            return [];
        }

        return [[
            'amount' => $single,
            'account_id' => $data['account_id'] ?? null,
            // পুরনো একক-জমার পথ — এখানে method_id নেই, তাই ধরন জানা যায় না;
            // চেক-রেজিস্টার কেবল deposits[] সারিগুলো থেকে হয়
            'kind' => null,
            'instrument' => ($data['deposit_method'] ?? '') ?: null,
            'reference' => ($data['deposit_ref'] ?? '') ?: null,
            'ref_date' => null,
            'bank_name' => null,
            'narration' => null,
        ]];
    }

    /**
     * সব জমার যোগফল — চালানের গায়ে যেটা বসে।
     *
     * ⓘ `depositRows()` দিয়েই গোনা হয়, আলাদা করে নয় — নইলে একদিন
     * যোগফল আর কাগজগুলো আলাদা হয়ে যেত, আর কোনটা সত্যি তা বলার উপায়
     * থাকত না।
     *
     * @param  array<string, mixed>  $data
     */
    private function depositTotal(array $data): string
    {
        $sum = '0.0000';

        foreach ($this->depositRows($data) as $row) {
            $sum = bcadd($sum, $row['amount'], 4);
        }

        return $sum;
    }

    /**
     * "হাতে চেক" (১১০৪) খাত — চেকের টাকা এখানেই বসে।
     *
     * প্রতিটা কোম্পানির ছকে StandardChart এটা বসায়, তাই সচরাচর পাওয়া
     * যায়; না পেলে চুপ করে অন্য খাতে বসিয়ে দেওয়ার চেয়ে থেমে বলে দেওয়াই
     * ভালো — নইলে চেকের টাকা ভুল জায়গায় গিয়ে রেওয়ামিল মিলত, কারণ বোঝা যেত না।
     */
    private function chequesInHandAccount(): Account
    {
        $account = Account::query()->postable()
            ->where('code', StandardChart::CHEQUES_IN_HAND)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'deposits' => __('sales::validation.no_cheques_in_hand_account'),
            ]);
        }

        return $account;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '0.0000';
        }

        if (! is_numeric($value) || bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    /**
     * পর্দার জন্য একটা পণ্যের ছয়টা সংখ্যা।
     *
     * নমুনা দাবি করে Main / Free / Reserved / Available সরাসরি দেখা যাবে।
     * ফাঁকটা আসল: যিনি "Available ৭৪৬" দেখেন তিনি জানেন না তার মধ্যে কতটা
     * অন্য অর্ডারে ধরা — আর ওটা জানতে হলে অন্য পর্দায় যেতে হত।
     *
     * @return array<string, string>
     */
    public function stockPanel(Product $product, ?Warehouse $warehouse = null): array
    {
        return $this->stock->statesFor($product, $warehouse ?? $this->resolveWarehouse(null));
    }
}
