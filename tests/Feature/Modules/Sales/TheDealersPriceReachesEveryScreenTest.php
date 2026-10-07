<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\DirectSaleOptions;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesQuotationService;
use App\Modules\Sales\Services\SalesPrice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ডিলারের দর প্রতিটা বিক্রির পর্দায় পৌঁছায় — দর তালিকা, ধাপ ২ (মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⭐ পণ্যের দাম ১০০, এই ডিলারের তালিকায় ৮০। ⓘ দামের নীতি "নিচে আটকাও, ০%" — তাই ৮৫-এ বিক্রি কেবল তখনই চলে
 * যখন পাহারা ডিলারের দামে মাপে; আগের মতো পণ্যের দামে মাপলে ৮৫ আটকাত।
 */
final class TheDealersPriceReachesEveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->dealer = Customer::query()->firstOrFail();

        // ⓘ কাউন্টারের তালিকায় আসে এমন পণ্য — মজুদ আছে
        $id = app(DirectSaleOptions::class)->catalogue($this->warehouse, 200)->first()?->id;
        $this->product = Product::query()->findOrFail($id);
        $this->product->forceFill(['sale_price' => '100'])->save();

        $list = PriceList::query()->create(['code' => 'DLR1', 'name_en' => 'Dealer one', 'customer_id' => $this->dealer->id, 'is_active' => true]);
        PriceListItem::query()->create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => '80',
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::BLOCK);
        $settings->set(PricingRule::BELOW, true);
        $settings->set(PricingRule::ABOVE, false);
        $settings->set(PricingRule::TOLERANCE, '0');
        $settings->flush();
    }

    /** ⭐ বিলের দামের পাহারা ডিলারের দামে মাপে — ৮৫ চলে, ৭৯ আটকায়। */
    public function test_the_bill_measures_the_rate_against_the_dealers_price(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->assertNotNull($this->bill('85'), '⛔ ডিলারের দাম ৮০, তবু ৮৫ আটকাল — পাহারা এখনো পণ্যের দাম ১০০-এ মাপে।');

        $this->expectException(ValidationException::class);
        $this->bill('79');
    }

    /** ⭐ উদ্ধৃতির পাহারাও একই মাপে। */
    public function test_the_quotation_measures_against_the_dealers_price_too(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $quote = fn (string $rate) => app(SalesQuotationService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate]],
        );

        $this->assertNotNull($quote('85'));

        $this->expectException(ValidationException::class);
        $quote('79');
    }

    /** ⭐ DO-র সারি ডিলারের দামে বসে, পণ্যের দামে নয়। */
    public function test_a_delivery_order_line_takes_the_dealers_price(): void
    {
        $writer = $this->staff(['sales.do.view', 'sales.do.create']);
        $this->actingAs($writer);

        $do = app(DeliveryOrderService::class)->create(
            ['customer_id' => $this->dealer->id],
            [['product_id' => $this->product->id, 'qty' => '2']],
            $writer,
        );

        $this->assertSame('80.0000', bcadd((string) $do->lines->first()->rate, '0', 4));
    }

    /** ⭐ ফোনের বিক্রয় আদেশ ডিলারের দামে। */
    public function test_the_phone_order_takes_the_dealers_price(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->staff(['sales.order.view', 'sales.order.create']), [\App\Http\Controllers\Api\AuthController::APP]);

        $made = $this->postJson('/api/v1/sales/orders', [
            'customer' => (string) $this->dealer->public_id,
            'lines' => [['product' => (string) $this->product->public_id, 'qty' => '3']],
        ])->assertCreated();

        $line = \App\Modules\Sales\Models\SalesOrderLine::query()->latest('id')->firstOrFail();
        $this->assertSame('80.0000', bcadd((string) $line->rate, '0', 4), '⛔ ফোনের আদেশ পণ্যের দাম নিল। '.$made->getContent());
    }

    /** ⭐ কাউন্টারের তালিকা গ্রাহক জানলে তাঁর দর আর উৎস দেয়; না জানলে পণ্যের দাম। */
    public function test_the_counter_catalogue_answers_for_the_chosen_customer(): void
    {
        $options = app(DirectSaleOptions::class);

        $mine = $options->catalogue($this->warehouse, 1, (int) $this->product->id, $this->dealer)->first();
        $anyone = $options->catalogue($this->warehouse, 1, (int) $this->product->id)->first();

        $this->assertSame(['80.0000', SalesPrice::CUSTOMER, __('sales::price_source.customer')], [$mine->rate, $mine->priceSource, $mine->priceLabel]);
        $this->assertSame(['100.0000', SalesPrice::STANDARD], [$anyone->rate, $anyone->priceSource]);
    }

    /** ⛔ দরের প্রশ্ন বিক্রির কোনো চাবি ছাড়া ৪০৩ — একই মানুষ, চাবি পেলে ডিলারের দর আর উৎস। */
    public function test_the_quote_door_asks_for_a_sales_key_and_answers_per_customer(): void
    {
        $clerk = $this->staff([]);

        $this->actingAs($clerk)->getJson(route('sales.price_list.quote', ['customer' => $this->dealer->id]))->assertForbidden();

        $clerk = $this->staff(['sales.order.create'], $clerk);

        $this->actingAs($clerk)->getJson(route('sales.price_list.quote', ['customer' => $this->dealer->id]))
            ->assertOk()
            ->assertJsonPath('prices.'.$this->product->id.'.rate', '80.0000')
            ->assertJsonPath('prices.'.$this->product->id.'.source', SalesPrice::CUSTOMER)
            ->assertJsonPath('prices.'.$this->product->id.'.label', __('sales::price_source.customer'));

        $this->actingAs($clerk)->getJson(route('sales.price_list.quote'))
            ->assertOk()
            ->assertJsonPath('prices.'.$this->product->id.'.rate', '100.0000');
    }

    /** ⭐ ফোনের "দাম দেখুন" গ্রাহক দিলে ডিলারের দর। */
    public function test_the_phone_price_check_names_the_customer_price(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->staff(['sales.challan.create', 'sales.invoice.create']), [\App\Http\Controllers\Api\AuthController::APP]);

        $this->getJson('/api/v1/sales/direct/price/'.$this->product->public_id.'?customer='.$this->dealer->public_id)
            ->assertOk()
            ->assertJsonPath('rate', '80.0000')
            ->assertJsonPath('priceSource', SalesPrice::CUSTOMER);
    }

    private function bill(string $rate): ?object
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate]],
        );
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }

        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
