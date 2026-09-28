<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\PlannedScreenController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গাড়ি লোড হত মুখে মুখে — কোন গাড়িতে কোন চালানের কী উঠবে, কোনো কাগজ ছিল না
 * (মালিকের "Delivery Processing" নকশা, ২৮ সেপ্টেম্বর ২০২৬, রাত)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লোডিং শিট ট্রিপ ধরে ([[LoadingSheetController]]): পণ্য ধরে মোট, চালান ধরে সারি, ছাপা, আর
 * "লোডিং নিশ্চিত" — ট্রিপের বরাদ্দ বা তোলার ধাপের চালান "প্যাক হয়েছে"-তে; আগেই প্যাক বা অন্য
 * ধাপের চালান যেমন আছে। ⛔ বেরোনো ট্রিপে নয়। মেনুর "তৈরি হচ্ছে" সারি এখন আসল পাতা।
 */
final class TheTruckWasLoadedFromMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** ⭐ শিট — পণ্য ধরে যোগফল দুই চালান মিলিয়ে, আর প্রতিটা চালান তার গ্রাহকসহ। */
    public function test_the_sheet_totals_every_product_on_the_truck(): void
    {
        $a = $this->confirmedChallan('5');
        $b = $this->confirmedChallan('3');
        $trip = $this->openTrip([$a->id, $b->id]);

        $this->get(route('sales.loading_sheet.index'))->assertOk()->assertSee($trip->document_no);

        $this->get(route('sales.loading_sheet.show', $trip))
            ->assertOk()
            ->assertSee($a->document_no)->assertSee($b->document_no)
            // ⓘ ৫ + ৩ = ৮ — এক পণ্য, দুই চালান, এক সারিতে যোগফল
            ->assertSee('8.00');
    }

    /**
     * ⭐ লোডিং নিশ্চিত — বরাদ্দ আর তোলার ধাপের চালান প্যাকে; আগেই প্যাক যা, তা প্যাকেই।
     * ⛔→⭐ একই মানুষ: ধাপ বদলানোর চাবি ছাড়া ৪০৩, চাবিতে কাজ।
     */
    public function test_confirming_the_load_packs_the_trips_challans_on_its_key(): void
    {
        $allocated = $this->confirmedChallan('2');
        $picking = $this->confirmedChallan('2');
        $packed = $this->confirmedChallan('2');
        $stages = app(DeliveryStageService::class);
        $stages->move($picking, DeliveryStage::PICKING);
        $stages->move($packed, DeliveryStage::PACKED);

        $trip = $this->openTrip([$allocated->id, $picking->id, $packed->id]);

        $clerk = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $clerk->givePermissionTo('sales.shipment.view');

        $this->actingAs($clerk->fresh())->post(route('sales.loading_sheet.confirm', $trip))->assertForbidden();
        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($allocated), '⛔ ৪০৩ ফিরেছে, তবু প্যাক হয়ে গেছে।');

        $clerk->givePermissionTo('sales.delivery.update');

        $this->actingAs($clerk->fresh())->from(route('sales.loading_sheet.show', $trip))
            ->post(route('sales.loading_sheet.confirm', $trip))
            ->assertSessionHasNoErrors()->assertRedirect();

        foreach ([$allocated, $picking, $packed] as $challan) {
            $this->assertSame(DeliveryStage::PACKED, $this->stageOf($challan),
                "⛔ {$challan->document_no}: লোডিং নিশ্চিতের পরে প্যাক হয়নি।");
        }
    }

    /** ⛔ বেরোনো ট্রিপে "লোডিং নিশ্চিত" নয় — মাল তখন রাস্তায়। */
    public function test_a_trip_already_out_cannot_be_confirmed_loaded(): void
    {
        $challan = $this->confirmedChallan('2');
        $trip = app(ShipmentService::class)->dispatch($this->openTrip([$challan->id]));

        $this->from(route('sales.loading_sheet.show', $trip))
            ->post(route('sales.loading_sheet.confirm', $trip))
            ->assertSessionHasErrors('status');

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));
    }

    /** ⭐ ছাপা — PDF, ট্রিপের নম্বরসহ; আর মেনুর সারি আর "তৈরি হচ্ছে" নয়। */
    public function test_the_sheet_prints_and_the_menu_row_is_no_longer_a_sign(): void
    {
        $trip = $this->openTrip([$this->confirmedChallan('2')->id]);

        $bytes = $this->get(route('sales.print.loading_sheet', $trip))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', (string) $bytes);

        $this->assertNotContains('loading_sheet', PlannedScreenController::SCREENS,
            '⛔ লোডিং শিট আসল পাতা, অথচ এখনো "তৈরি হচ্ছে" তালিকায়।');
        $this->get(route('sales.planned', ['screen' => 'loading_sheet']))->assertNotFound();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmedChallan(string $qty): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $biscuit->id, 'delivered_qty' => $qty, 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /** @param  list<int>  $challanIds */
    private function openTrip(array $challanIds): Shipment
    {
        return app(ShipmentService::class)->create([
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩',
            'driver_name' => 'রফিক',
        ], $challanIds);
    }

    private function stageOf(DeliveryChallan $challan): string
    {
        return (string) DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }
}
