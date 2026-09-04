<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillGiftLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সরাসরি ক্রয় চালান — গাড়ি আসে, মাল নামে, কাগজ হাতে।
 *
 * ── কেন আলাদা একটা সার্ভিস, শুধু বিলের পর্দা নয় ─────────────────────
 * বিলের পর্দা দিয়েই সরাসরি কেনা যেত — যে লাইনের সাথে GRN জোড়া নেই তার
 * মাল বিল নিশ্চিত করলেই গুদামে ঢোকে। কিন্তু ডিপোর মানুষটা এক ঘটনায়
 * তিনটা কাজ করেন: মাল বুঝে নেওয়া, দাম ঠিক করা, আর অনেক সময় হাতে হাতে
 * টাকা দেওয়া। তিন পর্দায় ভাগ করলে প্রতিবার তিনবার একই সরবরাহকারী আর
 * একই তারিখ লিখতে হত, আর মাঝপথে একটা ধাপ বাদ পড়লে খাতা আধা থেকে যেত।
 *
 * এখানে তিনটাই এক লেনদেনে: হয় পুরোটা বসে, নয় কিছুই বসে না।
 *
 * ── বিক্রয়মূল্যটা এখানেই কেন ────────────────────────────────────────
 * মাল ঢোকার মুহূর্তেই মানুষটা জানেন কত দামে বেচবেন — ক্রয়দর তখন চোখের
 * সামনে। পরে আলাদা পর্দায় গিয়ে বসাতে হলে বেশিরভাগ পণ্যের দাম কখনোই
 * বসানো হত না, আর বিক্রয়ের পর্দা প্রতিবার পুরনো দাম দেখাত।
 */
final class DirectPurchaseService
{
    /*
     * প্যাক থেকে মূল এককে নামানোর নিয়মটা এখান থেকে আসে।
     *
     * ⚠️ নিজে লিখতে গিয়ে থেমেছি — ট্রেইটটা আগে থেকেই আছে, আর
     * ক্রয়ের বাকি চারটা সার্ভিসও ওটাই ব্যবহার করে। দ্বিতীয় একটা
     * কপি মানে একদিন বিলের লাইনে বাক্স ২০০ পিস আর উপহারে ২ পিস।
     */
    use ReadsPackedQuantities;

    public function __construct(
        private readonly PurchaseBillService $bills,
        private readonly PaymentService $payments,
        private readonly StockService $stock,
    ) {}

