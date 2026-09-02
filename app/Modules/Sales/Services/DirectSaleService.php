<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchAllocator;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
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
 *     আদায়            — কাউন্টারে টাকা নিলে, ততটুকুই
 *
 * তিনটাই বিদ্যমান সেবা দিয়ে — DeliveryChallanService, SalesInvoiceService,
 * CollectionService। নিজে পোস্ট করলে একদিন এখানে বিক্রীত পণ্যের ব্যয় বসত
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
        private readonly CollectionService $collections,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly BatchAllocator $batches,
    ) {}

    /**
     * একটা সরাসরি বিক্রি সম্পূর্ণ করা।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, change: string}
     */
    public function complete(array $data, array $lines, array $gifts = []): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);
        $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? null);

        return DB::transaction(function () use ($data, $lines, $gifts, $customer, $warehouse) {
            $challan = $this->challans->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
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

            $challan = $this->challans->confirm($challan->fresh(['lines']));

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

            $invoice = $this->invoices->confirm($invoice);

            $deposit = $this->money($data['deposit'] ?? '0');
            $total = (string) $invoice->total;

            $applied = bccomp($deposit, $total, 4) > 0 ? $total : $deposit;
            $change = bccomp($deposit, $total, 4) > 0 ? bcsub($deposit, $total, 4) : '0.0000';

            if (bccomp($applied, '0', 4) > 0) {
                $this->collections->confirm($this->collections->create(
                    [
                        'customer_id' => $customer->id,
                        'account_id' => $data['account_id'] ?? null,
                        'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                        'amount' => $applied,
                        'narration' => __('sales::message.direct_narration', ['no' => $challan->document_no]),
                    ],
                    [['sales_invoice_id' => $invoice->id, 'amount' => $applied]],
                ));
            }

            return [
                'challan' => $challan->fresh(['lines', 'giftLines']),
                'invoice' => $invoice->fresh(['lines']),
                'change' => $change,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function challanLines(array $lines): array
    {
        return array_values(array_map(fn (array $line) => [
            'product_id' => (int) $line['product_id'],
            'delivered_qty' => (string) $line['qty'],
            'rate' => (string) $line['rate'],

            // প্যাকটা চালান পর্যন্ত যায়, আর সেখানেই একবার নামে
            'unit_id' => $line['unit_id'] ?? null,
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
            'rounding_amount' => $this->money($data['rounding_amount'] ?? '0'),
            'deposit_amount' => $this->money($data['deposit'] ?? '0'),
            'credit_period_days' => $data['credit_period_days'] ?? null,

            /*
             * ছয়টা বোতামের ঘরগুলো — ২৯ আগস্ট ২০২৬।
             *
             * ── কেন `?: null`, `?? null` নয় ──────────────────────────
             * ফর্ম খালি ঘরও পাঠায়, খালি স্ট্রিং হিসেবে। `??` কেবল
             * অনুপস্থিত হলে ধরত, তাই ডাটাবেজে খালি স্ট্রিং বসত — আর
             * তখন "লেখা হয়নি" আর "ফাঁকা লেখা হয়েছে" আলাদা করা যেত না।
             */
            'expense_narration' => ($data['expense_narration'] ?? '') ?: null,
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
            'deposit_method' => bccomp($this->money($data['deposit'] ?? '0'), '0', 4) > 0
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
        $from = Carbon::parse($data['trx_date'] ?? now());

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
