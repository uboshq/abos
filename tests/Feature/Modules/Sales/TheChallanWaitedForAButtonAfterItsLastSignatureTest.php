<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SignedChallanConfirmer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * সব সই হয়ে গেলেও অফিসের চালান খসড়া থাকত — কাউকে আবার "নিশ্চিত" চাপতে হত (বিক্রয়ের হাঁটা, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত (২৭ সেপ্টেম্বর): *"সব সহ শেষ হলে নিজে থেকেই পোস্ট হবে"*। কাউন্টারে এটা ছিল
 * ([[FinishTheHeldSaleOnTheLastSignature]]), অফিসের চালানে ছিল না — সইয়ের পরেও চালান খসড়া,
 * আর গুদাম মাল তুলতে পারত না যতক্ষণ না বানানেওয়ালা আবার ফিরে এসে চাপ দিতেন।
 *
 * ── ⭐ এখন ([[SignedChallanConfirmer]]) ──────────────────────────────────
 * শেষ সই পড়লেই চালান পাকা, বানানেওয়ালার নামে (ঠিক যা তিনি নিজে চাপলে হত), সইকারীর নাম অডিটে।
 * দুই ধাপের সইয়ে প্রথম সইয়ে কিছু হয় না। তখনো আটকালে (ধরুন বাকির দেয়াল) সই টিকে থাকে,
 * চালান খসড়া থাকে, আর বানানেওয়ালা খবর পান — কারণসহ।
 */
final class TheChallanWaitedForAButtonAfterItsLastSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->maker = $this->member();
        $this->maker->assignRole(Role::query()->where('name', 'Manager')->where('company_id', $this->company->id)->firstOrFail());
    }

    /** ⛔→⭐ একই চালান: সইয়ের আগে খসড়া, শেষ সইয়ে নিজে থেকেই পাকা — বানানেওয়ালার নামে, সইকারী অডিটে। */
    public function test_the_last_signature_confirms_the_challan(): void
    {
        $signer = $this->member();
        $this->flow([$signer]);

        [$challan, $approval] = $this->heldChallan();
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);

        $this->sign($approval, $signer);

        $challan->refresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->status, '⛔ শেষ সইয়ের পরেও চালান খসড়া।');
        $this->assertSame((int) $this->maker->id, (int) $challan->created_by);

        // ⭐ মাল নড়েছে বানানেওয়ালার নামে — ঠিক যা তিনি নিজে "নিশ্চিত" চাপলে হত; সইকারীর নামে নয়
        $movers = StockMovement::query()
            ->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)
            ->pluck('created_by')->unique()->values()->all();
        $this->assertSame([(int) $this->maker->id], array_map('intval', $movers),
            '⛔ চালানের মাল বানানেওয়ালার নামে নড়েনি।');

        $this->assertTrue(AuditTrail::query()
            ->where('auditable_type', $challan->getMorphClass())->where('auditable_id', $challan->id)
            ->where('action', SignedChallanConfirmer::AUDIT_ACTION)->where('user_id', $signer->id)
            ->exists(), '⛔ অডিটে সইকারীর নামে "স্বয়ংক্রিয়ভাবে পাকা" সারি নেই।');
    }

    /** ⭐ দুই ধাপের সই: প্রথম সইয়ে চালান খসড়াই থাকে, দ্বিতীয় (শেষ) সইয়ে পাকা। */
    public function test_the_first_of_two_signatures_does_not_confirm(): void
    {
        $first = $this->member();
        $second = $this->member();
        $this->flow([$first, $second]);

        [$challan, $approval] = $this->heldChallan();

        $this->sign($approval, $first);
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status, '⛔ প্রথম সইয়েই চালান পাকা হয়ে গেছে।');

        $this->sign($approval, $second);
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status, '⛔ শেষ সইয়ের পরেও চালান খসড়া।');
    }

    /**
     * ⛔ সইয়ের পরে বাকির দেয়ালে আটকালে: সই টিকে থাকে, চালান খসড়া, আর বানানেওয়ালা খবর পান —
     * সইকারী নন।
     */
    public function test_a_wall_after_the_signature_keeps_the_signature_and_tells_the_maker(): void
    {
        $signer = $this->member();
        $this->flow([$signer]);

        [$challan, $approval] = $this->heldChallan();

        // ⓘ সইয়ের অপেক্ষার মধ্যে গ্রাহকের সীমা কমল — এখন চালানটা দেয়ালে আটকায়
        Customer::query()->whereKey($challan->customer_id)->firstOrFail()->forceFill(['credit_limit' => '1'])->save();

        $this->sign($approval, $signer);

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, '⛔ পরের ধাপের বাধা সইটাই মুছে দিয়েছে।');
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);

        $told = Notification::query()->where('user_id', $this->maker->id)
            ->where('type', SignedChallanConfirmer::NOTICE)->get();
        $this->assertCount(1, $told, '⛔ চালান আটকে রইল, অথচ বানানেওয়ালা জানলেন না।');
        $this->assertStringContainsString((string) $challan->document_no, (string) $told->first()->title.' '.$told->first()->body);
        $this->assertSame(0, Notification::query()->where('user_id', $signer->id)
            ->where('type', SignedChallanConfirmer::NOTICE)->count());
    }

    /**
     * ⓘ সইয়ের অপেক্ষার মধ্যে চালানটা বাতিল হলে শেষ সইয়ে কিছুই ঘটে না — বাতিল থাকে, আর বানানেওয়ালার
     * কাছে ভুয়া "আটকে আছে" খবরও যায় না। (একটা মিউট্যান্ট বেঁচে গিয়েছিল — খসড়ার পরীক্ষা তুলে দিলেও
     * কোনো দাবি লাল হত না।)
     */
    public function test_a_challan_cancelled_while_waiting_is_left_alone(): void
    {
        $signer = $this->member();
        $this->flow([$signer]);

        [$challan, $approval] = $this->heldChallan();

        app(DeliveryChallanService::class)->cancel($challan->fresh(), 'ক্রেতা আর নেবেন না');

        $this->sign($approval, $signer);

        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status);
        $this->assertSame(0, Notification::query()->where('user_id', $this->maker->id)
            ->where('type', SignedChallanConfirmer::NOTICE)->count(), '⛔ বাতিল চালানের জন্য বানানেওয়ালার কাছে ভুয়া খবর গেছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    /** @param  list<User>  $signers  ধাপ ধরে */
    private function flow(array $signers): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);

        foreach ($signers as $n => $signer) {
            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id, 'level' => $n + 1,
                'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id,
            ]);
        }

        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();
    }

    /**
     * বানানেওয়ালা চালান বানিয়ে আসল দরজায় "নিশ্চিত" চাপেন — চালান সইয়ে যায়।
     *
     * @return array{0: DeliveryChallan, 1: Approval}
     */
    private function heldChallan(): array
    {
        $this->actingAs($this->maker);

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [[
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'),
            'delivered_qty' => '1',
            'rate' => '10',
        ]]);

        $this->actingAs($this->maker->fresh())->post(route('sales.challan.confirm', $challan))->assertRedirect();

        $approval = Approval::query()
            ->where('approvable_type', $challan->getMorphClass())->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)->first();

        $this->assertNotNull($approval, 'দৃশ্যটাই বানানো যায়নি — চালান সইয়ে যায়নি।');

        return [$challan, $approval];
    }

    private function sign(Approval $approval, User $signer): void
    {
        $this->actingAs($signer);
        app(ApprovalEngine::class)->approve($approval->fresh(), $signer);
    }
}
