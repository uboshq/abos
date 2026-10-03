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

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
