<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন পর্দা ভাগ ২ — মূল নকশা (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ২, SAP Fiori "My Inbox")।
 *
 * ⛔ আগে: ১১ কলামের টেবিল, ৫০-এ কাটা আর মোট নেই; কাগজ খুলতে আলাদা পাতা, সিদ্ধান্তের পরে আবার তালিকায় ফিরে পরেরটা খুঁজতে হত;
 * মেনুতে ১৩টা সারি; শেষ সময় কেবল মাউস রাখলে; ধাপ "স্তর 1"; যাত্রাপথে মন্তব্য আর "কার হয়ে" ছিল না; চারটা কাজ ট্যাবে;
 * ভাউচারের তালিকা থেকে একসাথে পাঠানোর পথ ছিল না; আর দায়িত্ব অর্পণে পাওয়া কাগজ ইনবক্সে আসতই না।
 *
 * দাবি — প্রতিটা নামের সাথে দফার নম্বর:
 *   দফা ১  — তালিকার প্রথম কাগজ পাশে খোলা; `?open=` বাছাইটা খোলে; অনুমোদন দিলে ইনবক্সে ফেরে পরের কাগজ খোলা, ছাঁকনি অক্ষত;
 *   দফা ২  — চার ট্যাব নিজের নিজের কাগজ গোনে; দায়িত্ব অর্পণের কাগজ "অন্যের হয়ে" আর অপেক্ষায় আসে, আর তিনি সেটায় অনুমোদন দিতে
 *            পারেন (তালিকা আর দরজা এক কথা বলে); মেনুতে পাঁচ সারি;
 *   দফা ৩  — শাখা, অঙ্ক আর সাজানো তালিকা বদলায়;
 *   দফা ৬  — পাতায় ২৫টা, "এই পাতার মোট" আর "সবগুলোর মোট" আলাদা ও সঠিক, পরের পাতায় বাকিগুলো;
 *   দফা ৭/৮ — কার্ডে "শেষ সময় পেরিয়েছে 3 ঘণ্টা" আর "ধাপ 1 / 2";
 *   দফা ১০ — যাত্রাপথে কে, কার হয়ে, আর মন্তব্য;
 *   দফা ১১ — চারটা কাজ পাশাপাশি, প্রত্যাখ্যান আর ফেরত পপ-আপে নিজের জিজ্ঞাসাসহ;
 *   দফা ১৪ — ভাউচারের তালিকা থেকে আগে দেখা, তারপর একসাথে অনুমোদনে পাঠানো; পাকা ভাউচার বাদ, কারণসহ।
 */
