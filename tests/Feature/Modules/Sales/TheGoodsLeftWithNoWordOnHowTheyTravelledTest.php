<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\ChallanTransportController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\TransportRule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * মাল বেরোল, অথচ কীভাবে গেল তার কোনো কথা নেই — মালিকের পরিকল্পনা, ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬;
 * প্রশ্নের জায়গা বদলাল ১ অক্টোবর ২০২৬ (মালিকের অনুমোদিত বদল)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * DO বা সরাসরি বিক্রি পাকা হত গাড়ি, বাহক কিছু না লিখেই। ধাপ ৫ নিশ্চিতের দরজায় প্রশ্নটা বসাল — কিন্তু
 * কাউন্টারে ক্রেতা দাঁড়িয়ে, গাড়ি তখনো ঠিক হয়নি, তাই বিক্রিই আটকে থাকত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * বিক্রি নিশ্চিত হয় পরিবহন ছাড়াও। মাল বেরোনোর কাগজ — চালান আর গেট পাস — ছাপার আগে প্রশ্নটা আসে
 * ([[RequireTransportBeforePrint]]); উত্তর দেয় [[ChallanTransportController]]-এর তিন পথ: গাড়িতে, ক্রেতা নিজে,
 * সরাসরি ডেলিভারি। গেট পাস হলে (আর পরিবহন বলা থাকলে) বদলানো বন্ধ। কোম্পানি পরিবহনের ঘর বন্ধ রাখলে
 * প্রশ্নই নেই।
 * ⓘ প্রতিটা দাবি একই মানুষ, একই কাগজ — পরিবহন না থাকলে থামে, বসালে চলে ([[a-door-claim-needs-one-actor-twice]])।
 */
