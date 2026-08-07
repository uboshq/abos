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
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিক্রয় ফেরত — মাল গ্রাহকের কাছ থেকে ফিরে এসেছে।
 *
 * ── এখানে চারটা জিনিস একসাথে নড়ে ────────────────────────────────────
 * মাল গুদামে ফেরে, গ্রাহকের পাওনা কমে, আয় কমে (আলাদা খাতে), আর
 * বিক্রীত পণ্যের ব্যয়ও ফেরত আসে। চারটার একটাও বাদ পড়লে খাতা মেলে না,
 * অথচ পর্দায় সব ঠিক দেখাত।
 */
class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

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

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ফেরত এলে মাল ফেরে, পাওনা কমে, আয় কমে, আর ব্যয়ও ফেরে।
     */
    public function test_a_return_moves_the_goods_the_money_and_the_cost(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $stockBefore = $this->availableQty();
        $receivableBefore = $this->balanceOf(StandardChart::RECEIVABLE);
        $cogsBefore = $this->balanceOf(StandardChart::COST_OF_GOODS_SOLD);

        $this->returns()->confirm(
            $this->returns()->create(
                [
                    'customer_id' => $this->customer->id,
                    'warehouse_id' => $this->warehouse->id,
                    'sales_invoice_id' => $invoice->id,
                    'trx_date' => now()->toDateString(),
                ],
                [[
                    'product_id' => $this->product->id,
                    'sales_invoice_line_id' => $invoice->lines->first()->id,
                    'qty' => '2',
                ]],
            )
        );

        // দুই বস্তা গুদামে ফিরল
        $this->assertSame(0, bccomp(bcadd($stockBefore, '2', 4), $this->availableQty(), 4));

        // ৪০০ টাকার পাওনা কমল (২ × ২০০)
        $this->assertSame(0, bccomp(
            bcsub($receivableBefore, '400', 4),
            $this->balanceOf(StandardChart::RECEIVABLE),
            4,
        ));

        // আয় কমল — কিন্তু বিক্রয় খাতে নয়, ফেরতের নিজের খাতে
        $this->assertSame(0, bccomp('400', $this->balanceOf(StandardChart::SALES_RETURN), 4));

        // আর ব্যয়ও ফিরল — দুই বস্তার ক্রয়মূল্য
        $cost = bcmul('2', (string) $this->product->purchase_price, 4);
        $this->assertSame(0, bccomp(
            bcsub($cogsBefore, $cost, 4),
            $this->balanceOf(StandardChart::COST_OF_GOODS_SOLD),
            4,
        ));
    }

    /**
     * মোট বিক্রয়ের অঙ্ক ফেরতে ছোট হয় না।
     *
     * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────────
     * ৪১০০-এ সরাসরি ডেবিট বসালে "এই মাসে কত বেচলাম" সংখ্যাটাই বদলে
     * যেত, আর "কতটা ফেরত এল" প্রশ্নের উত্তর হারাত। ফেরতের হার বেড়ে
     * গেলে সেটা চোখে পড়া দরকার।
     */
    public function test_the_sales_figure_itself_is_not_quietly_reduced(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $salesBefore = $this->balanceOf(StandardChart::SALES);

        $this->returns()->confirm(
            $this->returns()->create(
                // মূল বিলটা এখন বাধ্যতামূলক: কোন বিলের মাল ফিরছে না
                // জানলে ওই মালের খরচ কত ছিল তাও জানা যায় না
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'sales_invoice_id' => $invoice->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id,
                    'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '2']],
            )
        );

        $this->assertSame(0, bccomp($salesBefore, $this->balanceOf(StandardChart::SALES), 4));
    }

    /**
     * নষ্ট মাল ফিরলে গুদামে ঢোকে, কিন্তু আবার বেচা যায় না।
     *
     * ── কেন floor আর hold দুইটাই বাড়ে ───────────────────────────────
     * মালটা গুদামে সত্যিই আছে — গুনতে গেলে পাওয়া যাবে। কিন্তু
     * বিক্রয়যোগ্য নয়। দুইটাই সত্যি, তাই দুইটাই লেখা হয়।
     */
    public function test_damaged_goods_come_back_but_stay_unsellable(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $availableBefore = $this->availableQty();
        $floorBefore = app(StockService::class)->floorQty($this->product, $this->warehouse);

        $this->returns()->confirm(
            $this->returns()->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id,
                    'sales_invoice_line_id' => $invoice->lines->first()->id,
                    'qty' => '2', 'to_hold' => true]],
            )
        );

        // তাকে মাল বেড়েছে
        $this->assertSame(0, bccomp(
            bcadd($floorBefore, '2', 4),
            app(StockService::class)->floorQty($this->product, $this->warehouse),
            4,
        ));

        // কিন্তু বিক্রয়যোগ্য বাড়েনি — ওটা Hold-এ
        $this->assertSame(0, bccomp($availableBefore, $this->availableQty(), 4));
    }

    /**
     * যত বেচা হয়েছে তার বেশি ফেরত নয়।
     */
    public function test_more_cannot_come_back_than_went_out(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $return = $this->returns()->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '12']],
        );

        $this->expectException(ValidationException::class);

        $this->returns()->confirm($return);
    }

    /**
     * দর বিল থেকেই আসে।
     */
    public function test_the_rate_comes_from_the_invoice(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $return = $this->returns()->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'qty' => '2', 'rate' => '9999']],
        );

        $this->assertSame('200.0000', (string) $return->lines->first()->rate);
    }

    /**
     * বাতিল করলে সব উল্টে যায় — মাল, পাওনা, আয় আর ব্যয়।
     */
    public function test_cancelling_reverses_everything(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $return = $this->returns()->confirm(
            $this->returns()->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id,
                    'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '2']],
            )
        );

        $stockAfter = $this->availableQty();
        $receivableAfter = $this->balanceOf(StandardChart::RECEIVABLE);

        $this->returns()->cancel($return, 'ভুল করে ফেরত বসানো হয়েছিল');

        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status);
        $this->assertSame(0, bccomp(bcsub($stockAfter, '2', 4), $this->availableQty(), 4));
        $this->assertSame(0, bccomp(
            bcadd($receivableAfter, '400', 4),
            $this->balanceOf(StandardChart::RECEIVABLE),
            4,
        ));
        $this->assertSame(0, bccomp('0', $this->balanceOf(StandardChart::SALES_RETURN), 4));
    }

    /**
     * পর্দাগুলো খোলে।
     */
    public function test_the_screens_open(): void
    {
        $invoice = $this->confirmedInvoice(qty: '10', rate: '200');

        $return = $this->returns()->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '2']],
        );

        $this->get(route('sales.return.index'))->assertOk()->assertSee($return->document_no);
        $this->get(route('sales.return.create'))->assertOk();
        $this->get(route('sales.return.show', $return))->assertOk();
        $this->get(route('sales.return.edit', $return))->assertOk();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function returns(): SalesReturnService
    {
        return app(SalesReturnService::class);
    }

    private function confirmedInvoice(string $qty, string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm(
            $service->create(
                [
                    'customer_id' => $this->customer->id,
                    'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString(),
                ],
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