    /**
     * একটা সরাসরি ক্রয় — বিল, মাল, দাম, আর চাইলে পরিশোধ।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts  মিল যা সাথে দিয়ে দিল
     * @return array{bill: PurchaseBill, payment: Payment|null}
     */
    public function complete(array $data, array $lines, array $gifts = []): array
    {
        $lines = array_values(array_filter(
            $lines,
            fn (array $line) => filled($line['product_id'] ?? null) && (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines, $gifts) {
            $bill = $this->bills->create($data, $this->billLines($lines));

            /*
             * বিলটা এখানেই নিশ্চিত — খসড়া রেখে দেওয়ার মানে নেই।
             *
             * মালটা তো নেমেই গেছে। খসড়া রাখলে গুদামে মাল আছে অথচ খাতায়
             * নেই, আর ওই ফাঁকটায় বিক্রি হলে মজুদ ঋণাত্মক হত।
             */
            $bill = $this->bills->confirm($bill);

            /*
             * উপহার — বিল নিশ্চিত হওয়ার **পরে**, আর সেটা ইচ্ছাকৃত।
             *
             * নিশ্চিত হওয়ার মুহূর্তেই মালটা গুদামে ঢোকে। উপহারটা তার
             * সাথেই ঢুকতে হয়, নাহলে একই গাড়ির মাল অর্ধেক আজকের খাতায়
             * আর অর্ধেক অন্য কোথাও পড়ত।
             *
             * ⓘ বিক্রয়ের দিকেও ক্রমটা একই ([[DirectSaleService::complete]])।
             */
            $this->writeGifts($bill, $gifts);
            $this->bringInGifts($bill->fresh(['giftLines.product']));

            $this->stampSalesPrices($lines);

            $payment = $this->payNow($data, $bill);

            return ['bill' => $bill->fresh(['lines', 'giftLines']), 'payment' => $payment];
        });
    }

    /**
     * পর্দার সারিগুলো বিলের সারিতে।
     *
     * purchase_receipt_line_id কখনো বসে না — সেটাই একে "সরাসরি" করে, আর
     * ওই ফাঁকা ঘরটা দেখেই PurchaseBillService মালটা গুদামে ঢোকায়।
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function billLines(array $lines): array
    {
        return array_map(fn (array $line) => [
            'product_id' => (int) $line['product_id'],
            'qty' => (string) $line['qty'],
            'free_qty' => (string) ($line['free_qty'] ?? '0'),
            'rate' => (string) $line['rate'],

            /*
             * লটের তিনটা ঘর — এখান দিয়ে না গেলে পর্দার ঘরগুলো নীরবে
             * হারাত।
             *
             * ⚠️ এই তালিকাটা একটা **ছাঁকনি**: যা এখানে লেখা নেই তা
             * বিলের সারিতে পৌঁছায় না, আর কোথাও কোনো ত্রুটি হয় না।
             * ⓘ পর্দায় ঘর, request-এ নিয়ম, কলাম টেবিলে — তিনটাই থাকা
             * সত্ত্বেও এই এক লাইনের অভাবে লট নম্বরটা হারিয়ে যেত, আর
             * তারপর `BatchService` বলত "লট নম্বর লাগবে"।
             *
             * ⓘ প্যাকের ঘরটাও একই কারণে — `unit_id` না গেলে
             * [[ReadsPackedQuantities]] জানত না কোন প্যাকে লেখা হয়েছিল।
             */
            'batch_no' => $line['batch_no'] ?? null,
            'expiry_date' => $line['expiry_date'] ?? null,
            'mrp' => filled($line['mrp'] ?? null) ? (string) $line['mrp'] : null,
            'unit_id' => $line['unit_id'] ?? null,
            'discount' => (string) ($line['discount'] ?? '0'),
            'tax' => (string) ($line['tax'] ?? '0'),
            'sales_price' => filled($line['sales_price'] ?? null) ? (string) $line['sales_price'] : null,
            'narration' => $line['narration'] ?? null,
        ], $lines);
    }

    /**
     * উপহারের সারিগুলো লেখা — দর ছাড়া, জোড়া ধরে।
     *
     * ── কেন প্রতিটা উপহার "কার বিপরীতে" জানে ────────────────────────
     * মালিকের নির্দেশ: *"উপহার কোন পণ্যের সাথে আসল তাও manage করতে
     * হবে।"* আর হিসাবেও ওটাই লাগে — দশ কার্টন সাবানের সাথে একটা বালতি
     * পেলে **সাবানের আসল ক্রয়দর** বের করতে জোড়াটা ছাড়া উপায় নেই।
     *
     * ⚠️ জোড়াটা ঐচ্ছিক, আর সেটা ইচ্ছাকৃত। মিল একটা ক্যালেন্ডার বা
     * ছাতা পাঠাতে পারে যা কোনো নির্দিষ্ট পণ্যের সাথে নয়। বাধ্যতামূলক
     * করলে ক্যাশিয়ার একটা যেকোনো পণ্য বেছে নিতেন — **খালি থাকার চেয়ে
     * ভুল ভরা খারাপ**।
     *
     * @param  list<array<string, mixed>>  $gifts
     */
    private function writeGifts(PurchaseBill $bill, array $gifts): void
    {
        $lineNo = 0;

        foreach ($gifts as $gift) {
            $productId = (int) ($gift['product_id'] ?? 0);
            $qty = (string) ($gift['qty'] ?? '0');

            if ($productId <= 0 || bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'gifts' => __('purchase::validation.unknown_product'),
                ]);
            }

            /*
             * ⛔ লট ধরা পণ্য উপহার হিসেবে নেওয়া যায় না — এখনো।
             *
             * ── কেন থামানো, আর কেন নীরবে ঢুকিয়ে দেওয়া নয় ───────────
             * লট ধরা পণ্যে লট নম্বরটা বাধ্যতামূলক ([[BringsInLots]]), আর
             * এই পর্দাটা লট নম্বর নেয়ই না — লাইনের জন্যও নয়, উপহারের
             * জন্যও নয়। নম্বর ছাড়া ঢুকিয়ে দিলে মালটা বসত "কোন লট জানা
             * নেই" অবস্থায়, আর তার দুইটা ফল, দুইটাই খারাপ:
             *
             *   ১. মেয়াদোত্তীর্ণ ওষুধ উপহারের পথে বেরিয়ে যেত
             *   ২. রিকলে ওই ক্রেতারা তালিকায় উঠতেন না
             *
             * তাই কারণসহ থামা — চুপচাপ একটা মিথ্যা লট বানানোর চেয়ে
             * ভালো।
             *
             * ⛔ আর "GRN-এর পথে নিন" বলাও যায় না — মেপে দেখা গেছে
             * **কোনো ক্রয়-পর্দাই** লট নম্বর নেয় না (সরাসরি ক্রয় · বিল ·
             * GRN — তিনটাই একই লাইন-এডিটর ব্যবহার করে, আর তাতে ঘরটা
             * নেই)। বার্তাটা তাই কোনো পথ দেখায় না, কারণ আজ পথটা নেই;
             * ⓘ পথ না দেখিয়ে সৎ থাকা ভালো, ভুল পথ দেখানোর চেয়ে।
             *
             * ⓘ নকশা লেখা আছে: `A3 — লট ধরা পণ্য ক্রয়ের পথ (মাপা ও নকশা)`।
             * ঘর তিনটা বসলে এই বাধাটা এক লাইনেই উঠবে।
             */
            if ($product->track_batch) {
                throw ValidationException::withMessages([
                    'gifts' => __('purchase::validation.gift_needs_a_lot', [
                        'product' => $product->name(),
                    ]),
                ]);
            }

            $pack = $this->packed($product, $qty, $gift['unit_id'] ?? null);

            PurchaseBillGiftLine::create([
                'purchase_bill_id' => $bill->id,
                'product_id' => $productId,
                'against_product_id' => ($gift['against_product_id'] ?? null) ?: null,
                'qty' => $pack['qty'],
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'remarks' => $gift['remarks'] ?? null,
                'line_no' => ++$lineNo,
            ]);
        }
    }

    /**
     * উপহারগুলো গুদামে — **ফ্রি ভাণ্ডারে**, কেনা মজুদে নয়।
     *
     * মালিকের নির্দেশ: *"stock-এ free আলাদা manage হবে।"* আর কারণটা
     * হিসাবের: এই মালের কোনো ক্রয়দর নেই। কেনা মজুদে মেশালে গড় ক্রয়দর
     * নিচে নেমে যেত, আর মুনাফা বেশি দেখাত — **প্রতিটা উপহারে একটু করে**।
     *
     * ⓘ উৎসের নাম `:gift`, `:free` নয় — দুইটা আলাদা প্রশ্ন: "একই পণ্যের
     * কত ফ্রি এল" আর "অন্য পণ্য কত উপহার এল"। বিক্রয়ের দিকেও এই
     * দুইটা নাম আলাদা।
     */
    private function bringInGifts(PurchaseBill $bill): void
    {
        if ($bill->giftLines->isEmpty()) {
            return;
        }

        $warehouse = $this->bills->warehouseFor($bill);

        foreach ($bill->giftLines as $gift) {
            $this->stock->move(
                product: $gift->product,
                warehouse: $warehouse,
                sourceType: PurchaseBill::STOCK_SOURCE.':gift',
                sourceId: $bill->id,
                date: $bill->trx_date,
                documentNo: $bill->document_no,
                narration: $gift->remarks,
                free: (string) $gift->qty,
            );
        }
    }

    /**
     * নতুন বিক্রয়মূল্য পণ্যের গায়ে বসানো।
     *
     * ── কেন বিলের সারিতে রাখাই যথেষ্ট নয় ───────────────────────────
     * বিলের সারিতে দামটা থাকে ইতিহাস হিসেবে — "ওই চালানে এই দাম ঠিক
     * হয়েছিল"। কিন্তু বিক্রয়ের পর্দা পণ্যের গায়ের দামটা পড়ে। দুইটা
     * আলাদা জিনিস, আর দ্বিতীয়টা না বসালে দাম ঠিক করার পুরো কাজটাই
     * কোথাও গিয়ে পৌঁছাত না।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function stampSalesPrices(array $lines): void
    {
        foreach ($lines as $line) {
            if (blank($line['sales_price'] ?? null)) {
                continue;
            }

            $product = Product::query()->find((int) $line['product_id']);

            if ($product === null) {
                continue;
            }

            /*
             * ক্রয়দরটাও বসে।
             *
             * পণ্যের গায়ের ক্রয়দর হলো "শেষ কত দামে কিনেছি" — পরের বার
             * দর ঠিক করার সময় ওটাই মাপকাঠি। FIFO স্তরের খরচের সাথে
             * গুলিয়ে ফেলার জিনিস নয়: মজুদের মূল্য স্তর থেকেই আসে,
             * এটা শুধু চোখের সামনে রাখার একটা সংখ্যা।
             */
            $product->forceFill([
                'sale_price' => (string) $line['sales_price'],
                'purchase_price' => (string) $line['rate'],
            ])->save();
        }
    }

    /**
     * হাতে হাতে দেওয়া টাকা।
     *
     * শূন্য বা ফাঁকা হলে কিছুই হয় না — বাকিতে কেনাটাই স্বাভাবিক, আর
     * শূন্য টাকার একটা পরিশোধ ভাউচার খাতায় শুধু আবর্জনা।
     *
     * @param  array<string, mixed>  $data
     */
    private function payNow(array $data, PurchaseBill $bill): ?Payment
    {
        /*
         * ── একাধিক জমা এলে সেগুলোই ─────────────────────────────────
         *
         * পর্দা এখন `deposits[]` পাঠায়, কিন্তু পুরনো একক ঘরটাও এখনো
         * চলে — API, ইমপোর্ট আর সিডার ওটাই পাঠায়, আর ওগুলো ভাঙার কোনো
         * কারণ নেই।
         *
         * ⚠️ দুইটা একসাথে এলে `deposits` জেতে: ওটাই বিস্তারিত, আর
         * ব্যবহারকারী শেষ যেটা লিখেছেন। ⓘ বিক্রয়ের দিকেও হুবহু এই
         * নিয়ম, তাই দুই পর্দা একই আচরণ করে।
         */
        if (filled($data['deposits'] ?? null)) {
            return $this->payEachWay($data['deposits'], $bill);
        }

        $amount = (string) ($data['paid_now'] ?? '0');

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        $payment = $this->payments->create(
            [
                'supplier_id' => $bill->supplier_id,
                'account_id' => $data['paid_from_account_id'] ?? null,
                'trx_date' => $bill->trx_date,
                'amount' => $amount,
                'narration' => __('purchase::message.paid_against', ['no' => $bill->document_no]),
            ],
            [['purchase_bill_id' => $bill->id, 'amount' => $amount]],
        );

        return $this->payments->confirm($payment);
    }

    /**
     * সারি ধরে ধরে পরিশোধ — নগদ কিছু, চেকে কিছু, bKash-এ কিছু।
     *
     * ── কেন প্রতিটা সারির নিজের পরিশোধ ──────────────────────────────
     * একটা পরিশোধে একটাই খাত আর একটাই উপায় বসে (`pur_payments`)। তিন
     * পথে টাকা গেলে সেটা তিনটা ঘটনা — একটা নয় — আর খাতাতেও তিনটাই
     * আলাদা দেখা দরকার: ব্যাংকের সারিতে চেকটা, নগদের সারিতে নগদটা।
     *
     * ⛔ একটা পরিশোধে মোট অঙ্ক বসালে টাকাটা একটা খাত থেকেই গেছে বলে
     * লেখা থাকত, আর মাস শেষে নগদ মিলত না।
     *
     * ⓘ দিকটা নিয়ে ভাবতে হয় না: [[PaymentService]] ক্রয়ের পরিশোধ
     * জানে, তাই টাকা **কমে** — বিক্রয়ের মতো বাড়ে না।
     *
     * ⚠️ উপায়ের `kind` → পরিশোধের `instrument`। ক্রেতা নতুন উপায় যোগ
     * করলে তার `kind` চেনা মানগুলোর একটা না হলে ঘরটা খালি যাবে, আর
     * "কীভাবে দেওয়া হলো" প্রশ্নের উত্তর হারাত।
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function payEachWay(array $rows, PurchaseBill $bill): ?Payment
    {
        $methods = PaymentMethod::query()
            ->whereIn('id', array_filter(array_column($rows, 'payment_method_id')))
            ->get()
            ->keyBy('id');

        $last = null;

        foreach ($rows as $row) {
            $amount = (string) ($row['amount'] ?? '0');

            if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $method = $methods->get((int) ($row['payment_method_id'] ?? 0));

            $payment = $this->payments->create(
                [
                    'supplier_id' => $bill->supplier_id,
                    'account_id' => $row['account_id'] ?? null,
                    'trx_date' => $bill->trx_date,
                    'amount' => $amount,
                    'instrument' => $method?->kind,
                    'instrument_no' => $row['reference'] ?? null,
                    'instrument_date' => $row['ref_date'] ?? null,
                    'narration' => $row['narration']
                        ?? __('purchase::message.paid_against', ['no' => $bill->document_no]),
                ],
                [['purchase_bill_id' => $bill->id, 'amount' => $amount]],
            );

            $last = $this->payments->confirm($payment);
        }

        return $last;
    }

    /**
     * একটা পণ্যের এখনকার অবস্থা — পর্দার উপরের স্ট্রিপের জন্য।
     *
     * @return array<string, mixed>
     */
    public function stockPanel(Product $product, ?Warehouse $warehouse = null): array
    {
        $warehouse ??= Warehouse::query()->where('is_default', true)->first();

        $onHand = $warehouse === null
            ? '0'
            : $this->stock->availableQty($product, $warehouse);

        return [
            'id' => $product->id,
            'name' => $product->name(),
            'code' => $product->code,
            'on_hand' => (float) $onHand,

            // শেষ যে দামে কেনা হয়েছিল — নতুন দর বসানোর সময় এটাই মাপকাঠি
            'last_rate' => (float) ($product->purchase_price ?? 0),
            'sales_price' => (float) ($product->sale_price ?? 0),
        ];
    }

    public function companyId(): int
    {
        return CompanyContext::id();
    }
}
