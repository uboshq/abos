<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
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
     * @return array{bill: PurchaseBill, payment: Payment|null}
     */
    public function complete(array $data, array $lines): array
    {
        $lines = array_values(array_filter(
            $lines,
            fn (array $line) => filled($line['product_id'] ?? null) && (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $bill = $this->bills->create($data, $this->billLines($lines));

            /*
             * বিলটা এখানেই নিশ্চিত — খসড়া রেখে দেওয়ার মানে নেই।
             *
             * মালটা তো নেমেই গেছে। খসড়া রাখলে গুদামে মাল আছে অথচ খাতায়
             * নেই, আর ওই ফাঁকটায় বিক্রি হলে মজুদ ঋণাত্মক হত।
             */
            $bill = $this->bills->confirm($bill);

            $this->stampSalesPrices($lines);

            $payment = $this->payNow($data, $bill);

            return ['bill' => $bill->fresh(['lines']), 'payment' => $payment];
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
            'discount' => (string) ($line['discount'] ?? '0'),
            'tax' => (string) ($line['tax'] ?? '0'),
            'sales_price' => filled($line['sales_price'] ?? null) ? (string) $line['sales_price'] : null,
            'narration' => $line['narration'] ?? null,
        ], $lines);
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
