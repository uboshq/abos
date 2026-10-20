<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Services\CustomerMetrics;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ওয়েবের মান ধাপ ২ — SAP Fiori shell bar, মালিকের অনুমোদিত নমুনায় (মালিকের আদেশ "100% int. standard", ২০ অক্টোবর ২০২৬;
 * E:\ABOS\মালিকের-ফাইল\পুরো-পাতার-নমুনা-ওয়েব-ও-অ্যাপ-২০-অক্টোবর.pdf, পাতা ১)।
 *
 * দাবি, আসল পাতার দরজা দিয়ে (ABOS রূপ):
 *  - বারটা পুরো চওড়া, সাইডবারের আগে; তাতে কেবল লোগো · কোম্পানি · শাখা ▾ · একটাই খোঁজা · ＋ তৈরি · (অনুমোদন) · 🔔 · ছবি;
 *    ভাষা, রং আর পূর্ণপর্দা ছবির মেনুর ভেতরে, বারে আলাদা নয় (ভুল ১, ৩)।
 *  - শাখা কখনো বাছা না থাকলেও বারে শাখার নাম (ভুল ৪)।
 *  - খোঁজা একটাই: মডিউল-প্যানেলের খোঁজার ঘর নেই, আর বারের খোঁজায় মেনুর পাতাও মেলে (ভুল ২)।
 *  - অনুমোদনের চিহ্ন কেবল সইদাতার বারে, সংখ্যা ইনবক্সের হুবহু একই; সইদাতা না হলে চিহ্নই নেই।
 *  - পাতার মাথায় "তারিখ · কোম্পানি · শাখা", অঙ্ক ইংরেজিতে।
 *  - টাকার বাক্সের "বাজারে বকেয়া" হোমের বকেয়ার হুবহু একই ([[CustomerMetrics::dues()]])।
 */
final class TheBarIsAFioriShellBarTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        // ⓘ শাখা কখনো বাছা হয়নি (লাইভে ঠিক তাই), আর এক শাখায় দেখা — "সব শাখা" নয়
        $this->owner->forceFill(['ui' => 'navy', 'current_branch_id' => null, 'view_all_branches' => false])->save();
    }

    public function test_the_bar_is_full_width_and_holds_only_the_fiori_items(): void
    {
        $html = $this->page(route('dashboard'));
        $bar = $this->bar($html);

        $this->assertLessThan(strpos($html, '<aside'), strpos($html, '<header data-topbar="full"'), '⛔ বারটা সাইডবারের পাশে, উপরে পুরো চওড়ায় নয়।');

        foreach (['data-shell-logo', 'data-shell-company', 'data-shell-search', 'data-shell-actions', 'data-profile-menu', 'data-notification-bell'] as $mark) {
            $this->assertStringContainsString($mark, $bar, "⛔ বারে {$mark} নেই।");
        }
        $this->assertSame(1, substr_count($bar, 'data-shell-search'), '⛔ বারে একাধিক খোঁজা।');

        // ⓘ ভাষা, রং, পূর্ণপর্দা — কেবল ছবির মেনুর ভেতরে
        $outsideMenu = substr($bar, 0, (int) strpos($bar, 'data-profile-menu'));
        $this->assertStringNotContainsString(route('locale.switch'), $outsideMenu, '⛔ ভাষার বোতাম বারে, ছবির মেনুতে নয়।');
        $this->assertStringNotContainsString(route('theme.switch'), $outsideMenu, '⛔ রঙের বোতাম বারে, ছবির মেনুতে নয়।');
        $this->assertStringNotContainsString('fullscreenToggle', $outsideMenu, '⛔ পূর্ণপর্দা বারে, ছবির মেনুতে নয়।');
        $menu = substr($bar, (int) strpos($bar, 'data-profile-menu'));
        foreach (['data-menu-language', 'data-menu-theme', 'data-menu-fullscreen'] as $mark) {
            $this->assertStringContainsString($mark, $menu, "⛔ ছবির মেনুতে {$mark} নেই।");
        }
        $this->assertStringContainsString('value="system"', $menu, '⛔ রঙে "ফোনের মতো" নেই।');
    }

    public function test_the_branch_is_named_even_when_none_was_ever_chosen(): void
    {
        $bar = $this->bar($this->page(route('dashboard')));

        $this->assertMatchesRegularExpression('/data-shell-branch> · ([^<]+)</u', $bar, '⛔ বারে শাখার নাম নেই।');
        preg_match('/data-shell-branch> · ([^<]+)</u', $bar, $m);
        $branches = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->get()->map->name()->all();
        $this->assertContains(html_entity_decode(trim($m[1])), $branches, '⛔ বারের শাখা এই কোম্পানির কোনো শাখা নয়।');
    }

    public function test_one_search_finds_pages_and_the_panel_has_no_second_box(): void
    {
        $html = $this->page(route('accounts.period.index'));
        $this->assertStringNotContainsString('x-model="filter"', $html, '⛔ মডিউল-প্যানেলে দ্বিতীয় খোঁজার ঘর।');

        $label = __('accounts::menu.periods');
        $hits = $this->actingAs($this->owner)->getJson('/search?q='.urlencode(mb_substr($label, 0, 4)))->assertOk()->json('hits');

        $this->assertContains(route('accounts.period.index'), array_column($hits, 'url'), '⛔ বারের খোঁজায় মেনুর পাতা মেলে না।');
    }

    public function test_the_approvals_mark_counts_the_inbox_and_only_a_signer_has_it(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true, 'ui' => 'navy']);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $flow = ApprovalFlow::create(['module' => 'purchase', 'action' => 'order', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);

        foreach ([1, 2] as $n) {
            Approval::create(['company_id' => $this->company->id, 'approvable_type' => User::class, 'approvable_id' => $clerk->id + $n,
                'module' => 'purchase', 'action' => 'order', 'amount' => '1000', 'status' => Approval::PENDING, 'current_level' => 1,
                'requested_by' => $clerk->id, 'requested_at' => now()]);
        }

        $inbox = app(ApprovalEngine::class)->pendingQueryFor($this->owner->fresh())->count();
        $this->assertGreaterThan(0, $inbox, 'প্রস্তুতিটাই ভুল — ইনবক্সে কিছু নেই।');

        $bar = $this->bar($this->page(route('dashboard')));
        $this->assertMatchesRegularExpression('/data-shell-approvals="'.$inbox.'"/', $bar, '⛔ বারের সংখ্যা ইনবক্সের সংখ্যা নয়।');
        $this->assertStringContainsString(e(__('core.notice.approvals_waiting', ['count' => $inbox])), $bar, '⛔ চিহ্নের নামে সংখ্যা নেই।');

        $this->app['auth']->forgetGuards();
        $clerkBar = $this->bar($this->actingAs($clerk)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringNotContainsString('data-shell-approvals', $clerkBar, '⛔ যিনি সইদাতা নন তাঁর বারেও অনুমোদনের চিহ্ন।');
    }

    public function test_the_page_head_says_date_company_and_branch_in_english_digits(): void
    {
        $html = $this->page(route('accounts.period.index'));

        $this->assertMatchesRegularExpression('/data-page-context[^>]*>([^<]+)</u', $html, '⛔ পাতার মাথায় তারিখ · কোম্পানি · শাখা নেই।');
        preg_match('/data-page-context[^>]*>([^<]+)</u', $html, $m);
        $line = html_entity_decode($m[1]);

        $this->assertStringContainsString(now()->format('Y'), $line, '⛔ সাল ইংরেজি অঙ্কে নেই।');
        $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', $line, '⛔ তারিখে বাংলা অঙ্ক।');
        $this->assertStringContainsString($this->company->name(), $line);
        $this->assertSame(3, count(explode(' · ', $line)), '⛔ তিনটা অংশ নয় (তারিখ · কোম্পানি · শাখা)।');
    }

    public function test_the_money_box_dues_equal_the_homes_dues(): void
    {
        $html = $this->page(route('dashboard'));

        $this->assertStringContainsString('data-money-today', $html, 'প্রস্তুতিটাই ভুল — টাকার বাক্সে আজকের ঘর নেই।');
        preg_match('/data-money-tile="plain".*?<p class="tabular[^"]*"[^>]*>([^<]+)</su', $html, $m);
        $this->assertNotEmpty($m, '⛔ "বাজারে বকেয়া" ঘর টাকার বাক্সে নেই।');

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        $dues = app(CustomerMetrics::class)->dues($this->owner->fresh(), now()->toDateString(), null);
        $this->assertSame(Money::format($dues['amount']), html_entity_decode(trim($m[1])), '⛔ বাক্সের বকেয়া হোমের বকেয়া নয়।');
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
    }

    private function bar(string $html): string
    {
        $start = strpos($html, '<header data-topbar="full"');
        $this->assertNotFalse($start, '⛔ পুরো বারটাই নেই।');

        return substr($html, $start, strpos($html, '</header>', $start) - $start);
    }
}
