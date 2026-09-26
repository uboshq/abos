<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
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
        if ($this->wantsDraft($data) || $this->counterDepositNeedsApproval($data)) {
            return $this->hold($data, $lines, $gifts, $customer, $warehouse);
        }

        return DB::transaction(function () use ($data, $lines, $gifts, $customer, $warehouse) {
            $trxDate = $data['trx_date'] ?? now()->toDateString();

            $challan = $this->challans->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => $trxDate,
                    'vehicle_no' => $data['vehicle_no'] ?? null,
                    'driver_name' => $data['driver_name'] ?? null,
                    'narration' => $data['narration'] ?? null,
                ],
                $this->challanLines($lines),
            );

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

            $challan = $this->challans->confirm($challan->fresh(['lines']), $deposit);

            // ফ্রি ও উপহার — চালান নিশ্চিত হওয়ার পর, ফ্রি ভাণ্ডার থেকে
            $this->moveFreeStock($challan->fresh(['lines.product', 'giftLines.product']), $warehouse);

            $invoice = $this->invoices->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'due_on' => $this->dueOn($data, $customer),
                    // হাতে লেখা নম্বর — খালি হলে সিরিজ নিজেই দেবে
                    'document_no' => trim((string) ($data['invoice_no'] ?? '')) ?: null,
                    'narration' => $data['narration'] ?? null,
                ],
                $this->invoiceLines($challan),
            );

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
     * সই লাগবে — তাই চালান আর বিল খসড়া, ডিপোজিট রসিদ ভাউচার হয়ে সইয়ের অপেক্ষায়।
     *
     * ── ⚠️ কী হয় আর কী হয় না ─────────────────────────────────────────
     * ✓ চালান ও তার সারি, উপহার, বিল — সব লেখা থাকে, খসড়া অবস্থায়।
     * ✓ প্রতিটা ডিপোজিট একটা **রসিদ ভাউচার** (খসড়া), বিলের সাথে বাঁধা
     *   (`against`), কাউন্টারের চিহ্নসহ (`origin`) — আর অনুমোদনের অনুরোধ।
     * ✗ মাল বের হয় না, ফ্রি মাল নড়ে না, খাতায় কিছু বসে না।
     *
     * ⓘ বাকিটা [[finishHeld()]] করে, সই হয়ে যাওয়ার পর — বিলের পাতার বোতাম।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, extra: string, held: list<mixed>, awaiting: list<Voucher>}
     */
    private function hold(array $data, array $lines, array $gifts, Customer $customer, Warehouse $warehouse): array
    {
        return DB::transaction(function () use ($data, $lines, $gifts, $customer, $warehouse) {
            $trxDate = $data['trx_date'] ?? now()->toDateString();

            $challan = $this->challans->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => $trxDate,
                    'vehicle_no' => $data['vehicle_no'] ?? null,
                    'driver_name' => $data['driver_name'] ?? null,
                    'narration' => $data['narration'] ?? null,
                ],
                $this->challanLines($lines),
            );

            $this->stampExtras($challan, $data, $lines);
            $this->writeGifts($challan, $gifts, $warehouse);

            // ⓘ খসড়া চালানের বিল — কেবল এই চালানের জন্য ছাড় ([[SalesInvoiceService::createForHeldCounterSale()]])
            $invoice = $this->invoices->createForHeldCounterSale(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => $trxDate,
                    'due_on' => $this->dueOn($data, $customer),
                    'document_no' => trim((string) ($data['invoice_no'] ?? '')) ?: null,
                    'narration' => $data['narration'] ?? null,
                ],
                $this->invoiceLines($challan->fresh(['lines'])),
                (int) $challan->id,
            );

            /*
             * ⛔ খসড়াও সীমার ভিতরে — ২৬ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ মালিক: *"bill khosora hole product zemon atkay temon customer
             * er balanceo atkabe"*। ⚠️ তাই সীমার বেশি খসড়া রাখতে দিলে সীমার
             * বেশি টাকা আটকে থাকত — সীমার বাইরে কোনো কাগজই তৈরি হয় না,
             * খসড়াও না। ⓘ লেনদেনের ভিতরে, তাই বাধা পেলে চালান আর বিল
             * দুইটাই ফিরে যায়।
             */
            $this->credit->assertRoom(
                customer: $customer,
                adding: (string) $invoice->total,
                payingNow: $this->depositTotal($data),
                exceptInvoiceId: (int) $invoice->id,
            );

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
            ];
        });
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
            $deposit = array_reduce(
                $vouchers,
                fn (string $sum, Voucher $v) => bcadd($sum, (string) $v->amount, 4),
                '0',
            );

            // ⓘ গোনা টাকাসহ — নইলে নগদে দেওয়া বিক্রয়ও চালানের সীমায় আটকাত
            $challan = $this->challans->confirm($challan, $deposit);

            $this->moveFreeStock($challan->fresh(['lines.product', 'giftLines.product']), $challan->warehouse);

            $invoice = $this->invoices->confirm($invoice->fresh(['lines']), $deposit);

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

        $moneyAccount = ($row['kind'] ?? null) === 'cheque'
            ? $this->chequesInHandAccount()->id
            : (int) (($row['account_id'] ?? null) ?: $this->tills->ensurePrimaryTill()->account_id);

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

            $may = $allowance->on($product, $warehouse, (string) ($line['qty'] ?? '0'));

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
    ): void {
        $out = bcmul($qty, '-1', 4);

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

        if (! $cheque && $legacy !== '') {
            $cheque = PaymentMethod::query()->where('code', $legacy)->value('kind') === 'cheque';
        }

        if ($cheque) {
            throw ValidationException::withMessages([
                'deposits' => __('sales::validation.no_cheque_at_counter'),
            ]);
        }
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
                'account_id' => ($row['account_id'] ?? null) ?: $method?->account_id,
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