final class TheGoodsLeftWithNoWordOnHowTheyTravelledTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⚠️ স্পষ্ট করে চালু — ডেমোর সেটিং বদলালেও দাবিটা নিয়মটাই মাপে
        app(SettingsService::class)->set('sales.field_transport', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
    }

    // ── নিশ্চিত আর আটকায় না ──────────────────────────────────────────────

    /** ⭐ কাউন্টার: পরিবহনের কোনো কথা ছাড়াই নিশ্চিত — বিল আর চালান দুটোই পাকা। */
    public function test_the_counter_confirms_with_no_transport(): void
    {
        $this->sell()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $this->lastChallan()->status,
            '⛔ পরিবহন ছাড়া বিক্রি আবার আটকাচ্ছে — প্রশ্নটা ছাপার দরজায়, নিশ্চিতে নয়।');
        $this->assertFalse(TransportRule::named($this->lastChallan()), 'দৃশ্যটাই বানানো যায়নি — চালানে পরিবহন বসে গেছে।');
    }

    /** ⭐ বিলের পাতা: রাখা খসড়া পরিবহন ছাড়াই পাকা হয় ([[DirectSaleService::finishHeld()]])। */
    public function test_a_parked_draft_finishes_from_the_bill_page_with_no_transport(): void
    {
        $this->sell(['save_as_draft' => '1'])->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->from(route('sales.invoice.show', $invoice))
            ->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status, '⛔ পরিবহন ছাড়া রাখা খসড়া পাকা হল না।');
    }

    /** ⭐ অফিসের চালান: পরিবহন ছাড়াই পাকা। */
    public function test_the_office_challan_confirms_with_no_transport(): void
    {
        $challan = $this->officeChallan();

        $this->from(route('sales.challan.show', $challan))
            ->post(route('sales.challan.confirm', $challan))
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status);
    }

    // ── ছাপার দরজা ────────────────────────────────────────────────────

    /**
     * ⛔→⭐ একই চালান, একই মানুষ: পরিবহন ছাড়া চালান আর গেটপাস ছাপা হয় না — চালানের পাতায় ফেরে;
     * "ক্রেতা নিজে" বসালে দুটোই ছাপে।
     */
    public function test_the_challan_prints_only_once_the_transport_is_named(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();

        foreach (['sales.print.challan', 'sales.print.gatepass'] as $print) {
            $this->get(route($print, $challan))
                ->assertRedirect(route('sales.challan.show', $challan))
                ->assertSessionHasErrors('transport');
        }

        $this->answer($challan, ['mode' => 'own'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $challan->fresh()->own_transport, '⛔ "ক্রেতা নিজে" চালানে পৌঁছায়নি।');

        foreach (['sales.print.challan', 'sales.print.gatepass'] as $print) {
            $this->get(route($print, $challan))->assertOk();
        }
    }

    /** ⛔ ফোনের দলিল-দরজাও একই নিয়মে — পরিবহন না বললে চালান ছাপা নয়, কারণসহ ৪২২; বললে ছাপা (অডিট ফোন ⚠️৮) */
    public function test_the_phone_prints_the_challan_only_once_the_transport_is_named(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $url = '/api/v1/documents/DeliveryChallan/'.$challan->public_id.'/pdf';

        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($owner, [\App\Http\Controllers\Api\AuthController::APP]);
        $this->getJson($url)->assertStatus(422)->assertJsonPath('errors.print.0', (string) __('sales::transport.needed_before_print'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner);
        $this->answer($challan, ['mode' => 'own'])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($owner, [\App\Http\Controllers\Api\AuthController::APP]);
        $this->get($url)->assertOk();
    }

    /** ⭐ তিন পথের প্রতিটা চালানে ঠিক ঘরে বসে; ⛔ "গাড়িতে" বললে নম্বর ছাড়া নয়। */
    public function test_each_of_the_three_answers_lands_on_the_challan(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();

        $this->answer($challan, ['mode' => 'vehicle', 'driver_name' => 'রফিক'])->assertSessionHasErrors('vehicle_no');
        $this->assertFalse(TransportRule::named($challan->fresh()), '⛔ নম্বর ছাড়া "গাড়িতে" তবু উত্তর বলে বসে গেল।');

        $this->answer($challan, ['mode' => 'vehicle', 'vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩',
            'driver_name' => 'রফিক', 'driver_phone' => '01711000000'])->assertSessionHasNoErrors();
        $fresh = $challan->fresh();
        $this->assertSame('ঢাকা মেট্রো ট ১১-২২৩৩', $fresh->vehicle_no);
        $this->assertSame('রফিক', $fresh->driver_name);
        $this->assertSame('01711000000', $fresh->driver_phone);
        $this->assertFalse((bool) $fresh->own_transport);

        $this->answer($challan, ['mode' => 'direct'])->assertSessionHasNoErrors();
        $fresh = $challan->fresh();
        $this->assertSame(ChallanTransportController::DIRECT, $fresh->carrier_name);
        $this->assertNull($fresh->vehicle_no, '⛔ পুরনো গাড়ির নম্বর রয়ে গেছে — কাগজে দুই রকম কথা।');
        $this->assertSame('direct', ChallanTransportController::mode($fresh));

        $this->answer($challan, ['mode' => 'own'])->assertSessionHasNoErrors();
        $fresh = $challan->fresh();
        $this->assertTrue((bool) $fresh->own_transport);
        $this->assertNull($fresh->carrier_name);
    }

    /**
     * ⛔ গেট পাস হলে মাল বেরিয়ে গেছে — পরিবহন আর বদলায় না। ⭐ কিন্তু কিছু বলা না থাকলে একবার বলা যায়,
     * নইলে গেট পাসের কাগজ চিরকাল আটকে থাকত (ছাপা চাইত পরিবহন, ফর্ম বলত বন্ধ)।
     */
    public function test_after_the_gate_pass_the_answer_is_given_once_and_then_kept(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();

        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $this->get(route('sales.print.gate_pass', $pass))
            ->assertRedirect(route('sales.challan.show', $challan))
            ->assertSessionHasErrors('transport');

        $this->answer($challan, ['mode' => 'direct'])->assertSessionHasNoErrors();
        $this->get(route('sales.print.gate_pass', $pass))->assertOk();

        $this->answer($challan, ['mode' => 'own'])->assertSessionHasErrors('transport');
        $this->assertSame(ChallanTransportController::DIRECT, $challan->fresh()->carrier_name,
            '⛔ গেট পাসের পরে পরিবহন বদলে গেল — কাগজ বলে এক, খাতা বলে আরেক।');
    }

    /** ⛔ যিনি চালান নিশ্চিত করতে পারেন না, তিনি পরিবহনও বসান না — একই মানুষ, চাবি ছাড়া তারপর চাবিসহ। */
    public function test_only_who_may_confirm_a_challan_names_the_transport(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();

        $clerk = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $clerk->givePermissionTo('sales.challan.view');

        $this->actingAs($clerk)->get(route('sales.challan.transport', $challan))->assertForbidden();
        $this->actingAs($clerk)->put(route('sales.challan.transport.update', $challan), ['mode' => 'own'])->assertForbidden();
        $this->assertFalse(TransportRule::named($challan->fresh()));

        $clerk->givePermissionTo('sales.challan.create');

        $this->actingAs($clerk->fresh())->get(route('sales.challan.transport', $challan))->assertOk();
        $this->actingAs($clerk->fresh())->put(route('sales.challan.transport.update', $challan), ['mode' => 'own'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(TransportRule::named($challan->fresh()));
    }

    // ── কোম্পানি পরিবহনের ঘর বন্ধ রাখলে ─────────────────────────────────

    /** ⛔→⭐ একই চালান, একই মানুষ: ঘর চালু থাকলে ছাপা থামে, বন্ধ করলে চলে। */
    public function test_a_company_that_hides_the_transport_fields_prints_without_them(): void
    {
        $this->sell()->assertSessionHasNoErrors();
        $challan = $this->lastChallan();

        $this->get(route('sales.print.challan', $challan))->assertRedirect();

        app(SettingsService::class)->set('sales.field_transport', false);

        $this->get(route('sales.print.challan', $challan))->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = []): TestResponse
    {
        return $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function answer(DeliveryChallan $challan, array $data): TestResponse
    {
        return $this->from(route('sales.challan.transport', $challan))
            ->put(route('sales.challan.transport.update', $challan), $data);
    }

    private function officeChallan(): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '2', 'rate' => '100']]);
    }

    private function lastChallan(): DeliveryChallan
    {
        return DeliveryChallan::query()->latest('id')->firstOrFail();
    }
}
