<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\RoleTemplateRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লাইভে ম্যানেজার ডেলিভারি নিশ্চিত করতে পারেননি, আর গুদামের লোক চালানের পাতা খুলতে পারেননি
 * (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── কারণ ─────────────────────────────────────────────────────────────
 * ⓘ দুটো দরজাই ঠিক চাবি চায়। ম্যানেজারের বেলায় টেমপ্লেটে চাবিটাই ছিল না: "পৌঁছেছে" বসাতে
 * `sales.delivery.update` লাগে, আর ম্যানেজারের ছিল কেবল `sales.delivery.view`।
 * ⚠️ গুদামের বেলায় ভুলটা লিংকে: গেট পাসের পাতা চালানের পাতায় লিংক দিত চাবি না দেখেই। অথচ
 * গুদামের টেমপ্লেটে `sales.challan.view` ইচ্ছা করেই নেই, কারণ ঐ পাতায় দর আর টাকার অঙ্ক থাকে।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ম্যানেজারের টেমপ্লেটে ধাপ বদলানোর চাবি আছে। গেট পাস চালানের পাতায় পাঠায় কেবল ঐ চাবিধারীকে,
 * বাকিদের পাঠায় ডেলিভারির পাতায়, যেটা ঐ একই চালান দেখায় দাম ছাড়া।
 * ⓘ টেমপ্লেট আগের রোল চওড়া করে না, তাই লাইভের ম্যানেজার রোলে চাবিটা আলাদা করে দিতে হবে।
 */
final class TheManagerAndTheStorekeeperWereShutOutTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔→⭐ একই মানুষ, একই চালান: ম্যানেজারের টেমপ্লেটের চাবিগুলোয় "পৌঁছেছে" বসে, আর
     * `sales.delivery.update` সরালে বসে না।
     */
    public function test_the_manager_template_confirms_a_delivery_and_only_by_the_update_key(): void
    {
        $keys = app(RoleTemplateRegistry::class)->all()['Manager'] ?? [];
        $this->assertContains('sales.delivery.update', $keys, '⛔ ম্যানেজারের টেমপ্লেটে ধাপ বদলানোর চাবি নেই।');

        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::PACKED);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $manager = $this->userWith(array_values(array_diff($keys, ['sales.delivery.update'])));
        $delivered = ['stage' => DeliveryStage::DELIVERED, 'receiver_name' => 'রহিম স্টোর'];

        $this->actingAs($manager->fresh())->post(route('sales.delivery.move', $challan), $delivered)->assertForbidden();
        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));

        $manager->givePermissionTo('sales.delivery.update');

        $this->actingAs($manager->fresh())->from(route('sales.delivery.show', $challan))
            ->post(route('sales.delivery.move', $challan), $delivered)
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan), '⛔ ম্যানেজার ডেলিভারি নিশ্চিত করতে পারেননি।');
    }

    /**
     * ⛔→⭐ একই গুদামের লোক, একই গেট পাস: চালানের চাবি ছাড়া লিংক ডেলিভারির পাতায় যায় (আর সেটা খোলে),
     * চালানের পাতায় নয়; চাবি দিলে চালানের পাতায় যায় আর সেটা খোলে।
     */
    public function test_the_gate_pass_links_the_storekeeper_to_a_page_they_can_open(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::PACKED);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $storekeeper = $this->userWith(app(RoleTemplateRegistry::class)->all()['Warehouse'] ?? []);
        $challanPage = route('sales.challan.show', $challan);
        $deliveryPage = route('sales.delivery.show', $challan);

        $this->actingAs($storekeeper->fresh())->get(route('sales.gate_pass.show', $pass))->assertOk()
            ->assertSee('href="'.$deliveryPage.'"', false)
            ->assertDontSee('href="'.$challanPage.'"', false);
        $this->actingAs($storekeeper->fresh())->get($deliveryPage)->assertOk();
        $this->actingAs($storekeeper->fresh())->get($challanPage)->assertForbidden();

        $storekeeper->givePermissionTo('sales.challan.view');

        $this->actingAs($storekeeper->fresh())->get(route('sales.gate_pass.show', $pass))->assertOk()
            ->assertSee('href="'.$challanPage.'"', false);
        $this->actingAs($storekeeper->fresh())->get($challanPage)->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<string>  $keys  টেমপ্লেট থেকে পড়া — হাতে লেখা নয় */
    private function userWith(array $keys): User
    {
        $this->assertNotSame([], $keys, '⛔ টেমপ্লেটটাই খুঁজে পাওয়া যায়নি।');

        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo($keys);

        return $user;
    }

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
