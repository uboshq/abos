<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ভূমিকাগুলো নিজের কাজই করতে পারত না — বিক্রয়ের হাঁটার স্ক্রিপ্টে ধরা (সমন্বয়কারী, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * মালিকের ভূমিকা-ভাগে (২৭ সেপ্টেম্বর) অর্ডার নিশ্চিত করেন ম্যানেজার, আর ট্রিপ, লোডিং শিট ও রওনা
 * গুদামের কাজ। অথচ কোনো ছাঁচেই এই চাবিগুলো ছিল না:
 * - অর্ডার নিশ্চিত করার `sales.order.update`;
 * - ট্রিপ বানানো, রওনা, settle আর close-এর `sales.shipment.create`।
 * গুদামের ছাঁচে `sales.shipment.view`-ও ছিল না, তাই লোডিং শিট ৪০৩ দিত।
 *
 * ── ⭐ এখন (সমন্বয়কারীর অনুমোদিত প্রস্তাব) ────────────────────────────
 * - Warehouse: `sales.shipment.view` আর `sales.shipment.create`।
 * - Manager: `sales.order.update`, `sales.shipment.create`, আর বাতিলের `sales.shipment.cancel`।
 * - বাতিল কেবল Manager-এর; গুদামের নয়।
 * ⓘ দাবিগুলো কোম্পানির নিজের ভূমিকা দিয়ে, যেটা ছাঁচ থেকে বানানো ([[PermissionSyncer]])। একই মানুষ,
 * একই কাগজ: চাবি থাকলে দরজা খোলে, ভূমিকা থেকে চাবি তুলে নিলে ৪০৩।
 * ⚠️ ছাঁচ আগের ভূমিকা চওড়া করে না, তাই লাইভের পুরনো কোম্পানিতে চাবিগুলো হাতে দিতে হয়।
 */
final class TheRolesCouldNotDoTheirOwnJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⛔→⭐ ম্যানেজার অর্ডার নিশ্চিত করেন; ভূমিকা থেকে `sales.order.update` তুলে নিলে ৪০৩। */
    public function test_the_manager_confirms_an_order_and_only_by_the_update_key(): void
    {
        $manager = $this->userInRole('Manager');

        $first = $this->draftOrder();
        $this->actingAs($manager->fresh())->post(route('sales.order.confirm', $first))->assertRedirect();
        $this->assertSame(DocumentStatus::CONFIRMED, $first->fresh()->status, '⛔ ম্যানেজার অর্ডার নিশ্চিত করতে পারেননি।');

        $this->role('Manager')->revokePermissionTo('sales.order.update');

        $second = $this->draftOrder();
        $this->actingAs($manager->fresh())->post(route('sales.order.confirm', $second))->assertForbidden();
        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status);
    }

    /**
     * ⛔→⭐ গুদাম লোডিং শিট খোলে, ট্রিপ বানায় আর রওনা দেয়। ভূমিকা থেকে চাবি তুলে নিলে ঐ দরজাগুলোই ৪০৩।
     */
    public function test_the_warehouse_runs_the_trip_and_only_by_the_shipment_keys(): void
    {
        $storekeeper = $this->userInRole('Warehouse');

        $this->actingAs($storekeeper->fresh())->get(route('sales.loading_sheet.index'))->assertOk();

        $trip = $this->tripBy($storekeeper, $this->confirmedChallan());
        $this->assertNotNull($trip, '⛔ গুদাম ট্রিপ বানাতে পারেনি।');

        $this->actingAs($storekeeper->fresh())->post(route('sales.shipment.dispatch', $trip))->assertRedirect();
        $this->assertSame(DocumentStatus::CONFIRMED, $trip->fresh()->status, '⛔ গুদাম রওনা দিতে পারেনি।');

        $this->role('Warehouse')->revokePermissionTo('sales.shipment.create');

        $before = Shipment::query()->count();
        $this->actingAs($storekeeper->fresh())->post(route('sales.shipment.store'), $this->tripForm($this->confirmedChallan()))
            ->assertForbidden();
        $this->assertSame($before, Shipment::query()->count());

        $this->role('Warehouse')->revokePermissionTo('sales.shipment.view');
        $this->actingAs($storekeeper->fresh())->get(route('sales.loading_sheet.index'))->assertForbidden();
    }

    /** ⭐ ট্রিপ বাতিল কেবল ম্যানেজারের — গুদাম পারে না, ম্যানেজার পারেন; ম্যানেজারও ট্রিপ বানাতে পারেন। */
    public function test_only_the_manager_cancels_a_trip(): void
    {
        $storekeeper = $this->userInRole('Warehouse');
        $manager = $this->userInRole('Manager');

        $trip = $this->tripBy($manager, $this->confirmedChallan());
        $this->assertNotNull($trip, '⛔ ম্যানেজার ট্রিপ বানাতে পারেননি।');

        $reason = ['reason' => 'গাড়ি নষ্ট'];
        $this->actingAs($storekeeper->fresh())->post(route('sales.shipment.cancel', $trip), $reason)->assertForbidden();
        $this->assertNotSame(DocumentStatus::CANCELLED, $trip->fresh()->status);

        $this->actingAs($manager->fresh())->post(route('sales.shipment.cancel', $trip), $reason)->assertRedirect();
        $this->assertSame(DocumentStatus::CANCELLED, $trip->fresh()->status, '⛔ ম্যানেজার ট্রিপ বাতিল করতে পারেননি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** কোম্পানির নিজের ভূমিকা — ছাঁচ থেকে বানানো, হাতে লেখা চাবি নয়। */
    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('company_id', $this->company->id)->firstOrFail();
    }

    private function userInRole(string $name): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole($this->role($name));

        return $user;
    }

    private function draftOrder(): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'),
            'ordered_qty' => '1',
            'rate' => '10',
        ]]);
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [[
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'),
            'delivered_qty' => '1',
            'rate' => '10',
        ]]));
    }

    /** @return array<string, mixed> */
    private function tripForm(DeliveryChallan $challan): array
    {
        return [
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-০০০১',
            'challans' => [$challan->id],
        ];
    }

    private function tripBy(User $user, DeliveryChallan $challan): ?Shipment
    {
        $before = (int) Shipment::query()->max('id');

        $this->actingAs($user->fresh())->post(route('sales.shipment.store'), $this->tripForm($challan))
            ->assertSessionHasNoErrors()->assertRedirect();

        return Shipment::query()->where('id', '>', $before)->first();
    }
}
