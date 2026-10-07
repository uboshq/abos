<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ DO বিক্রয় আদেশে মেশানো, নকশার ধাপ ১২ (৫ অক্টোবর ২০২৬) — কোম্পানি `sales.orders_replace_do` চালু করলে নতুন DO বন্ধ,
 * তিন দরজাতেই একই পাহারা: অফিসের ডেস্ক, ডিলারের পোর্টাল, ফোনের API। খোলা DO আগের মতো নিজের নম্বরে শেষ হয়।
 *
 * ⭐ দাবি (প্রতিটা দরজায় একই মানুষ — সুইচ বন্ধ, চালু, আবার বন্ধ):
 *   বন্ধে লেখা যায়; চালুতে ফর্ম খোলে না, পাঠানো লেখা ফেরে বাংলা কারণসহ, একটা DO-ও বাড়ে না, "নতুন DO" বোতাম নেই;
 *   আবার বন্ধে আগের মতো — সুইচটাই কারণ, অন্য কিছু নয়;
 *   সুইচের আগে লেখা খসড়া চালুর পরে আর বদলায় না, জমাও হয় না (ধাপ ১৫, ৬ অক্টোবর ২০২৬); আগেই জমা হওয়া DO সই পেয়ে নিজের পথে শেষ হয়।
 */
