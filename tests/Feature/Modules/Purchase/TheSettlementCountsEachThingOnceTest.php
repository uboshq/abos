<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ প্রিন্সিপালের নিষ্পত্তি — প্রতিটা জিনিস একবার (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ ⛔৮; [[SettlementReport]], [[ReturnOnCapitalReport]])।
 *
 *   · একই বিলে একই পণ্য দুই লাইনে (এক লাইনে এক লট) — বিক্রির খরচ একবার, বিক্রয় দুই লাইনের যোগ
 *   · "দেওয়া হয়েছে" কেবল টাকা দেওয়ার কাগজ — বিলের GRNI ডেবিট আর ক্রয়-ফেরত নয়; বাতিলের উল্টো সারি বিয়োগ
 *   · "মাল এসেছে"-তে সরাসরি বিলের মালও — মাল-গ্রহণ থেকে আসা বিলের লাইন আবার নয়
 */
final class TheSettlementCountsEachThingOnceTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Supplier $principal;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->principal = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_a_product_on_two_lines_of_one_bill_is_costed_once(): void
    {
        $product = $this->product('SET-2L');
        $this->receive($product, '10', '80');

        // ⓘ একই বিলে একই পণ্য: ২টা @ ১০০ আর ৩টা @ ১২০ — বিক্রয় ৫৬০, খরচ ৫ × ৮০ = ৪০০
        app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'rate' => '100'], ['product_id' => $product->id, 'qty' => '3', 'rate' => '120']],
        ));

        $row = $this->settlement()[$this->principal->id];
        $this->assertMoney('400', $row->cost_of_sold, '⛔ দুই লাইনের পণ্যের খরচ দুবার');
        $this->assertMoney('560', $row->sold, '⛔ দুই লাইনের বিক্রয় গুলিয়ে গেল');

        $capital = collect(app(ReportEngine::class)->run('purchase.return_on_capital', $this->range())->rows)
            ->map(fn ($r) => (array) $r)->firstWhere('supplier_id', $this->principal->id);
        // ⓘ মূলধনের লাভের রিপোর্ট লাভ দেখায়: ৫৬০ − ৪০০ = ১৬০ (আগে দুই লাইনে দুবার গুনে ভুল অঙ্ক)
        $this->assertMoney('160', $capital['margin'] ?? null, '⛔ মূলধনের লাভেও খরচ বা বিক্রয় দুবার');
    }

    public function test_paid_counts_only_money_paid_and_goods_in_counts_a_direct_bill(): void
    {
        $product = $this->product('SET-PAY');
        $receipt = $this->receive($product, '10', '100');

        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $cash = $this->cash();
        $party = ['party_type' => 'supplier', 'party_id' => $this->principal->id];

        // ⓘ টাকা দেওয়া: পরিশোধ ৩০০ (তার ১০০ পরে বাতিল) + পরিশোধ-ভাউচার ৫০ = ২৫০
        $this->postLines('purchase_payment', [['account_id' => $payable, 'debit' => '300', ...$party], ['account_id' => $cash, 'credit' => '300']]);
        $this->postLines('purchase_payment:reversal', [['account_id' => $payable, 'credit' => '100', ...$party], ['account_id' => $cash, 'debit' => '100']]);
        $this->postLines('payment_voucher', [['account_id' => $payable, 'debit' => '50', ...$party], ['account_id' => $cash, 'credit' => '50']]);
        // ⛔ টাকা নয়: বিলের GRNI ডেবিট আর ক্রয়-ফেরত
        $this->postLines('purchase_bill', [['account_id' => $payable, 'debit' => '1000', ...$party], ['account_id' => $cash, 'credit' => '1000']]);
        $this->postLines('purchase_return', [['account_id' => $payable, 'debit' => '70', ...$party], ['account_id' => $cash, 'credit' => '70']]);

        // ⓘ সরাসরি বিল (চালান ছাড়া) ৫০০, আর মাল-গ্রহণ থেকে আসা বিলের লাইন ৯৯৯ — সেটা আবার গোনা হবে না
        $this->bill('DIR-1', null, '500');
        $this->bill('GRN-1', (int) DB::table('pur_receipt_lines')->where('purchase_receipt_id', $receipt->id)->value('id'), '999');

        $row = $this->settlement()[$this->principal->id];
        $this->assertMoney('250', $row->paid_to_them, '⛔ "দেওয়া হয়েছে"-তে GRNI, ফেরত বা বাতিল পরিশোধ গোনা হল');
        $this->assertMoney('1500', $row->goods_in, '⛔ সরাসরি বিলের মাল আসেনি, বা মাল-গ্রহণের বিল দুবার');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{from: string, to: string} */
    private function range(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];
    }

    /** @return array<int, object> */
    private function settlement(): array
    {
        $rows = [];

        foreach (app(ReportEngine::class)->run('purchase.settlement', $this->range())->rows as $row) {
            $row = (object) $row;
            $rows[(int) $row->supplier_id] = $row;
        }

        return $rows;
    }

    private function product(string $code): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => 'Settle '.$code, 'name_bn' => 'নিষ্পত্তি '.$code,
            'unit_id' => Product::query()->value('unit_id'), 'purchase_price' => '80', 'sale_price' => '100', 'is_active' => true,
        ]);
    }

    private function receive(Product $product, string $qty, string $rate): PurchaseReceipt
    {
        $receipt = app(PurchaseReceiptService::class)->confirm(app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->principal->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => $qty, 'rate' => $rate]],
        ));

        app(StockService::class)->place(product: $product, warehouse: $this->warehouse, qty: $qty,
            sourceType: PurchaseReceipt::STOCK_SOURCE, sourceId: $receipt->id);

        return $receipt;
    }

    private function bill(string $no, ?int $receiptLine, string $amount): void
    {
        $bill = DB::table('pur_bills')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no, 'supplier_id' => $this->principal->id,
            'trx_date' => now()->toDateString(), 'subtotal' => $amount, 'total' => $amount, 'status' => DocumentStatus::CONFIRMED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pur_bill_lines')->insert([
            'public_id' => (string) Str::uuid7(), 'purchase_bill_id' => $bill,
            'product_id' => Product::query()->value('id'), 'purchase_receipt_line_id' => $receiptLine,
            'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'line_no' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function postLines(string $source, array $lines): void
    {
        app(PostingEngine::class)->post(sourceType: $source, sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(),
            lines: $lines, branchId: $this->company->defaultBranch()?->id);
    }

    private function cash(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
