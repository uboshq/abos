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
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SaleTracking;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ চালানের সময়রেখা — মালিকের বিক্রয়-পরিকল্পনা, ৪ অক্টোবর ২০২৬:
 * তৈরি → গাড়ি → লোডিং → প্যাক → গেট পাস → পথে → পৌঁছেছে; প্রতিটা ধাপে সময়, কে করলেন, গাড়ি আর চালক।
 *
 * দাবি: পুরো পথ পেরোলে সাতটাই "হয়েছে", সময়সহ; গাড়ির ধাপ বলে কে কখন বসালেন (পরে বসালে তিনিই, লেখক নন);
 * গাড়ি আর চালক গাড়ির ধাপ থেকে দেখা যায়, না-হওয়া ধাপে নয়; চালানের পাতা আর ট্র্যাকিংয়ের পাতা দুটোই আঁকে।
 */
final class EveryChallanShowsItsTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_a_challan_that_reached_the_door_shows_all_seven_steps_with_the_truck(): void
    {
        $challan = $this->confirmed(['vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩', 'driver_name' => 'করিম চালক', 'driver_phone' => '01711000111']);
        $stages = app(DeliveryStageService::class);
        foreach ([DeliveryStage::PICKING, DeliveryStage::PACKED, DeliveryStage::DISPATCHED] as $stage) {
            $stages->move($challan->fresh(), $stage, ['note' => 'test']);
        }
        $stages->move($challan->fresh(), DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম']);

        $timeline = collect(app(SaleTracking::class)->timeline($challan->fresh()))->keyBy('step');

        $this->assertSame(['created', 'vehicle', 'loading', 'packed', 'gate_pass', 'on_the_way', 'delivered'], $timeline->keys()->all());
        foreach ($timeline as $step => $row) {
            $this->assertSame('done', $row['state'], "⛔ '{$step}' পেরিয়েও হয়নি বলছে।");
            $this->assertNotNull($row['at'], "⛔ '{$step}'-এর সময় নেই।");
        }
        $this->assertSame('ঢাকা মেট্রো ট ১১-২২৩৩', $timeline['vehicle']['vehicle']);
        $this->assertSame('করিম চালক · 01711000111', $timeline['on_the_way']['driver'], '⛔ পথের ধাপে চালক নেই।');
        $this->assertSame($this->owner->name, $timeline['vehicle']['by']);

        $page = $this->get(route('sales.challan.show', $challan))->assertOk();
        $page->assertSee('data-challan-timeline', false)->assertSee('ঢাকা মেট্রো ট ১১-২২৩৩')->assertSee(__('sales::tracking.timeline.on_the_way'));

        $this->get(route('sales.tracking.show', ['challan', $challan->public_id]))->assertOk()
            ->assertSee('data-challan-timeline', false)->assertSee('করিম চালক · 01711000111');
    }

    public function test_the_vehicle_step_names_whoever_set_the_truck_and_untaken_steps_show_no_truck(): void
    {
        $challan = $this->draft([]);

        $before = collect(app(SaleTracking::class)->timeline($challan))->keyBy('step');
        $this->assertNotSame('done', $before['vehicle']['state'], '⛔ গাড়ি না বসাতেই "গাড়ি" হয়েছে বলছে।');
        $this->assertNull($before['vehicle']['vehicle']);

        // অন্য একজন পরে গাড়ি বসালেন
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'name' => 'গাড়ির দায়িত্বে জামাল']);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($clerk);
        $service = app(DeliveryChallanService::class);
        $service->update($challan->fresh(), [
            'customer_id' => $challan->customer_id, 'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(), 'vehicle_no' => 'ঢাকা-১২', 'driver_name' => 'করিম',
        ], $this->lines());

        $after = collect(app(SaleTracking::class)->timeline($challan->fresh()))->keyBy('step');
        $this->assertSame('done', $after['vehicle']['state']);
        $this->assertSame('গাড়ির দায়িত্বে জামাল', $after['vehicle']['by'], '⛔ গাড়ি যিনি বসালেন, তাঁর বদলে অন্য নাম।');
        $this->assertSame('ঢাকা-১২', $after['vehicle']['vehicle']);
        $this->assertNull($after['on_the_way']['vehicle'], '⛔ মাল রওনা হয়নি, অথচ "পথে" ধাপে গাড়ি দেখাচ্ছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $transport */
    private function confirmed(array $transport): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm($this->draft($transport));
    }

    /** @param  array<string, mixed>  $transport */
    private function draft(array $transport): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            ...$transport,
        ], $this->lines());
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']];
    }
}
