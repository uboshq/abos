<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\AllBranchesView;
use App\Core\Services\DataScope;
use App\Core\Services\FormBranchPin;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ "সব শাখা" = কেবল দেখা, লেখা নয় — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[AllBranchesView]])।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * "সব শাখা" বাছা অবস্থায় আদায় লেখা হলো — বসল লুকানো কাজের শাখায় (Super-এর বদলে TD), তারপর আবার লেখা হলো।
 *
 * ── ⭐ যা মাপা হয় (সব আসল দরজায়) ───────────────────────────────────
 *  · একাধিক শাখার মানুষ "সব শাখা"-তে নতুন কাগজ, বদল, বাতিল — কিছুই লেখে না; ফর্মে ফেরত, ইনপুটসহ, বার্তায় "শাখা বাছুন";
 *    JSON হলে ৪০৯; `_once`-এর দাবি ফেরত (শাখা বেছে একই ফর্ম আবার জমা চলে)।
 *  · একটা শাখা বাছলে সেই একই জমা চলে।
 *  · অনুমোদনের সিদ্ধান্ত "সব শাখা"-তেও চলে।
 *  · যাঁর নাগালে একটাই শাখা, তিনি থামেন না।
 *  · দেখার পাতা খোলে; চিহ্ন আর ফিতা কেবল "সব শাখা"-তে; একটা শাখা বাছা থাকলে ফর্মের মাথায় "X শাখায় লিখছেন"।
 */
