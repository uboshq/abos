<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পাকা হয়ে যাওয়া কাগজে পুরনো ট্যাবের সম্পাদনা বসত (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⚠️ বিল, রসিদ, ফেরত, পরিশোধ আর আদেশ — পাঁচটার `update()` "খসড়া কি না" দেখত হাতের কপিতে, তালা ছাড়া। এক ট্যাবে খসড়া খোলা, আরেক
 * ট্যাবে পাকা — তারপর প্রথম ট্যাবে "সংরক্ষণ": পাকা কাগজের লাইন মুছে নতুন লেখা হত, অথচ খাতা আর মজুদ পুরনো লাইনে। ⭐ এখন লেনদেনের
 * ভিতরে তালা দিয়ে আবার দেখা — থামে, লাইন অক্ষত।
 */
final class AnEditLandedOnAConfirmedPaperTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
    }

    public function test_a_bill(): void
    {
        $bills = app(PurchaseBillService::class);
        $head = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'ED-1'];
        $stale = $bills->create($head, [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']]);
        $bills->confirm(PurchaseBill::query()->findOrFail($stale->id));

        $this->refused(fn () => $bills->update($stale, $head, [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '50']]));
        $this->assertSame('10.0000', (string) $stale->fresh()->lines()->firstOrFail()->qty, '⛔ পাকা বিলের লাইন বদলে গেল।');
    }

    public function test_a_receipt(): void
    {
        $receipts = app(PurchaseReceiptService::class);
        $head = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()];
        $stale = $receipts->create($head, [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '50']]);
        $receipts->confirm(PurchaseReceipt::query()->findOrFail($stale->id));

        $this->refused(fn () => $receipts->update($stale, $head, [['product_id' => $this->product->id, 'received_qty' => '1', 'rate' => '50']]));
        $this->assertSame('10.0000', (string) $stale->fresh()->lines()->firstOrFail()->received_qty, '⛔ পাকা রসিদের লাইন বদলে গেল।');
    }

    public function test_a_return(): void
    {
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'ED-2'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        ));
        $returns = app(PurchaseReturnService::class);
        $head = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()];
        $line = $bill->lines()->firstOrFail()->id;
        $stale = $returns->create($head, [['product_id' => $this->product->id, 'qty' => '3', 'purchase_bill_line_id' => $line]]);
        $returns->confirm(PurchaseReturn::query()->with('lines')->findOrFail($stale->id));

        $this->refused(fn () => $returns->update($stale, $head, [['product_id' => $this->product->id, 'qty' => '1', 'purchase_bill_line_id' => $line]]));
        $this->assertSame('3.0000', (string) $stale->fresh()->lines()->firstOrFail()->qty, '⛔ পাকা ফেরতের লাইন বদলে গেল।');
    }

    public function test_a_payment(): void
    {
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'ED-3'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        ));
        $payments = app(PaymentService::class);
        $head = ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '300'];
        $stale = $payments->create($head, [['purchase_bill_id' => $bill->id, 'amount' => '300']]);
        $this->putMoneyIn($stale->account, '1000');
        $payments->confirm(Payment::query()->findOrFail($stale->id));

        $this->refused(fn () => $payments->update($stale, ['trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']]));
        $this->assertSame(0, bccomp((string) $stale->fresh()->amount, '300', 4), '⛔ পাকা পরিশোধের অঙ্ক বদলে গেল।');
    }

    public function test_an_order(): void
    {
        $orders = app(PurchaseOrderService::class);
        $head = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()];
        $stale = $orders->create($head, [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '100']]);
        $orders->confirm(PurchaseOrder::query()->findOrFail($stale->id));

        $this->refused(fn () => $orders->update($stale, $head, [['product_id' => $this->product->id, 'ordered_qty' => '1', 'rate' => '100']]));
        $this->assertSame('10.0000', (string) $stale->fresh()->lines()->firstOrFail()->ordered_qty, '⛔ পাকা আদেশের লাইন বদলে গেল।');
    }

    private function refused(Closure $edit): void
    {
        try {
            $edit();
            $this->fail('⛔ পুরনো ট্যাবের সম্পাদনা পাকা কাগজে বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(), 'অন্য কারণে থামল।');
        }
    }
}
