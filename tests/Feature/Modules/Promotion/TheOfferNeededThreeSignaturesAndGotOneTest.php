<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionApprovalChain;
use App\Modules\Promotion\Services\PromotionLifecycle;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * অফারের তিন সই লাগত, আর একটাতেই পার হত — স্পেক §১৪।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ স্পেকের পথ: *খসড়া → জমা → বিক্রয় ব্যবস্থাপক → বিক্রয় প্রধান → অর্থ
 * → অনুমোদিত → সক্রিয়*। ⛔ কোডে ছিল একটাই `approve` — প্রথম যিনি চাপতেন
 * তাঁর এক সইয়েই অফার *"অনুমোদিত"*, আর অর্থ বিভাগ কোনোদিন দেখতই না।
 *
 * ⭐ স্তরগুলো কোম্পানির নিজের ছক (`promotion · approve`), আর
 * [[PromotionApprovalChain]] বাড়ির [[ApprovalEngine]]-এর উপর দাঁড়ায়।
 *
 * ⚠️ প্রতিটা "না"-এর পাশে একটা "হ্যাঁ" — ঐ স্তরটা **অন্য মানুষের** হাতে
 * খোলে। ⓘ নাহলে যে সেবা সবাইকে থামায় সেও সবুজ হত।
 */
