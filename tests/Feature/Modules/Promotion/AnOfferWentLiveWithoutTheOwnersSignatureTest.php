<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\Ownership;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionApprovalChain;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * অফারের সইয়ের ছক যে-কারও হাতে দেওয়া যেত — গ্যাপ ৪৩ (fe-র বিক্রয় তালিকা, ২০ অক্টোবর ২০২৬)।
 *
 * ── ⛔ মালিকের নিয়ম ────────────────────────────────────────────────────
 * ⓘ *"যেকোনো ছাড়ে মালিকের সই"*। অফারও ছাড়। ⚠️ আগে `promotion.approve` চাবিওয়ালা যে-কেউ শেষ সই দিতেন, আর
 * অফারটা অনুমোদিত হয়ে চালু হত — মালিক জানতেনই না।
 *
 * ⭐ এখন: সুইচ চালু থাকলে যে সইয়ে অফার অনুমোদিত হয় সেটা মালিকের (super admin)। আগের স্তরে অন্য কেউ সই দিতে
 * পারেন; ইনবক্স বা ফোনের পথে অন্য কেউ শেষ সই দিলে অনুমোদনটা গণ্য নয়; চালুর দরজাও এটা পাশ কাটায় না।
 * ⓘ সুইচ বন্ধ (যেমন UB) থাকলে আচরণ অবিকল আগের মতো।
 */
