<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বাতিল আগের মাসের মজুদ-মূল্য বদলে দিত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️৫)।
 *
 * ⓘ [[CostLayerService::withdraw()]] ক্রয় বাতিলে স্তরটা মুছত, আর [[CostLayerService::undoReturn()]] ফেরত বাতিলে ফেরতের
 * ব্যবহার-সারি মুছত — দুটোই মূল তারিখসহ। খাতা উল্টো হয় আজকের তারিখে, তাই আগের মাসের মজুদ-মূল্য রিপোর্ট বদলাত, খাতা
 * বদলাত না: বন্ধ মাসের দুই সংখ্যা আলাদা। ⭐ এখন কিছুই মোছে না — আজকের তারিখে `…:cancel` ব্যবহার-সারি (বিক্রির বাতিলের
 * একই রীতি)। আগের মাস এক টাকাও নড়ে না, আর আজ স্তরের মোট আর খাতার মজুদ খাত (১১২০) একসাথে নড়ে।
 */
final class ACancelNeverRewritesAClosedMonthsStockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private string $lastMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString();
    }

    public function test_a_purchase_cancelled_today_leaves_last_months_value_alone_and_the_books_agree(): void
    {
        $product = $this->product('Cancel probe buy');
        $before = $this->snapshot();

        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id,
            'trx_date' => $this->lastMonth, 'supplier_bill_no' => 'W5-BUY-1',
        ], [['product_id' => $product->id, 'unit_id' => $product->unit_id, 'qty' => '10', 'rate' => '100', 'sales_price' => '150']])['bill'];

        $closedMonth = $this->monthValue($product);
        $layers = CostLayer::query()->where('product_id', $product->id)->count();
        $this->assertSame(0, bccomp($closedMonth, '1000', 4), 'প্রস্তুতিটাই ভুল — আগের মাসে ১০ × ১০০ ঢোকার কথা।');

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'W5 ভুল বিল');

        $this->assertSame(0, bccomp($this->monthValue($product), $closedMonth, 4), '⛔ আজকের বাতিল আগের মাসের মজুদ-মূল্য বদলে দিল।');
        $this->assertSame($layers, CostLayer::query()->where('product_id', $product->id)->count(), '⛔ বাতিলে খরচ-স্তর মুছে গেল।');
        $this->assertBooksAgree($before, 'ক্রয় বাতিলের পরে');
    }

    public function test_a_return_cancelled_today_leaves_last_months_value_alone_and_can_be_returned_again(): void
    {
        $product = $this->product('Cancel probe return');
        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id,
            'trx_date' => $this->lastMonth, 'supplier_bill_no' => 'W5-BUY-2',
        ], [['product_id' => $product->id, 'unit_id' => $product->unit_id, 'qty' => '10', 'rate' => '100', 'sales_price' => '150']])['bill'];
        $this->placeAll($product, (int) $bill->id);

        $sales = app(SalesInvoiceService::class);
        $invoice = $sales->confirm($sales->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => $this->lastMonth],
            [['product_id' => $product->id, 'qty' => '6', 'rate' => '150']],
        ))->load('lines');

        $returns = app(SalesReturnService::class);
        $return = $returns->confirm($returns->create($this->returnHead($invoice, $this->lastMonth), [$this->returnLine($product, $invoice, '4')]));

        $before = $this->snapshot();
        $closedMonth = $this->monthValue($product);
        $uses = CostLayerUse::query()->where('product_id', $product->id)->count();
        $this->assertSame(0, bccomp($closedMonth, '800', 4), 'প্রস্তুতিটাই ভুল — আগের মাস শেষে ১০ − ৬ + ৪ = ৮টা × ১০০।');

        $returns->cancel($return->fresh(), 'W5 ভুল ফেরত');

        $this->assertSame(0, bccomp($this->monthValue($product), $closedMonth, 4), '⛔ আজকের ফেরত-বাতিল আগের মাসের মজুদ-মূল্য বদলে দিল।');
        $this->assertGreaterThan($uses, CostLayerUse::query()->where('product_id', $product->id)->count(), '⛔ ফেরতের ব্যবহার-সারি মুছে গেল।');
        $this->assertBooksAgree($before, 'ফেরত বাতিলের পরে', '-400');

        // ⓘ বাতিল ফেরত আর "আগে ফিরেছে" নয় — একই বিলের ৪টা আবার ফেরত নেওয়া যায়
        $again = $returns->confirm($returns->create($this->returnHead($invoice, now()->toDateString()), [$this->returnLine($product, $invoice, '4')]));
        $this->assertSame(SalesReturn::query()->find($again->id)->status, 'confirmed', '⛔ বাতিল ফেরতটা এখনো "আগে ফিরেছে" গোনা হল।');
    }

    public function test_a_goods_receipt_cancelled_today_leaves_last_months_value_alone(): void
    {
        $product = $this->product('Cancel probe receipt');
        $before = $this->snapshot();
        $receipts = app(\App\Modules\Purchase\Services\PurchaseReceiptService::class);

        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => $this->lastMonth],
            [['product_id' => $product->id, 'received_qty' => '5', 'rate' => '80']],
        ));
        $closedMonth = $this->monthValue($product);
        $this->assertSame(0, bccomp($closedMonth, '400', 4), 'প্রস্তুতিটাই ভুল — আগের মাসে ৫ × ৮০।');

        $receipts->cancel($receipt->fresh(), 'W5 ভুল চালান');

        $this->assertSame(0, bccomp($this->monthValue($product), $closedMonth, 4), '⛔ আজকের চালান-বাতিল আগের মাসের মজুদ-মূল্য বদলে দিল।');
        $this->assertSame(1, CostLayer::query()->where('product_id', $product->id)->count(), '⛔ চালান-বাতিলে খরচ-স্তর মুছে গেল।');
        $this->assertBooksAgree($before, 'চালান বাতিলের পরে');
    }

    /**
     * ⓘ পোস্ট হওয়া বিল সম্পাদনা = পুরনো স্তর বাতিল, নতুন বসানো, একই বিলের নামে। পরে বাতিলে আগে খালি হওয়া স্তর আবার
     * ধরলে "ছোঁয়া স্তর" বলে থামত — সেগুলো বাদ, কেবল নতুনগুলো খালি হয়।
     */
    public function test_a_bill_edited_after_posting_and_then_cancelled_empties_only_its_live_layers(): void
    {
        $product = $this->product('Cancel probe edit');
        $before = $this->snapshot();
        $head = ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id,
            'trx_date' => $this->lastMonth, 'supplier_bill_no' => 'W5-EDIT-1'];
        $line = fn (string $qty) => ['product_id' => $product->id, 'unit_id' => $product->unit_id, 'qty' => $qty, 'rate' => '100', 'sales_price' => '150'];

        $monthEnd = Carbon::parse($this->lastMonth)->endOfMonth()->toDateString();
        $booksAtMonthEnd = fn () => StandardChart::find(StandardChart::INVENTORY)->balanceOn($monthEnd);
        $booksBefore = $booksAtMonthEnd();

        $bill = app(DirectPurchaseService::class)->complete($head, [$line('10')])['bill'];
        $bill = app(PurchaseBillService::class)->update($bill->fresh(), $head, [$line('7')], repost: true);

        // ⓘ সম্পাদনা পুরনো দাখিলা উল্টায় আজ, নতুনটা বসায় বিলের দিনে — আগের মাসের শেষে খাতায় ১০০০ + ৭০০; স্তরও তাই বলবে
        $books = bcsub($booksAtMonthEnd(), $booksBefore, 4);
        $this->assertSame(0, bccomp($this->monthValue($product), $books, 4),
            "⛔ আগের মাসের শেষে স্তর {$this->monthValue($product)}, খাতার মজুদ খাত {$books} — সম্পাদনা দুটোকে আলাদা করল।");

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'W5 সম্পাদনার পরে বাতিল');

        $this->assertSame(0, (int) CostLayer::query()->where('product_id', $product->id)->sum('qty_remaining'), '⛔ বাতিলের পরেও স্তরে মাল রইল।');
        $this->assertSame(0, bccomp($this->monthValue($product), bcsub($booksAtMonthEnd(), $booksBefore, 4), 4), '⛔ বাতিলের পরে আগের মাসের স্তর আর খাতা আলাদা।');
        $this->assertBooksAgree($before, 'সম্পাদনা আর বাতিলের পরে');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{layers: string, books: string} */
    private function snapshot(): array
    {
        return [
            'layers' => (string) DB::table('inv_cost_layers')->where('company_id', $this->company->id)
                ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')->value('v'),
            'books' => StandardChart::find(StandardChart::INVENTORY)->balanceOn(),
        ];
    }

    private function assertBooksAgree(array $before, string $when, string $expected = '0'): void
    {
        $after = $this->snapshot();
        $layers = bcsub($after['layers'], $before['layers'], 4);
        $books = bcsub($after['books'], $before['books'], 4);

        $this->assertSame(0, bccomp($layers, $expected, 4), "⛔ {$when} স্তরের মোট {$layers} নড়ল, {$expected} নয়।");
        $this->assertSame(0, bccomp($layers, $books, 4), "⛔ {$when} স্তর {$layers} আর খাতার মজুদ খাত {$books} আলাদা নড়ল।");
    }

    /** আগের মাসের শেষে এই পণ্যের সমাপনী মূল্য — মজুদ-মূল্য রিপোর্ট নিজেই */
    private function monthValue(Product $product): string
    {
        $end = Carbon::parse($this->lastMonth)->endOfMonth()->toDateString();
        $rows = (app(ReportEngine::class)->get('inventory.stock_value')->query)([
            'company_id' => $this->company->id, 'branch_id' => null,
            'from' => Carbon::parse($this->lastMonth)->startOfMonth()->toDateString(), 'to' => $end,
        ])->get();

        return (string) ($rows->firstWhere('product_id', $product->id)?->closing_value ?? '0');
    }

    private function product(string $name): Product
    {
        return Product::query()->create(['code' => 'W5-'.mb_substr(md5($name.microtime()), 0, 8), 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'sale_price' => '150', 'is_active' => true, 'track_batch' => false]);
    }

    /** ⓘ ক্রয়ের মাল বসানোর অপেক্ষায় ঢোকে — বিক্রির আগে তাকে, বিলের নিজের উৎসে (পর্দা যেমন করে) */
    private function placeAll(Product $product, int $billId): void
    {
        $stock = app(\App\Modules\Inventory\Services\StockService::class);
        $waiting = $stock->statesFor($product, $this->warehouse)['unplaced'];

        if (bccomp($waiting, '0', 4) > 0) {
            $stock->place($product, $this->warehouse, $waiting, \App\Modules\Purchase\Models\PurchaseBill::STOCK_SOURCE, $billId);
        }
    }

    private function returnHead($invoice, string $date): array
    {
        return ['customer_id' => $invoice->customer_id, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $invoice->id,
            'trx_date' => $date,
            'reason_code_id' => (int) ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->orderBy('id')->value('id')];
    }

    private function returnLine(Product $product, $invoice, string $qty): array
    {
        return ['product_id' => $product->id, 'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => $qty];
    }
}