final class AllBranchesViewIsReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $opened;

    private Branch $other;

    private User $clerk;

    private Account $debit;

    private Account $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->opened = $this->branch('MMS');
        $this->other = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->opened->id);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        // ⓘ হিসাবরক্ষকের নাগাল দুই শাখায় — "সব শাখা" তাঁর কাছে সত্যিই একাধিক
        $this->clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        foreach ([$this->opened, $this->other] as $branch) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->clerk->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id]);
        }

        [$this->debit, $this->credit] = Account::query()->postable()->active()->whereNull('money_kind')
            ->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        // ⛔ TestCase নিয়মটা বন্ধ রাখে (পুরনো হাজার পরীক্ষার জন্য) — এই দাবি নিয়মটাই মাপে, তাই চালু
        config(['abos.branch_pin.all_branches_read_only' => true]);
    }

    public function test_a_new_paper_is_refused_in_the_all_branches_view_and_goes_through_with_one_branch_picked(): void
    {
        $this->as($this->clerk, 'all');
        $this->assertTrue(app(AllBranchesView::class)->on($this->clerk->fresh()), 'প্রস্তুতি — "সব শাখা" চালু হওয়ার কথা');

        $once = 'all-branches-'.uniqid();
        $this->from($this->voucherForm())
            ->post(route('accounts.voucher.store', 'journal'), [...$this->journal('সব শাখায় লেখা'), '_once' => $once])
            ->assertRedirect($this->voucherForm())
            ->assertSessionHasInput('narration', 'সব শাখায় লেখা');

        $this->assertSame(0, $this->vouchers('সব শাখায় লেখা')->count(), '⛔ "সব শাখা"-তে কাগজ লেখা হলো — লুকানো কাজের শাখায়।');
        $this->assertSame(__('core.all_branches.refused'), session(FormBranchPin::OFFER)['message'] ?? null, '⛔ "শাখা বাছুন" বার্তা নেই।');
        $this->assertSame(0, DB::table('submitted_forms')->where('token', $once)->count(), '⛔ থামানো জমার `_once` দাবি ফেরত যায়নি।');

        // ⓘ JSON/XHR — ৪০৯, একই বার্তা
        $this->postJson(route('accounts.voucher.store', 'journal'), $this->journal('সব শাখায় JSON'))
            ->assertStatus(409)
            ->assertJsonPath('message', __('core.all_branches.refused'));
        $this->assertSame(0, $this->vouchers('সব শাখায় JSON')->count());

        // ⭐ একটা শাখা বেছে একই ফর্ম, একই `_once` — এবার যায়, আর সেই শাখায়
        $this->as($this->clerk, $this->opened->id);
        $this->from($this->voucherForm())
            ->post(route('accounts.voucher.store', 'journal'), [...$this->journal('সব শাখায় লেখা'), '_once' => $once])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(FormBranchPin::OFFER);

        $voucher = $this->vouchers('সব শাখায় লেখা')->sole();
        $this->assertSame((int) $this->opened->id, (int) $voucher->branch_id, '⛔ বাছা শাখায় বসেনি।');
    }

    public function test_an_edit_and_a_cancel_are_refused_in_the_all_branches_view(): void
    {
        $this->as($this->clerk, $this->opened->id);
        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'journal'), $this->journal('বদলের আগে'))
            ->assertSessionHasNoErrors();
        $voucher = $this->vouchers('বদলের আগে')->sole();

        $this->as($this->clerk, 'all');
        $show = route('accounts.voucher.show', $voucher);

        $this->from($show)->put(route('accounts.voucher.update', $voucher), $this->journal('সব শাখায় বদল'))
            ->assertRedirect($show);
        $this->assertSame('বদলের আগে', Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->narration,
            '⛔ "সব শাখা"-তে কাগজ বদলানো গেল।');

        $this->from($show)->post(route('accounts.voucher.cancel', $voucher), ['cancel_reason' => 'সব শাখায় বাতিল'])
            ->assertRedirect($show);
        $this->assertFalse(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->isCancelled(),
            '⛔ "সব শাখা"-তে কাগজ বাতিল করা গেল।');
        $this->assertSame(__('core.all_branches.refused'), session(FormBranchPin::OFFER)['message'] ?? null);

        // ⭐ একটা শাখা বাছলে বদল চলে
        $this->as($this->clerk, $this->opened->id);
        $this->from($show)->put(route('accounts.voucher.update', $voucher), $this->journal('শাখা বেছে বদল'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(FormBranchPin::OFFER);
        $this->assertSame('শাখা বেছে বদল', Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->narration);
    }

    public function test_an_approval_decision_still_works_in_the_all_branches_view(): void
    {
        $signer = $this->person(['approval.view', 'approval.decide', 'accounts.voucher.view']);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        $this->actingAs($this->clerk->fresh());
        [$voucher] = app(VoucherWriter::class)->store(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের অপেক্ষায়', 'branch_id' => $this->opened->id],
            [['account_id' => $this->debit->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $this->credit->id, 'debit' => '0', 'credit' => '500']],
            asDraft: false,
        );
        $asked = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->sole();
        $this->assertSame(Approval::PENDING, $asked->status, 'প্রস্তুতি — সই চাওয়ার কথা');

        $this->as($signer, 'all');
        $this->assertTrue(app(AllBranchesView::class)->on($signer->fresh()), 'প্রস্তুতি — সইকারী "সব শাখা"-তে');

        $this->from(route('approval.inbox.show', $asked->id))
            ->post(route('approval.inbox.approve', $asked->id), ['remarks' => 'ঠিক আছে'])
            ->assertSessionMissing(FormBranchPin::OFFER);

        $this->assertSame(Approval::APPROVED, $asked->fresh()->status, '⛔ "সব শাখা"-তে অনুমোদনের সিদ্ধান্ত থামল — কাগজের শাখা তো বাঁধাই।');
    }

    public function test_a_single_branch_user_is_never_stopped(): void
    {
        // ⓘ নাগালে একটাই শাখা — তাঁর "সব শাখা" মানে সেই শাখাই
        UserDataScope::query()->where('user_id', $this->clerk->id)->where('scope_id', $this->other->id)->delete();
        $this->forgetScope();
        $this->clerk->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($this->clerk->fresh());

        $this->assertFalse(app(AllBranchesView::class)->on($this->clerk->fresh()), '⛔ এক শাখার মানুষকে "সব শাখা" ধরা হলো।');

        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'journal'), $this->journal('এক শাখার মানুষ'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(FormBranchPin::OFFER);
        $this->assertSame(1, $this->vouchers('এক শাখার মানুষ')->count(), '⛔ এক শাখার মানুষের লেখা থামল।');
    }

    public function test_pages_open_in_the_all_branches_view_and_the_marker_shows_only_there(): void
    {
        $this->as($this->clerk, 'all');

        // ⓘ ভাউচারের তালিকার ঠিকানায় ধরন লাগে (vouchers/{type}) — ফর্মের একই ধরন, জাবেদা (Mac run of e88be43a3: UrlGenerationException)
        foreach ([route('accounts.voucher.index', 'journal'), $this->voucherForm(), route('dashboard')] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-all-branches-view', $html, "⛔ \"সব শাখা\"-র চিহ্ন নেই: {$url}");
            // ⛔ ফিতা নয় — মালিকের নিয়ম, ১৮–১৯ নভেম্বর ২০২৬ (পাতায় কোনো ফিতা নয়); ধূসর বোতামের কারণ-লেখাই যথেষ্ট
            $this->assertStringNotContainsString('data-all-branches-banner', $html, "⛔ \"সব শাখা\"-তে পাতায় ফিতা: {$url}");
            $this->assertStringContainsString('data-all-branches-allow', $html, '⛔ খোলা দরজার ছাঁচ পাতায় যায়নি।');
            $this->assertStringNotContainsString('data-writing-branch', $html);
        }

        $this->as($this->clerk, $this->opened->id);

        $list = (string) $this->get(route('accounts.voucher.index', 'journal'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-all-branches-view', $list, '⛔ একটা শাখা বাছার পরেও "সব শাখা"-র চিহ্ন।');
        $this->assertStringNotContainsString('data-all-branches-banner', $list);
        $this->assertStringNotContainsString('data-writing-branch', $list, '⛔ তালিকায় "লিখছেন" লাইন — কেবল ফর্মে।');

        $form = (string) $this->get($this->voucherForm())->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<div[^>]*data-writing-branch[^>]*>(.*?)<\/div>/s', $form, $line), '⛔ ফর্মের মাথায় "X শাখায় লিখছেন" নেই।');
        $this->assertTrue(
            str_contains($line[1], e($this->opened->name('bn'))) || str_contains($line[1], e($this->opened->name('en'))),
            '⛔ লাইনে বাছা শাখার নাম নেই: '.$line[1],
        );
    }

    public function test_the_allow_list_names_only_routes_that_exist(): void
    {
        foreach (AllBranchesView::ALLOWED as $name) {
            $this->assertTrue(app('router')->getRoutes()->hasNamedRoute($name), "⛔ ছাড়ের তালিকায় এমন দরজা যার রুট নেই: {$name}");
        }

        $patterns = app(AllBranchesView::class)->allowedPathPatterns();
        $this->assertNotEmpty($patterns);
        $this->assertTrue(collect($patterns)->contains(fn (string $p) => preg_match('#'.$p.'#', '/approvals/12/approve') === 1),
            '⛔ অনুমোদনের দরজার ঠিকানা পাতার ছাঁচে মেলে না।');
        $this->assertFalse(collect($patterns)->contains(fn (string $p) => preg_match('#'.$p.'#', '/accounts/voucher/journal') === 1),
            '⛔ নতুন কাগজের দরজা খোলা ছাঁচে পড়ল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function voucherForm(): string
    {
        return route('accounts.voucher.create', 'journal');
    }

    /** @return array<string, mixed> */
    private function journal(string $narration): array
    {
        return ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration, 'save_as_draft' => '1',
            'lines' => [
                ['account_id' => $this->debit->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => $this->credit->id, 'debit' => '0', 'credit' => '100'],
            ]];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Voucher> */
    private function vouchers(string $narration): \Illuminate\Database\Eloquent\Builder
    {
        return Voucher::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('narration', $narration);
    }

    /** উপরের শাখা বদল — আসল দরজায় ("all" = সব শাখা)। ⓘ `branch.switch` নিজেই ছাড়ের তালিকায়, তাই "সব শাখা" থেকেও বেরোনো যায়। */
    private function as(User $user, int|string $branch): void
    {
        $this->actingAs($user->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();
        $this->forgetScope();
        $this->actingAs($user->fresh());
    }

    private function forgetScope(): void
    {
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->opened->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
