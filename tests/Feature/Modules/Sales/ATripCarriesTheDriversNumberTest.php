<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ট্রিপে চালকের ফোন আর বাহক — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: *"গাড়ি, চালক, ফোন;
 * কয়েকটা চালান মিলে ট্রিপ"*।
 *
 * ⭐ দাবি: ট্রিপের ফর্মে লেখা ফোন আর বাহক জমা হয়, বদলালে বদলায়; ট্রিপের পাতায় আর লোডিং শিটে দেখা যায়; ফোন-নম্বরের
 * মতো না হলে ফেরত।
 */
final class ATripCarriesTheDriversNumberTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_the_trip_keeps_the_drivers_phone_and_the_carrier_and_shows_them(): void
    {
        $challans = [$this->confirmedChallan()->id, $this->confirmedChallan()->id];

        $this->post(route('sales.shipment.store'), $this->form($challans, ['driver_phone' => '01811-222333', 'carrier_name' => 'করিম পরিবহন']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $trip = Shipment::query()->latest('id')->firstOrFail();

        $this->assertSame(['01811-222333', 'করিম পরিবহন'], [$trip->driver_phone, $trip->carrier_name], '⛔ ট্রিপে ফোন বা বাহক জমা হয়নি।');
        $this->assertSame(2, $trip->lines()->count(), 'দাবির ভিত্তি নেই — কয়েকটা চালানের ট্রিপ নয়।');

        $this->get(route('sales.shipment.show', $trip))->assertOk()->assertSee('01811-222333')->assertSee('করিম পরিবহন');
        $this->get(route('sales.loading_sheet.show', $trip))->assertOk()->assertSee('data-trip-driver-phone', false)->assertSee('01811-222333');

        $this->put(route('sales.shipment.update', $trip), $this->form($challans, ['driver_phone' => '01911-444555']))
            ->assertSessionHasNoErrors();
        $this->assertSame('01911-444555', $trip->fresh()->driver_phone, '⛔ বদলানো ফোন জমা হয়নি।');
    }

    public function test_something_that_is_not_a_phone_number_is_refused(): void
    {
        $this->post(route('sales.shipment.store'), $this->form([$this->confirmedChallan()->id], ['driver_phone' => 'call karim']))
            ->assertSessionHasErrors('driver_phone');
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '2', 'rate' => '10']]));
    }

    /** @param  list<int>  $challans @param array<string, string> $more @return array<string, mixed> */
    private function form(array $challans, array $more = []): array
    {
        return [
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-০০০১',
            'driver_name' => 'করিম',
            'challans' => $challans,
            ...$more,
        ];
    }
}
