<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * চালান ছাড়া বিল লট ভুলে যেত — Inventory অডিট গ১০, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ হাতে লেখা বিল বা POS (চালান ছাড়া সারি) মাল বের করত লট ছাড়া: পণ্যের মোট কমত, কিন্তু কোনো লট কমত না — গ৯-এর মতোই
 * লটের হিসাব ভাঙত; আর লটের ছাপা দামের (MRP) চেয়ে বেশি দামে বিক্রি ধরা পড়ত না, অথচ চালানের পথে ঠিক এটাই থামে।
 * ⭐ এখন চালান-ছাড়া সারিও চালানের মতো: আগে-মেয়াদের লট থেকে বেরোয়, প্রতিটা লটের ছাপা দামের সীমা মাপা হয়, বাতিলে সেই লটে ফেরে।
 */
final class TheBillWithoutAChallanForgotTheLotTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

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
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ⓘ আগের কোনো মাল নেই এমন পণ্য — লটহীন পুরনো মাল যেন বাছাই না গোলায়
        $this->product = Product::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('id')->firstOrFail()
            ->replicate(['public_id']);
        $this->product->forceFill(['code' => 'LOT-TEST-1', 'barcode' => null, 'name_en' => 'Lot Test Biscuit', 'name_bn' => 'লট পরীক্ষার বিস্কুট',
            'track_batch' => true])->save();

        $this->lot('LOT-A', now()->addYear()->toDateString(), '120');
        $this->lot('LOT-B', now()->addMonth()->toDateString(), '100'); // আগে মেয়াদ — FEFO এটাই নেয়
    }

    /** ⭐ আগে-মেয়াদের লট থেকে বেরোয়, আর খরচ সেই লটের স্তর থেকে */
    public function test_a_bill_without_a_challan_takes_its_goods_from_a_lot(): void
    {
        $invoice = $this->sell('3', '90');

        $this->assertSame('7', $this->balance('LOT-B'), '⛔ চালান-ছাড়া বিলে লট B কমেনি — মাল লট ছাড়া বেরিয়েছে।');
        $this->assertSame('10', $this->balance('LOT-A'));
        $this->assertSame(0, bccomp('180', (string) $invoice->fresh()->cost_of_goods, 4), '⛔ খরচ লটের স্তর থেকে আসেনি।');
    }

    /** ⛔ লটের ছাপা দামের চেয়ে বেশি — থামে, চালানের পথের মতোই */
    public function test_a_bill_without_a_challan_cannot_sell_above_the_lots_printed_price(): void
    {
        try {
            $this->sell('3', '110');
            $this->fail('⛔ লট B-র ছাপা দাম ১০০, অথচ ১১০-এ চালান-ছাড়া বিল পাকা হলো।');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame('10', $this->balance('LOT-B'), '⛔ থামা বিলেও লট থেকে মাল বেরিয়েছে।');
    }

    /** ⭐ সীমা মাপা হয় ক্রেতা যা দেন তাতে — সারির ছাড়ের পরে: ১০৫ দর, ৩টায় ৩০ ছাড় = এককে ৯৫, ছাপা দাম ১০০-এর ভিতরে */
    public function test_the_printed_price_is_measured_after_the_lines_discount(): void
    {
        // ⓘ যেকোনো ছাড়ে মালিকের সই — আগে সইয়ে আটকায়, মালিক সই দেন, তারপর পাকা ([[TheOwnerSignsEveryDiscountTest]]-এর পথ)
        $service = app(SalesInvoiceService::class);
        $bill = $service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '3', 'rate' => '105', 'discount' => '30']],
        );

        try {
            $service->confirm($bill->fresh());
        } catch (ValidationException) {
            // সইয়ের অপেক্ষা
        }

        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $bill->id)
                ->where('action', 'discount')->firstOrFail(),
            User::query()->where('email', 'owner@abos.test')->firstOrFail(),
        );
        $service->confirm($bill->fresh());

        $this->assertSame('7', $this->balance('LOT-B'), '⛔ ছাড়ের পরে সীমার ভিতরে, তবু বিক্রি হয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function sell(string $qty, string $rate, string $discount = '0'): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => $discount]],
        ));
    }

    private function lot(string $no, string $expiry, string $mrp): void
    {
        $batch = app(BatchService::class)->receive($this->product, $no, $expiry, $mrp);

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT,
            sourceId: $this->product->id, floor: '10', batch: $batch,
        );
        // ⓘ দরসহ — নাহলে বিক্রির খরচ "ক্রয়মূল্যের হিসাব নেই" বলে থামে
        app(CostLayerService::class)->receive($this->product, '10', '60', StockService::ADJUSTMENT, $this->product->id, batch: $batch);
    }

    private function balance(string $no): string
    {
        $batch = Batch::query()->where('product_id', $this->product->id)->where('batch_no', $no)->firstOrFail();

        return rtrim(rtrim(bcadd($batch->floorBalance($this->warehouse), '0', 4), '0'), '.') ?: '0';
    }
}
