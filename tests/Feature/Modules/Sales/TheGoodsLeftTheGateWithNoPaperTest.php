<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\GatePassService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাল গেট পেরোল, হাতে কোনো কাগজ নেই — মালিকের "Delivery Processing" নকশা, ২৮ সেপ্টেম্বর ২০২৬ (রাত)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "গেটপাস" ছিল কেবল চালানের দাম-ছাড়া একটা ছাপা — কোনো নিজের কাগজ নয়, নম্বর নেই, কে
 * দিলেন কখন দিলেন তার খাতা নেই। দারোয়ানের হাতে যে কাগজ, সেটা পরে কেউ মেলাতে পারত না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * রওনার মুহূর্তে নিজে জন্মায় — ট্রিপ বেরোলে বা হাতে "রওনা" বসালে ([[GatePassService]])।
 * এক রওনা, এক কাগজ; বাতিল বা খসড়া চালানে নয়; তারপর কেবল দেখা, ছাপা আর কারণসহ বাতিল।
 */
final class TheGoodsLeftTheGateWithNoPaperTest extends TestCase
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

    /** ⭐ হাতে "রওনা" — ঠিক একটা গেট পাস, চালান, গাড়ি আর যিনি দিলেন সহ। */
    public function test_a_hand_dispatch_issues_exactly_one_gate_pass(): void
    {
        $challan = $this->confirmedChallan(['vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩', 'driver_name' => 'রফিক']);

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        $passes = GatePass::query()->where('delivery_challan_id', $challan->id)->get();

        $this->assertCount(1, $passes, '⛔ রওনা হলো, অথচ গেট পাস একটা নয়।');
        $pass = $passes->first();
        $this->assertStringStartsWith('GP', (string) $pass->document_no, '⛔ গেট পাসের নিজের সিরিজ নেই।');
        $this->assertSame('ঢাকা মেট্রো ট ১১-২২৩৩', $pass->vehicle_no);
        $this->assertSame('রফিক', $pass->driver_name);
        $this->assertSame((int) $this->owner->id, (int) $pass->issued_by);
        $this->assertSame(GatePass::ISSUED, $pass->status);
    }

    /**
     * ⛔ একই রওনায় দুইবার ডাকলে দ্বিতীয় কাগজ নয়; ⭐ পৌঁছায়নি থেকে আবার রওনা মানে নতুন কাগজ।
     */
    public function test_one_dispatch_one_gate_pass_and_a_second_dispatch_a_second(): void
    {
        $challan = $this->confirmedChallan();
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $event = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)
            ->where('to_stage', DeliveryStage::DISPATCHED)->latest('id')->firstOrFail();

        $again = app(GatePassService::class)->issueFor($challan->fresh(), $event);

        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ একই রওনায় দ্বিতীয় গেট পাস।');
        $this->assertSame((int) $event->id, (int) $again->delivery_event_id);

        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $stages->move($challan, DeliveryStage::DISPATCHED);

        $this->assertSame(2, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ পরদিন আবার রওনা, অথচ নতুন গেট পাস নেই — দারোয়ান আগের দিনের কাগজ দেখবেন।');
    }

    /** ⭐ ট্রিপ বেরোলে প্রতিটা চালানের একটা করে, ট্রিপের গাড়ি ও চালক নিয়ে। */
    public function test_a_trip_dispatch_issues_one_gate_pass_per_challan(): void
    {
        $a = $this->confirmedChallan();
        $b = $this->confirmedChallan();

        $trips = app(ShipmentService::class);
        $trip = $trips->dispatch($trips->create([
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'চট্ট মেট্রো ন ২২-৪৪৫৫',
            'driver_name' => 'করিম',
        ], [$a->id, $b->id]));

        foreach ([$a, $b] as $challan) {
            $passes = GatePass::query()->where('delivery_challan_id', $challan->id)->get();
            $this->assertCount(1, $passes, "⛔ {$challan->document_no}: ট্রিপে গেল, গেট পাস একটা নয়।");
            $this->assertSame((int) $trip->id, (int) $passes->first()->shipment_id);
            $this->assertSame('চট্ট মেট্রো ন ২২-৪৪৫৫', $passes->first()->vehicle_no, '⛔ গেট পাসে ট্রিপের গাড়ি নেই।');
        }
    }

    /** ⛔ বাতিল চালানে গেট পাস হয় না — মাল গুদামে ফিরেছে। */
    public function test_a_cancelled_challan_gets_no_gate_pass(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryChallanService::class)->cancel($challan, 'ভুল চালান');

        $event = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->latest('id')->firstOrFail();

        $this->assertNull(app(GatePassService::class)->issueFor($challan->fresh(), $event));
        $this->assertSame(0, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ বাতিল চালানের গেট পাস বেরিয়েছে — কাগজ হাতে মাল ছাড়া গাড়ি বেরোত।');
    }

    /** ⛔ বাতিলে কারণ লাগে, দুইবার নয়, আর নিরীক্ষায় থাকে। */
    public function test_cancelling_needs_a_reason_and_is_audited(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $service = app(GatePassService::class);

        try {
            $service->cancel($pass, '   ');
            $this->fail('⛔ কারণ ছাড়া গেট পাস বাতিল হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $service->cancel($pass, 'গাড়ি বদল');
        $pass->refresh();

        $this->assertSame(GatePass::CANCELLED, $pass->status);
        $this->assertSame('গাড়ি বদল', $pass->cancel_reason);
        $this->assertSame((int) $this->owner->id, (int) $pass->cancelled_by);
        $this->assertTrue(AuditTrail::query()->where('auditable_type', $pass->getMorphClass())
            ->where('auditable_id', $pass->id)->exists(), '⛔ বাতিলটা নিরীক্ষায় নেই।');

        $this->expectException(ValidationException::class);
        $service->cancel($pass, 'আবার');
    }

    /** ⛔→⭐ একই মানুষ: দেখার চাবি ছাড়া তালিকা ও পাতা বন্ধ; চাবি দিলে খোলে, গেট পাস দেখা যায়। */
    public function test_the_list_and_the_page_open_on_the_view_key(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $clerk = $this->clerk();

        $this->actingAs($clerk)->get(route('sales.gate_pass.index'))->assertForbidden();
        $this->actingAs($clerk)->get(route('sales.gate_pass.show', $pass))->assertForbidden();

        $clerk->givePermissionTo('sales.gate_pass.view');

        // ⚠️ সারি আছে বলেই রেন্ডারের ক্লোজার সত্যিই চলে ([[a-render-closure-never-runs-on-an-empty-list]])
        $this->actingAs($clerk->fresh())->get(route('sales.gate_pass.index'))
            ->assertOk()->assertSee($pass->document_no)->assertSee($challan->document_no);
        $this->actingAs($clerk->fresh())->get(route('sales.gate_pass.show', $pass))
            ->assertOk()->assertSee($pass->document_no)
            // দেখার চাবিতে বাতিলের ঘর নেই
            ->assertDontSee(route('sales.gate_pass.cancel', $pass));
    }

    /** ⛔→⭐ একই মানুষ: বাতিলের নিজের চাবি — দেখার চাবিতে ৪০৩, বাতিলের চাবিতে বাতিল। */
    public function test_cancelling_asks_for_its_own_key(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $clerk = $this->clerk();
        $clerk->givePermissionTo('sales.gate_pass.view');

        $this->actingAs($clerk->fresh())->post(route('sales.gate_pass.cancel', $pass), ['reason' => 'ভুল'])
            ->assertForbidden();
        $this->assertSame(GatePass::ISSUED, $pass->fresh()->status, '⛔ ৪০৩ ফিরেছে, তবু বাতিল হয়ে গেছে।');

        $clerk->givePermissionTo('sales.gate_pass.cancel');

        $this->actingAs($clerk->fresh())->from(route('sales.gate_pass.show', $pass))
            ->post(route('sales.gate_pass.cancel', $pass), ['reason' => 'গাড়ি বদল'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(GatePass::CANCELLED, $pass->fresh()->status);
    }

    /** ⭐ ছাপা — PDF, আধা পাতায় (A5), গেট পাসের নম্বরসহ। */
    public function test_the_gate_pass_prints_on_half_a_page(): void
    {
        $challan = $this->confirmedChallan(['vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩']);
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $bytes = $this->get(route('sales.print.gate_pass', $pass))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', (string) $bytes);
        // ⓘ A5 = ৪১৯.৫৩ × ৫৯৫.২৮ পয়েন্ট — পাতার মাপ PDF-এর MediaBox-এ খোলা লেখা থাকে
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+419\.5/', (string) $bytes,
            '⛔ গেট পাস আধা পাতায় (A5) ছাপা হয়নি।');
    }

    private function clerk(): User
    {
        $clerk = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        return $clerk;
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function confirmedChallan(array $extra = []): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
            ...$extra,
        ], [['product_id' => $biscuit->id, 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }
}
