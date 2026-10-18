<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা বিলের মেয়াদ গ্রাহকের নিজের বাকির দিন থেকে — মালিকের উত্তর ২, ১১ অক্টোবর ২০২৬ (বাকি নিয়ন্ত্রণ ধাপ ২.২)।
 *
 * ⛔ আগে কেবল কাউন্টার মেয়াদ বসাত; অফিসের বিল, চালান থেকে বিল আর রওনার বিল (DO, ফোনের আর পোর্টালের অর্ডার এই পথেই বিল
 * হয়) মেয়াদ খালি রাখত — আর খালি মানে বিলের দিনই মেয়াদ, তাই ২১ দিনের বাকির গ্রাহক পরদিনই "মেয়াদ পেরোনো"-তে আটকাতেন।
 *
 * পথ ধরে দাবি (গ্রাহকের বাকির দিন ২১): অফিসের বিল · চালান থেকে বিল · রওনার বিল · কাউন্টার (ওয়েব) · ফোনের বিক্রি —
 * প্রতিটায় মেয়াদ = বিলের দিন + ২১। হাতে লেখা মেয়াদ থাকে; বাকির দিন ০ হলে খালি (বিলের দিনেই)।
 */
final class EveryBillTakesItsDueDateFromTheCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000', 'credit_days' => 21])->save();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_an_office_bill_with_no_date_takes_the_customers_days(): void
    {
        $bill = $this->officeBill([]);

        $this->assertSame(now()->addDays(21)->toDateString(), $bill->due_on?->toDateString(), '⛔ অফিসের বিলে মেয়াদ বসেনি।');
    }

    public function test_a_typed_date_is_kept_and_no_days_means_the_bill_day(): void
    {
        $typed = now()->addDays(5)->toDateString();
        $this->assertSame($typed, $this->officeBill(['due_on' => $typed])->due_on?->toDateString(), '⛔ হাতে লেখা মেয়াদ মুছে গেল।');

        $this->customer->forceFill(['credit_days' => 0])->save();
        $this->assertNull($this->officeBill([])->due_on, 'বাকির দিন ০ — মেয়াদ বিলের দিনেই (খালি)।');
    }

    public function test_a_bill_from_a_challan_takes_the_customers_days(): void
    {
        $challan = $this->confirmedChallan();
        $line = $challan->fresh('lines')->lines->firstOrFail();

        $bill = app(SalesInvoiceService::class)->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'due_on' => null,
        ], [[
            'product_id' => $this->product->id, 'delivery_challan_line_id' => $line->id,
            'qty' => (string) $line->delivered_qty, 'rate' => (string) $line->rate,
        ]]);

        $this->assertSame(now()->addDays(21)->toDateString(), $bill->due_on?->toDateString(), '⛔ চালান থেকে বিলে মেয়াদ বসেনি।');
    }

    /** ⓘ DO, ফোনের অর্ডার আর পোর্টালের অর্ডার — সবাই মাল বেরোনোর মুহূর্তে এই পথে বিল হয় ([[DispatchBill]]) */
    public function test_the_bill_made_when_the_goods_leave_takes_the_customers_days(): void
    {
        $challan = $this->confirmedChallan();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        $bill = $this->billOf($challan);
        $this->assertSame(now()->addDays(21)->toDateString(), $bill->due_on?->toDateString(), '⛔ রওনার বিলে মেয়াদ বসেনি।');
    }

    public function test_the_counter_and_the_phone_take_the_customers_days(): void
    {
        $lot = $this->lot();
        $seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($seller->fresh());
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'payment_term' => 'credit', 'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১৩', 'driver_name' => 'করিম',
            'transport_cost' => '100', 'fare_paid_by' => 'customer',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $lot->id, 'qty' => '2', 'rate' => '100', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(21)->toDateString(), $this->latestBill()->due_on?->toDateString(), '⛔ কাউন্টারের বিলে মেয়াদ বসেনি।');

        Sanctum::actingAs($seller->fresh(), [AuthController::APP]);
        $before = $this->latestBill()->id;
        $this->postJson('/api/v1/sales/direct', [
            'customer' => (string) $this->customer->public_id, 'warehouse' => (string) $this->warehouse->public_id,
            'payment_term' => 'credit', 'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১২', 'driver_name' => 'রহিম',
            'transport_cost' => '100', 'fare_paid_by' => 'customer',
            // ⓘ ওয়েবের বিক্রি থেকে আলাদা পরিমাণ — নইলে "এইমাত্র একই বিল" পাহারা থামায়
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $lot->public_id, 'qty' => '3', 'rate' => '100']],
        ])->assertCreated();

        $phone = $this->latestBill();
        $this->assertNotSame($before, $phone->id, 'ফোনের বিক্রিতে বিলই হয়নি — দাবি অন্ধ।');
        $this->assertSame(now()->addDays(21)->toDateString(), $phone->due_on?->toDateString(), '⛔ ফোনের বিলে মেয়াদ বসেনি।');
    }

    /**
     * ⛔ শোধের তারিখ গ্রাহকের বাকির দিনের বাইরে নয় — পূর্ণ নিরীক্ষা §৫, ১৮ অক্টোবর ২০২৬ ([[DueWithinTerms]])।
     * ⓘ আগে অফিসের বিলে লেখা তারিখ যা খুশি বসত — ২১ দিনের গ্রাহককে ৯০ দিন।
     */
    public function test_an_office_bill_cannot_be_given_more_days_than_the_customer_has(): void
    {
        $this->assertSame(now()->addDays(21)->toDateString(),
            $this->officeBill(['due_on' => now()->addDays(21)->toDateString()])->due_on?->toDateString(), 'ঠিক ২১ দিন চলে।');

        $before = SalesInvoice::query()->withoutGlobalScopes()->count();
        try {
            $this->officeBill(['due_on' => now()->addDays(22)->toDateString()]);
            $this->fail('⛔ ২১ দিনের গ্রাহকের বিলে ২২ দিনের মেয়াদ বসল।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('due_on', $e->errors());
        }
        $this->assertSame($before, SalesInvoice::query()->withoutGlobalScopes()->count(), '⛔ থামার আগেই বিল বসে গেল।');
    }

    /** ⓘ সম্পাদনায় বিলের নিজের আগের তারিখ থাকে — পরে গ্রাহকের দিন কমলেও; নতুন তারিখ নতুন দিনেই মাপা */
    public function test_an_edit_keeps_the_bills_own_date_but_a_new_date_follows_todays_terms(): void
    {
        $bill = $this->officeBill(['due_on' => now()->addDays(20)->toDateString()]);
        $this->customer->forceFill(['credit_days' => 7])->save();
        $line = [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '10']];
        $head = ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()];

        $kept = app(SalesInvoiceService::class)->update($bill->fresh(), [...$head, 'due_on' => now()->addDays(20)->toDateString()], $line);
        $this->assertSame(now()->addDays(20)->toDateString(), $kept->fresh()->due_on?->toDateString(), 'নিজের আগের তারিখে সম্পাদনা আটকাল।');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SalesInvoiceService::class)->update($bill->fresh(), [...$head, 'due_on' => now()->addDays(15)->toDateString()], $line);
    }

    /** ⓘ কাউন্টার আর ফোন — "কত দিন", "কোন তারিখে" আর "মাসের শেষে", তিন পথেই */
    public function test_the_counter_and_the_phone_cannot_stretch_the_customers_days(): void
    {
        $lot = $this->lot();
        $seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($seller->fresh());

        $sale = fn (array $terms, string $qty) => $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১৪', 'driver_name' => 'করিম', 'transport_cost' => '100', 'fare_paid_by' => 'customer',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $lot->id, 'qty' => $qty, 'rate' => '100', 'free_qty' => '0']],
            ...$terms,
        ]);
        $bills = fn () => SalesInvoice::query()->withoutGlobalScopes()->where('customer_id', $this->customer->id)->count();
        $before = $bills();

        $sale(['payment_term' => 'credit', 'credit_period_days' => '60'], '2')->assertSessionHasErrors('due_on');
        $sale(['payment_term' => 'fixed', 'due_on' => now()->addDays(45)->toDateString()], '3')->assertSessionHasErrors('due_on');
        $this->assertSame($before, $bills(), '⛔ কাউন্টারে গ্রাহকের দিনের বাইরে মেয়াদের বিল বসল।');

        // ⓘ মাসের শেষ — গ্রাহকের ১ দিনে, মাসের শেষ এক দিনের বেশি দূরে হলে থামে
        $this->customer->forceFill(['credit_days' => 1])->save();
        if (now()->startOfDay()->diffInDays(now()->endOfMonth()->startOfDay()) > 1) {
            $sale(['payment_term' => 'month_end'], '4')->assertSessionHasErrors('due_on');
        }
        $this->customer->forceFill(['credit_days' => 21])->save();

        $sale(['payment_term' => 'credit', 'credit_period_days' => '21'], '5')->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $bills(), 'নিজের দিনের ভিতরে কাউন্টারের বিক্রি আটকাল।');

        Sanctum::actingAs($seller->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/sales/direct', [
            'customer' => (string) $this->customer->public_id, 'warehouse' => (string) $this->warehouse->public_id,
            'payment_term' => 'fixed', 'due_on' => now()->addDays(60)->toDateString(),
            'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১৫', 'driver_name' => 'রহিম', 'transport_cost' => '100', 'fare_paid_by' => 'customer',
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $lot->public_id, 'qty' => '6', 'rate' => '100']],
        ])->assertStatus(422)->assertJsonValidationErrors('due_on');
        $this->assertSame($before + 1, $bills(), '⛔ ফোনে গ্রাহকের দিনের বাইরে মেয়াদের বিল বসল।');
    }

    // ── যন্ত্রপাতি ──

    /** @param  array<string, mixed>  $extra */
    private function officeBill(array $extra): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), ...$extra,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']]);
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    private function billOf(DeliveryChallan $challan): SalesInvoice
    {
        $id = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->where('cl.delivery_challan_id', $challan->id)
            ->value('il.sales_invoice_id');

        return SalesInvoice::query()->findOrFail($id);
    }

    private function latestBill(): SalesInvoice
    {
        return SalesInvoice::query()->withoutGlobalScopes()->where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    }

    private function lot(): Batch
    {
        $this->product->update(['track_batch' => true]);
        $lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_no' => 'LOT-DUE', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-DUE', batch: $lot,
        );

        return $lot;
    }
}
