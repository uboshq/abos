<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ExchangeRate;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\MasterData\Models\VehicleType;
use App\Modules\MasterData\Services\ExchangeRateService;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মুদ্রা ও তার হারের ইতিহাস, আর গাড়ি ও বহর।
 *
 * ── কেন হারের পরীক্ষাগুলো তারিখ ধরে ধরে ─────────────────────────────
 * ভুল হারের ক্ষতি সাথে সাথে দেখা যায় না। ১১৭-র বদলে ১১৯ বসলে বিলটা
 * দেখতে ঠিকই লাগে — শুধু দুই টাকা বেশি। ধরা পড়ে মাস শেষে, যখন
 * ব্যাংকের কাগজের সাথে বই মেলে না, আর তখন কোন বিলটা ভুল তা খুঁজতে
 * পুরো মাস উল্টাতে হয়।
 *
 * তাই এখানে দেখা হয় একটা পুরনো তারিখের লেনদেন আজও তার নিজের দিনের
 * হারেই থাকে কি না।
 */
class CurrencyAndVehicleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $settings = app(SettingsService::class);
        $settings->set('master_data.multi_currency_enabled', true);
        $settings->set('master_data.vehicle_enabled', true);
        $settings->flush();

        app(MasterListService::class)->installDefaults();
    }

    private function rates(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    private function currency(string $code): Currency
    {
        return Currency::query()->where('code', $code)->firstOrFail();
    }

    // ── হার ও ইতিহাস ──────────────────────────────────────────────────

    public function test_the_taka_is_the_base_and_needs_no_rate(): void
    {
        $bdt = $this->currency('BDT');

        $this->assertTrue($bdt->is_default, 'প্রমিত তালিকায় টাকাই ভিত্তি মুদ্রা হওয়ার কথা');
        $this->assertSame(0, bccomp((string) $bdt->rateOn(), '1', 6));
    }

    public function test_the_base_currency_cannot_be_given_a_rate(): void
    {
        $this->expectException(ValidationException::class);

        $this->rates()->record($this->currency('BDT'), '2026-08-01', '1.02');
    }

    public function test_a_rate_must_be_greater_than_zero(): void
    {
        $this->expectException(ValidationException::class);

        $this->rates()->record($this->currency('USD'), '2026-08-01', '0');
    }

    /**
     * পুরনো তারিখের লেনদেন তার নিজের দিনের হারেই থাকে।
     *
     * এটাই পুরো ইতিহাস রাখার একমাত্র কারণ — নাহলে মুদ্রার সারিতে
     * একটা কলামই যথেষ্ট হত।
     */
    public function test_a_july_invoice_keeps_the_july_rate_after_august_arrives(): void
    {
        $usd = $this->currency('USD');

        $this->rates()->record($usd, '2026-07-01', '117.25', 'Sonali Bank');
        $this->rates()->record($usd, '2026-08-01', '119.80', 'Sonali Bank');

        $july = Carbon::parse('2026-07-15');

        $this->assertSame(0, bccomp((string) $usd->rateOn($july), '117.25', 6),
            'আগস্টের হার বসার পরেও জুলাইয়ের বিল জুলাইয়ের হারেই থাকার কথা');

        $this->assertSame(0, bccomp((string) $usd->rateOn(Carbon::parse('2026-08-04')), '119.80', 6));
        $this->assertSame(0, bccomp((string) $usd->toBase('100', $july), '11725', 4));
    }

    /**
     * হার বসার আগের দিন — হার নেই, শূন্যও নয়।
     *
     * শূন্য ফেরালে ডলারের বিলটা শূন্য টাকার বিল হয়ে বইয়ে বসত, আর
     * সংখ্যাটা এত ছোট যে কেউ খেয়ালও করত না। এক ফেরালে আরো খারাপ:
     * ১০০ ডলার ১০০ টাকা হয়ে যেত।
     */
    public function test_before_the_first_rate_there_is_no_rate_at_all(): void
    {
        $usd = $this->currency('USD');

        $this->rates()->record($usd, '2026-07-01', '117.25');

        $this->assertNull($usd->rateOn(Carbon::parse('2026-06-30')));
        $this->assertNull($usd->toBase('100', Carbon::parse('2026-06-30')));
        $this->assertNull($this->currency('EUR')->rateOn());
    }

    /** একই দিনে দ্বিতীয়বার বসানো মানে সংশোধন, দ্বিতীয় সারি নয়। */
    public function test_the_same_day_twice_corrects_instead_of_duplicating(): void
    {
        $usd = $this->currency('USD');

        $this->rates()->record($usd, '2026-08-01', '119.80');
        $this->rates()->record($usd, '2026-08-01', '119.95');

        $this->assertSame(1, ExchangeRate::query()->where('currency_id', $usd->id)->count());
        $this->assertSame(0, bccomp((string) $usd->rateOn(Carbon::parse('2026-08-01')), '119.95', 6));
    }

    public function test_the_rate_screen_lists_every_dated_rate(): void
    {
        $usd = $this->currency('USD');

        $this->rates()->record($usd, '2026-07-01', '117.25', 'Sonali Bank');
        $this->rates()->record($usd, '2026-08-01', '119.80', 'Sonali Bank');

        $this->get(route('master_data.currency.rates', ['id' => $usd->id]))
            ->assertOk()
            ->assertSee('117.250000')
            ->assertSee('119.800000')
            ->assertSee('Sonali Bank');
    }

    public function test_a_rate_is_saved_through_the_screen(): void
    {
        $usd = $this->currency('USD');

        $this->post(route('master_data.currency.rates.store', ['id' => $usd->id]), [
            'effective_from' => '2026-08-01',
            'rate' => '119.80',
            'source' => 'Sonali Bank',
        ])->assertRedirect();

        $this->assertSame(0, bccomp((string) $usd->fresh()->rateOn(Carbon::parse('2026-08-01')), '119.80', 6));
    }

    // ── সুইচ ──────────────────────────────────────────────────────────

    /**
     * বন্ধ করা তালিকার ঠিকানাও বন্ধ।
     *
     * মেনু থেকে সরানোই যথেষ্ট নয়: বুকমার্ক থেকে যায়। খোলা থাকলে
     * সুইচটা কেবল লুকানোর ভান করত।
     */
    public function test_switching_currencies_off_closes_the_screens_too(): void
    {
        $usd = $this->currency('USD');

        $settings = app(SettingsService::class);
        $settings->set('master_data.multi_currency_enabled', false);
        $settings->flush();

        $this->get(route('master_data.currency.index'))->assertNotFound();
        $this->get(route('master_data.currency.rates', ['id' => $usd->id]))->assertNotFound();
        $this->post(route('master_data.currency.rates.store', ['id' => $usd->id]), [
            'effective_from' => '2026-08-01', 'rate' => '119.80',
        ])->assertNotFound();
    }

    public function test_switching_vehicles_off_closes_both_vehicle_lists(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('master_data.vehicle_enabled', false);
        $settings->flush();

        $this->get(route('master_data.vehicle.index'))->assertNotFound();
        $this->get(route('master_data.vehicle_type.index'))->assertNotFound();
        $this->get(route('master_data.vehicle.create'))->assertNotFound();
    }

    /** সুইচ বন্ধ থাকলে মেনুতেও সারিগুলো নেই। */
    public function test_the_menu_follows_the_switches(): void
    {
        $settings = app(SettingsService::class);

        $this->assertStringContainsString(
            __('master_data::menu.currencies'),
            $this->get(route('master_data.unit.index'))->assertOk()->getContent(),
        );

        $settings->set('master_data.multi_currency_enabled', false);
        $settings->set('master_data.vehicle_enabled', false);
        $settings->flush();

        $html = $this->get(route('master_data.unit.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(__('master_data::menu.currencies'), $html);
        $this->assertStringNotContainsString(__('master_data::menu.vehicles'), $html);
    }

    // ── গাড়ি ──────────────────────────────────────────────────────────

    public function test_a_vehicle_needs_a_plate(): void
    {
        $this->post(route('master_data.vehicle.store'), [
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'owner_type' => 'own',
        ])->assertSessionHasErrors('registration_no');

        $this->assertSame(0, Vehicle::query()->count());
    }

    /**
     * বাছাইয়ের ঘরে তালিকার বাইরের মান বসে না।
     *
     * আগে select-এর নিয়ম ছিল শুধু 'nullable', তাই ফর্ম বাইপাস করে
     * যেকোনো লেখা পাঠানো যেত — আর তালিকার পর্দায় অচেনা মানটা ফাঁকা
     * দেখাত, যেন ঘরটা ভরাই হয়নি।
     */
    public function test_a_dropdown_refuses_a_value_that_is_not_on_its_list(): void
    {
        $this->post(route('master_data.vehicle.store'), [
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'registration_no' => 'DHAKA METRO TA 11-2233',
            'owner_type' => 'leased',
        ])->assertSessionHasErrors('owner_type');

        $this->assertSame(0, Vehicle::query()->count());
    }

    /**
     * অন্য কোম্পানির ধরন বেছে নেওয়া যায় না।
     *
     * ড্রপডাউনে সেটা কখনো দেখা যেত না, কিন্তু id-টা হাতে পাঠানো যেত —
     * আর তখন এক কোম্পানির গাড়ি অন্য কোম্পানির ধরনের দিকে দেখাত।
     */
    public function test_a_vehicle_cannot_borrow_another_companys_type(): void
    {
        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        $foreign = VehicleType::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'code' => 'FOREIGN',
            'name_en' => 'Someone else\'s type',
        ]);

        $this->post(route('master_data.vehicle.store'), [
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'registration_no' => 'DHAKA METRO TA 11-2233',
            'vehicle_type_id' => $foreign->id,
            'owner_type' => 'own',
        ])->assertSessionHasErrors('vehicle_type_id');

        $this->assertSame(0, Vehicle::query()->count());
    }

    public function test_a_vehicle_is_created_and_found_by_its_plate(): void
    {
        $truck = VehicleType::query()->where('code', 'TRUCK')->firstOrFail();

        $this->post(route('master_data.vehicle.store'), [
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'name_bn' => 'নীল ট্রাক',
            'registration_no' => 'DHAKA METRO TA 11-2233',
            'vehicle_type_id' => $truck->id,
            'capacity_kg' => '3000',
            'owner_type' => 'own',
            'driver_name' => 'Karim Mia',
            'driver_phone' => '01711000000',
        ])->assertRedirect(route('master_data.vehicle.index'));

        $vehicle = Vehicle::query()->firstOrFail();

        $this->assertSame('DHAKA METRO TA 11-2233', $vehicle->registration_no);
        $this->assertSame('TRUCK', $vehicle->vehicleType->code);
        $this->assertSame(0, bccomp((string) $vehicle->capacity_kg, '3000', 4));

        // নম্বরপ্লেট দিয়েও খোঁজা যায় — কাগজে ওটাই লেখা থাকে
        $this->assertTrue(Vehicle::query()->search('11-2233')->exists());
        $this->assertTrue(Vehicle::query()->search('Karim')->exists());
    }

    // ── চালানের সাথে যোগ ──────────────────────────────────────────────

    /**
     * বহরের গাড়ি বাছলে চালান সত্যিকারের যোগ রাখে, শুধু লেখা নয়।
     *
     * ── কেন FK, শুধু নম্বর নয় ────────────────────────────────────────
     * "এই ট্রাকটা এ মাসে কয়টা চালানে গেল" — লেখা নম্বর দিয়ে এর উত্তর
     * দিতে গেলে বানানে মেলাতে হত, আর "DHAKA METRO TA 11-2233" ও
     * "Dhaka Metro Ta 11 2233" দুইটা আলাদা গাড়ি হয়ে যেত।
     */
    public function test_a_challan_remembers_which_fleet_vehicle_carried_it(): void
    {
        $vehicle = $this->fleetVehicle();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => '2026-08-04',
            'vehicle_id' => $vehicle->id,
            'driver_name' => 'Karim Mia',
        ], [[
            'product_id' => Product::query()->value('id'),
            'delivered_qty' => '2',
            'rate' => '100',
        ]]);

        $this->assertSame($vehicle->id, $challan->vehicle_id);
        $this->assertSame($vehicle->registration_no, $challan->fresh()->vehiclePlate());
    }

    /**
     * বহরের বাইরের গাড়ি — শুধু লেখা নম্বর, আর সেটাই ছাপা হয়।
     *
     * ভাড়ার ট্রাক বাধ্যতামূলকভাবে তালিকায় থাকতে হবে বললে গুদামের লোক
     * যেকোনো একটা গাড়ি বেছে নিতেন শুধু ফর্মটা পার করতে।
     */
    public function test_a_rented_truck_still_goes_out_with_only_a_typed_number(): void
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => '2026-08-04',
            'vehicle_no' => 'DHAKA METRO TA 99-0001',
        ], [[
            'product_id' => Product::query()->value('id'),
            'delivered_qty' => '1',
            'rate' => '100',
        ]]);

        $this->assertNull($challan->vehicle_id);
        $this->assertSame('DHAKA METRO TA 99-0001', $challan->vehiclePlate());
    }

    /** নম্বরপ্লেট বদলালে পুরনো চালানও নতুন নম্বর দেখায়। */
    public function test_correcting_a_plate_in_the_master_fixes_every_old_challan(): void
    {
        $vehicle = $this->fleetVehicle();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => '2026-08-04',
            'vehicle_id' => $vehicle->id,
            'vehicle_no' => 'typed by hand, wrongly',
        ], [[
            'product_id' => Product::query()->value('id'),
            'delivered_qty' => '1',
            'rate' => '100',
        ]]);

        $vehicle->forceFill(['registration_no' => 'DHAKA METRO TA 22-3344'])->save();

        $this->assertSame('DHAKA METRO TA 22-3344', $challan->fresh()->vehiclePlate());
    }

    public function test_the_challan_form_offers_the_fleet_only_when_it_is_switched_on(): void
    {
        $this->fleetVehicle();

        $this->assertStringContainsString('name="vehicle_id"',
            $this->get(route('sales.challan.create'))->assertOk()->getContent());

        $settings = app(SettingsService::class);
        $settings->set('master_data.vehicle_enabled', false);
        $settings->flush();

        $html = $this->get(route('sales.challan.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="vehicle_id"', $html);

        // লেখা নম্বরের ঘরটা থেকেই যায় — নাহলে ভাড়ার গাড়ির নম্বর
        // লেখার কোনো জায়গা থাকত না
        $this->assertStringContainsString('name="vehicle_no"', $html);
    }

    private function fleetVehicle(): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'registration_no' => 'DHAKA METRO TA 11-2233',
            'vehicle_type_id' => VehicleType::query()->where('code', 'TRUCK')->value('id'),
            'owner_type' => 'own',
        ]);
    }

    public function test_the_defaults_install_a_base_currency_and_vehicle_types(): void
    {
        $this->assertGreaterThan(0, VehicleType::query()->count());
        $this->assertSame(1, Currency::query()->where('is_default', true)->count());
        $this->assertSame('BDT', Currency::query()->where('is_default', true)->value('code'));
    }
}
