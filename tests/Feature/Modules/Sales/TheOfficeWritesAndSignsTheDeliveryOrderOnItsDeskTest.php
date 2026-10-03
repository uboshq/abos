<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অফিসের DO ডেস্ক — "DO Create & List" (মালিক, ২ অক্টোবর ২০২৬), মেনুর "ডেলিভারি অর্ডার (DO)" ভাঁজ।
 *
 * দাবি:
 *   একই মানুষ — চাবি ছাড়া তালিকা আর নতুন DO ৪০৩; `sales.do.view` দিলে তালিকা, `sales.do.create` দিলে লেখা;
 *   খসড়া "খসড়া" ট্যাবে, জমার পরে আর নয়;
 *   সুপারভাইজার (ছকের মানুষ) পাতাতেই পরিমাণ কমান আর সই দেন — মোট আবার গোনা, DO অনুমোদিত;
 *   একই পাতা অচেনা কর্মীকে পরিমাণের ঘর দেখায় না, আর তাঁর পাঠানো পরিমাণ ৪০৩।
 */
final class TheOfficeWritesAndSignsTheDeliveryOrderOnItsDeskTest extends TestCase
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
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();

        // ⓘ abos-86-এর হিসাব/মজুদের শোনা এই দাবির বাইরে — সংকেতটা ধরা, চালানো নয়
        Event::fake([DeliveryOrderSupervisorApproved::class]);
    }

    public function test_the_same_person_needs_the_keys_and_a_draft_leaves_the_drafts_tab_once_submitted(): void
    {
        $writer = $this->member();

        $this->actingAs($writer)->get(route('sales.delivery_order.index'))->assertForbidden();
        $this->actingAs($writer)->get(route('sales.delivery_order.create'))->assertForbidden();

        $this->grant($writer, 'sales.do.view');
        $this->actingAs($writer->fresh())->get(route('sales.delivery_order.index'))->assertOk()
            ->assertDontSee('data-new-do', false);
        $this->actingAs($writer->fresh())->get(route('sales.delivery_order.create'))->assertForbidden();

        $this->grant($writer, 'sales.do.create');
        $this->actingAs($writer->fresh())->get(route('sales.delivery_order.index'))->assertOk()
            ->assertSee('data-new-do', false);
        $this->get(route('sales.delivery_order.create'))->assertOk()->assertSee('data-do-form', false);

        $this->post(route('sales.delivery_order.store'), [
            'customer_id' => $this->dealer->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10'], ['product_id' => '', 'qty' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = DeliveryOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(DeliveryOrderStatus::DRAFT, $order->status, '"খসড়া রাখুন" চাপলেও জমা হয়ে গেল।');
        $this->assertCount(1, $order->lines, 'খালি সারি লাইন হয়ে গেল।');
        $this->assertSame(0, bccomp('400', (string) $order->total, 4), 'দাম পণ্যের — 10 × 40');

        $this->get(route('sales.delivery_order.index', ['tab' => 'drafts']))->assertOk()->assertSee((string) $order->document_no);
        $this->get(route('sales.delivery_order.index', ['tab' => 'history']))->assertOk()->assertDontSee((string) $order->document_no);

        $this->post(route('sales.delivery_order.submit', $order))->assertRedirect(route('sales.delivery_order.show', $order));
        $this->assertNotSame(DeliveryOrderStatus::DRAFT, $order->fresh()->status);
        $this->get(route('sales.delivery_order.index', ['tab' => 'drafts']))->assertOk()
            ->assertDontSee((string) $order->document_no, false);
        $this->get(route('sales.delivery_order.index'))->assertOk()->assertSee((string) $order->document_no);
    }

    public function test_the_supervisor_lowers_the_quantity_and_signs_on_the_page_a_stranger_cannot(): void
    {
        $writer = $this->member(['sales.do.view', 'sales.do.create']);
        $supervisor = $this->member(['sales.do.view', 'approval.decide']);
        $stranger = $this->member(['sales.do.view', 'approval.decide']);
        $this->flow($supervisor);

        $this->actingAs($writer)->post(route('sales.delivery_order.store'), [
            'customer_id' => $this->dealer->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10']],
            'submit' => '1',
        ])->assertSessionHasNoErrors();
        $order = DeliveryOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->status, 'ছক থাকতেও সইয়ের অপেক্ষা নেই।');
        $line = $order->lines()->firstOrFail();

        // অচেনা কর্মী — পাতা দেখেন, পরিমাণের ঘর আর সইয়ের বোতাম নয়; পাঠালেও ৪০৩
        $this->actingAs($stranger)->get(route('sales.delivery_order.show', $order))->assertOk()
            ->assertDontSee('data-do-quantities', false)->assertDontSee('data-do-decide', false);
        $this->actingAs($stranger)->post(route('sales.delivery_order.quantities', $order), ['lines' => [$line->id => '1']])
            ->assertForbidden();

        // সুপারভাইজার — একই পাতায় ঘর আর বোতাম
        $this->actingAs($supervisor)->get(route('sales.delivery_order.show', $order))->assertOk()
            ->assertSee('data-do-quantities', false)->assertSee('data-do-decide', false);
        $this->post(route('sales.delivery_order.quantities', $order), ['lines' => [$line->id => '6']])
            ->assertSessionHasNoErrors()->assertRedirect(route('sales.delivery_order.show', $order));
        $this->assertSame(0, bccomp('240', (string) $order->fresh()->total, 4), '⛔ পরিমাণ কমল, মোট পুরনো রইল।');

        $approval = app(ApprovalEngine::class)->latestFor($order, DeliveryOrderService::APPROVAL_ACTION);
        $this->post(route('approval.inbox.approve', $approval->id))->assertSessionHasNoErrors();

        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_APPROVED, $order->fresh()->status, '⛔ পাতার সইয়েও DO অনুমোদিত হলো না।');
        Event::assertDispatched(DeliveryOrderSupervisorApproved::class,
            fn ($e) => bccomp('240', (string) $e->payload['total'], 4) === 0);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<string>  $keys */
    private function member(array $keys = []): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** কোম্পানির নিজের ছক — এক স্তর, অনুমোদনকারী একজন নির্দিষ্ট মানুষ */
    private function flow(User $supervisor): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-DO', 'module' => 'sales',
            'action' => DeliveryOrderService::APPROVAL_ACTION, 'document_type' => 'DeliveryOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }
}
