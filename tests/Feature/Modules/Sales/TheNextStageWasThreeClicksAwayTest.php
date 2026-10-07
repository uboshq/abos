<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরের ধাপ তিন ক্লিক দূরে ছিল — তালিকা থেকে চালান খোলো, নিচে নামো, ফর্ম খোলো (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * "হাতে থাকা কাজ"-এর প্রতিটা সারিতে পরের ধাপের নামে বোতাম; তথ্য লাগলে ছোট ঘর — রওনায় গাড়ি ও চালক
 * (গেট পাস ঐ ছবিটাই নেয়), পৌঁছেছেতে প্রাপক আগে থেকে ভরা; বাকি ধাপ "⋯"-তে; ট্রিপে থাকা সারিতে
 * "ট্রিপ …"; আর কেবল ধাপ বদলানোর চাবিধারী দেখেন।
 */
final class TheNextStageWasThreeClicksAwayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->clerk = User::factory()->create(['current_company_id' => $company->id]);
        $this->clerk->companies()->attach($company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo('sales.delivery.view');
    }

    /** ⛔→⭐ একই মানুষ: ধাপ বদলানোর চাবি ছাড়া সারিতে বোতাম নেই; চাবিতে পরের ধাপের নামে বোতাম। */
    public function test_the_row_button_shows_the_next_stage_only_on_the_update_key(): void
    {
        $challan = $this->confirmedChallan();

        $this->actingAs($this->clerk->fresh())->get(route('sales.delivery.index'))
            ->assertOk()->assertSee($challan->document_no)
            ->assertDontSee('data-row-next', false);

        $this->clerk->givePermissionTo('sales.delivery.update');

        $this->actingAs($this->clerk->fresh())->get(route('sales.delivery.index'))
            ->assertOk()
            ->assertSee('data-row-next', false)
            // ⓘ বরাদ্দ থেকে স্বাভাবিক পরের ধাপ — তোলা হচ্ছে
            ->assertSee(DeliveryStage::label(DeliveryStage::PICKING));
    }

    /**
     * ⭐ সারির ঘর থেকে রওনা — বহরের গাড়ি বাছলে গাড়ি আর তার চালক চালানে বসে, আর গেট পাস ঐ
     * গাড়ির নম্বরপ্লেট নিয়ে তৈরি হয়।
     */
    public function test_dispatching_from_the_row_stamps_the_fleet_vehicle_and_the_gate_pass_takes_it(): void
    {
        $this->clerk->givePermissionTo('sales.delivery.update');
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::PACKED);

        $vehicle = Vehicle::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'VAN-9',
            'name_en' => 'Van 9',
            'name_bn' => 'ভ্যান ৯',
            'registration_no' => 'ঢাকা মেট্রো ন ৯৯-০০০৯',
            'driver_name' => 'সালাম',
            'driver_phone' => '01711000009',
            'is_active' => true,
        ]);

        $this->actingAs($this->clerk->fresh())->from(route('sales.delivery.index'))
            ->post(route('sales.delivery.move', $challan), [
                'stage' => DeliveryStage::DISPATCHED,
                'vehicle_id' => $vehicle->id,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $challan->refresh();
        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));
        $this->assertSame((int) $vehicle->id, (int) $challan->vehicle_id, '⛔ রওনার গাড়ি চালানে বসেনি।');
        $this->assertSame('সালাম', $challan->driver_name, '⛔ বহরের গাড়ির চালক চালানে আসেনি।');

        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $this->assertSame('ঢাকা মেট্রো ন ৯৯-০০০৯', $pass->vehicle_no, '⛔ গেট পাসে বহরের গাড়ির নম্বরপ্লেট নেই।');
        $this->assertSame('সালাম', $pass->driver_name);
    }

    /** ⭐ ট্রিপে থাকা চালানের সারিতে বোতাম নেই — "ট্রিপ …"। */
    public function test_a_row_on_a_trip_says_the_trip_instead_of_a_button(): void
    {
        $this->clerk->givePermissionTo('sales.delivery.update');
        $challan = $this->confirmedChallan();

        $trips = app(ShipmentService::class);
        $trip = $trips->dispatch($trips->create(['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id], [$challan->id]));

        $this->actingAs($this->clerk->fresh())->get(route('sales.delivery.index'))
            ->assertOk()
            ->assertSee('data-row-trip', false)
            ->assertSee(__('sales::delivery.action.trip_short', ['trip' => $trip->document_no]));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmedChallan(): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $biscuit->id, 'delivered_qty' => '2', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    private function stageOf(DeliveryChallan $challan): string
    {
        return (string) DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }
}
