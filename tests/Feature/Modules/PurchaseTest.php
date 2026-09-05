<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Purchase — Phase 7।
 *
 * প্ল্যানে এই ধাপের শেষ হওয়ার শর্ত দুইটা: **PO → GRN → Bill** কাজ করবে,
 * আর **সরবরাহকারীর প্রদেয় নিজে থেকে মেলে**। এখানকার পরীক্ষাগুলো ঠিক ওই
 * দুইটাই যাচাই করে, আর তার সাথে ওই পথের প্রতিটা জায়গা যেখানে ভুল হলে
 * টাকা বা মাল হারিয়ে যেত।
 */
class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function orders(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function receipts(): PurchaseReceiptService
    {
        return app(PurchaseReceiptService::class);
    }

    private function bills(): PurchaseBillService
    {
        return app(PurchaseBillService::class);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $rows = LedgerEntry::query()->where('account_id', $account->id)->get();

        return $rows->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    private function makeOrder(string $qty = '100', string $rate = '50'): PurchaseOrder
    {
        return $this->orders()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => $rate]],
        );
    }

    private function makeReceipt(?PurchaseOrder $order, string $qty = '100', string $rate = '50'): PurchaseReceipt
    {
        return $this->receipts()->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_order_id' => $order?->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'received_qty' => $qty,
                'rate' => $rate,
                'purchase_order_line_id' => $order?->lines->first()->id,
            ]],
        );
    }

    /**
     * গুদামে মোট কত — তাক আর বসার অপেক্ষা, দুইটা মিলে।
     *
     * ── কেন `floorQty` নয় ───────────────────────────────────────────
     * Stock Placement আসার পর (৫ সেপ্টেম্বর ২০২৬) ক্রয়ের মাল আর
     * সরাসরি তাকে ওঠে না — কেউ বুঝে নেওয়ার আগে অপেক্ষার ঘরে বসে।
     *
     * ⭐ এই ফাইলের দাবিগুলো *"মাল এল / ফিরে গেল"* নিয়ে, *"মাল বেচা
     * যায়"* নিয়ে নয়। ⛔ তাই `place()` ডেকে সবুজ করা হয়নি: ডাকলে
     * পরীক্ষাটা আর আসার কথাটা মাপত না, আর ওটাই এখানে মাপার জিনিস।
     */
    private function onHand(): string
    {
        return app(StockService::class)
            ->statesFor($this->product, $this->warehouse)['on_hand'];
    }

    private function makeBill(?PurchaseReceipt $receipt, string $qty = '100', string $rate = '50'): PurchaseBill
    {
        return $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'rate' => $rate,
                'purchase_receipt_line_id' => $receipt?->lines->first()->id,
            ]],
        );
    }

    // ── প্ল্যানের শেষ হওয়ার শর্ত ────────────────────────────────────────

    /**
     * PO → GRN → Bill, আর প্রতিটা ধাপে ঠিক যা হওয়ার কথা তা-ই হয়।
     *
     * এই একটা পরীক্ষাই পুরো মডিউলের মেরুদণ্ড: আদেশ কিছু নাড়ায় না, চালান
     * স্টক ও অপেক্ষমাণ দায় বসায়, আর বিল দায়টা সরবরাহকারীর নামে সরায়।
     */
    public function test_the_whole_path_from_order_to_bill(): void
    {
        $stock = app(StockService::class);
        $openingStock = $this->onHand();

        /*
         * খতিয়ানেও শুরুর অবস্থাটা ধরে রাখা হয়, স্টকের মতোই।
         *
         * খোলা মজুদ এখন খতিয়ানেও বসে (আগে বসত না — আট লাখ টাকার মাল
         * খাতার বাইরে পড়ে থাকত)। তাই মজুদ খাতের পরম মান আর শূন্য থেকে
         * শুরু হয় না, আর এই পরীক্ষার প্রশ্নটাও পরম মান নিয়ে নয় —
         * "এই তিনটা কাগজ কতটা নাড়াল" নিয়ে।
         */
        $openingLedger = $this->balanceOf(StandardChart::INVENTORY);

        // ── আদেশ: কিছুই নড়ে না ──
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $this->assertSame(0, bccomp($this->onHand(), $openingStock, 4),
            'আদেশে স্টক নড়ার কথা নয়');
        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', PurchaseOrder::drillSourceType())->count(),
            'আদেশে খতিয়ানে কিছু বসার কথা নয়');

        // ── চালান: স্টক বাড়ে, অপেক্ষমাণ দায় জন্মায় ──
        $receipt = $this->receipts()->confirm($this->makeReceipt($order, '100', '50'));

        $this->assertSame(0, bccomp(
            $this->onHand(),
            bcadd($openingStock, '100', 4),
            4,
        ));

        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $openingLedger, 4),
            '5000',
            4,
        ));
        // দায় ক্রেডিটে, তাই ডেবিট − ক্রেডিট ঋণাত্মক
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '-5000', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PAYABLE), '0', 4),
            'বিল আসার আগে সরবরাহকারীর নামে কিছু বসার কথা নয়');

        // ── বিল: দায় সরবরাহকারীর নামে যায় ──
        $this->bills()->confirm($this->makeBill($receipt, '100', '50'));

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '0', 4),
            'বিলের পর অপেক্ষমাণ খাতটা শূন্যে ফিরতে হবে');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PAYABLE), '-5000', 4));

        // মজুদ একবারই বেড়েছে — বিল নতুন করে মাল আনে না
        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $openingLedger, 4),
            '5000',
            4,
        ));
    }

    /**
     * সরবরাহকারীর প্রদেয় নিজে থেকেই মেলে।
     *
     * সংখ্যাটা কোথাও জমা থাকে না, খতিয়ান থেকে গোনা হয় — তাই বিল বসানোর
     * সাথে সাথেই সরবরাহকারীর পাতায় দেখা যায়, কেউ আলাদা করে কিছু না
     * করলেও।
     */
    public function test_the_supplier_payable_reconciles_by_itself(): void
    {
        $before = $this->supplier->fresh()->payable();

        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '20', '125'));
        $this->bills()->confirm($this->makeBill($receipt, '20', '125'));

        $after = $this->supplier->fresh()->payable();

        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '2500', 4));
    }

    // ── অপেক্ষমাণ দায় ───────────────────────────────────────────────────

    /**
     * আংশিক বিল হলে বাকিটা অপেক্ষমাণ খাতেই থাকে।
     *
     * ১০০ বস্তা এসেছে, ৪০ বস্তার বিল এসেছে — বাকি ৬০ বস্তার দায় এখনো
     * কারও নামে নয়, আর সেটাই ঠিক: ওগুলোর বিল আসেনি।
     */
    public function test_a_partial_bill_leaves_the_rest_pending(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '100', '50'));

        $this->bills()->confirm($this->makeBill($receipt, '40', '50'));

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '-3000', 4),
            '৬০ বস্তা × ৫০ = ৩,০০০ এখনো ঝুলে আছে');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PAYABLE), '-2000', 4));
    }

    /**
     * এক চালানের একই লাইন দুইবার বিল করা যায় না।
     *
     * পারলে সরবরাহকারীকে একই মালের দাম দুইবার দেওয়া হত, আর অপেক্ষমাণ
     * খাতটা ঋণাত্মক হয়ে যেত — এমন একটা দায় যা কেউ কোনোদিন বসায়নি।
     */
    public function test_the_same_receipt_line_cannot_be_billed_twice(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '50', '50'));

        $this->bills()->confirm($this->makeBill($receipt, '50', '50'));

        $this->expectException(ValidationException::class);

        $this->makeBill($receipt, '1', '50');
    }

    /**
     * বিলের দর আলাদা হলে পার্থক্যটা আলাদা খাতে যায়, মজুদে নয়।
     *
     * মজুদে ঢোকালে গুদামের একই মালের দুই রকম দাম হয়ে যেত, অথচ মালটা একই।
     */
    public function test_a_price_difference_goes_to_its_own_account(): void
    {
        // এই কোম্পানিতে দামের অমিল আটকানো বন্ধ — নাহলে বিলটাই বসত না
        app(SettingsService::class)->set('purchase.block_price_mismatch', false);

        // মজুদ খাতে খোলা মজুদের টাকাও বসে আছে, তাই পরিবর্তনটাই মাপা হয়
        $inventoryBefore = $this->balanceOf(StandardChart::INVENTORY);

        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '100', '50'));

        // সরবরাহকারী ৫২ টাকা দরে বিল পাঠালেন
        $this->bills()->confirm($this->makeBill($receipt, '100', '52'));

        // অপেক্ষমাণ খাত শূন্যে ফিরেছে চালানের দরেই (১০০ × ৫০)
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '0', 4));

        // পার্থক্যের ২০০ টাকা খরচে
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PURCHASE_PRICE_VARIANCE), '200', 4));

        // মজুদ চালানের দামেই আছে
        $this->assertSame(0, bccomp(
            bcsub($this->balanceOf(StandardChart::INVENTORY), $inventoryBefore, 4),
            '5000',
            4,
        ));

        // সরবরাহকারীকে দিতে হবে বিলের পুরোটাই
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PAYABLE), '-5200', 4));
    }

    /** ডিফল্টে দামের অমিল আটকে দেওয়া হয়। */
    public function test_a_price_mismatch_is_blocked_by_default(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '100', '50'));

        $this->expectException(ValidationException::class);

        $this->bills()->confirm($this->makeBill($receipt, '100', '52'));
    }

    // ── আদেশের নিয়ম ─────────────────────────────────────────────────────

    /**
     * আদেশের চেয়ে বেশি মাল নেওয়া যায় না।
     */
    public function test_more_than_ordered_cannot_be_received(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $this->expectException(ValidationException::class);

        $this->makeReceipt($order, '101', '50');
    }

    /**
     * ছাড়ের হার বাড়ালে সামান্য বেশি নেওয়া যায়।
     *
     * বস্তায় ভরা মালে দুই-এক শতাংশ এদিক-ওদিক হয়। প্রতিবার আদেশ সংশোধন
     * করতে বললে গুদামের লোক শেষে আদেশ ছাড়াই মাল নামাতে শুরু করেন —
     * অর্থাৎ নিয়ন্ত্রণ বেশি কড়া করলে নিয়ন্ত্রণটাই উঠে যায়।
     */
    public function test_the_allowance_lets_a_little_more_through(): void
    {
        app(SettingsService::class)->set('purchase.over_receipt_percent', 5);

        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $receipt = $this->makeReceipt($order, '104', '50');

        $this->assertSame(0, bccomp((string) $receipt->lines->first()->received_qty, '104', 4));
    }

    /** এক আদেশের মাল কিস্তিতে আসতে পারে। */
    public function test_an_order_can_arrive_in_instalments(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $this->receipts()->confirm($this->makeReceipt($order, '60', '50'));
        $this->receipts()->confirm($this->makeReceipt($order, '40', '50'));

        $line = $order->lines()->first();

        $this->assertSame(0, bccomp($line->receivedQty(), '100', 4));
        $this->assertSame(0, bccomp($line->pendingQty(), '0', 4));
    }

    public function test_goods_cannot_be_received_against_a_draft_order(): void
    {
        $order = $this->makeOrder();

        $this->expectException(ValidationException::class);

        $this->makeReceipt($order);
    }

    /** সেটিংস চালু থাকলে আদেশ ছাড়া মাল নেওয়া যায় না। */
    public function test_the_setting_can_require_an_order(): void
    {
        app(SettingsService::class)->set('purchase.receipt_needs_order', true);

        $this->expectException(ValidationException::class);

        $this->makeReceipt(null);
    }

    /** ডিফল্টে আদেশ ছাড়াই মাল নেওয়া যায় — ছোট ডিপোর বাস্তবতা। */
    public function test_goods_can_arrive_without_an_order_by_default(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '30', '50'));

        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status);
        $this->assertNull($receipt->purchase_order_id);
    }

    // ── বাতিল ───────────────────────────────────────────────────────────

    /**
     * চালান বাতিল হলে স্টক ও খতিয়ান দুটোই উল্টো সারিতে ফেরে।
     */
    public function test_cancelling_a_receipt_reverses_both_stock_and_books(): void
    {
        $stock = app(StockService::class);
        $before = $this->onHand();
        $inventoryBefore = $this->balanceOf(StandardChart::INVENTORY);

        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '40', '50'));

        $this->receipts()->cancel($receipt, 'ভুল পণ্য এসেছিল');

        $this->assertSame(0, bccomp($this->onHand(), $before, 4));

        // বাতিলের পর মজুদ খাত ঠিক যেখানে ছিল সেখানেই — শূন্যে নয়,
        // কারণ খোলা মজুদের টাকা ওখানে আগে থেকেই বসা
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::INVENTORY), $inventoryBefore, 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '0', 4));

        // সারি মোছা হয়নি — উল্টো সারি বসেছে
        $this->assertGreaterThan(0, LedgerEntry::query()
            ->where('source_type', PurchaseReceipt::drillSourceType().':reversal')->count());
    }

    /**
     * বিল হয়ে যাওয়া চালান বাতিল করা যায় না — ক্রমটা উল্টো দিকে।
     */
    public function test_a_billed_receipt_cannot_be_cancelled(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '40', '50'));
        $this->bills()->confirm($this->makeBill($receipt, '40', '50'));

        $this->expectException(ValidationException::class);

        $this->receipts()->cancel($receipt, 'ভুল হয়েছিল');
    }

    /** বিল বাতিল হলে দায়টা অপেক্ষমাণ খাতে ফেরে। */
    public function test_cancelling_a_bill_returns_the_liability_to_pending(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '40', '50'));
        $bill = $this->bills()->confirm($this->makeBill($receipt, '40', '50'));

        $this->bills()->cancel($bill, 'ভুল বিল');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::PAYABLE), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::GOODS_RECEIVED_NOT_INVOICED), '-2000', 4));
    }

    /**
     * বেচা হয়ে যাওয়া মালের চালান বাতিল করলে ঋণাত্মক স্টক হত।
     */
    public function test_a_receipt_cannot_be_cancelled_once_the_goods_are_gone(): void
    {
        $receipt = $this->receipts()->confirm($this->makeReceipt(null, '40', '50'));

        // মালটা বেরিয়ে গেছে
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test_issue', sourceId: 1,
            floor: bcmul(app(StockService::class)->floorQty($this->product, $this->warehouse), '-1', 4),
        );

        $this->expectException(ValidationException::class);

        $this->receipts()->cancel($receipt, 'ফেরত পাঠাব');
    }

    // ── সরবরাহকারীর বিল নম্বর ───────────────────────────────────────────

    /**
     * একই সরবরাহকারীর একই বিল নম্বর দুইবার নয়।
     *
     * না আটকালে একই মালের দাম দুইবার শোধ হয়ে যেত, আর ধরা পড়ত অনেক পরে।
     */
    public function test_the_same_supplier_bill_number_cannot_come_twice(): void
    {
        $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'INV-9911'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        );

        $this->expectException(ValidationException::class);

        $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'INV-9911'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        );
    }

    // ── অঙ্ক ────────────────────────────────────────────────────────────

    /** ছাড় ভ্যাটের আগে বসে — যা নেওয়া হয়নি তার উপর ভ্যাট হয় না। */
    public function test_the_discount_comes_off_before_the_tax(): void
    {
        $bill = $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100',
                'discount' => '100', 'tax' => '135']],
        );

        // ১০ × ১০০ = ১,০০০ − ১০০ ছাড় = ৯০০, তার ১৫% ভ্যাট ১৩৫ → ১,০৩৫
        $this->assertSame(0, bccomp((string) $bill->subtotal, '1000', 4));
        $this->assertSame(0, bccomp((string) $bill->total, '1035', 4));
    }

    public function test_a_line_with_no_quantity_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '0', 'rate' => '50']],
        );
    }

    public function test_a_discount_larger_than_the_line_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '50', 'discount' => '60']],
        );
    }

    // ── অবস্থা ও সম্পাদনা ───────────────────────────────────────────────

    public function test_a_confirmed_document_cannot_be_edited(): void
    {
        $order = $this->orders()->confirm($this->makeOrder());

        $this->expectException(ValidationException::class);

        $this->orders()->update($order, ['trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '5', 'rate' => '50']]);
    }

    public function test_a_document_cannot_be_confirmed_twice(): void
    {
        $order = $this->orders()->confirm($this->makeOrder());

        $this->expectException(ValidationException::class);

        $this->orders()->confirm($order);
    }

    // ── টেন্যান্ট ও অনুমতি ──────────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_purchases(): void
    {
        $order = $this->makeOrder();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(PurchaseOrder::query()->find($order->id));
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_a_user_without_the_permission_cannot_reach_any_screen(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $order = $this->makeOrder();

        foreach ([
            route('purchase.order.index'),
            route('purchase.order.show', $order),
            route('purchase.receipt.index'),
            route('purchase.bill.index'),
            route('purchase.report.show', 'uninvoiced'),
        ] as $url) {
            $this->actingAs($stranger)->get($url)->assertForbidden();
        }
    }

    // ── পর্দা ───────────────────────────────────────────────────────────

    public function test_creating_an_order_through_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('purchase.order.store'), [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'ordered_qty' => '12', 'rate' => '75'],
                ],
            ])
            ->assertRedirect();

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();

        $this->assertStringStartsWith('PO-', $order->document_no);
        $this->assertSame(0, bccomp((string) $order->total, '900', 4));
    }

    public function test_the_uninvoiced_report_shows_what_is_still_pending(): void
    {
        $this->receipts()->confirm($this->makeReceipt(null, '100', '50'));

        $result = app(ReportEngine::class)->run('purchase.uninvoiced', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertCount(1, $result->rows);

        $row = (array) $result->rows[0];

        $this->assertSame(0, bccomp((string) $row['unbilled_qty'], '100', 4));
        $this->assertSame(0, bccomp((string) $row['unbilled_value'], '5000', 4));
    }

    // ── ক্রয়ের কাগজে বিক্রয়মূল্য ──────────────────────────────────────

    /**
     * বিলে দেওয়া বিক্রয়মূল্য পণ্যের দামে গিয়ে বসে।
     *
     * মালিকের কথা: "direct purchase-এর সময়েই sales price দেব।" ট্রাক গেটে
     * দাঁড়িয়ে, নতুন দরে মাল এসেছে, আর ওই দর দেখেই ঠিক হয় আজ কত দামে বেচা
     * হবে। আলাদা পর্দায় পাঠালে মাঝের সময়টুকু পুরনো দামে বিক্রি চলত।
     */
    public function test_a_sales_price_on_the_bill_reaches_the_product(): void
    {
        $before = (string) $this->product->fresh()->sale_price;

        $bill = $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50', 'sales_price' => '72.50']],
        );

        $this->bills()->confirm($bill);

        $this->assertSame(0, bccomp((string) $this->product->fresh()->sale_price, '72.50', 4));

        // আগের দামটা ৭২.৫০ ছিল না — নইলে "বদলেছে" প্রমাণ করার কিছু থাকত
        // না। উপরে ছিল না নিচে, সেটা প্রশ্ন নয়; সমান না হলেই যথেষ্ট।
        $this->assertNotSame(0, bccomp($before, '72.50', 4),
            'পরীক্ষাটা অর্থহীন হত যদি দামটা আগেই ৭২.৫০ থাকত');
    }

    /**
     * দাম না বললে দাম বদলায় না — শূন্য করে দেয় না।
     *
     * null মানে "দাম বদলাব না", শূন্য মানে "বিনামূল্যে"। দুইটা এক করে
     * ফেললে দাম না বদলাতে চাওয়া প্রতিটা লাইনই পণ্যটার দাম শূন্য করে দিত,
     * আর তারপর প্রতিটা বিক্রয়ে পুরো টাকাটাই ছাড় হয়ে যেত।
     */
    public function test_a_bill_without_a_sales_price_leaves_the_product_alone(): void
    {
        $before = (string) $this->product->fresh()->sale_price;

        $this->bills()->confirm($this->makeBill(null, '10', '50'));

        $this->assertSame(0, bccomp((string) $this->product->fresh()->sale_price, $before, 4));
        $this->assertSame(1, bccomp($before, '0', 4), 'শুরুর দামটা শূন্য হলে এই পরীক্ষা কিছুই প্রমাণ করত না');
    }

    /** তিনটা ঘরই বিলের পর্দায় আছে — দাম, markup, margin। */
    public function test_the_bill_screen_carries_the_three_pricing_boxes(): void
    {
        $response = $this->get(route('purchase.bill.create'));

        $response->assertOk();
        $response->assertSee('sales_price', false);
        $response->assertSee(__('purchase::field.markup'), false);
        $response->assertSee(__('purchase::field.margin'), false);
    }

    // ── আদেশ থেকে সরাসরি বিল (চালান ছাড়া) ──────────────────────────────

    /**
     * আদেশ ধরে বিলের ফর্ম খুললে সারিগুলো ভরে আসে।
     *
     * এতদিন কেবল চালান ধরে খোলা যেত। যে ডিপো মাল গ্রহণের কাগজ লেখে না
     * তার আদেশ বিলে পৌঁছাতই না — ঠিকানায় ?purchase_order_id= দিলে ফর্ম
     * ফাঁকা আসত, আর পরীক্ষক সেটাকেই বাগ হিসেবে রিপোর্ট করেছিলেন।
     */
    public function test_the_bill_form_fills_from_an_order(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $response = $this->get(route('purchase.bill.create', ['purchase_order_id' => $order->id]))
            ->assertOk();

        // সরবরাহকারী আদেশ থেকে বেছে নেওয়া
        $response->assertSee('value="'.$this->supplier->id.'" selected', false);

        // আর পরিমাণ ও দর আদেশের সারি থেকে
        $this->assertStringContainsString('100', $response->getContent());
        $this->assertStringContainsString(__('purchase::field.order'), $response->getContent());
    }

    /**
     * আদেশের বিপরীতে বিল করলে মাল গুদামে ঢোকে।
     *
     * মাঝে কোনো গ্রহণের কাগজ নেই, তাই মালটা এই বিল নিশ্চিত করার সময়েই
     * ঢুকতে হবে — নইলে গুদামে মাল আছে অথচ খাতায় নেই।
     */
    public function test_billing_an_order_brings_the_goods_in(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));
        $stock = app(StockService::class);
        $before = $this->onHand();

        $bill = $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => '100',
                'rate' => '50',
                'purchase_order_line_id' => $order->lines->first()->id,
            ]],
        );

        $this->bills()->confirm($bill);

        $after = $this->onHand();
        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '100', 4));

        // জোড়াটা সত্যিই লেখা হয়েছে — নইলে আদেশটা অপেক্ষমাণই থেকে যেত
        $this->assertSame(
            $order->lines->first()->id,
            $bill->fresh(['lines'])->lines->first()->purchase_order_line_id,
        );
    }

    /**
     * বিল হয়ে গেলে আদেশটা আর "অপেক্ষমাণ" থাকে না।
     *
     * শুধু ফর্ম ভরালে এটা হত না: রিপোর্টটা কেবল চালানের পরিমাণ গুনত,
     * তাই বিল হয়ে যাওয়ার পরেও আদেশ তালিকায় বসে থাকত।
     */
    public function test_an_order_billed_directly_leaves_the_pending_list(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $pending = fn () => app(ReportEngine::class)
            ->run('purchase.pending_orders', [
                'from' => '2020-01-01',
                'to' => '2030-12-31',
            ])->totalRows;

        $this->assertGreaterThan(0, $pending(), 'নতুন আদেশটা অপেক্ষমাণ তালিকায় থাকার কথা।');

        $this->bills()->confirm($this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => '100',
                'rate' => '50',
                'purchase_order_line_id' => $order->lines->first()->id,
            ]],
        ));

        $this->assertSame(0, $pending(), 'পুরো বিল হয়ে যাওয়ার পরেও আদেশটা অপেক্ষমাণ দেখাচ্ছে।');
    }

    /** অন্য সরবরাহকারীর আদেশের সারি এই বিলে বসানো যায় না। */
    public function test_an_order_line_of_another_supplier_is_refused(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('100', '50'));

        $other = app(SupplierService::class)
            ->create(['name_en' => 'Another House', 'opening_balance' => 0]);

        $this->expectException(ValidationException::class);

        $this->bills()->create(
            ['supplier_id' => $other->id, 'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '50',
                'purchase_order_line_id' => $order->lines->first()->id,
            ]],
        );
    }
}
