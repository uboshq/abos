<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের বিক্রয় আদেশ — DO বিক্রয় আদেশে মেশানো, নকশার ধাপ ১০ (৫ অক্টোবর ২০২৬): `/api/v1/sales/orders`, DO-র যমজ।
 *
 * ⭐ দাবি:
 *   একই মানুষ — চাবি ছাড়া ৪০৩, `sales.order.create` দিলে লেখেন; দাম পণ্যের, উৎস `sr`, JSON-এ `kind: so`;
 *   সুইচ বন্ধে জমা চাইলে ফেরে আর একটা আদেশও বাড়ে না; খসড়া চলে;
 *   সুইচ চালুতে জমা → সুপারভাইজারের অপেক্ষা; অন্য SR বদলাতে বা জমা দিতে পারেন না (৪০৩), লেখক জমার পরে বদলাতে পারেন না;
 *   সুপারভাইজার "আমার অপেক্ষায়" তালিকায় পান, কমাতে পারেন বাড়াতে নয়, সই দেন — অচেনা কর্মী তালিকায় পান না;
 *   নেট ছাড়া লেখা আদেশ (সিঙ্ক) সুইচ চালুতে জমা হয়, বন্ধে আজকের মতো খসড়া।
 */
final class ThePhoneWritesTheSalesOrderTheWayItWroteTheDoTest extends TestCase
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
    }

    public function test_the_same_person_needs_the_key_and_a_submit_waits_for_the_switch(): void
    {
        $sr = $this->staff([]);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $this->postJson('/api/v1/sales/orders', $this->body())->assertForbidden();

        $sr = $this->staff(['sales.order.view', 'sales.order.create'], $sr);
        Sanctum::actingAs($sr, [AuthController::APP]);

        $before = SalesOrder::query()->count();
        $this->postJson('/api/v1/sales/orders', $this->body(submit: true))->assertStatus(422);
        $this->assertSame($before, SalesOrder::query()->count(), '⛔ সুইচ বন্ধে জমা ফিরল, কিন্তু একটা অনাথ খসড়া রয়ে গেল।');

        $made = $this->postJson('/api/v1/sales/orders', $this->body())->assertCreated()->json();
        $this->assertSame('so', $made['kind']);
        $this->assertSame(SalesOrderStatus::DRAFT, $made['status']);
        $this->assertTrue($made['editable']);
        $order = SalesOrder::query()->where('public_id', $made['id'])->firstOrFail();
        $this->assertSame(SalesOrderStatus::SOURCE_SR, $order->source);
        $this->assertSame((int) $sr->id, (int) $order->created_by);
        $this->assertSame(0, bccomp('200', (string) $order->subtotal, 4), 'দাম পণ্যের — 5 × 40, ফোন দাম পাঠায় না');

        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/submit')->assertStatus(422);
        // ⓘ অ্যাপ /me থেকে জানে কোন দরজায় লিখবে
        $this->assertFalse($this->getJson('/api/v1/me')->assertOk()->json('ordersReplaceDo'));
        $this->replaceDo(true);
        $this->assertTrue($this->getJson('/api/v1/me')->assertOk()->json('ordersReplaceDo'));
        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/submit')->assertOk();
        $this->assertNotSame(SalesOrderStatus::DRAFT, $order->fresh()->status, '⛔ সুইচ চালুতেও জমা হলো না।');
    }

    public function test_only_the_writer_edits_or_submits_and_never_after_submitting(): void
    {
        $this->replaceDo(true);
        $writer = $this->staff(['sales.order.view', 'sales.order.create']);
        $other = $this->staff(['sales.order.view', 'sales.order.create']);

        Sanctum::actingAs($writer, [AuthController::APP]);
        $made = $this->postJson('/api/v1/sales/orders', $this->body())->assertCreated()->json();

        Sanctum::actingAs($other, [AuthController::APP]);
        $this->putJson('/api/v1/sales/orders/'.$made['id'], $this->body(qty: '50'))->assertForbidden();
        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/submit')->assertForbidden();
        $this->assertFalse($this->getJson('/api/v1/sales/orders/'.$made['id'])->assertOk()->json('editable'));

        Sanctum::actingAs($writer, [AuthController::APP]);
        $this->putJson('/api/v1/sales/orders/'.$made['id'], $this->body(qty: '6'))->assertOk();
        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/submit')->assertOk();
        $this->putJson('/api/v1/sales/orders/'.$made['id'], $this->body(qty: '50'))->assertStatus(422);
        $this->assertSame('6.0000', $this->getJson('/api/v1/sales/orders/'.$made['id'])->assertOk()->json('lines.0.qty'),
            '⛔ জমার পরে লেখক পরিমাণ বদলে ফেললেন।');
    }

    public function test_the_supervisor_sees_it_awaiting_lowers_but_never_raises_and_signs_a_stranger_does_not_see_it(): void
    {
        $this->replaceDo(true);
        $supervisor = $this->staff(['sales.order.view', 'approval.decide']);
        $stranger = $this->staff(['sales.order.view', 'approval.decide']);
        $this->flow($supervisor);

        $writer = $this->staff(['sales.order.view', 'sales.order.create']);
        Sanctum::actingAs($writer, [AuthController::APP]);
        $made = $this->postJson('/api/v1/sales/orders', $this->body(qty: '10', submit: true))->assertCreated()->json();
        $this->assertSame(SalesOrderStatus::AWAITING_APPROVAL, $made['status'], 'প্রস্তুতিটাই ভুল — সইয়ের অপেক্ষা নেই।');

        Sanctum::actingAs($stranger, [AuthController::APP]);
        $this->assertSame([], $this->getJson('/api/v1/sales/orders?scope=awaiting_me')->assertOk()->json('orders'),
            '⛔ অচেনা কর্মীর "আমার অপেক্ষায়" তালিকায় অন্যের সইয়ের আদেশ।');
        $this->assertNull($this->getJson('/api/v1/sales/orders/'.$made['id'])->assertOk()->json('approval_id'));
        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/approved-quantities', ['lines' => [$made['lines'][0]['id'] => 1]])
            ->assertForbidden();
        $this->assertSame('10.0000', SalesOrder::query()->where('public_id', $made['id'])->firstOrFail()->lines()->value('ordered_qty'),
            '⛔ অচেনা কর্মী আদেশের পরিমাণ কমিয়ে ফেললেন।');

        Sanctum::actingAs($supervisor, [AuthController::APP]);
        $this->assertSame([$made['id']], array_column($this->getJson('/api/v1/sales/orders?scope=awaiting_me')->assertOk()->json('orders'), 'id'));
        $line = $made['lines'][0]['id'];
        $this->postJson('/api/v1/sales/orders/'.$made['id'].'/approved-quantities', ['lines' => [$line => 12]])->assertStatus(422);
        $low = $this->postJson('/api/v1/sales/orders/'.$made['id'].'/approved-quantities', ['lines' => [$line => 7]])->assertOk()->json();
        $this->assertSame('10.0000', $low['lines'][0]['qty'], 'চাওয়া পরিমাণ হারিয়ে গেল।');
        $this->assertSame('7.0000', $low['lines'][0]['approved_qty']);
        $this->assertSame('7.0000', $low['lines'][0]['final_qty']);

        $approval = $low['approval_id'];
        $this->assertNotNull($approval, '⛔ সুপারভাইজার সইয়ের দরজা পেলেন না।');
        $this->postJson('/api/v1/approvals/'.$approval.'/approve')->assertOk();
        $this->assertNotSame(SalesOrderStatus::AWAITING_APPROVAL, SalesOrder::query()->where('public_id', $made['id'])->value('status'),
            '⛔ ফোনের সইয়েও আদেশ এগোল না।');
    }

    public function test_an_order_written_offline_is_submitted_only_when_the_switch_is_on(): void
    {
        $sr = $this->staff(['sales.order.view', 'sales.order.create']);
        $this->actingAs($sr);

        $off = $this->push($sr, 'local-1759600000000000-0');
        $this->assertSame(SalesOrderStatus::DRAFT, $off->status, 'সুইচ বন্ধে নেট ছাড়া লেখা আদেশ আজকের মতো খসড়া থাকার কথা।');
        $this->assertSame(SalesOrderStatus::SOURCE_SR, $off->source);

        $this->replaceDo(true);
        $on = $this->push($sr, 'local-1759600000000001-0');
        $this->assertNotSame(SalesOrderStatus::DRAFT, $on->status, '⛔ সুইচ চালুতে নেট ছাড়া লেখা আদেশ খসড়ায় পড়ে রইল — কারো চোখে পড়বে না।');
        $this->assertNotNull($on->submitted_at);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function body(string $qty = '5', bool $submit = false): array
    {
        return [
            'customer' => (string) $this->dealer->public_id,
            'lines' => [['product' => (string) $this->product->public_id, 'qty' => $qty]],
            'submit' => $submit,
        ];
    }

    private function push(User $sr, string $changeId): SalesOrder
    {
        $outcome = app(SyncService::class)->push($sr, 'phone-a', 'sales', [[
            'changeId' => $changeId,
            'entityType' => 'SalesOrder',
            'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'customerId' => (string) $this->dealer->public_id,
                'lines' => [['productId' => (string) $this->product->public_id, 'qty' => '3', 'rate' => '40']],
            ]),
            'clientVersion' => 1,
        ]]);
        $this->assertSame(SyncChange::APPLIED, $outcome[0]['status'], (string) ($outcome[0]['message'] ?? ''));

        return SalesOrder::query()->latest('id')->firstOrFail();
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

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
