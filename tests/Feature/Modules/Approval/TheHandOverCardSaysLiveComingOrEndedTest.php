<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন পর্দা ভাগ ৩, দফা ১৮ — দায়িত্ব হস্তান্তর (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ৫)।
 *
 * ⛔ আগে: কার্ডে কেবল একটা "●" — আসছে আর শেষের কোনো লেখা নেই, আর শেষ দিনে চালু ভারটাও "চালু" দেখাত না (`isFuture()` শেষ
 * দিনের মাঝরাতে মিথ্যা); কার হয়ে কয়টা সিদ্ধান্ত হলো জানার পথ ছিল না; আসছে ভার বদলাতে মুছে নতুন দিতে হত; "আমাকে দেওয়া" কেবল
 * আজ চালুটা দেখাত, কাল থেকে কার কাগজ আসবে তা নয়।
 *
 * দাবি:
 *   ১৮ক — অবস্থা: শুরুর আগে আসছে, শুরু থেকে শেষ দিন পর্যন্ত চালু (শেষ দিনও), তারপর বা থামালে শেষ;
 *   ১৮খ — পাতা: চালু → আসছে → শেষ ক্রমে, প্রতিটার লেখা; চালুতে "থামান" (জিজ্ঞাসাসহ), আসছেতে "বদলান", শেষে কিছুই না;
 *   ১৮গ — "এ পর্যন্ত আমার হয়ে": ইঞ্জিনে অন্যের হয়ে দেওয়া অনুমোদন গোনে; মেয়াদের বাইরের, অন্য মানুষের, হাত-বদলের আর থামানোর পরের নয়;
 *   ১৮ঘ — বদলান: আসছেটা বদলায়; চালুটা ফেরায় নিজের বার্তায়; অন্যের ভার 403;
 *   ১৮ঙ — "আমাকে দেওয়া": চালু আর আসছে, শেষ বা থামানো নয়।
 */
