<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Events\DeliveryOrderCancelled;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DO লেখা আর সুপারভাইজারের সই — মালিকের বিক্রয়-ধারা §২ক-খ (২ অক্টোবর ২০২৬; টুকরো ২-৩)।
 *
 * ⭐ দাবি:
 *   ডিলার নিজের নামেই লেখেন — ফর্মে অন্য ডিলার দিলেও নিজের নামে বসে; দাম পণ্যের, লেখকের নয়;
 *   জমার পরে লেখক আর বদলাতে পারেন না; একই ডিলার নিজেরটা বদলান, অন্য ডিলার পারেন না (এক অভিনেতা দুবার);
 *   ছক না থাকলে জমাতেই অনুমোদিত আর abos-86-এর সংকেত; ছক থাকলে সুপারভাইজারের অপেক্ষা;
 *   অনুমোদনকারী মজুদ দেখে পরিমাণ কমান — মোট আবার গোনা; অনুমোদনকারী নন এমন কেউ পারেন না;
 *   শেষ সইয়ে অনুমোদিত + সংকেত; ফেরতে `rejected` + বাতিলের সংকেত।
 */
final class ADealerWritesTheDeliveryOrderAndTheSupervisorSignsItTest extends TestCase
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
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->dealer = Customer::query()->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();
    }

    public function test_a_dealer_writes_in_his_own_name_at_the_products_price(): void
    {
        $other = Customer::query()->whereKeyNot($this->dealer->id)->firstOrFail();

        $order = $this->service()->create(['customer_id' => $other->id],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '1']], $this->dealer);

        $this->assertSame((int) $this->dealer->id, (int) $order->customer_id, '⛔ ডিলার অন্যের নামে DO লিখলেন।');
        $this->assertSame((int) $this->dealer->id, (int) $order->created_by_customer_id);
        $this->assertSame(0, bccomp('40', (string) $order->lines->first()->rate, 4), '⛔ লেখকের দেওয়া দাম বসল।');
        $this->assertSame(0, bccomp('400', (string) $order->total, 4));
        $this->assertStringStartsWith('DO', (string) $order->document_no);
    }

    public function test_the_same_dealer_edits_his_own_draft_another_cannot_and_nobody_after_submitting(): void
    {
        $order = $this->draft();
        $other = Customer::query()->whereKeyNot($this->dealer->id)->firstOrFail();

        $this->service()->update($order, [], [['product_id' => $this->product->id, 'qty' => '7']], $this->dealer);
        $this->assertSame(0, bccomp('280', (string) $order->fresh()->total, 4), 'নিজের খসড়া বদলানো যায়নি।');

        try {
            $this->service()->update($order, [], [['product_id' => $this->product->id, 'qty' => '1']], $other);
            $this->fail('⛔ অন্য ডিলার এই DO বদলালেন।');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        Event::fake([DeliveryOrderSupervisorApproved::class]);
        $this->service()->submit($order, $this->dealer);

        $this->expectException(ValidationException::class);
        $this->service()->update($order->fresh(), [], [['product_id' => $this->product->id, 'qty' => '99']], $this->dealer);
    }

    public function test_with_no_flow_submitting_approves_and_tells_accounts(): void
    {
        Event::fake([DeliveryOrderSupervisorApproved::class]);

        $order = $this->service()->submit($this->draft(), $this->dealer);

        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_APPROVED, $order->status);
        Event::assertDispatched(DeliveryOrderSupervisorApproved::class,
            fn ($e) => $e->payload['delivery_order_id'] === (int) $order->id);
    }

    public function test_the_supervisor_lowers_a_quantity_signs_and_accounts_hear_of_it(): void
    {
        $supervisor = $this->member();
        $this->flow($supervisor);
        Event::fake([DeliveryOrderSupervisorApproved::class]);

        $order = $this->service()->submit($this->draft(), $this->dealer);
        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $order->status, 'ছক থাকতেও সইয়ের অপেক্ষা নেই।');
        Event::assertNotDispatched(DeliveryOrderSupervisorApproved::class);

        $stranger = $this->member();
        try {
            $this->service()->setApprovedQuantities($order, [$order->lines->first()->id => '4'], $stranger);
            $this->fail('⛔ অনুমোদনকারী নন, অথচ পরিমাণ বদলালেন।');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $line = $order->lines->first();
        $this->service()->setApprovedQuantities($order, [$line->id => '6'], $supervisor);
        $this->assertSame(0, bccomp('240', (string) $order->fresh()->total, 4), '⛔ পরিমাণ কমল, মোট পুরনো রইল।');

        $approval = app(ApprovalEngine::class)->latestFor($order, DeliveryOrderService::APPROVAL_ACTION);
        app(ApprovalEngine::class)->approve($approval, $supervisor);

        $this->assertSame(DeliveryOrderStatus::SUPERVISOR_APPROVED, $order->fresh()->status, '⛔ শেষ সইয়েও DO অনুমোদিত হলো না।');
        Event::assertDispatched(DeliveryOrderSupervisorApproved::class,
            fn ($e) => bccomp('240', (string) $e->payload['total'], 4) === 0);
    }

    public function test_a_sent_back_do_stops_and_releases_its_hold(): void
    {
        $supervisor = $this->member();
        $this->flow($supervisor);
        Event::fake([DeliveryOrderCancelled::class]);

        $order = $this->service()->submit($this->draft(), $this->dealer);
        $approval = app(ApprovalEngine::class)->latestFor($order, DeliveryOrderService::APPROVAL_ACTION);
        app(ApprovalEngine::class)->reject($approval, $supervisor, 'মজুদ নেই');

        $this->assertSame(DeliveryOrderStatus::REJECTED, $order->fresh()->status);
        Event::assertDispatched(DeliveryOrderCancelled::class, fn ($e) => $e->payload['reason'] === 'rejected');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function service(): DeliveryOrderService
    {
        return app(DeliveryOrderService::class);
    }

    private function draft(): DeliveryOrder
    {
        return $this->service()->create([], [['product_id' => $this->product->id, 'qty' => '10']], $this->dealer);
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    /** কোম্পানির নিজের ছক — এক স্তর, নামসহ, অনুমোদনকারী একজন নির্দিষ্ট মানুষ */
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
