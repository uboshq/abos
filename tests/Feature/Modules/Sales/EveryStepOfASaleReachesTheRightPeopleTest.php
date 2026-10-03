<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\TrackingNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডেলিভারি ট্র্যাকিং, ধাপ ২ — ধাপ বদলালে ঠিক মানুষদের কাছে বার্তা, আর দোকানির নিজের ট্র্যাকিং (মালিক, ২ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি:
 *   রওনা হলে চালানের লেখক আর অনুমোদনকারী বার্তা পান, সময়রেখার ঠিকানাসহ (সমন্বয়কের সিদ্ধান্ত — SR/এলাকা আসবে ⛔১৬-এর সূত্রে);
 *   যিনি বদলালেন (মালিক নিজে) পান না; সম্পর্কহীন মানুষ পান না;
 *   দোকানি পোর্টালে নিজের বিক্রি দেখেন — অন্যের বিক্রির দাগে ৪০৩।
 */
final class EveryStepOfASaleReachesTheRightPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000', 'portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
    }

    public function test_the_writer_and_the_approver_hear_of_the_dispatch_and_nobody_else(): void
    {
        $writer = $this->member();
        $approver = $this->member();
        $stranger = $this->member();

        $this->actingAs($writer);
        $challan = $this->challan();
        \App\Models\ApprovalDecision::query()->create([
            'approval_id' => \App\Models\Approval::query()->create([
                'company_id' => $this->company->id, 'approvable_type' => DeliveryChallan::class, 'approvable_id' => $challan->id,
                'module' => 'sales', 'action' => 'zq_notice', 'status' => \App\Models\Approval::APPROVED, 'current_level' => 1,
                'requested_by' => $writer->id, 'requested_at' => now(),
            ])->id,
            'level' => 1, 'user_id' => $approver->id, 'decision' => 'approved', 'decided_at' => now(),
        ]);
        Notification::query()->delete();

        $this->actingAs($this->owner);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED, ['note' => 'test']);

        $told = Notification::query()->where('type', TrackingNotices::TYPE)->pluck('user_id')->all();
        $this->assertContains($writer->id, $told, '⛔ চালান যিনি লিখলেন, রওনার খবর পাননি।');
        $this->assertContains($approver->id, $told, '⛔ অনুমোদনকারী খবর পাননি।');
        $this->assertNotContains($stranger->id, $told, '⛔ এই বিক্রির সাথে সম্পর্কহীন মানুষ খবর পেলেন।');
        $this->assertNotContains($this->owner->id, $told, 'যিনি নিজে বদলালেন তাঁকে খবর দেওয়ার মানে নেই।');

        $note = Notification::query()->where('user_id', $writer->id)->firstOrFail();
        $this->assertStringContainsString((string) ($challan->sale_no ?: $challan->document_no), $note->title);
        $this->assertSame(route('sales.tracking.show', ['challan', $challan->public_id]), $note->url);
    }

    public function test_the_dealer_tracks_their_own_sales_only(): void
    {
        $mine = $this->challan();
        $other = Customer::query()->whereKeyNot($this->customer->id)->firstOrFail();
        $other->forceFill(['credit_limit' => '100000'])->save();
        $theirs = $this->challan($other);

        $this->actingAs($this->customer->fresh(), 'portal');
        $this->get(route('sales.portal.tracking'))->assertOk()
            ->assertSee((string) ($mine->sale_no ?: $mine->document_no))
            ->assertDontSee(route('sales.portal.tracking.show', ['challan', $theirs->public_id]), false)
            ->assertSee('liveRefresh(', false);

        $this->get(route('sales.portal.tracking.show', ['challan', $mine->public_id]))->assertOk()
            ->assertSee('data-tracking-milestones', false);
        // ⓘ সরু পথ — অন্যের বিক্রি খোঁজাতেই নেই, তাই ৪০৪ ([[CustomerPapers::trackedSale()]])
        $this->get(route('sales.portal.tracking.show', ['challan', $theirs->public_id]))->assertNotFound();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function challan(?Customer $for = null): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => ($for ?? $this->customer)->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }
}
