<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ডিলার সই চাইতে পারতেন না — ৩ অক্টোবর ২০২৬ (সমন্বয়কের অনুমোদিত নকশা "ক")।
 *
 * কোম্পানিতে DO-র সইয়ের ছক থাকলে পোর্টাল থেকে জমা দিলেই ৫০০ — `approvals.requested_by` কেবল কর্মী নিত।
 * ⭐ দাবি:
 *   ডিলার পোর্টালে DO জমা দেন → ৩০২, আর সইয়ের অনুরোধ ডিলারের নিজের নামে (কর্মীর ঘর খালি);
 *   সুপারভাইজারের ইনবক্সে সেটা আছে (null-এর কারণে বাদ পড়ে না), পাতায়, ফোনে আর রিপোর্টে ডিলারের নাম;
 *   একই সুপারভাইজার — সইয়ের চাবি ছাড়া ইনবক্স বন্ধ, চাবি দিলে খোলে;
 *   SR-এর পথ অবিকল আগের মতো (কর্মীর নামে); অনুরোধকারী ঠিক একজন — না দুজন, না কেউ-না।
 */
final class ADealerAsksForASignatureInTheirOwnNameTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Product $product;

    private User $supervisor;

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

        $this->supervisor = $this->staff([]);
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-DO', 'module' => 'sales',
            'action' => DeliveryOrderService::APPROVAL_ACTION, 'document_type' => 'DeliveryOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $this->supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }

    public function test_a_dealer_submits_on_the_portal_and_the_signature_is_asked_in_the_dealers_name(): void
    {
        $this->actingAs($this->dealer->fresh(), 'portal');
        $this->post(route('sales.portal.do.store'), [
            'lines' => [['product_id' => $this->product->id, 'qty' => '12']],
            'submit' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = DeliveryOrder::query()->where('customer_id', $this->dealer->id)->firstOrFail();
        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->status, '⛔ ছক থাকতেও সইয়ের অপেক্ষা নেই।');

        $approval = Approval::query()->where('approvable_id', $order->id)->where('approvable_type', $order->getMorphClass())->firstOrFail();
        $this->assertNull($approval->requested_by, '⛔ ডিলারের অনুরোধ কোনো কর্মীর নামে বসল।');
        $this->assertSame((int) $this->dealer->id, (int) $approval->requested_by_customer_id);
        // ⓘ নামের পাশে পয়েন্ট — মালিকের "সব জায়গায় পয়েন্ট" (১০ অক্টোবর ২০২৬; [[Customer::nameWithPoint()]])
        $this->assertSame($this->dealer->fresh()->nameWithPoint(), $approval->requesterName());

        // ⓘ একই সুপারভাইজার — চাবি ছাড়া ইনবক্স বন্ধ, চাবি দিলে খোলে আর ডিলারের অনুরোধ সেখানে, নামসহ
        $this->actingAs($this->supervisor, 'web');
        $this->get(route('approval.inbox.index'))->assertForbidden();

        $this->grant($this->supervisor, 'approval.decide');
        $this->grant($this->supervisor, 'approval.report');
        $supervisor = $this->supervisor->fresh();
        $this->actingAs($supervisor, 'web');
        $this->assertTrue(app(ApprovalEngine::class)->pendingFor($supervisor)->contains('id', $approval->id),
            '⛔ ডিলারের অনুরোধ সুপারভাইজারের ইনবক্সে নেই — null-এর শর্তে বাদ পড়ল।');
        $this->get(route('approval.inbox.index'))->assertOk()->assertSee($this->dealer->name());
        $this->get(route('approval.inbox.show', $approval->id))->assertOk()->assertSee($this->dealer->name());
        $this->get(route('approval.report.show', ['slug' => 'pending']))->assertOk()->assertSee($this->dealer->name());

        Sanctum::actingAs($supervisor, [AuthController::APP]);
        $names = array_column($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows') ?? [], 'requesterName');
        $this->assertContains($this->dealer->fresh()->nameWithPoint(), $names, '⛔ ফোনের ইনবক্সে ডিলারের নাম নেই।');

        // আর সই দিলে DO এগোয়
        $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertOk();
        $this->assertNotSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->fresh()->status);
    }

    public function test_the_sr_still_asks_in_their_own_staff_name(): void
    {
        $sr = $this->staff(['sales.do.view', 'sales.do.create']);
        $service = app(DeliveryOrderService::class);
        $order = $service->submit($service->create(['customer_id' => $this->dealer->id],
            [['product_id' => $this->product->id, 'qty' => '5']], $sr), $sr);

        $approval = app(ApprovalEngine::class)->latestFor($order, DeliveryOrderService::APPROVAL_ACTION);
        $this->assertSame((int) $sr->id, (int) $approval->requested_by);
        $this->assertNull($approval->requested_by_customer_id);
        $this->assertSame($sr->name, $approval->requesterName());
    }

    public function test_an_approval_has_exactly_one_requester(): void
    {
        $base = [
            'company_id' => $this->company->id, 'approvable_type' => 'x', 'approvable_id' => 1,
            'module' => 'sales', 'action' => 'delivery_order', 'status' => Approval::PENDING,
            'current_level' => 1, 'requested_at' => now(),
        ];

        foreach ([['requested_by' => null, 'requested_by_customer_id' => null],
            ['requested_by' => $this->supervisor->id, 'requested_by_customer_id' => $this->dealer->id]] as $who) {
            try {
                Approval::query()->create([...$base, ...$who]);
                $this->fail('⛔ অনুরোধকারী ঠিক একজন নয়, তবু অনুরোধ বসল: '.json_encode($who));
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
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