final class TheHandOverCardSaysLiveComingOrEndedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $deputy;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app()->setLocale('bn');

        $this->deputy = $this->signer('ভারপ্রাপ্ত মানুষ');
        $this->other = $this->signer('অন্য মানুষ');
    }

    public function test_dofa18a_the_state_follows_the_dates(): void
    {
        $row = $this->give($this->deputy, '2026-11-01', '2026-11-05');

        $this->assertSame(ApprovalDelegation::COMING, $row->stateOn('2026-10-31'), '⛔ দফা ১৮: শুরুর আগের দিনে ভারটা "আসছে" নয়।');
        $this->assertSame(ApprovalDelegation::LIVE, $row->stateOn('2026-11-01'), '⛔ দফা ১৮: শুরুর দিনে ভারটা "চালু" নয়।');
        $this->assertSame(ApprovalDelegation::LIVE, $row->stateOn('2026-11-05'), '⛔ দফা ১৮: শেষ দিনে চালু ভারটা "চালু" দেখায় না।');
        $this->assertSame(ApprovalDelegation::ENDED, $row->stateOn('2026-11-06'), '⛔ দফা ১৮: মেয়াদের পরে ভারটা "শেষ" নয়।');

        $row->forceFill(['revoked_at' => now()])->save();
        $this->assertSame(ApprovalDelegation::ENDED, $row->fresh()->stateOn('2026-11-03'), '⛔ দফা ১৮: থামানো ভারটা "শেষ" নয়।');
    }

    public function test_dofa18b_the_page_orders_the_cards_and_offers_the_right_button(): void
    {
        $ended = $this->give($this->other, now()->subDays(20)->toDateString(), now()->subDays(18)->toDateString());
        $coming = $this->give($this->other, now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString());
        $live = $this->give($this->deputy, now()->toDateString(), now()->addDays(2)->toDateString());

        $html = (string) $this->get(route('approval.delegation.index'))->assertOk()->getContent();

        $at = fn (ApprovalDelegation $row) => strpos($html, 'data-delegation="'.$row->id.'"');
        $this->assertNotFalse($at($live), 'প্রস্তুতিটাই ভুল — চালুর কার্ড নেই।');
        $this->assertTrue($at($live) < $at($coming) && $at($coming) < $at($ended), '⛔ দফা ১৮: কার্ডের ক্রম চালু → আসছে → শেষ নয়।');

        $card = fn (ApprovalDelegation $row) => substr($html, $at($row), 6000);
        $stop = route('approval.delegation.destroy', $live);
        $this->assertStringContainsString(__('approval::status.delegation_live'), $card($live), '⛔ দফা ১৮: চালুর কার্ডে "চালু" লেখা নেই।');
        $this->assertStringContainsString('action="'.$stop.'"', $card($live), '⛔ দফা ১৮: চালু কার্ডে "থামান" নেই।');
        $this->assertStringContainsString(e(__('approval::message.confirm_delegation_stop')), $card($live), '⛔ দফা ১৮: "থামান" জিজ্ঞাসা করে না।');

        $this->assertStringContainsString(__('approval::status.delegation_coming'), $card($coming), '⛔ দফা ১৮: আসছের কার্ডে "আসছে" লেখা নেই।');
        $this->assertStringContainsString('change='.$coming->id, $card($coming), '⛔ দফা ১৮: আসছের কার্ডে "বদলান" নেই।');
        // ⓘ নকশায় আসছের কার্ডে কেবল "বদলান" — বাতিল বদলানোর ফর্মে, জিজ্ঞাসাসহ
        $comingCard = substr($html, $at($coming), $at($ended) - $at($coming));
        $this->assertStringNotContainsString(route('approval.delegation.destroy', $coming), $comingCard, '⛔ দফা ১৮: আসছের কার্ডে নকশার বাইরের "থামান"।');
        $form = (string) $this->get(route('approval.delegation.index', ['change' => $coming->id]))->assertOk()->getContent();
        $cancel = substr($form, (int) strpos($form, 'id="delegation-cancel"'), 600);
        $this->assertStringContainsString('action="'.route('approval.delegation.destroy', $coming).'"', $cancel, '⛔ দফা ১৮: বদলানোর ফর্মে "এই দায়িত্ব বাতিল করুন" নেই।');
        $this->assertStringContainsString(e(__('approval::message.confirm_delegation_stop')), $cancel, '⛔ দফা ১৮: আসছের বাতিল জিজ্ঞাসা করে না।');
        $this->assertStringContainsString('form="delegation-cancel"', $form, '⛔ দফা ১৮: বাতিলের বোতাম ফর্মে বাঁধা নেই।');

        $endedCard = substr($html, $at($ended), 3000);
        $this->assertStringContainsString(__('approval::status.delegation_ended'), $endedCard, '⛔ দফা ১৮: শেষের কার্ডে "শেষ" লেখা নেই।');
        $this->assertStringNotContainsString(route('approval.delegation.destroy', $ended), $endedCard, '⛔ দফা ১৮: শেষ ভারেও "থামান" আছে।');
        $this->assertStringNotContainsString('change='.$ended->id, $endedCard, '⛔ দফা ১৮: শেষ ভারেও "বদলান" আছে।');
    }

    public function test_dofa18c_the_card_counts_what_was_decided_on_my_behalf(): void
    {
        // ⓘ ডেমো বীজে ছাড়ের ছক আগেই আছে (একই কোম্পানি-কাজে দ্বিতীয়টা বসে না) — সেটাই নিয়ে ধাপ নতুন করে
        $flow = ApprovalFlow::query()->firstOrCreate(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount', 'document_type' => '']);
        $flow->forceFill(['is_active' => true, 'threshold_amount' => null])->save();
        $flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
        ApprovalEngine::flowsChanged();
        $row = $this->give($this->deputy, now()->toDateString(), now()->addDays(3)->toDateString());
        // ⓘ আগের একটা ভার "অন্য মানুষ"-কে — তাই একটাই কোয়েরির নিচের সীমা আর মানুষের তালিকা চওড়া; বাছাই হয় কার্ড ধরে
        $older = $this->give($this->other, now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());

        $engine = app(ApprovalEngine::class);
        $paper = $engine->request($this->company->defaultBranch(), 'sales', 'discount', '5000', null, 'দফা ১৮', $this->other->id);
        $engine->approve($paper->fresh(), $this->deputy);
        $this->assertSame((int) $this->owner->id, (int) ApprovalDecision::query()->where('approval_id', $paper->id)->value('on_behalf_of'),
            'প্রস্তুতিটাই ভুল — ভারপ্রাপ্তের অনুমোদন মালিকের হয়ে বসেনি।');

        $noise = $engine->request($this->company->defaultBranch(), 'sales', 'discount', '7000', null, 'দফা ১৮ গোলমাল', $this->other->id);
        $decide = fn (array $row) => ApprovalDecision::query()->forceCreate($row + ['approval_id' => $noise->id, 'level' => 1, 'decision' => ApprovalDecision::APPROVED]);
        $decide(['user_id' => $this->deputy->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now()->subDays(2)]);   // মেয়াদের আগে
        $decide(['user_id' => $this->other->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now()]);                // অন্য মানুষ
        $decide(['user_id' => $this->deputy->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now(), 'decision' => ApprovalDecision::FORWARDED]);
        // ⓘ পরিকল্পনা "আমার হয়ে অনুমোদন" — ফেরত আর প্রত্যাখ্যান অনুমোদন নয় (মালিক, ২৯ অক্টোবর: "plan onuzayi 100%")
        $decide(['user_id' => $this->deputy->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now(), 'decision' => ApprovalDecision::RETURNED]);
        $decide(['user_id' => $this->deputy->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now(), 'decision' => ApprovalDecision::REJECTED]);

        $page = $this->get(route('approval.delegation.index'))->assertOk();
        $this->assertCount(1, $page->viewData('actedFor')[$row->id] ?? [], '⛔ দফা ১৮: "এ পর্যন্ত আমার হয়ে অনুমোদন" ভুল গোনে — মেয়াদের বাইরের, অন্য মানুষের, হাত-বদল, ফেরত বা প্রত্যাখ্যানও গোনা হচ্ছে।');
        $this->assertCount(0, $page->viewData('actedFor')[$older->id] ?? [], '⛔ দফা ১৮: শেষ হওয়া ভারে তার মেয়াদের পরের সিদ্ধান্ত গোনা হচ্ছে।');
        $page->assertSee(__('approval::message.delegation_acted', ['count' => 1]));
        $page->assertSee(route('approval.inbox.show', $paper->id), false);

        app(DelegationService::class)->revoke($row);
        $decide(['user_id' => $this->deputy->id, 'on_behalf_of' => $this->owner->id, 'decided_at' => now()->addMinute()]);   // থামানোর পরে
        $after = $this->get(route('approval.delegation.index'));
        $this->assertCount(1, $after->viewData('actedFor')[$row->id] ?? [],
            '⛔ দফা ১৮: থামানোর পরের সিদ্ধান্তও এই ভারে গোনা হচ্ছে।');

        // ⓘ শেষ কার্ডে নকশার মতো একই লাইনে "অনুমোদন: ১টা"
        $html = (string) $after->getContent();
        $endedCard = substr($html, (int) strpos($html, 'data-delegation="'.$row->id.'"'), 4000);
        $this->assertStringContainsString(__('approval::message.delegation_acted_short', ['count' => 1]), $endedCard,
            '⛔ দফা ১৮: শেষ কার্ডে "অনুমোদন: কয়টা" নেই।');
    }

    public function test_dofa18d_only_a_coming_hand_over_of_mine_changes(): void
    {
        $coming = $this->give($this->deputy, now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString());
        $form = ['to_user_id' => $this->other->id, 'starts_on' => now()->addDays(4)->toDateString(), 'ends_on' => now()->addDays(9)->toDateString(),
            'modules' => ['sales'], 'reason' => 'সফর বদলেছে'];

        $this->get(route('approval.delegation.index', ['change' => $coming->id]))->assertOk()
            ->assertSee('action="'.route('approval.delegation.update', $coming).'"', false);
        $this->put(route('approval.delegation.update', $coming), $form)->assertSessionHasNoErrors();
        $fresh = $coming->fresh();
        $this->assertSame([(int) $this->other->id, now()->addDays(9)->toDateString(), ['sales'], 'সফর বদলেছে'],
            [(int) $fresh->to_user_id, $fresh->ends_on->toDateString(), $fresh->modules, $fresh->reason], '⛔ দফা ১৮: "বদলান" আসছে ভারটা বদলায়নি।');

        $live = $this->give($this->deputy, now()->toDateString(), now()->addDays(2)->toDateString());
        $refused = $this->from(route('approval.delegation.index'))->put(route('approval.delegation.update', $live), $form);
        $this->assertSame((int) $this->deputy->id, (int) $live->fresh()->to_user_id, '⛔ দফা ১৮: চালু ভার বদলে গেছে — যা হয়ে গেছে তার মানুষ পিছন থেকে বদলাত।');
        $refused->assertSessionHasErrors(['delegation' => __('approval::validation.delegation_not_coming')]);

        $theirs = ApprovalDelegation::query()->create(['company_id' => $this->company->id, 'from_user_id' => $this->other->id, 'to_user_id' => $this->deputy->id,
            'starts_on' => now()->addDays(3)->toDateString(), 'ends_on' => now()->addDays(4)->toDateString(), 'modules' => [], 'actions' => []]);
        // ⓘ ভারটা অন্যের নিজের — তাঁকেই আবার দিলে "নিজেকে নয়" নিয়মেই আটকাত; তাই মালিককে, যা দরজা না থাকলে বসে যেত
        $foreign = $this->put(route('approval.delegation.update', $theirs), ['to_user_id' => $this->owner->id] + $form);
        $this->assertSame((int) $this->deputy->id, (int) $theirs->fresh()->to_user_id, '⛔ দফা ১৮: অন্যের ভার বদলানো গেছে।');
        $foreign->assertForbidden();
    }

    public function test_dofa18e_handed_to_me_shows_live_and_coming_only(): void
    {
        $mk = fn (string $from, string $to, bool $stopped = false) => ApprovalDelegation::query()->create(['company_id' => $this->company->id,
            'from_user_id' => $this->other->id, 'to_user_id' => $this->owner->id, 'starts_on' => $from, 'ends_on' => $to,
            'modules' => [], 'actions' => [], 'revoked_at' => $stopped ? now() : null]);

        $live = $mk(now()->toDateString(), now()->addDay()->toDateString());
        $coming = $mk(now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString());
        $ended = $mk(now()->subDays(5)->toDateString(), now()->subDays(4)->toDateString());
        $stopped = $mk(now()->toDateString(), now()->addDay()->toDateString(), true);

        $page = $this->get(route('approval.delegation.index'))->assertOk();
        $ids = $page->viewData('held')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([(int) $live->id, (int) $coming->id], $ids, '⛔ দফা ১৮: "আমাকে দেওয়া"-তে চালু আর আসছে নেই, বা শেষ/থামানোটা আছে।');
        $page->assertSee(__('approval::message.delegation_held_where'));
        $this->assertNotContains((int) $ended->id, $ids);
        $this->assertNotContains((int) $stopped->id, $ids);
    }

    private function give(User $to, string $from, string $until): ApprovalDelegation
    {
        return ApprovalDelegation::query()->create(['company_id' => $this->company->id, 'from_user_id' => $this->owner->id, 'to_user_id' => $to->id,
            'starts_on' => $from, 'ends_on' => $until, 'modules' => [], 'actions' => [], 'created_by' => $this->owner->id]);
    }

    private function signer(string $name): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'bn', 'name' => $name]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
