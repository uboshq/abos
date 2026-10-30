<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ বিলের শোধের তারিখ গ্রাহকের শেষ বাকির দিনে থামে — ঠিক সীমায় চলে, এক দিন বেশি ফেরে (fe-র বিক্রয় ফাঁক ২৭, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ আন্তর্জাতিক রীতি: শোধের শর্ত (SAP ZTERM) গ্রাহকের নিজের — বিলে হাতে লিখে বাড়ানো যায় না। নিয়ম [[DueWithinTerms]]:
 * শোধের তারিখ ≤ বিলের দিন + গ্রাহকের `credit_days`; আগে হলে ঠিক আছে। বাকির দিন ০ = বিলের দিনেই শোধ (মালিকের নিয়ম, ১৮ অক্টোবর)।
 *
 * পাশের [[\Tests\Feature\Modules\CreditControl\EveryBillTakesItsDueDateFromTheCustomerTest]] সেবা ডেকে আর ৬০/৪৫ দিনের মতো দূরের
 * তারিখে মাপে। এখানে প্রতিটা দরজা আসল রুটে, আর ঠিক সীমার দুই পাশে: অফিসের বিল (তৈরি আর সম্পাদনা), কাউন্টার (লেখা তারিখ আর
 * দিনের সংখ্যা), ফোনের কাউন্টার (Sanctum + অ্যাপের টোকেন)। প্রতিটা ফেরায় বিল একটাও বাড়ে না।
 */
final class ABillsDueDateStopsOnTheCustomersLastCreditDayTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = 21;

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
        $this->customer->forceFill(['credit_limit' => '1000000', 'credit_days' => self::DAYS])->save();

        // ⓘ ফিক্সচার প্রমাণ: গ্রাহকের নিজের ঘরে সত্যিই ২১ দিন
        $this->assertSame(self::DAYS, (int) Customer::query()->withoutGlobalScopes()->whereKey($this->customer->id)->value('credit_days'));
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_office_bill_takes_the_last_credit_day_and_refuses_one_more(): void
    {
        $over = $this->challan();
        $this->assertSame($this->customer->id, (int) $over->customer_id, 'চালানটা ২১ দিনের গ্রাহকের নয় — দাবি অন্ধ।');
        $before = $this->bills();

        $this->post(route('sales.invoice.store'), $this->officeBill($over, $this->day(self::DAYS + 1)))
            ->assertSessionHasErrors(['due_on' => $this->refusal($this->day(self::DAYS + 1))]);
        $this->assertSame($before, $this->bills(), '⛔ বাকির দিনের এক দিন পরের মেয়াদে অফিসের বিল বসল।');

        $this->post(route('sales.invoice.store'), $this->officeBill($this->challan(), $this->day(self::DAYS)))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before + 1, $this->bills(), 'ঠিক শেষ বাকির দিনে অফিসের বিল আটকাল।');
        $this->assertSame($this->day(self::DAYS), $this->latestBill()->due_on?->toDateString());

        // ⭐ আগের তারিখ চলে
        $this->post(route('sales.invoice.store'), $this->officeBill($this->challan(), $this->day(3)))->assertSessionHasNoErrors();
        $this->assertSame($this->day(3), $this->latestBill()->due_on?->toDateString(), 'আগের মেয়াদ আটকাল।');
    }

    public function test_an_office_edit_cannot_push_the_due_date_past_the_last_credit_day(): void
    {
        $challan = $this->challan();
        $this->post(route('sales.invoice.store'), $this->officeBill($challan, $this->day(10)))->assertSessionHasNoErrors();
        $bill = $this->latestBill();
        $this->assertSame($this->day(10), $bill->due_on?->toDateString(), 'বিলটা ১০ দিনে বসেনি — দাবি অন্ধ।');

        $edit = fn (string $due) => $this->put(route('sales.invoice.update', $bill), $this->officeBill($challan, $due));

        $edit($this->day(self::DAYS + 1))->assertSessionHasErrors(['due_on' => $this->refusal($this->day(self::DAYS + 1))]);
        $this->assertSame($this->day(10), $bill->fresh()->due_on?->toDateString(), '⛔ সম্পাদনায় মেয়াদ বাকির দিনের বাইরে গেল।');

        $edit($this->day(self::DAYS))->assertSessionHasNoErrors();
        $this->assertSame($this->day(self::DAYS), $bill->fresh()->due_on?->toDateString(), 'সম্পাদনায় ঠিক শেষ দিন আটকাল।');
    }

    /** ⓘ বাকির দিন ০ (ঘরের ডিফল্ট) = বিলের দিনেই শোধ — নতুন নীতি নয়, [[DueWithinTerms]]-এর মালিকের নিয়ম (১৮ অক্টোবর, D10) */
    public function test_a_customer_with_no_credit_days_pays_on_the_bill_day(): void
    {
        $this->customer->forceFill(['credit_days' => 0])->save();
        $before = $this->bills();

        $this->post(route('sales.invoice.store'), $this->officeBill($this->challan(), $this->day(1)))
            ->assertSessionHasErrors(['due_on' => $this->refusal($this->day(1), 0)]);
        $this->assertSame($before, $this->bills(), '⛔ ০ দিনের গ্রাহকের বিলে পরদিনের মেয়াদ বসল।');

        $this->post(route('sales.invoice.store'), $this->officeBill($this->challan(), $this->day(0)))->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $this->bills(), 'বিলের দিনেই শোধ আটকাল।');
    }

    public function test_the_counter_takes_the_last_credit_day_and_refuses_one_more(): void
    {
        $lot = $this->lot();
        $this->actingAs($this->seller());
        $before = $this->bills();

        $this->counterSale(['payment_term' => 'fixed', 'due_on' => $this->day(self::DAYS + 1)], $lot, '2')
            ->assertSessionHasErrors(['due_on' => $this->refusal($this->day(self::DAYS + 1))]);
        $this->counterSale(['payment_term' => 'credit', 'credit_period_days' => (string) (self::DAYS + 1)], $lot, '3')
            ->assertSessionHasErrors(['due_on' => $this->refusal($this->day(self::DAYS + 1))]);
        $this->assertSame($before, $this->bills(), '⛔ কাউন্টারে বাকির দিনের এক দিন পরের মেয়াদে বিল বসল।');

        $this->counterSale(['payment_term' => 'fixed', 'due_on' => $this->day(self::DAYS)], $lot, '4')->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $this->bills(), 'ঠিক শেষ বাকির দিনে কাউন্টার আটকাল।');
        $this->assertSame($this->day(self::DAYS), $this->latestBill()->due_on?->toDateString());

        $this->counterSale(['payment_term' => 'credit', 'credit_period_days' => (string) self::DAYS], $lot, '5')->assertSessionHasNoErrors();
        $this->assertSame($before + 2, $this->bills(), 'ঠিক ২১ দিনের সংখ্যায় কাউন্টার আটকাল।');
        $this->assertSame($this->day(self::DAYS), $this->latestBill()->due_on?->toDateString());
    }

    public function test_the_phone_counter_takes_the_last_credit_day_and_refuses_one_more_with_422(): void
    {
        $lot = $this->lot();
        Sanctum::actingAs($this->seller(), [AuthController::APP]);
        $before = $this->bills();

        $this->phoneSale($this->day(self::DAYS + 1), $lot, '6')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_on' => $this->refusal($this->day(self::DAYS + 1))]);
        $this->assertSame($before, $this->bills(), '⛔ ফোনে বাকির দিনের এক দিন পরের মেয়াদে বিল বসল।');

        $this->phoneSale($this->day(self::DAYS), $lot, '7')->assertCreated();
        $this->assertSame($before + 1, $this->bills(), 'ঠিক শেষ বাকির দিনে ফোন আটকাল।');
        $this->assertSame($this->day(self::DAYS), $this->latestBill()->due_on?->toDateString());
    }

    // ── যন্ত্রপাতি ──

    private function day(int $offset): string
    {
        return now()->startOfDay()->addDays($offset)->toDateString();
    }

    /** ⓘ বার্তা বলে গ্রাহকের বাকির দিন আর শেষ অনুমোদিত তারিখ (বাংলা, মডিউলের lang) */
    private function refusal(string $asked, int $days = self::DAYS): string
    {
        $message = __('sales::due.beyond_terms', [
            'days' => $days,
            'asked' => (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($asked)),
            'due' => $asked,
            'latest' => $this->day($days),
        ]);
        $this->assertStringContainsString($this->day($days), $message, 'বার্তায় শেষ তারিখ নেই।');

        return $message;
    }

    private function bills(): int
    {
        return SalesInvoice::query()->withoutGlobalScopes()->where('customer_id', $this->customer->id)->count();
    }

    private function latestBill(): SalesInvoice
    {
        return SalesInvoice::query()->withoutGlobalScopes()->where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    }

    private function challan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '2', 'rate' => '10']]));
    }

    /** @return array<string, mixed> ফর্ম যা পাঠায় — চালান, আর প্রতি সারিতে চালানের সারির যোগ */
    private function officeBill(DeliveryChallan $challan, string $due): array
    {
        $line = $challan->lines()->firstOrFail();

        return [
            'delivery_challan_id' => $challan->id,
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'due_on' => $due,
            'lines' => [[
                'product_id' => $line->product_id, 'delivery_challan_line_id' => $line->id,
                'qty' => '2', 'rate' => '10', 'discount' => '0',
            ]],
        ];
    }

    private function seller(): User
    {
        $seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $seller->fresh();
    }

    /** @param  array<string, string>  $terms ⓘ প্রতি বিক্রিতে আলাদা পরিমাণ — নইলে "এইমাত্র একই বিল" পাহারা থামায় */
    private function counterSale(array $terms, Batch $lot, string $qty): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ২৭', 'driver_name' => 'করিম', 'transport_cost' => '100', 'fare_paid_by' => 'customer',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $lot->id, 'qty' => $qty, 'rate' => '100', 'free_qty' => '0']],
            ...$terms,
        ]);
    }

    private function phoneSale(string $due, Batch $lot, string $qty): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sales/direct', [
            'customer' => (string) $this->customer->public_id, 'warehouse' => (string) $this->warehouse->public_id,
            'payment_term' => 'fixed', 'due_on' => $due,
            'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ২৮', 'driver_name' => 'রহিম', 'transport_cost' => '100', 'fare_paid_by' => 'customer',
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $lot->public_id, 'qty' => $qty, 'rate' => '100']],
        ]);
    }

    private function lot(): Batch
    {
        $this->product->update(['track_batch' => true]);
        $lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_no' => 'LOT-G27', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-G27', batch: $lot,
        );

        return $lot;
    }
}
