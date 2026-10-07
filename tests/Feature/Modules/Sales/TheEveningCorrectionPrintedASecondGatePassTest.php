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
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ট্রিপের সন্ধ্যার শোধরানো নতুন গেট পাস বা বিল বানায় না — ৩০ সেপ্টেম্বর ২০২৬ (৯-এর পাশের সন্দেহ)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * চালক বললেন "পৌঁছেছে" বা "ফেরত", তারপর ভুল বুঝে সারিটা আবার "অপেক্ষায়" করলেন। চালানের
 * ধাপ তখন ফেরে "রওনা"-য়, আর রওনার পথ ([[DeliveryStageService::write()]]) ধরে নিত মাল আবার
 * গেট পেরোল — একটা নতুন গেট পাস বেরোত, অথচ গাড়ি একবারই বেরিয়েছিল।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * একই ট্রিপে এই চালানের গেট পাস আগে থেকে থাকলে ওটা শোধরানো, রওনা নয় — বিল বা গেট পাস
 * কিছুই নতুন হয় না। ⭐ নতুন ট্রিপে বা হাতে আবার রওনা হলে আগের মতোই নতুন কাগজ।
 */
final class TheEveningCorrectionPrintedASecondGatePassTest extends TestCase
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

    /** ⛔ "পৌঁছেছে" থেকে আবার "অপেক্ষায়" — গাড়ি একবারই বেরিয়েছে। */
    public function test_undoing_delivered_on_the_same_trip_prints_nothing_new(): void
    {
        [$challan, $line] = $this->onATrip();
        $trips = app(ShipmentService::class);

        $trips->settle($line, ShipmentLine::DELIVERED);
        $this->assertSame(DeliveryStage::DELIVERED, $this->stage($challan), 'প্রস্তুতিটাই ভুল — চালক "পৌঁছেছে" বলার পরে ধাপ বদলায়নি।');

        $trips->settle($line->fresh(), ShipmentLine::PENDING);
        $this->assertSame(DeliveryStage::DISPATCHED, $this->stage($challan), 'প্রস্তুতিটাই ভুল — শোধরানোয় ধাপ "রওনা"-য় ফেরেনি।');

        $this->assertSame(1, $this->passes($challan), '⛔ সন্ধ্যার শোধরানোয় দ্বিতীয় গেট পাস বেরিয়েছে — গাড়ি একবারই বেরিয়েছিল।');
        $this->assertSame(1, $this->bills($challan), '⛔ সন্ধ্যার শোধরানোয় আরেকটা বিল।');
    }

    /** ⛔ "ফেরত" থেকে আবার "অপেক্ষায়" — একই ট্রিপ, একই কথা। */
    public function test_undoing_returned_on_the_same_trip_prints_nothing_new(): void
    {
        [$challan, $line] = $this->onATrip();
        $trips = app(ShipmentService::class);

        $trips->settle($line, ShipmentLine::RETURNED, 'দোকান বন্ধ');
        $trips->settle($line->fresh(), ShipmentLine::PENDING);
        $this->assertSame(DeliveryStage::DISPATCHED, $this->stage($challan), 'প্রস্তুতিটাই ভুল — শোধরানোয় ধাপ "রওনা"-য় ফেরেনি।');

        $this->assertSame(1, $this->passes($challan), '⛔ "ফেরত" শোধরাতেই দ্বিতীয় গেট পাস।');
        $this->assertSame(1, $this->bills($challan));
    }

    /** ⭐ পৌঁছায়নি, তারপর হাতে সত্যিকারের নতুন রওনা — নতুন গেট পাস চাই, বিল নয়। */
    public function test_a_real_second_departure_still_gets_its_own_gate_pass(): void
    {
        $challan = $this->confirmedChallan();
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $stages->move($challan, DeliveryStage::DISPATCHED);

        $this->assertSame(2, $this->passes($challan), '⛔ পরদিন সত্যিই আবার বেরোল, অথচ নতুন গেট পাস নেই।');
        $this->assertSame(1, $this->bills($challan), '⛔ আবার রওনায় দ্বিতীয় বিল।');
    }

    /** ⭐ ট্রিপ বাতিল, তারপর নতুন ট্রিপে বেরোনো — অন্য ট্রিপ, তাই নতুন গেট পাস; বিল একটাই। */
    public function test_a_new_trip_after_a_cancelled_one_gets_its_own_gate_pass(): void
    {
        [$challan] = $this->onATrip();
        $trips = app(ShipmentService::class);

        $first = ShipmentLine::query()->where('delivery_challan_id', $challan->id)->firstOrFail()->shipment;
        $trips->cancel($first, 'ভুল গাড়ি');

        $trips->dispatch($trips->create([
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'চট্ট মেট্রো ন ২২-৪৪৫৫',
            'driver_name' => 'করিম',
        ], [$challan->id]));

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stage($challan), 'প্রস্তুতিটাই ভুল — নতুন ট্রিপে চালান বেরোয়নি।');
        $this->assertSame(2, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ নতুন ট্রিপে সত্যিই বেরোল, অথচ নতুন গেট পাস নেই — দারোয়ান আগের গাড়ির কাগজ দেখবেন।');
        $this->assertSame(1, $this->bills($challan), '⛔ নতুন ট্রিপে দ্বিতীয় বিল।');
    }

    /** @return array{0: DeliveryChallan, 1: ShipmentLine} */
    private function onATrip(): array
    {
        $challan = $this->confirmedChallan();
        $trips = app(ShipmentService::class);

        $trip = $trips->dispatch($trips->create([
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩',
            'driver_name' => 'রফিক',
        ], [$challan->id]));

        $this->assertSame(1, $this->passes($challan), 'প্রস্তুতিটাই ভুল — ট্রিপ বেরোলে একটা গেট পাস হওয়ার কথা।');
        $this->assertSame(1, $this->bills($challan), 'প্রস্তুতিটাই ভুল — ট্রিপ বেরোলে একটা বিল হওয়ার কথা।');

        return [$challan, ShipmentLine::query()->where('shipment_id', $trip->id)->where('delivery_challan_id', $challan->id)->firstOrFail()];
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    private function stage(DeliveryChallan $challan): string
    {
        return (string) app(DeliveryStageService::class)->ensure($challan->fresh())->stage;
    }

    private function passes(DeliveryChallan $challan): int
    {
        return GatePass::query()->where('delivery_challan_id', $challan->id)->where('status', GatePass::ISSUED)->count();
    }

    private function bills(DeliveryChallan $challan): int
    {
        return SalesInvoice::query()
            ->whereHas('lines', fn ($q) => $q->whereIn('delivery_challan_line_id', $challan->lines()->pluck('id')))
            ->count();
    }
}
