<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DO-র দরজা — পোর্টাল (ডিলার) আর ফোন (SR ও উপরের সবাই) — মালিকের বিক্রয়-ধারা §২ক (২ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি:
 *   ডিলার পোর্টালে লেখেন ও জমা দেন — নিজের নামে; একই ডিলার নিজেরটা খোলেন, অন্য ডিলার ৪০৪ (এক অভিনেতা দুবার);
 *   ফোনে একই মানুষ — চাবি ছাড়া ৪০৩, `sales.do.create` দিলে লেখেন আর জমা দেন; জমার পরে বদলানো ফেরে।
 */
final class TheDeliveryOrderDoorsOpenOnlyForTheirOwnersTest extends TestCase
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

    public function test_a_dealer_writes_and_submits_on_the_portal_and_another_dealer_cannot_open_it(): void
    {
        $this->actingAs($this->dealer->fresh(), 'portal');
        $this->get(route('sales.portal.do.create'))->assertOk()->assertSee('data-portal-do-form', false);

        $this->post(route('sales.portal.do.store'), [
            'lines' => [['product_id' => $this->product->id, 'qty' => '12'], ['product_id' => '', 'qty' => '']],
            'submit' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = DeliveryOrder::query()->where('customer_id', $this->dealer->id)->firstOrFail();
        $this->assertSame((int) $this->dealer->id, (int) $order->created_by_customer_id);
        $this->assertNotSame(DeliveryOrderStatus::DRAFT, $order->status, '"জমা দিন" চাপলেও খসড়া রইল।');
        $this->assertCount(1, $order->lines, 'খালি সারি লাইন হয়ে গেল।');

        $this->get(route('sales.portal.do.index'))->assertOk()->assertSee((string) $order->document_no);
        $this->get(route('sales.portal.do.show', $order->public_id))->assertOk();

        $other = Customer::query()->whereKeyNot($this->dealer->id)->firstOrFail();
        $other->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-2'])->save();
        $this->actingAs($other->fresh(), 'portal');
        // ⓘ সরু পথ — অন্যের DO খোঁজাতেই নেই, তাই ৪০৪ ([[CustomerPapers::deliveryOrder()]])
        $this->get(route('sales.portal.do.show', $order->public_id))->assertNotFound();
        $this->post(route('sales.portal.do.submit', $order->public_id))->assertNotFound();
        $this->get(route('sales.portal.do.index'))->assertOk()->assertDontSee((string) $order->document_no);
    }

    public function test_on_the_phone_the_same_person_needs_the_key_and_cannot_edit_after_submitting(): void
    {
        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $sr->companies()->attach($this->company->id, ['is_active' => true]);
        $body = ['customer' => (string) $this->dealer->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '5']]];

        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/sales/delivery-orders', $body)->assertForbidden();

        $this->grant($sr, 'sales.do.create');
        $this->grant($sr, 'sales.do.view');
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);
        $made = $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated()->json();
        $this->assertSame('draft', $made['status']);
        $this->assertTrue($made['editable']);
        $this->assertSame('200.00', $made['total'], 'দাম পণ্যের — 5 × 40');

        $this->postJson('/api/v1/sales/delivery-orders/'.$made['id'].'/submit')->assertOk();
        $this->putJson('/api/v1/sales/delivery-orders/'.$made['id'], ['lines' => [['product' => (string) $this->product->public_id, 'qty' => '50']]])
            ->assertStatus(422);
        $this->assertSame('200.00', $this->getJson('/api/v1/sales/delivery-orders/'.$made['id'])->assertOk()->json('total'),
            '⛔ জমার পরে লেখক পরিমাণ বদলে ফেললেন।');
    }

    /** ⓘ সুইচের আগের খসড়া — সুইচ চালু হলে সার্ভার আর বদলাতে দেয় না, তাই ফোনে "বদলান"-ও নয় (অডিট ফোন ⓘ, ৭ অক্টোবর ২০২৬) */
    public function test_a_draft_from_before_the_switch_is_no_longer_offered_for_editing(): void
    {
        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $sr->companies()->attach($this->company->id, ['is_active' => true]);
        $this->grant($sr, 'sales.do.create');
        $this->grant($sr, 'sales.do.view');
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);

        $made = $this->postJson('/api/v1/sales/delivery-orders', [
            'customer' => (string) $this->dealer->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '5']],
        ])->assertCreated()->json();
        $this->assertTrue($made['editable']);

        app(\App\Core\Services\SettingsService::class)->set(\App\Modules\Sales\Services\SalesOrderService::REPLACES_DO, true);
        $this->assertFalse($this->getJson('/api/v1/sales/delivery-orders/'.$made['id'])->assertOk()->json('editable'),
            '⛔ সুইচের পরে ফোনে "বদলান" দেখাল, অথচ সার্ভার ফেরাবে।');
        $this->putJson('/api/v1/sales/delivery-orders/'.$made['id'], ['lines' => [['product' => (string) $this->product->public_id, 'qty' => '6']]])
            ->assertStatus(422);
    }

    /** ⭐ ফোনের সুপারভাইজার — "আমার সইয়ের অপেক্ষায়" তালিকায় DO, আর সই অনুমোদন-বাক্সের একই দরজায়; অচেনা কর্মী দেখেন না */
    public function test_on_the_phone_the_supervisor_sees_it_awaiting_and_signs_a_stranger_does_not_see_it(): void
    {
        $supervisor = $this->staff(['sales.do.view', 'approval.decide']);
        $stranger = $this->staff(['sales.do.view', 'approval.decide']);
        $flow = \App\Models\ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-DO', 'module' => 'sales',
            'action' => \App\Modules\Sales\Services\DeliveryOrderService::APPROVAL_ACTION, 'document_type' => 'DeliveryOrder', 'is_active' => true,
        ]);
        \App\Models\ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        // ⓘ ফোনের পথ — SR (একজন কর্মী) লেখেন আর জমা দেন
        $writer = $this->staff(['sales.do.view', 'sales.do.create']);
        $service = app(\App\Modules\Sales\Services\DeliveryOrderService::class);
        $order = $service->submit($service->create(['customer_id' => $this->dealer->id], [['product_id' => $this->product->id, 'qty' => '5']], $writer), $writer);
        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->status, 'প্রস্তুতিটাই ভুল — সইয়ের অপেক্ষা নেই।');

        Sanctum::actingAs($stranger, [AuthController::APP]);
        $this->assertSame([], $this->getJson('/api/v1/sales/delivery-orders?scope=awaiting_me')->assertOk()->json('orders'),
            '⛔ অচেনা কর্মীর "আমার অপেক্ষায়" তালিকায় অন্যের সইয়ের DO।');
        $this->assertNull($this->getJson('/api/v1/sales/delivery-orders/'.$order->public_id)->assertOk()->json('approval_id'));

        Sanctum::actingAs($supervisor, [AuthController::APP]);
        $mine = $this->getJson('/api/v1/sales/delivery-orders?scope=awaiting_me')->assertOk()->json('orders');
        $this->assertSame([(string) $order->public_id], array_column($mine, 'id'));
        $approval = $this->getJson('/api/v1/sales/delivery-orders/'.$order->public_id)->assertOk()->json('approval_id');
        $this->assertNotNull($approval, '⛔ সুপারভাইজার সইয়ের দরজা পেলেন না।');

        $this->postJson('/api/v1/approvals/'.$approval.'/approve')->assertOk();
        $this->assertNotSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->fresh()->status, '⛔ ফোনের সইয়েও DO এগোল না।');
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
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
}