final class TheInboxIsOnePageWithTheListAndThePaperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app()->setLocale('bn');

        $this->first = $this->signer('প্রথম অনুমোদনকারী');
        $this->second = $this->signer('দ্বিতীয় অনুমোদনকারী');

        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'journal')->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->first->id]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => 'user', 'approver_id' => $this->second->id]);
        ApprovalEngine::flowsChanged();
    }

    public function test_dofa1_the_first_paper_opens_beside_the_list_and_the_next_opens_after_approving(): void
    {
        $a = $this->ask('500', now()->subHours(2));
        $b = $this->ask('700', now()->subHour());

        $page = $this->actingAs($this->first)->get(route('approval.inbox.index'))->assertOk();
        $this->assertSame($a->id, $page->viewData('opened')?->id, '⛔ দফা ১: ইনবক্স খুললে তালিকার প্রথম কাগজ পাশে খোলে না।');
        $page->assertSee('data-approval-detail="'.$a->id.'"', false);
        $this->assertSame($b->id, $page->viewData('nextId'), '⛔ দফা ১: অনুমোদনের পরে কোনটা খুলবে তা ভুল।');

        $this->actingAs($this->first)->get(route('approval.inbox.index', ['open' => $b->id]))->assertOk()
            ->assertSee('data-approval-detail="'.$b->id.'"', false);

        $this->actingAs($this->first)
            ->post(route('approval.inbox.approve', $a->id), ['next' => $b->id, 'from' => 'sort=amount&evil=https://x.test'])
            ->assertRedirect(route('approval.inbox.index', ['sort' => 'amount', 'open' => $b->id]));

        $this->assertSame(2, (int) $a->fresh()->current_level, 'প্রস্তুতিটাই ভুল — প্রথম ধাপের অনুমোদন হয়নি।');
    }

    public function test_dofa2_four_tabs_count_their_own_papers_and_a_stand_in_sees_theirs(): void
    {
        $a = $this->ask('500', now()->subHours(2));
        $b = $this->ask('700', now()->subHour());

        app(ApprovalEngine::class)->approve($a->fresh(), $this->first, 'ঠিক আছে');

        $counts = $this->actingAs($this->first)->get(route('approval.inbox.index'))->assertOk()->viewData('tabCounts');
        $this->assertSame(['waiting' => 1, 'sent' => 0, 'done' => 1, 'behalf' => 0], $counts, '⛔ দফা ২: প্রথম অনুমোদনকারীর ট্যাবের সংখ্যা ভুল।');

        $mine = $this->actingAs($this->owner)->get(route('approval.inbox.index', ['tab' => 'sent']))->assertOk();
        $this->assertSame(2, $mine->viewData('tabCounts')['sent'], '⛔ দফা ২: "আমার পাঠানো" নিজের খোলা অনুরোধ গোনে না।');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $mine->viewData('approvals')->pluck('id')->all());

        $deputy = $this->signer('ভারপ্রাপ্ত');
        app(DelegationService::class)->grant($this->first, $deputy, now()->toDateString(), now()->addDay()->toDateString(), reason: 'ছুটি');

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($b->fresh(), $deputy), 'প্রস্তুতিটাই ভুল — ভারপ্রাপ্ত মানুষ অনুমোদন দিতে পারেন না।');

        $page = $this->actingAs($deputy)->get(route('approval.inbox.index', ['tab' => 'behalf']))->assertOk();
        $this->assertSame([$b->id], $page->viewData('approvals')->pluck('id')->all(),
            '⛔ দফা ২: দায়িত্ব অর্পণে পাওয়া কাগজ "অন্যের হয়ে" ট্যাবে নেই — দরজা হ্যাঁ বলে, তালিকা দেখায় না।');
        $this->assertSame(1, $page->viewData('tabCounts')['waiting'], '⛔ দফা ২: দায়িত্ব অর্পণে পাওয়া কাগজ অপেক্ষার তালিকায় নেই।');
        $page->assertSee(__('approval::message.on_behalf_of', ['name' => $this->first->name]));
    }

    public function test_dofa2_the_menu_has_five_rows_besides_the_dashboard(): void
    {
        $module = collect(app(ModuleRegistry::class)->all())->firstWhere('code', 'approval');
        $rows = collect($module->menu)->except('dashboard')->flatten(1)->pluck('label')->all();

        $this->assertSame([
            'approval::menu.inbox', 'approval::menu.mine', 'approval::menu.flows', 'approval::menu.delegation', 'approval::menu.reports',
        ], $rows, '⛔ দফা ২: মেনু পাঁচ সারির নয়, বা ক্রম পরিকল্পনার নয়।');

        $this->actingAs($this->owner)->get(route('approval.limit.index'))->assertOk()
            ->assertSee('data-tab="exceptions"', false);
        $this->actingAs($this->owner)->get(route('approval.report.show', 'pending'))->assertOk()
            ->assertSee('data-tab="why-rejected"', false);
    }

    public function test_dofa3_branch_amount_and_sort_narrow_the_list(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'P2B', 'name_en' => 'Second branch', 'name_bn' => 'দ্বিতীয় শাখা', 'is_active' => true,
        ]);

        $a = $this->ask('500', now()->subHours(3));
        $b = $this->ask('700', now()->subHours(2));
        $c = $this->ask('50000', now()->subHour(), $other->id);

        $ids = fn (array $query) => $this->actingAs($this->first)->get(route('approval.inbox.index', $query))->assertOk()
            ->viewData('approvals')->pluck('id')->all();

        $this->assertSame([$c->id], $ids(['branch' => $other->id]), '⛔ দফা ৩: শাখার ছাঁকনি কাগজের শাখা মানে না।');
        $this->assertSame([$c->id], $ids(['amount' => '10k_1l']), '⛔ দফা ৩: অঙ্কের ছাঁকনি কাজ করে না।');
        $this->assertSame([$a->id, $b->id], $ids(['amount' => 'below_10k']), '⛔ দফা ৩: ১০ হাজারের কম ধাপে ভুল কাগজ।');
        $this->assertSame([$c->id, $b->id, $a->id], $ids(['sort' => 'amount']), '⛔ দফা ৩: অঙ্ক ধরে সাজানো বড় আগে নয়।');
        $this->assertSame([$c->id, $b->id, $a->id], $ids(['sort' => 'new']), '⛔ দফা ৩: নতুন আগে সাজানো নয়।');
        $this->assertSame([$a->id, $b->id, $c->id], $ids([]), '⛔ দফা ৩: ডিফল্টে (দেরি আগে, তারপর পুরনো আগে) ক্রম ভুল।');
    }

    public function test_dofa6_a_page_holds_twenty_five_and_both_totals_are_right(): void
    {
        foreach (range(1, 27) as $i) {
            $this->ask('100', now()->subMinutes(100 - $i));
        }

        $page = $this->actingAs($this->first)->get(route('approval.inbox.index'))->assertOk();
        $this->assertCount(25, $page->viewData('approvals')->getCollection(), '⛔ দফা ৬: পাতায় ২৫টা নয়।');
        $this->assertSame(27, $page->viewData('visibleTotal'));
        $this->assertSame(0, bccomp('2500', $page->viewData('sumPage'), 2), '⛔ দফা ৬: এই পাতার মোট ভুল।');
        $this->assertSame(0, bccomp('2700', $page->viewData('sumAll'), 2), '⛔ দফা ৬: সবগুলোর মোট ভুল।');
        $page->assertSee(__('approval::field.all_total', ['count' => 27]));
        $page->assertSee('৳2,700.00', false);

        $two = $this->actingAs($this->first)->get(route('approval.inbox.index', ['page' => 2]))->assertOk();
        $this->assertCount(2, $two->viewData('approvals')->getCollection(), '⛔ দফা ৬: বাকি দুইটা পরের পাতায় নেই।');
    }

    public function test_dofa7_and_8_the_card_says_when_it_was_due_and_which_step(): void
    {
        $a = $this->ask('500', now()->subHours(5));
        $a->forceFill(['due_at' => now()->subHours(3)->subMinutes(10)])->save();

        $this->actingAs($this->first)->get(route('approval.inbox.index'))->assertOk()
            ->assertSee(__('approval::message.due_passed_hours', ['hours' => 3]))
            ->assertSee(__('approval::field.step_at', ['at' => 1, 'of' => 2]));
    }

    public function test_dofa10_the_journey_says_who_for_whom_and_what(): void
    {
        $a = $this->ask('500', now()->subHour());
        $deputy = $this->signer('ভারপ্রাপ্ত');
        app(DelegationService::class)->grant($this->first, $deputy, now()->toDateString(), now()->addDay()->toDateString(), reason: 'ছুটি');

        app(ApprovalEngine::class)->approve($a->fresh(), $deputy, 'বিল মিলেছে');

        $this->actingAs($this->second)->get(route('approval.inbox.index'))->assertOk()
            ->assertSee($deputy->name)
            ->assertSee(__('approval::message.on_behalf_of', ['name' => $this->first->name]))
            ->assertSee('“বিল মিলেছে”', false);
    }

    public function test_dofa11_four_actions_side_by_side_and_the_hard_ones_ask_in_a_popup(): void
    {
        $this->ask('500', now()->subHour());

        $page = $this->actingAs($this->first)->get(route('approval.inbox.index'))->assertOk();

        foreach (['data-ask="reject"', 'data-ask="fix"', 'data-key="a"'] as $mark) {
            $page->assertSee($mark, false);
        }

        $page->assertSee('data-asks-in-dialog="'.e(__('approval::message.confirm_reject')).'"', false);
        $page->assertSee('data-asks-in-dialog="'.e(__('approval::message.confirm_send_back')).'"', false);
        $page->assertSee(__('approval::message.reject_final'));
    }

    public function test_dofa14_drafts_go_together_after_a_look_and_a_posted_one_is_left_out(): void
    {
        $one = $this->draft('400');
        $two = $this->draft('600');
        $posted = $this->draft('900');
        $posted->forceFill(['status' => \App\Core\Support\DocumentStatus::CONFIRMED])->save();

        $look = $this->actingAs($this->owner)
            ->get(route('accounts.voucher.bulk_review', ['ids' => [$one->id, $two->id, $posted->id]]))->assertOk();
        $this->assertEqualsCanonicalizing([$one->id, $two->id], collect($look->viewData('ask'))->pluck('id')->all(),
            '⛔ দফা ১৪: অনুমোদন লাগে এমন খসড়া "অনুমোদনে যাবে" দলে নেই।');
        $this->assertSame('not_draft', $look->viewData('skip')[0]['why'] ?? null, '⛔ দফা ১৪: পাকা ভাউচার কারণসহ বাদ পড়েনি।');
        $this->assertSame(0, Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', [$one->id, $two->id])->count(),
            '⛔ দফা ১৪: আগে দেখার পাতা নিজেই অনুমোদন চেয়ে বসেছে।');

        $this->actingAs($this->owner)
            ->post(route('accounts.voucher.bulk_post'), ['ids' => [$one->id, $two->id, $posted->id]])
            ->assertRedirect(route('accounts.voucher.list'))
            ->assertSessionHas('saved', __('accounts::message.bulk_post_done', ['posted' => 0, 'asked' => 2]).' · '
                .__('accounts::message.bulk_post_left_out', ['count' => 1]));

        $this->assertSame(2, Approval::query()->pending()->where('approvable_type', Voucher::class)->whereIn('approvable_id', [$one->id, $two->id])->count(),
            '⛔ দফা ১৪: একসাথে পাঠানো খসড়া অনুমোদনে যায়নি।');
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

    private function draft(string $amount, ?int $branch = null): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ভাগ ২-এর পরীক্ষা', 'branch_id' => $branch ?? CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        );
    }

    private function ask(string $amount, \Illuminate\Support\Carbon $at, ?int $branch = null): Approval
    {
        $this->actingAs($this->owner);
        $asked = app(VoucherWriter::class)->post($this->draft($amount, $branch));
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুমোদন চাওয়া হয়নি।');
        $asked->forceFill(['requested_at' => $at])->save();

        return $asked->fresh();
    }
}
