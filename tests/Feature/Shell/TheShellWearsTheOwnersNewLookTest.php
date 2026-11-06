<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Core\Support\Release;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিকের নতুন চেহারা — ৬ নভেম্বর ২০২৬, নমুনা "ABOS নতুন চেহারা" (v4) দেখে "ok":
 *   ১ টপবারে আইকনসহ জীবন্ত লোগো · ২ শাখার পিল সাদা · ৩ এক সারির রেল, নিচে নাম, "⋯" নেই, চাকায় চলে · ৪ ফুটারে জীবন্ত লোগো আর
 *   Powered by UNIVER BANGLADESH · ৫ ফুটারের ডানে কেবল ©, সংস্করণ লগইন পাতায়, নাম-পদবি ছবির বাঁয়ে · ৬ টপবারের আইকন রঙিন ·
 *   ৮ অনুমোদনের ইনবক্সে পাঁচ রঙিন ঘর আর কার্ডে অবস্থার রং · ৯ জরুরি নোটিশ কাজের কলামে, রেল আর মেনু অটুট ·
 *   ১০ উপরের রঙিন মডিউল-মেনু চিহ্ন রেলের সমান চওড়া।
 */
final class TheShellWearsTheOwnersNewLookTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_top_bar_carries_the_live_logo_white_branch_coloured_icons_and_the_name_with_title(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('approval.inbox.index'))->assertOk()->getContent();
        $bar = $this->between($html, '<header data-topbar', '</header>');

        $this->assertMatchesRegularExpression('/data-shell-product(?:(?!<\/a>).)*data-abos-live/s', $bar, '⛔ টপবারে জীবন্ত লোগো নেই (১)।');
        $this->assertMatchesRegularExpression('/data-menu-cell\s+class="[^"]*w-\(--spacing-sidebar-icon\)/', $bar, '⛔ মডিউল-মেনু চিহ্নের ঘর রেলের সমান চওড়া নয় (১০)।');
        $this->assertStringContainsString('color: var(--color-topbar-pill-ink)', $bar, '⛔ শাখার পিলের লেখা গাঢ় নয় (২)।');
        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertMatchesRegularExpression('/--color-topbar-pill-bg:\s*#ffffff;/', $tokens, '⛔ শাখার পিল সাদা নয় (২)।');
        foreach (['tasks', 'bell'] as $icon) {
            $this->assertMatchesRegularExpression('/data-topbar-icon="'.$icon.'" style="background: var\(--color-topbar-icon-'.$icon.'\)/', $bar, "⛔ টপবারের \"{$icon}\" আইকন রঙিন নয় (৬)।");
        }
        $this->assertMatchesRegularExpression('/data-profile-identity(?:(?!<\/span>\s*<\/span>).)*'.preg_quote(e($this->owner->name), '/').'/s', $bar, '⛔ ছবির বাঁয়ে নাম নেই (৫)।');
        $title = \App\Core\Support\UserDesignation::of($this->owner);
        if ($title !== null) {
            $this->assertStringContainsString(e($title), $this->between($bar, 'data-profile-identity', '</span>
        </span>'), '⛔ নামের নিচে পদবি নেই (৫)।');
        }
    }

    public function test_the_rail_shows_every_module_with_its_name_and_scrolls_without_a_more_button(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('approval.inbox.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-rail-more-button', $html, '⛔ রেলে এখনো "⋯ আরও" (৩)।');
        $this->assertMatchesRegularExpression('/<nav class="rail-scroll[^"]*overflow-y-auto/', $html, '⛔ রেল চাকায় চলে না (৩)।');
        $this->assertSame(substr_count($html, 'data-rail-module="'), substr_count($html, 'data-rail-label'), '⛔ রেলের প্রতিটা আইকনের নিচে নাম নেই (৩)।');
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.rail-scroll\s*\{\s*scrollbar-width:\s*none;/', $css, '⛔ রেলের স্ক্রলবার দেখা যায় (৩)।');
        $this->assertMatchesRegularExpression('/class="rail-flyout invisible fixed/', $html, '⛔ পাশের তালিকা রেলের ভিতরে — চাকার ঘর তাকে কেটে দেবে (৩)।');
    }

    public function test_the_footer_carries_the_live_logo_univer_and_only_the_copyright(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('approval.inbox.index'))->assertOk()->getContent();
        $foot = $this->between($html, '<footer data-footer', '</footer>');

        $this->assertMatchesRegularExpression('/data-footer-brand(?:(?!<\/span>\s*<\/span>).)*data-abos-live/s', $foot, '⛔ ফুটারে জীবন্ত লোগো নেই (৪)।');
        $this->assertMatchesRegularExpression('/data-footer-brand.*brand\/univer-mark\.png.*UNIVER BANGLADESH/s', $foot, '⛔ ফুটারে Powered by UNIVER BANGLADESH নেই (৪)।');
        $this->assertMatchesRegularExpression('/data-footer-rights[^>]*>©\s*'.now()->year.'\s*'.preg_quote(e(__('core.brand.rights')), '/').'</u', $foot, '⛔ ফুটারের ডানে "© সাল সর্বস্বত্ব সংরক্ষিত" নেই (৫)।');
        $this->assertStringNotContainsString('v'.Release::version(), $foot, '⛔ ফুটারে এখনো সংস্করণ — সেটা লগইন পাতায় (৫)।');
        $this->assertStringNotContainsString(e($this->owner->name), $foot, '⛔ ফুটারে এখনো নাম — সেটা টপবারে ছবির বাঁয়ে (৫)।');
    }

    public function test_the_urgent_strip_sits_in_the_work_column_and_the_sign_in_card_carries_the_full_version(): void
    {
        $layout = (string) file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $this->assertSame(1, substr_count($layout, '<x-shell.notice-banner />'), '⛔ নোটিশের ফিতা একাধিক জায়গায়।');
        $this->assertGreaterThan(strpos($layout, '<x-shell.sidebar'), strpos($layout, '<x-shell.notice-banner />'), '⛔ জরুরি নোটিশ রেল আর মেনুর উপরে পুরো চওড়ায় — কাজের কলামে বসার কথা (৯)।');

        config(['release.version' => '1.4.2', 'release.date' => '2026-10-24']);
        $build = storage_path('app/release-build.json');
        file_put_contents($build, json_encode(['sha' => 'ae03a14fd8325c67828e7afc9769fa2a5e64b654', 'deployed_at' => '2026-11-06T16:41:00+06:00']));
        try {
            $page = (string) $this->get(route('login.calm'))->assertOk()->getContent();
        } finally {
            @unlink($build);
        }
        $line = $this->between($page, 'data-release-line', '</p>');
        $this->assertMatchesRegularExpression('/ABOS v1\.4\.2\s*·\s*'.preg_quote(__('core.brand.released'), '/').' 24-10-2026\s*·\s*'.preg_quote(__('core.brand.updated'), '/').' 06-11-2026/u', $line, '⛔ লগইনের ঘরের নিচে সংস্করণের পুরো বিবরণ নেই (৫)।');
        $this->assertStringNotContainsString('ae03a14', $line, '⛔ লগইনের ঘরে build নম্বর — মালিক চান না।');
    }

    /**
     * ⭐ ৭ নভেম্বর ২০২৬, মালিক: "অনুমোদনের ইনবক্স ekhon zemon live e ache temoi thakbe setake just colorfull koro" — পাঁচ ঘর বাদ,
     * সাজানো লাইভের মতো, শুধু রং: অনুমোদনের রঙে মাথা (নাম আর এক লাইনের হিসাব), কার্ডে অবস্থার দাগ।
     */
    public function test_the_inbox_keeps_its_live_layout_and_only_gains_colour(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('approval.inbox.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-inbox-board', $html, '⛔ ইনবক্সের মাথায় পাঁচ ঘর ফিরে এসেছে — মালিক লাইভের মতো চান (৮)।');
        $this->assertStringNotContainsString('data-board=', $html, '⛔ ইনবক্সে পাঁচ ঘরের একটা ঘর রয়ে গেছে (৮)।');
        $this->assertMatchesRegularExpression('/data-page-kind="approval"(?:(?!data-inbox-list).)*data-money-hero(?:(?!data-inbox-list).)*'.preg_quote(e(__('approval::menu.inbox')), '/').'/s', $html,
            '⛔ ইনবক্সের মাথা অনুমোদনের রঙে নয়, বা নাম নেই (৮)।');
        $this->assertMatchesRegularExpression('/data-hero-count[^>]*>\s*'.preg_quote(trim(explode(':count', __('approval::message.inbox_summary'))[0]), '/').'/u', $html,
            '⛔ মাথায় লাইভের এক লাইনের হিসাব (কয়টা · দেরি · মোট) নেই (৮)।');
        foreach (['data-list-tabs', 'data-inbox-filters', 'data-inbox-totals', 'data-inbox-detail'] as $part) {
            $this->assertStringContainsString($part, $html, "⛔ লাইভের অংশ হারাল: {$part} (৮)।");
        }
        $this->assertMatchesRegularExpression('/data-inbox-card="\d+"\s+data-card-state="(late|wait)"/', $html, '⛔ কার্ডে অবস্থার রঙের দাগ নেই (৮)।');
    }

    /**
     * ⭐ টাকার নয় পাতা আর অনুমোদনের পাতা — নিজের রঙে মাথা, "নতুন" বোতাম মাথায়, টুলবার আর নিচের মোটের সারি অটুট
     * (মালিক, ৬–৭ নভেম্বর ২০২৬: নমুনা "ok"; "ei box gulor dorkar nai", "egulo kintu thakbe", "egulo dibe", "fita ta ro cikon hoy")।
     */
    public function test_each_money_and_approval_page_wears_its_own_colour_and_keeps_its_tools(): void
    {
        $pages = [
            'loan' => [route('accounts.loan.index'), route('accounts.loan.create')],
            'receipt' => [route('accounts.voucher.index', 'receipt'), route('accounts.voucher.create', 'receipt')],
            'payment' => [route('accounts.voucher.index', 'payment'), route('accounts.voucher.create', 'payment')],
            'expense' => [route('accounts.voucher.index', 'expense'), route('accounts.voucher.create', 'expense')],
            'journal' => [route('accounts.voucher.index', 'journal'), route('accounts.voucher.create', 'journal')],
            'contra' => [route('accounts.voucher.index', 'contra'), route('accounts.voucher.create', 'contra')],
            'list' => [route('accounts.voucher.list'), null],
            'custody' => [route('accounts.custody'), route('accounts.transfer.create')],
            'transfer' => [route('accounts.transfer.index'), route('accounts.transfer.create')],
        ];

        foreach ($pages as $kind => [$url, $new]) {
            $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $hero = $this->between($html, 'data-money-hero', 'data-money-hero-end');

            $this->assertStringContainsString('data-page-kind="'.$kind.'"', $html, "⛔ {$kind}: পাতা নিজের রঙে নয়।");
            $this->assertStringContainsString('class="toolbar-view', $html, "⛔ {$kind}: টুলবার হারাল — মালিক: \"egulo kintu thakbe\"।");
            if ($new !== null) {
                $this->assertStringContainsString('href="'.e($new).'"', $hero, "⛔ {$kind}: \"নতুন\" বোতাম রঙিন মাথায় নেই।");
            }
            $this->assertDoesNotMatchRegularExpression('/<p\b/', $hero, "⛔ {$kind}: মাথায় নামের নিচে ব্যাখ্যার লেখা — মালিক সরু পট্টি চান।");
        }

        foreach ([route('approval.inbox.mine'), route('approval.flow.index'), route('approval.delegation.index')] as $url) {
            $this->assertStringContainsString('data-page-kind="approval"',
                (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent(), "⛔ অনুমোদনের পাতা রঙিন নয়: {$url}");
        }

        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertDoesNotMatchRegularExpression('/--color-kind-payment:\s*#(dc|ef|b9|c0)/i', $tokens, '⛔ পরিশোধ আবার লাল — মালিক বদলাতে বলেছেন।');
    }

    private function between(string $html, string $from, string $to): string
    {
        $a = strpos($html, $from);
        $this->assertNotFalse($a, "⛔ দৃশ্যটাই ভুল — {$from} নেই।");
        $b = strpos($html, $to, (int) $a);

        return substr($html, (int) $a, ($b === false ? strlen($html) : $b) - (int) $a);
    }
}
