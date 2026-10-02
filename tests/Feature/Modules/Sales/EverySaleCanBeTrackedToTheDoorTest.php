<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ডেলিভারি ট্র্যাকিং — মালিক, ২ অক্টোবর ২০২৬ ("Order Traking" → *"Etar Nam 'Delivery Traking' Daw"*)।
 *
 * ⭐ দাবি:
 *   একটা DO তালিকায় আসে তার বিক্রয় নম্বরসহ, আর ধাপ সত্যি বলে — গুদামে → গেট পেরিয়েছে → পৌঁছেছে;
 *   সময়রেখায় DO লেখা, গেট পাস (গাড়িসহ), কে মাল নিলেন — পুরনো আগে;
 *   ধাপের ছাঁকনি কেবল সেই ধাপের সারি দেয়, আর গোনা সংখ্যা মেলে;
 *   একই মানুষ — চাবি ছাড়া ৪০৩, sales.order.view বা sales.delivery.view যেকোনো একটায় খোলে।
 */
final class EverySaleCanBeTrackedToTheDoorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_a_do_walks_from_the_warehouse_to_the_door_and_the_story_says_who_and_when(): void
    {
        $challan = $this->challan();
        $this->phone();

        $row = $this->rowFor($challan);
        $this->assertSame('warehouse', $row['step'], 'পাকা DO, এখনো গুদামে');
        $this->assertSame((string) ($challan->sale_no ?: $challan->document_no), $row['no']);

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED, ['note' => 'test']);
        $this->assertSame('gate_out', $this->rowFor($challan)['step'], '⛔ গেট পেরোনো DO তবু "গুদামে" বলছে।');

        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম দোকানি']);
        $this->assertSame('delivered', $this->rowFor($challan)['step']);

        $story = $this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->assertOk()->json();
        $texts = array_column($story['events'], 'text');
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, (string) $challan->document_no)), 'সময়রেখায় DO লেখা নেই।');
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'রহিম দোকানি')), '⛔ কে মাল নিলেন, সময়রেখায় নেই।');
        $this->assertContains('gate_out', array_column($story['events'], 'step'), 'গেট পাসের ধাপ সময়রেখায় নেই।');
        $at = array_values(array_filter(array_column($story['events'], 'at')));
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'সময়রেখা পুরনো-আগে সাজানো নয়।');
    }

    public function test_the_step_filter_gives_only_that_step_and_the_counts_agree(): void
    {
        $out = $this->challan();
        app(DeliveryStageService::class)->move($out, DeliveryStage::DISPATCHED, ['note' => 'test']);
        $this->challan();
        $this->phone();

        $all = $this->getJson('/api/v1/sales/tracking')->assertOk()->json();
        $gate = $this->getJson('/api/v1/sales/tracking?step=gate_out')->assertOk()->json('rows');

        $this->assertNotEmpty($gate);
        $this->assertSame(['gate_out'], array_values(array_unique(array_column($gate, 'step'))));
        $this->assertSame($all['counts']['gate_out'], count($gate), 'ট্যাবের সংখ্যা আর তালিকা আলাদা কথা বলছে।');
        $this->assertSame($all['counts']['all'], count($all['rows']));
    }

    public function test_the_same_person_needs_either_key(): void
    {
        $challan = $this->challan();
        $person = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $person->companies()->attach($this->company->id, ['is_active' => true]);

        Sanctum::actingAs($person->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/tracking')->assertForbidden();
        $this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->assertForbidden();

        $this->grant($person, 'sales.order.view');
        Sanctum::actingAs($person->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/tracking')->assertOk();
        $this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->assertOk();
    }

    /** ⭐ টিকচিহ্নের দাগ — রওনার পরে গেট পাস আর রওনায় টিক, পৌঁছানো "এখন", রং ঠিক */
    public function test_the_milestones_tick_up_to_where_the_sale_is_and_no_further(): void
    {
        $challan = $this->challan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED, ['note' => 'test']);
        $this->phone();

        $ms = collect($this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->assertOk()->json('milestones'))
            ->keyBy('key');

        foreach (['order_created', 'challan_draft', 'challan_confirmed', 'stock_allocated', 'transport_assigned',
            'loading_started', 'loading_completed', 'gate_pass_generated', 'dispatched'] as $done) {
            $this->assertSame('done', $ms[$done]['state'] ?? null, "⛔ {$done}-এ টিক নেই, অথচ মাল রওনা হয়েছে।");
        }
        $this->assertSame('current', $ms['delivered']['state'], 'পরের ধাপটাই "এখন" নয়।');
        $this->assertSame('delivered', $ms['delivered']['category']);
        $this->assertNotNull($ms['gate_pass_generated']['at'], 'গেট পাসের সময় নেই।');
        $this->assertSame(1, $ms->where('state', 'current')->count(), '⛔ একসাথে দুই জায়গায় "এখন"।');
    }

    /** ⭐ অনুমোদনের স্তর কোম্পানির নিজের নামে — কোডে বাঁধা নয় */
    public function test_the_company_s_own_approval_steps_appear_by_name(): void
    {
        $challan = $this->challan();
        $flow = \App\Models\ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-TRACK', 'module' => 'sales', 'action' => 'zq_track',
            'document_type' => 'DeliveryChallan', 'is_active' => true,
        ]);
        foreach ([1 => 'হিসাব বিভাগ', 2 => 'জেনারেল ম্যানেজার'] as $level => $name) {
            \App\Models\ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id, 'level' => $level, 'step_name' => $name,
                'approver_type' => 'role', 'approver_id' => 1,
            ]);
        }
        \App\Models\Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => DeliveryChallan::class, 'approvable_id' => $challan->id,
            'module' => 'sales', 'action' => 'zq_track', 'status' => \App\Models\Approval::PENDING, 'current_level' => 1,
            'requested_by' => User::query()->where('email', 'owner@abos.test')->value('id'), 'requested_at' => now(),
        ]);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        $this->phone();

        $ms = collect($this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->assertOk()->json('milestones'));
        $first = $ms->firstWhere('label', 'হিসাব বিভাগ');
        $second = $ms->firstWhere('label', 'জেনারেল ম্যানেজার');

        $this->assertNotNull($first, '⛔ কোম্পানির নিজের স্তরের নাম দাগে নেই।');
        $this->assertSame(['current', 'pending'], [$first['state'], $first['category']], 'অপেক্ষার স্তর কমলা আর "এখন" নয়।');
        $this->assertSame('todo', $second['state']);
        $this->assertSame('approval', $this->rowFor($challan)['step'], 'তালিকায় সইয়ের অপেক্ষা দেখায় না।');
    }

    /** ⭐ ওয়েবেও — মালিক: "web eo eta dite bolo"; একই হিসাব, একই ধাপ */
    public function test_the_web_shows_the_same_sale_and_its_story(): void
    {
        $challan = $this->challan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED, ['note' => 'test']);

        $page = $this->get(route('sales.tracking.index'))->assertOk();
        $page->assertSee((string) ($challan->sale_no ?: $challan->document_no))
            ->assertSee(__('sales::tracking.step.gate_out'))
            ->assertSee(route('sales.tracking.show', ['challan', $challan->public_id]), false);

        $this->get(route('sales.tracking.index', ['step' => 'delivered']))->assertOk()
            ->assertDontSee(route('sales.tracking.show', ['challan', $challan->public_id]), false);

        $this->get(route('sales.tracking.show', ['challan', $challan->public_id]))->assertOk()
            ->assertSee('data-tracking-event', false)
            ->assertSee('data-milestone="dispatched" data-state="done"', false)
            ->assertSee('#22C55E', false)
            ->assertSee((string) $challan->document_no);

        // DO তালিকার "ডেলিভারি ট্র্যাকিং" ট্যাব এখানেই আসে
        $this->assertSame(route('sales.tracking.index'), app(\App\Modules\Sales\Services\DeliveryOrderTabs::class)->href('tracking'));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function rowFor(DeliveryChallan $challan): array
    {
        $rows = $this->getJson('/api/v1/sales/tracking')->assertOk()->json('rows');
        $row = collect($rows)->firstWhere('id', (string) $challan->public_id);
        $this->assertNotNull($row, 'DO-টা তালিকায় নেই।');

        return $row;
    }

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    private function phone(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