final class NoNewDeliveryOrderOnceOrdersTakeOverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->dealer = Customer::query()->firstOrFail();
        $this->dealer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();
    }

    public function test_the_office_desk_writes_a_do_only_while_the_switch_is_off(): void
    {
        $writer = $this->staff(['sales.do.view', 'sales.do.create', 'sales.order.create']);
        $this->actingAs($writer);
        $body = ['customer_id' => $this->dealer->id, 'lines' => [['product_id' => $this->product->id, 'qty' => '10']]];

        $this->get(route('sales.delivery_order.index'))->assertOk()->assertSee('data-new-do', false)
            ->assertDontSee('data-new-do-stopped', false);
        $this->get(route('sales.delivery_order.create'))->assertOk()->assertSee('data-do-form', false);
        $this->post(route('sales.delivery_order.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, DeliveryOrder::query()->count(), 'প্রস্তুতিটাই ভুল — সুইচ বন্ধেও DO হলো না।');

        $this->replaceDo(true);
        $this->get(route('sales.delivery_order.index'))->assertOk()
            ->assertDontSee('data-new-do"', false)
            ->assertSee('data-new-do-stopped', false)
            ->assertSee('data-new-order-instead', false)
            ->assertSee(__('sales::delivery_order.write_an_order_now'));
        $this->get(route('sales.delivery_order.create'))->assertRedirect(route('sales.delivery_order.index'));
        $this->post(route('sales.delivery_order.store'), $body)->assertSessionHasErrors('order');
        $this->assertSame(1, DeliveryOrder::query()->count(), '⛔ সুইচ চালুর পরেও ডেস্কে নতুন DO লেখা হলো।');

        $this->replaceDo(false);
        $this->get(route('sales.delivery_order.create'))->assertOk()->assertSee('data-do-form', false);
        $this->post(route('sales.delivery_order.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(2, DeliveryOrder::query()->count(), 'সুইচ বন্ধ করার পরেও DO বন্ধ রইল — কারণ সুইচ নয়।');
    }

    public function test_the_dealer_on_the_portal_writes_a_do_only_while_the_switch_is_off(): void
    {
        $this->actingAs($this->dealer->fresh(), 'portal');
        $body = ['lines' => [['product_id' => $this->product->id, 'qty' => '12']]];

        $this->get(route('sales.portal.do.index'))->assertOk()->assertSee('data-portal-do-new', false);
        $this->post(route('sales.portal.do.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, DeliveryOrder::query()->count());

        $this->replaceDo(true);
        $this->get(route('sales.portal.do.index'))->assertOk()
            ->assertDontSee('data-portal-do-new', false)
            ->assertSee('data-portal-do-stopped', false);
        $this->get(route('sales.portal.do.create'))->assertRedirect(route('sales.portal.do.index'))
            ->assertSessionHasErrors('order');
        $this->post(route('sales.portal.do.store'), $body)->assertSessionHasErrors('order');
        $this->assertSame(1, DeliveryOrder::query()->count(), '⛔ সুইচ চালুর পরেও পোর্টালে নতুন DO লেখা হলো।');

        $this->replaceDo(false);
        $this->get(route('sales.portal.do.create'))->assertOk()->assertSee('data-portal-do-form', false);
    }

    public function test_the_phone_writes_a_do_only_while_the_switch_is_off(): void
    {
        $sr = $this->staff(['sales.do.view', 'sales.do.create']);
        $body = ['customer' => (string) $this->dealer->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '5']]];
        Sanctum::actingAs($sr, [AuthController::APP]);

        $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated();

        $this->replaceDo(true);
        $this->postJson('/api/v1/sales/delivery-orders', $body)->assertStatus(422)
            ->assertJsonValidationErrors('order')
            ->assertJsonFragment([__('sales::delivery_order.write_an_order_now')]);
        $this->assertSame(1, DeliveryOrder::query()->count(), '⛔ সুইচ চালুর পরেও ফোনে নতুন DO লেখা হলো।');

        $this->replaceDo(false);
        $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated();
    }

    public function test_after_the_switch_an_old_draft_stops_and_a_submitted_do_still_finishes(): void
    {
        $supervisor = $this->staff(['sales.do.view', 'approval.decide']);
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-DO', 'module' => 'sales',
            'action' => DeliveryOrderService::APPROVAL_ACTION, 'document_type' => 'DeliveryOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);

        $sr = $this->staff(['sales.do.view', 'sales.do.create']);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $line = ['lines' => [['product' => (string) $this->product->public_id, 'qty' => '5']]];
        $draft = $this->postJson('/api/v1/sales/delivery-orders', ['customer' => (string) $this->dealer->public_id, ...$line])->assertCreated()->json();
        $made = $this->postJson('/api/v1/sales/delivery-orders', ['customer' => (string) $this->dealer->public_id, ...$line])->assertCreated()->json();
        $this->postJson('/api/v1/sales/delivery-orders/'.$made['id'].'/submit')->assertOk();
        $order = DeliveryOrder::query()->where('public_id', $made['id'])->firstOrFail();
        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->status, 'প্রস্তুতিটাই ভুল — সুইচের আগে জমা হলো না।');

        /*
         * ⛔ সুইচের পরে পুরনো খসড়া আর বদলায় না, জমাও হয় না — নকশার ধাপ ১৫, সমন্বয়কের সিদ্ধান্ত ৬ অক্টোবর ২০২৬ (মালিকের ৪ অক্টোবরের
         * "পুরনো নিয়ম কিছু থাকবে না")। ⓘ আগে (ধাপ ১২) এই খসড়াও জমা হত — তখন একই ডিলারের DO আর আদেশ পাশাপাশি চলত।
         */
        $this->replaceDo(true);
        $this->putJson('/api/v1/sales/delivery-orders/'.$draft['id'], ['lines' => [['product' => (string) $this->product->public_id, 'qty' => '6']]])
            ->assertStatus(422)->assertJsonValidationErrors('order');
        $this->postJson('/api/v1/sales/delivery-orders/'.$draft['id'].'/submit')->assertStatus(422)->assertJsonValidationErrors('order');
        $this->assertSame(DeliveryOrderStatus::DRAFT, DeliveryOrder::query()->where('public_id', $draft['id'])->value('status'),
            '⛔ সুইচের পরে পুরনো খসড়া DO জমা হয়ে গেল।');

        // ⭐ যা আগেই জমা — সে নিজের পথে শেষ হয়

        Sanctum::actingAs($supervisor, [AuthController::APP]);
        $approval = $this->getJson('/api/v1/sales/delivery-orders/'.$made['id'])->assertOk()->json('approval_id');
        $this->assertNotNull($approval, '⛔ সুইচের পরে খোলা DO সইয়ের দরজা পেল না।');
        $this->postJson('/api/v1/approvals/'.$approval.'/approve')->assertOk();
        $this->assertNotSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->fresh()->status, '⛔ সুইচের পরে খোলা DO সইয়েও এগোল না।');
    }

    private function replaceDo(bool $on): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, $on);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
