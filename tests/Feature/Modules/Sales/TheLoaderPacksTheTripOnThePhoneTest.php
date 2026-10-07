<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনে লোডিং শিট — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬ ([[LoadingApiController]])।
 *
 * ⭐ দাবি — দুই দোকানের দুই চালানে একই পণ্য (২ আর ৩), এক ট্রিপে:
 *   · তালিকায় খোলা ট্রিপ, চালকের ফোনসহ; শিটে পণ্য ধরে একটাই সারি ৫, কার জন্য দুই দোকান — ওয়েবের একই হিসাব
 *   · "প্যাক হয়েছে" দুই চালানকেই প্যাকে নেয়; আবার চাপলে কিছুই নয়; রওনা হওয়া ট্রিপে ফেরত, তালিকাতেও নেই
 *   · কেবল দেখার চাবিতে শিট খোলে, "প্যাক" নয়; কোনো চাবি ছাড়া শিটও নয়
 */
final class TheLoaderPacksTheTripOnThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_the_sheet_says_how_much_and_for_whom_and_packed_moves_every_challan_once(): void
    {
        [$shopA, $shopB] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $first = $this->challan($shopA, '2');
        $second = $this->challan($shopB, '3');
        $trip = $this->trip([$first->id, $second->id]);
        $this->phone($this->owner);

        $row = collect($this->getJson('/api/v1/sales/loading')->assertOk()->assertJsonPath('next_page', null)->json('rows'))
            ->firstWhere('id', $trip->public_id);
        $this->assertNotNull($row, '⛔ খোলা ট্রিপ ফোনের তালিকায় নেই।');
        $this->assertSame([2, '01811-222333'], [$row['challans'], $row['driver_phone']]);

        $sheet = $this->getJson('/api/v1/sales/loading/'.$trip->public_id)->assertOk();
        $this->assertCount(1, $sheet->json('products'), '⛔ একই পণ্য দুই সারিতে।');
        $this->assertSame(0, bccomp('5', $sheet->json('products.0.qty'), 4), '⛔ পণ্য ধরে যোগ ২ + ৩ নয়।');
        $who = collect($sheet->json('products.0.for'))->pluck('who')->implode(' | ');
        $this->assertStringContainsString($shopA->name(), $who, '⛔ কার জন্য কত — দোকান নেই।');
        $this->assertStringContainsString($shopB->name(), $who);
        $this->assertSame([false, false], $sheet->json('challans.*.packed'));

        $this->postJson('/api/v1/sales/loading/'.$trip->public_id.'/packed')->assertOk()->assertJsonPath('packed', 2);
        foreach ([$first, $second] as $challan) {
            $this->assertSame(DeliveryStage::PACKED, DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage'), '⛔ চালান প্যাকে যায়নি।');
        }
        $this->assertSame([true, true], $this->getJson('/api/v1/sales/loading/'.$trip->public_id)->json('challans.*.packed'));
        $this->postJson('/api/v1/sales/loading/'.$trip->public_id.'/packed')->assertOk()->assertJsonPath('packed', 0);

        DB::table('sal_shipments')->where('id', $trip->id)->update(['status' => DocumentStatus::CONFIRMED]);
        $this->postJson('/api/v1/sales/loading/'.$trip->public_id.'/packed')->assertStatus(422);
        $this->assertNotContains($trip->public_id, $this->getJson('/api/v1/sales/loading')->json('rows.*.id') ?? [], '⛔ রওনা হওয়া ট্রিপ এখনো তালিকায়।');
    }

    public function test_the_view_key_opens_the_sheet_but_only_the_delivery_key_packs(): void
    {
        $trip = $this->trip([$this->challan(Customer::query()->firstOrFail(), '2')->id]);

        $viewer = $this->member();
        $viewer->givePermissionTo('sales.shipment.view');
        $this->phone($viewer);
        $this->getJson('/api/v1/sales/loading/'.$trip->public_id)->assertOk();
        $this->postJson('/api/v1/sales/loading/'.$trip->public_id.'/packed')->assertForbidden();
        $this->assertNotSame(DeliveryStage::PACKED, DeliveryState::query()->where('delivery_challan_id', $trip->lines()->value('delivery_challan_id'))->value('stage'));

        $this->phone($this->member());
        $this->getJson('/api/v1/sales/loading')->assertForbidden();
        $this->getJson('/api/v1/sales/loading/'.$trip->public_id)->assertForbidden();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function challan(Customer $customer, string $qty): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => $qty, 'rate' => '10']]));
    }

    /** @param  list<int>  $challans */
    private function trip(array $challans): Shipment
    {
        return app(ShipmentService::class)->create([
            'trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id, 'vehicle_no' => 'DM-T 11-0001',
            'driver_name' => 'করিম', 'driver_phone' => '01811-222333',
        ], $challans);
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $user->companies()->attach($this->owner->current_company_id, ['is_active' => true]);

        return $user;
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
