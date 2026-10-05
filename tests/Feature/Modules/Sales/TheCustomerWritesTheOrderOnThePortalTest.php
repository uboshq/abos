<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পোর্টালে গ্রাহকের নিজের বিক্রয় আদেশ — DO+SO মেশানো, নকশার ধাপ ৯ (৫ অক্টোবর ২০২৬; [[PortalOrderController]])।
 *
 * ⭐ দাবি:
 *   সুইচ বন্ধে হোমে DO-র বোতাম, নতুন আদেশের ফর্ম খোলে না, পাঠানো লেখা ফেরে — একটা আদেশও বাড়ে না;
 *   সুইচ চালুতে হোমে আদেশের বোতাম (পুরনো DO ছোট লিংকে); গ্রাহক লেখেন আর জমা দেন — লেখক তিনি নিজে (কর্মী নন),
 *   উৎস `portal`, দাম পণ্যের, সই চাওয়া তাঁর নামে; খসড়া রেখে পরে পাতা থেকে জমা;
 *   অন্য গ্রাহক খুলতে বা জমা দিতে পারেন না (৪০৪), তালিকায়ও পান না (এক অভিনেতা দুবার);
 *   অফিসের লেখা আদেশ নিজের তালিকায় দেখেন, কিন্তু জমা দিতে পারেন না (৪০৩)।
 */
final class TheCustomerWritesTheOrderOnThePortalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['portal_enabled' => true, 'portal_password' => 'customer-pass-1'])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();
    }

    public function test_with_the_switch_off_the_portal_keeps_the_do_and_writes_no_order(): void
    {
        $this->actingAs($this->customer->fresh(), 'portal');

        $this->get(route('sales.portal.home'))->assertOk()
            ->assertSee('data-portal-do', false)->assertDontSee('data-portal-orders', false);
        $this->get(route('sales.portal.order.index'))->assertOk()->assertDontSee('data-portal-order-new', false);
        $this->get(route('sales.portal.order.create'))->assertRedirect(route('sales.portal.order.index'))
            ->assertSessionHasErrors('order');
        $this->post(route('sales.portal.order.store'), $this->body(submit: true))->assertSessionHasErrors('order');
        $this->assertSame(0, SalesOrder::query()->where('customer_id', $this->customer->id)->count(),
            '⛔ সুইচ বন্ধেও পোর্টালে আদেশ লেখা হলো।');
    }

    public function test_the_customer_writes_and_submits_in_their_own_name_and_another_customer_cannot_open_it(): void
    {
        $this->replaceDo(true);
        $supervisor = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $supervisor->companies()->attach($this->company->id, ['is_active' => true]);
        $this->flow($supervisor);

        $this->actingAs($this->customer->fresh(), 'portal');
        $this->get(route('sales.portal.home'))->assertOk()
            ->assertSee('data-portal-orders', false)->assertSee('data-portal-do-old', false);
        $this->get(route('sales.portal.order.create'))->assertOk()->assertSee('data-portal-order-form', false);

        $this->post(route('sales.portal.order.store'), $this->body(submit: true))->assertSessionHasNoErrors()->assertRedirect();

        $order = SalesOrder::query()->where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->assertNull($order->created_by, '⛔ গ্রাহকের id কর্মীর ঘরে বসল — অন্য একজন কর্মী লেখক হয়ে গেলেন।');
        $this->assertSame((int) $this->customer->id, (int) $order->created_by_customer_id);
        $this->assertSame(SalesOrderStatus::SOURCE_PORTAL, $order->source);
        $this->assertCount(1, $order->lines, 'খালি সারি লাইন হয়ে গেল।');
        $this->assertSame(0, bccomp('480', (string) $order->subtotal, 4), 'দাম পণ্যের — 12 × 40');
        $this->assertSame(SalesOrderStatus::AWAITING_APPROVAL, $order->status, '⛔ "জমা দিন" চাপলেও সুপারভাইজারের কাছে গেল না।');
        $asked = Approval::query()->where('approvable_type', $order->getMorphClass())->where('approvable_id', $order->id)->firstOrFail();
        $this->assertSame((int) $this->customer->id, (int) $asked->requested_by_customer_id, 'সই চাওয়া গ্রাহকের নিজের নামে।');

        $this->get(route('sales.portal.order.index'))->assertOk()->assertSee((string) $order->document_no);
        $this->get(route('sales.portal.order.show', $order->public_id))->assertOk()
            ->assertDontSee('data-portal-order-submit', false);

        $other = Customer::query()->whereKeyNot($this->customer->id)->firstOrFail();
        $other->forceFill(['portal_enabled' => true, 'portal_password' => 'customer-pass-2'])->save();
        $this->actingAs($other->fresh(), 'portal');
        $this->get(route('sales.portal.order.show', $order->public_id))->assertNotFound();
        $this->post(route('sales.portal.order.submit', $order->public_id))->assertNotFound();
        $this->get(route('sales.portal.order.index'))->assertOk()->assertDontSee((string) $order->document_no);
    }

    public function test_a_kept_draft_is_submitted_from_its_page_and_an_office_order_is_seen_but_not_submitted(): void
    {
        $this->replaceDo(true);
        $this->actingAs($this->customer->fresh(), 'portal');

        $this->post(route('sales.portal.order.store'), $this->body())->assertSessionHasNoErrors();
        $draft = SalesOrder::query()->where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->assertSame(SalesOrderStatus::DRAFT, $draft->status, '"খসড়া রাখুন" চাপলেও জমা হয়ে গেল।');
        $this->get(route('sales.portal.order.show', $draft->public_id))->assertOk()->assertSee('data-portal-order-submit', false);
        $this->post(route('sales.portal.order.submit', $draft->public_id))->assertRedirect();
        $this->assertNotSame(SalesOrderStatus::DRAFT, $draft->fresh()->status);

        // ⓘ অফিসের লেখা — একই গ্রাহকের, কর্মীর হাতে
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $office = $this->actingAs($clerk)->app->make(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
        ], [['product_id' => $this->product->id, 'ordered_qty' => '3', 'rate' => '40', 'discount' => '0']]);

        $this->actingAs($this->customer->fresh(), 'portal');
        $this->get(route('sales.portal.order.index'))->assertOk()->assertSee((string) $office->document_no);
        $this->get(route('sales.portal.order.show', $office->public_id))->assertOk()->assertDontSee('data-portal-order-submit', false);
        $this->post(route('sales.portal.order.submit', $office->public_id))->assertForbidden();
        $this->assertSame(SalesOrderStatus::DRAFT, $office->fresh()->status, '⛔ গ্রাহক অফিসের লেখা আদেশ জমা দিয়ে ফেললেন।');
    }

    /** @return array<string, mixed> */
    private function body(bool $submit = false): array
    {
        return [
            'lines' => [['product_id' => $this->product->id, 'qty' => '12'], ['product_id' => '', 'qty' => '']],
            'submit' => $submit ? '1' : null,
        ];
    }

    private function replaceDo(bool $on): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, $on);
    }

    private function flow(User $supervisor): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-SO', 'module' => 'sales',
            'action' => SalesOrderService::APPROVAL_ACTION, 'document_type' => 'SalesOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }
}