final class TheOfferNeededThreeSignaturesAndGotOneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $manager;

    private User $head;

    private User $finance;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->manager = $this->signer('Sales Manager');
        $this->head = $this->signer('Sales Head');
        $this->finance = $this->signer('Finance');

        /* ⓘ বানিয়েছেন মালিক — তাঁর নাম `created_by`-তে */
        $this->offer = new Promotion([
            'name_en' => 'Three signatures',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addMonth(),
        ]);
        $this->offer->code = 'PROM-C-0001';
        $this->offer->type = PromotionType::QUANTITY_SLAB;
        $this->offer->status = PromotionStatus::DRAFT;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $this->owner->id;
        $this->offer->save();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function signer(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo([
            Permission::findOrCreate('promotion.view', 'web'),
            Permission::findOrCreate('promotion.approve', 'web'),
        ]);

        return $user;
    }

    /**
     * কোম্পানির ছক — স্তর ধরে এক বা একাধিক মানুষ।
     *
     * @param  array<int, list<User>>  $levels
     */
    private function flow(array $levels): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'module' => PromotionApprovalChain::MODULE,
            'action' => PromotionApprovalChain::ACTION,
            'document_type' => '',
            'is_active' => true,
        ]);

        foreach ($levels as $level => $people) {
            foreach ($people as $person) {
                ApprovalFlowStep::create([
                    'approval_flow_id' => $flow->id,
                    'level' => $level,
                    'step_name' => $person->name,
                    'approver_type' => ApprovalFlowStep::BY_USER,
                    'approver_id' => $person->id,
                ]);
            }
        }

        return $flow;
    }

    /**
     * ⚠️ প্রতিবার নতুন ইঞ্জিন — ছকের স্মৃতি এক অনুরোধের।
     *
     * ⓘ ইঞ্জিন scoped, আর ছকগুলো একবার তুলে রাখে। ⛔ পুরনোটা রাখলে এই
     * টেস্টে বসানো ছকটা সে দেখতই না, আর দাবিটা ভুল কারণে লাল হত।
     */
    private function chain(): PromotionApprovalChain
    {
        $this->app->forgetScopedInstances();

        return app(PromotionApprovalChain::class);
    }

    private function submit(): void
    {
        $this->actingAs($this->owner);
        $this->chain()->submit($this->offer->fresh());
    }

    private function signAs(User $user): Promotion
    {
        $this->actingAs($user);

        return $this->chain()->sign($this->offer->fresh(), $user);
    }

    /** ⓘ থামানোর বার্তাটা — না থামালে দাবিটাই ব্যর্থ। */
    private function refusal(callable $act): string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        $this->fail('The service let it through.');
    }

    private function request(): Approval
    {
        return Approval::query()
            ->where('approvable_type', Promotion::class)
            ->where('approvable_id', $this->offer->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function offerStatus(): PromotionStatus
    {
        return $this->offer->fresh()->status;
    }

    /**
     * ⭐ প্রথম সইয়ের পরে অফার **অনুমোদিত নয়** — পরের স্তরে যায়।
     *
     * ⚠️ পাল্টা-দাবি: ঐ পরের স্তরে বিক্রয় প্রধান সত্যিই সই দিতে পারেন।
     */
    public function test_the_first_of_three_signatures_does_not_approve_the_offer(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->head], 3 => [$this->finance]]);
        $this->submit();

        $this->signAs($this->manager);

        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus(), 'এক সইয়েই অফার অনুমোদিত হয়ে গেছে।');
        $this->assertSame(2, (int) $this->request()->current_level);
        $this->assertNull($this->offer->fresh()->approved_by);

        $this->signAs($this->head);
        $this->assertSame(3, (int) $this->request()->current_level);
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus());
    }

    /**
     * ⛔ একই মানুষ দুই স্তরে সই দেন না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ব্যবস্থাপক স্তর ১ **আর** স্তর ২ — দুইটাতেই ছকে
     * আছেন, অর্থাৎ ইঞ্জিন তাঁকে দুইবারই দিত। ⓘ পাল্টা-দাবি: স্তর ২-এর
     * অন্য মানুষ (প্রধান) ঠিকই সই দিতে পারেন।
     */
    public function test_the_same_person_cannot_sign_two_levels(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->manager, $this->head], 3 => [$this->finance]]);
        $this->submit();

        $this->signAs($this->manager);

        $said = $this->refusal(fn () => $this->signAs($this->manager));
        $this->assertSame(__('promotion::approval.cannot_sign_twice', ['level' => 1]), $said);
        $this->assertSame(2, (int) $this->request()->current_level, 'দ্বিতীয় সইটা ইঞ্জিনে বসে গেছে।');

        $this->signAs($this->head);
        $this->assertSame(3, (int) $this->request()->current_level, 'স্তর ২ অন্য মানুষের হাতেও খোলেনি।');
    }

    /**
     * ⛔ ইনবক্সের পথে দুই সই দিলেও অফার অনুমোদিত হয় না।
     *
     * ⚠️ ইনবক্স ইঞ্জিন সরাসরি ডাকে, আর ইঞ্জিন কেবল একই স্তরে দুই সই
     * আটকায়। ⓘ তাই শেষ সইয়ের পরে [[PromotionApprovalChain::settle()]]
     * আবার মাপে, আর অফারটা কারণসহ খসড়ায় ফেরে।
     */
    public function test_two_levels_signed_by_one_person_through_the_inbox_do_not_count(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->manager, $this->head], 3 => [$this->finance]]);
        $this->submit();

        $engine = app(ApprovalEngine::class);
        $engine->approve($this->request(), $this->manager);
        $engine->approve($this->request(), $this->manager);

        $this->signAs($this->finance);

        $this->assertSame(PromotionStatus::DRAFT, $this->offerStatus(), 'একজনের দুই সইয়ে অফার অনুমোদিত হয়ে গেছে।');
        $this->assertSame(
            __('promotion::approval.signed_twice', ['name' => $this->manager->name]),
            $this->chain()->lastReason($this->offer->fresh()),
        );
    }

    /**
     * ⛔ যিনি বানালেন তিনি কোনো স্তরে সই দেন না — ছকে নাম থাকলেও।
     *
     * ⚠️ বিপজ্জনক ইনপুট: মালিক নিজে স্তর ১-এ ছকে আছেন। ⓘ পাল্টা-দাবি:
     * একই স্তরের ব্যবস্থাপক সই দিতে পারেন।
     */
    public function test_the_creator_cannot_sign_even_when_the_flow_names_them(): void
    {
        $this->flow([1 => [$this->owner, $this->manager]]);
        $this->submit();

        $said = $this->refusal(fn () => $this->signAs($this->owner));
        $this->assertSame(__('promotion::approval.cannot_sign_own'), $said);
        $this->assertSame(0, ApprovalDecision::query()->where('approval_id', $this->request()->id)->count());
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus());

        $this->signAs($this->manager);
        $this->assertSame(PromotionStatus::APPROVED, $this->offerStatus());
    }

    /**
     * ⭐ ফেরত পাঠালে অফার খসড়ায়, আর কারণটা থাকে।
     *
     * ⓘ আবার জমা দিলে নতুন অনুরোধ স্তর ১ থেকে — ⚠️ পুরনো সই বয়ে আনা
     * হয় না, কারণ অফারটা মাঝে বদলে থাকতে পারে।
     */
    public function test_sending_back_returns_the_offer_to_draft_and_keeps_the_reason(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->head], 3 => [$this->finance]]);
        $this->submit();
        $this->signAs($this->manager);

        $said = $this->refusal(fn () => $this->chain()->sendBack($this->offer->fresh(), $this->head, '   '));
        $this->assertSame(__('promotion::approval.reason_required'), $said);
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus());

        $this->actingAs($this->head);
        $this->chain()->sendBack($this->offer->fresh(), $this->head, 'Ceiling too high for a first run');

        $this->assertSame(PromotionStatus::DRAFT, $this->offerStatus());
        $this->assertSame('Ceiling too high for a first run', $this->chain()->lastReason($this->offer->fresh()));
        $this->assertSame(Approval::REJECTED, $this->request()->status);

        $first = $this->request()->id;
        $this->submit();

        $this->assertNotSame($first, $this->request()->id, 'আবার জমায় নতুন অনুরোধ খোলেনি।');
        $this->assertSame(1, (int) $this->request()->current_level);
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus(), 'পুরনো "না" নতুন জমাকেও ফেরত পাঠিয়েছে।');
    }

    /** ⭐ ইনবক্স থেকে ফেরত পাঠালেও একই ফল — পাতা খুললেই মেলে। */
    public function test_a_send_back_from_the_inbox_reaches_the_offer(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->head]]);
        $this->submit();

        app(ApprovalEngine::class)->reject($this->request(), $this->manager, 'Wrong products');
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus());

        $this->chain()->settle($this->offer->fresh());

        $this->assertSame(PromotionStatus::DRAFT, $this->offerStatus());
        $this->assertSame('Wrong products', $this->chain()->lastReason($this->offer->fresh()));
    }

    /** ⭐ সব স্তরের পরে — এবং কেবল তখনই — অনুমোদিত। */
    public function test_after_every_level_the_offer_is_approved(): void
    {
        $this->flow([1 => [$this->manager], 2 => [$this->head], 3 => [$this->finance]]);
        $this->submit();

        $this->signAs($this->manager);
        $this->signAs($this->head);
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offerStatus());

        $this->signAs($this->finance);

        $offer = $this->offer->fresh();
        $this->assertSame(PromotionStatus::APPROVED, $offer->status);
        $this->assertSame($this->finance->id, (int) $offer->approved_by, 'শেষ সইকারীর নাম বসেনি।');
        $this->assertNotNull($offer->approved_at);
        $this->assertSame(Approval::APPROVED, $this->request()->status);

        $path = $this->chain()->progress($offer);
        $this->assertSame([1, 2, 3], array_column($path, 'level'));
        $this->assertSame(['approved', 'approved', 'approved'], array_column($path, 'decision'));
    }

    /**
     * ⭐ এক স্তরের ছক = আজকের একক অনুমোদন।
     *
     * ⓘ ছক একেবারে না থাকলেও একই: এক সই, আর বানানেওয়ালা বাদ।
     */
    public function test_a_one_level_flow_behaves_like_the_single_approval_of_today(): void
    {
        $this->flow([1 => [$this->manager]]);
        $this->submit();

        $this->signAs($this->manager);

        $offer = $this->offer->fresh();
        $this->assertSame(PromotionStatus::APPROVED, $offer->status);
        $this->assertSame($this->manager->id, (int) $offer->approved_by);
    }

    public function test_with_no_flow_at_all_one_signature_still_approves_and_the_creator_still_cannot(): void
    {
        $this->submit();
        $this->assertSame(0, Approval::query()->where('approvable_id', $this->offer->id)
            ->where('approvable_type', Promotion::class)->count(), 'ছক ছাড়াই অনুরোধ খোলা হয়েছে।');

        $said = $this->refusal(fn () => $this->signAs($this->owner));
        $this->assertSame(__('promotion::validation.cannot_approve_own'), $said);

        $this->signAs($this->manager);

        $offer = $this->offer->fresh();
        $this->assertSame(PromotionStatus::APPROVED, $offer->status);
        $this->assertSame($this->manager->id, (int) $offer->approved_by);
    }
}