final class AnOfferWentLiveWithoutTheOwnersSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $maker;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->maker = $this->staff(['promotion.view', 'promotion.create', 'promotion.submit', 'promotion.activate']);
        $this->manager = $this->staff(['promotion.view', 'promotion.approve', 'promotion.activate', 'approval.decide']);

        /* ⭐ সেটআপটাই আগে প্রমাণ — মালিক মালিক, বাকি দুইজন নন, আর কোম্পানির কোনো অফারের ছক নেই */
        $ownership = app(Ownership::class);
        $this->assertTrue($ownership->isOwnerIn($this->owner, $this->company->id), 'ডেমোর মালিক এই কোম্পানির super admin নন।');
        $this->assertFalse($ownership->isOwnerIn($this->maker, $this->company->id));
        $this->assertFalse($ownership->isOwnerIn($this->manager, $this->company->id));
        $this->assertTrue($this->manager->can('promotion.approve'), 'ব্যবস্থাপকের হাতে সইয়ের চাবিই নেই — দাবিটা ভুল কারণে থামত।');
        $this->assertSame(0, ApprovalFlow::query()->where('module', PromotionApprovalChain::MODULE)->count(),
            'ডেমোতে আগে থেকেই অফারের ছক আছে — এক-সইয়ের পথটা মাপা হত না।');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⛔ ছক নেই, সুইচ চালু: চাবিওয়ালা ব্যবস্থাপকের সই থামে (৪২২, বাংলা কারণ), অফার চালু হয় না; মালিকের সইয়ে হয়।
     */
    public function test_a_manager_with_the_key_cannot_give_the_last_signature_and_the_owner_can(): void
    {
        $this->approvalSwitch(true);
        $offer = $this->submitted();

        $response = $this->actingAs($this->manager)->postJson(route('promotion.approve', $offer))->assertStatus(422);
        $this->assertSame(__('promotion::approval.owner_signs_last'), $response->json('errors.status.0'));
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', (string) trans('promotion::approval.owner_signs_last', [], 'bn'));
        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status, 'ব্যবস্থাপকের সইয়েই অফার অনুমোদিত হয়ে গেছে।');

        $this->actingAs($this->manager)->post(route('promotion.activate', $offer))->assertSessionHasErrors('status');
        $this->assertNotSame(PromotionStatus::ACTIVE, $offer->fresh()->status, 'মালিকের সই ছাড়াই অফার চালু হয়ে গেছে।');

        $this->actingAs($this->owner)->post(route('promotion.approve', $offer))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::APPROVED, $offer->fresh()->status, 'মালিকের সইয়েও অফার অনুমোদিত হলো না।');
        $this->assertSame($this->owner->id, (int) $offer->fresh()->approved_by);

        $this->actingAs($this->maker)->post(route('promotion.activate', $offer))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::ACTIVE, $offer->fresh()->status, 'মালিক সই দিলেন, তবু অফার চালু হলো না।');
    }

    /**
     * ⭐ ছকে আগের স্তরে অন্য কেউ সই দেন, শেষ স্তরে কেবল মালিক — ব্যবস্থাপক শেষ স্তরেও ছকে থাকলেও।
     */
    public function test_in_a_chain_a_manager_signs_an_earlier_level_but_not_the_last(): void
    {
        $this->approvalSwitch(true);
        /* ⓘ দুই স্তরে একজন নয় — তাই শেষ স্তরে আলাদা এক ব্যবস্থাপক, ছকে নাম আছে, চাবিও আছে */
        $second = $this->staff(['promotion.view', 'promotion.approve']);
        $this->flow([1 => [$this->manager], 2 => [$second, $this->owner]]);
        $offer = $this->submitted();
        $this->assertSame(1, (int) $this->request($offer)->current_level, 'ছকটা অনুরোধ খোলেনি।');

        $this->actingAs($this->manager)->post(route('promotion.approve', $offer))->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $this->request($offer)->current_level, 'আগের স্তরে ব্যবস্থাপকের সই বসেনি।');

        $response = $this->actingAs($second)->postJson(route('promotion.approve', $offer))->assertStatus(422);
        $this->assertSame(__('promotion::approval.owner_signs_last'), $response->json('errors.status.0'));
        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status);
        $this->assertSame(Approval::PENDING, $this->request($offer)->status, 'থামানো সইটা ইঞ্জিনে বসে রইল।');

        $this->actingAs($this->owner)->post(route('promotion.approve', $offer))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::APPROVED, $offer->fresh()->status);
    }

    /**
     * ⛔ ফোনের ইনবক্স থেকে অন্য কেউ শেষ সই দিলে অনুমোদন গণ্য নয় — চালুর দরজা অফারটা খসড়ায় ফেরায়, চালু করে না।
     */
    public function test_a_last_signature_from_the_phone_by_a_non_owner_does_not_let_the_activate_door_run_it(): void
    {
        $this->approvalSwitch(true);
        $this->flow([1 => [$this->manager]]);
        $offer = $this->submitted();
        $approval = $this->request($offer);

        Sanctum::actingAs($this->manager->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertOk();
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, 'ফোনের সইটাই বসেনি — দাবিটা কিছু মাপত না।');

        $this->actingAs($this->manager)->post(route('promotion.activate', $offer))->assertSessionHasErrors('status');

        $fresh = $offer->fresh();
        $this->assertNotSame(PromotionStatus::ACTIVE, $fresh->status, 'মালিকের সই ছাড়া ফোনের পথে অফার চালু হলো।');
        $this->assertSame(PromotionStatus::DRAFT, $fresh->status);
        /* ⓘ ছকের একমাত্র স্তরে মালিক নেই — তাই কারণটাও তাই বলে */
        $this->assertSame(__('promotion::approval.no_owner_at_last_level'), app(PromotionApprovalChain::class)->lastReason($fresh));
    }

    /**
     * ⛔ নিয়মের আগে অন্য কারও সইয়ে অনুমোদিত অফার — চালুর দরজা চাবিওয়ালাকেও থামায়; মালিক নিজে চালু করেন।
     */
    public function test_the_activate_door_cannot_skip_the_owner_for_an_offer_someone_else_approved(): void
    {
        $this->approvalSwitch(true);
        $offer = $this->aDraft();
        $offer->forceFill(['status' => PromotionStatus::APPROVED, 'approved_by' => $this->manager->id, 'approved_at' => Carbon::now()])->save();

        $response = $this->actingAs($this->maker)->postJson(route('promotion.activate', $offer))->assertStatus(422);
        $this->assertSame(__('promotion::approval.owner_must_have_signed'), $response->json('errors.status.0'));
        $this->assertSame(PromotionStatus::APPROVED, $offer->fresh()->status, 'চালুর দরজা মালিকের সই পাশ কাটাল।');

        $this->actingAs($this->owner)->post(route('promotion.activate', $offer))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::ACTIVE, $offer->fresh()->status);
    }

    /**
     * ⭐ সুইচ বন্ধ (UB-র মতো): মালিক নন এমন কেউ খসড়া সোজা চালু করেন — আজকের মতোই, নতুন কিছু থামায় না।
     */
    public function test_with_approvals_off_a_non_owner_activates_as_today(): void
    {
        $this->approvalSwitch(false);
        $offer = $this->aDraft();

        $this->actingAs($this->maker)->post(route('promotion.activate', $offer))->assertSessionHasNoErrors();

        $fresh = $offer->fresh();
        $this->assertSame(PromotionStatus::ACTIVE, $fresh->status, 'সুইচ বন্ধ, তবু মালিকের সই চাওয়া হলো।');
        $this->assertSame($this->maker->id, (int) $fresh->approved_by);
    }

    /**
     * ⛔ মালিক নিজের অফারে সই দিলে অডিটে আলাদা চিহ্ন (SOX) — অন্যের অফারে সই দিলে চিহ্ন নেই।
     */
    public function test_the_owner_signing_his_own_offer_is_marked_in_the_audit_and_someone_elses_is_not(): void
    {
        $this->approvalSwitch(true);

        $own = $this->aDraft();
        $own->forceFill(['created_by' => $this->owner->id])->save();
        $this->actingAs($this->owner)->post(route('promotion.submit', $own))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::SUBMITTED, $own->fresh()->status, 'মালিকের অফার জমাই হলো না।');
        $this->assertSame(0, $this->selfSignedMarks($own), 'সইয়ের আগেই চিহ্ন বসে আছে।');

        $this->actingAs($this->owner)->post(route('promotion.approve', $own))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::APPROVED, $own->fresh()->status);
        $this->assertSame(1, $this->selfSignedMarks($own), 'মালিক নিজের অফারে সই দিলেন, অডিটে আলাদা চিহ্ন নেই।');
        $this->assertSame(__('promotion::approval.owner_self_signed'), AuditTrail::query()
            ->forRecord(Promotion::class, (int) $own->id)->where('action', PromotionApprovalChain::OWNER_SELF_SIGNED)->value('reason'));

        $others = $this->submitted();
        $this->actingAs($this->owner)->post(route('promotion.approve', $others))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::APPROVED, $others->fresh()->status);
        $this->assertSame(0, $this->selfSignedMarks($others), 'অন্যের অফারে সই, তবু "নিজে সই" চিহ্ন বসল।');
    }

    /**
     * ⛔ ছকের শেষ স্তরে মালিক নেই: শেষ অ-মালিক সইয়ে অফার অনুমোদিত হয় না, খসড়ায় ফেরে, আর বাংলা কারণ পাতায় দেখা যায়।
     */
    public function test_a_flow_without_the_owner_at_its_last_level_sends_the_offer_back_with_the_reason_on_its_page(): void
    {
        $this->approvalSwitch(true);
        $this->flow([1 => [$this->manager]]);
        $this->assertFalse(ApprovalFlowStep::query()->get()
            ->where('approval_flow_id', ApprovalFlow::query()->where('module', PromotionApprovalChain::MODULE)->value('id'))
            ->contains(fn (ApprovalFlowStep $step) => $step->allows($this->owner)), 'ছকের শেষ স্তরে মালিক আছেন — দাবিটা অন্য কিছু মাপত।');

        $offer = $this->submitted();
        $this->actingAs($this->manager)->post(route('promotion.approve', $offer));

        $fresh = $offer->fresh();
        $this->assertNotSame(PromotionStatus::APPROVED, $fresh->status, 'মালিক ছাড়া ছকের শেষ সইয়ে অফার অনুমোদিত।');
        $this->assertNotSame(PromotionStatus::ACTIVE, $fresh->status);
        $this->assertSame(PromotionStatus::DRAFT, $fresh->status);

        $reason = __('promotion::approval.no_owner_at_last_level');
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', (string) trans('promotion::approval.no_owner_at_last_level', [], 'bn'));
        $this->assertSame($reason, app(PromotionApprovalChain::class)->lastReason($fresh));
        $this->actingAs($this->maker)->get(route('promotion.show', $offer))->assertOk()->assertSee($reason);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function approvalSwitch(bool $on): void
    {
        $settings = app(SettingsService::class);
        $settings->set('promotion.needs_approval', $on);
        $settings->flush();
        $this->assertSame($on, (bool) $settings->get('promotion.needs_approval', ! $on));
    }

    private function aDraft(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Owner signs',
            'name_bn' => 'মালিকের সই',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-G43-'.strtoupper(substr(md5(microtime()), 0, 6));
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::DRAFT;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->maker->id;
        $offer->save();

        return $offer;
    }

    private function submitted(): Promotion
    {
        $this->app->forgetScopedInstances();
        $offer = $this->aDraft();
        $this->actingAs($this->maker)->post(route('promotion.submit', $offer))->assertSessionHasNoErrors();
        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status, 'জমাই হলো না।');

        return $offer;
    }

    /** @param  array<int, list<User>>  $levels */
    private function flow(array $levels): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'module' => PromotionApprovalChain::MODULE,
            'action' => PromotionApprovalChain::ACTION,
            'document_type' => '',
            'is_active' => true,
        ]);

        foreach ($levels as $level => $people) {
            foreach ($people as $person) {
                ApprovalFlowStep::query()->create([
                    'approval_flow_id' => $flow->id, 'level' => $level, 'step_name' => 'স্তর '.$level,
                    'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $person->id,
                ]);
            }
        }

        $this->app->forgetScopedInstances();
        app()->forgetInstance(ApprovalEngine::class);
    }

    private function selfSignedMarks(Promotion $offer): int
    {
        return AuditTrail::query()->forRecord(Promotion::class, (int) $offer->id)
            ->where('action', PromotionApprovalChain::OWNER_SELF_SIGNED)->count();
    }

    private function request(Promotion $offer): Approval
    {
        return Approval::query()
            ->where('approvable_type', Promotion::class)
            ->where('approvable_id', $offer->id)
            ->orderByDesc('id')
            ->firstOrFail();
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
