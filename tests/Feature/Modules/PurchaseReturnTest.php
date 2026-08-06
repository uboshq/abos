<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

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
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফেরত।
 *
 * ── কেন এটা দরকার ছিল ───────────────────────────────────────────────
 * এতদিন ফেরতের কোনো কাগজ ছিল না। মাল নষ্ট বেরোলে হয় পুরো বিলটা বাতিল
 * করতে হত (অথচ বাকি মালটা গুদামেই আছে আর তার টাকাও দিতে হবে), নয়
 * স্টক ও খাতা হাতে ঠিক করতে হত।
 */
class PurchaseReturnTest extends TestCase
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

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ফেরত দিলে মাল গুদাম ছাড়ে আর প্রদেয় কমে — একই সাথে।
     */
    public function test_a_return_takes_the_goods_out_and_lowers_the_payable(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');

        $stockBefore = $this->availableQty();
        $payableBefore = $this->balanceOf(StandardChart::PAYABLE);
        $inventoryBefore = $this->balanceOf(StandardChart::INVENTORY);

        $this->returns()->confirm(
            $this->returns()->create(
                [
                    'supplier_id' => $this->supplier->id,
                    'warehouse_id' => $this->warehouse->id,
                    'purchase_bill_id' => $bill->id,
                    'trx_date' => now()->toDateString(),
                ],
                [[
                    'product_id' => $this->product->id,
                    'purchase_bill_line_id' => $bill->lines->first()->id,
                    'qty' => '2',
                ]],
            )
        );

        // দুই বস্তা গুদাম ছাড়ল
        $this->assertSame(0, bccomp(bcsub($stockBefore, '2', 4), $this->availableQty(), 4));

        // ১০০ টাকার দায় কমল (২ × ৫০), তাই ব্যালেন্স ডেবিটের দিকে সরে
        $this->assertSame(0, bccomp(
            bcadd($payableBefore, '100', 4),
            $this->balanceOf(StandardChart::PAYABLE),
            4,
        ));

        // আর মজুদের মূল্যও ততটাই কমল
        $this->assertSame(0, bccomp(
            bcsub($inventoryBefore, '100', 4),
            $this->balanceOf(StandardChart::INVENTORY),
            4,
        ));
    }

    /**
     * যত কেনা হয়েছে তার বেশি ফেরত নয়।
     */
    public function test_more_cannot_be_returned_than_was_bought(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');

        $return = $this->returns()->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_bill_id' => $bill->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'purchase_bill_line_id' => $bill->lines->first()->id,
                'qty' => '12',
            ]],
        );

        $this->expectException(ValidationException::class);

        $this->returns()->confirm($return);
    }

    /**
     * দুইটা ফেরত মিলেও বিলের পরিমাণ ছাড়াতে পারে না।
     *
     * প্রথমটা খাতায় বসার পর দ্বিতীয়টার জায়গা কমে যায় — না দেখলে
     * দুইটা আলাদা কাগজে দশ বস্তার বিলে ষোলো বস্তা ফেরত যেত।
     */
    public function test_two_returns_together_cannot_exceed_the_bill(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');
        $billLine = $bill->lines->first();

        $this->returns()->confirm($this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $billLine->id, 'qty' => '6']],
        ));

        $second = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $billLine->id, 'qty' => '6']],
        );

        $this->expectException(ValidationException::class);

        $this->returns()->confirm($second);
    }

    /**
     * গুদামে যা নেই তা ফেরত পাঠানো যায় না।
     */
    public function test_stock_that_is_not_there_cannot_be_returned(): void
    {
        $this->confirmedBill(qty: '10', rate: '50');

        $return = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '99999']],
        );

        $this->expectException(ValidationException::class);

        $this->returns()->confirm($return);
    }

    /**
     * দর বিল থেকেই আসে, হাতে লেখা দর নয়।
     *
     * হাতে বসাতে দিলে কেউ বেশি দরে ফেরত দেখিয়ে প্রদেয় বেশি কমাতে
     * পারত, আর মজুদের মূল্যও ভুল হত।
     */
    public function test_the_rate_comes_from_the_bill(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');

        $return = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'purchase_bill_line_id' => $bill->lines->first()->id,
                'qty' => '2',
                'rate' => '5000',   // ইচ্ছাকৃত ভুল দর
            ]],
        );

        $this->assertSame('50.0000', (string) $return->lines->first()->rate);
    }

    /**
     * বাতিল করলে মাল গুদামে ফিরে আসে আর দাখিলা উল্টে যায়।
     */
    public function test_cancelling_puts_the_goods_and_the_liability_back(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');

        $return = $this->returns()->confirm(
            $this->returns()->create(
                ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id,
                    'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => '2']],
            )
        );

        $stockAfterReturn = $this->availableQty();
        $payableAfterReturn = $this->balanceOf(StandardChart::PAYABLE);

        $this->returns()->cancel($return, 'ভুল করে ফেরত দেখানো হয়েছিল');

        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status);
        $this->assertSame(0, bccomp(bcadd($stockAfterReturn, '2', 4), $this->availableQty(), 4));
        $this->assertSame(0, bccomp(
            bcsub($payableAfterReturn, '100', 4),
            $this->balanceOf(StandardChart::PAYABLE),
            4,
        ));
    }

    /**
     * পর্দাগুলো খোলে।
     */
    public function test_the_screens_open(): void
    {
        $bill = $this->confirmedBill(qty: '10', rate: '50');

        $return = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id,
                'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => '2']],
        );

        $this->get(route('purchase.return.index'))->assertOk()->assertSee($return->document_no);
        $this->get(route('purchase.return.create'))->assertOk();
        $this->get(route('purchase.return.show', $return))->assertOk();
        $this->get(route('purchase.return.edit', $return))->assertOk();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function returns(): PurchaseReturnService
    {
        return app(PurchaseReturnService::class);
    }

    private function confirmedBill(string $qty, string $rate): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm(
            $service->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
            )
        )->load('lines');
    }

    private function availableQty(): string
    {
        return app(StockService::class)->availableQty($this->product, $this->warehouse);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0.0000',
            );
    }
}
