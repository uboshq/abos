<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\FioriLaunchpad;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ SAP রূপ = আসল Fiori-র Spaces and Pages — মালিক, ২০ নভেম্বর ২০২৬: *"SAP-এর ভেতরে Dynamic-এর চেহারা ঢুকে গেছে"*।
 *
 * ⛔ কী ভাঙা ছিল: SAP রূপে উপরের পটির মডিউল চাপলে মেনুর সব সারির লম্বা ড্রপডাউন খুলত (Business Central-এর ধরন)। আসল Fiori-তে
 *   স্পেসের ট্যাব চাপলে স্পেসের **পাতা** খোলে, আর পাতাটা ভাগে ভাগে টালি।
 * ⓘ এই পরীক্ষা দেখে: (১) SAP-এ পটির প্রতিটা স্পেস লিংক, সারির ড্রপডাউন নেই; (২) স্পেসের পাতায় ভাগ আর টালি, ঠিক মেনুর সারিগুলোই
 *   (অনুমতি মেনে); (৩) Dynamic আর বাকি `modules` রূপ আগের মতোই — কোনো Fiori markup নেই, NetSuite/ক্লাসিকের ড্রপডাউন অটুট।
 */
final class TheSapLookOpensSpacesNotDropDownsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_in_the_sap_look_every_space_tab_is_a_link_and_no_row_list_drops_down(): void
    {
        $nav = $this->topnav($this->pageAs($this->owner, 'tiles', route('dashboard')));

        $this->assertNotSame('', $nav, 'SAP রূপে স্পেসের পটিটাই আঁকা হয়নি');
        $this->assertStringNotContainsString('topnavMenu', $nav, 'SAP-এ স্পেসের ট্যাবে সারির ড্রপডাউন ফিরে এসেছে (Business Central-এর ধরন)');
        $this->assertStringNotContainsString('pops-onto-page', $nav, 'SAP-এ পটির ভেতরে ভাসমান সারির তালিকা আঁকা হয়েছে');
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-nav-item/s', $nav, 'SAP-এ স্পেসের ট্যাব বোতাম — লিংক হওয়ার কথা');

        $menu = app(MenuBuilder::class)->forUser($this->owner->fresh());
        $this->assertNotSame([], $menu);

        foreach ($menu as $module) {
            $href = e(FioriLaunchpad::spaceUrl($module['code']));
            $this->assertMatchesRegularExpression(
                '/<a href="'.preg_quote($href, '/').'" data-nav-item data-fiori-space="'.preg_quote($module['code'], '/').'"/',
                $nav,
                "স্পেস {$module['code']}-এর ট্যাব তার পাতার লিংক নয়",
            );
        }
    }

    public function test_the_sap_space_page_draws_one_tile_per_menu_row_in_sections(): void
    {
        // ⛔ আগে পাতা, পরে মেনু — মেনুটা SAP-এর মানুষের চোখে মাপতে হয়: SAP-এ মডিউল এক করা নয় (মালিক, ২০ নভেম্বর ২০২৬:
        //   "100% অরিজিনাল", [[Ui::keepsModulesApart()]])। আগের ক্রমে মেনু আসত মালিকের ABOS রূপের, এক করা — গ্রাহকের দল বিক্রয়ের
        //   ভিতরে, আর পাতায় (SAP) তা থাকে না (Mac run, ২১ নভেম্বর ২০২৬: "ভাগের শিরোনাম নেই: core.menu.customer:dashboard")
        $body = $this->pageAs($this->owner, 'tiles', route('module.dashboard', ['module' => 'sales']));

        $menu = app(MenuBuilder::class)->forUser($this->owner->fresh());
        $sales = FioriLaunchpad::space($menu, 'sales');
        $this->assertNotNull($sales, 'মালিকের মেনুতে বিক্রয় নেই');
        $this->assertNotContains('customer', $sales['codes'] ?? [], 'SAP রূপে গ্রাহক বিক্রয়ের ভিতরে — এক করা মেনু ঢুকেছে');

        $this->assertStringContainsString('data-fiori-launchpad="page"', $body);
        $this->assertStringContainsString('data-fiori-section=', $body);

        $sections = FioriLaunchpad::sections($sales);
        $this->assertNotSame([], $sections);

        $tiles = 0;

        foreach ($sections as $section) {
            $this->assertStringContainsString('>'.e($section['title']).'</h2>', $body, "ভাগের শিরোনাম নেই: {$section['title']}");

            foreach ($section['items'] as $tile) {
                $tiles++;

                if ($tile['url'] !== null && ! $tile['planned']) {
                    $this->assertStringContainsString('href="'.e($tile['url']).'"', $body, "টালি নেই: {$tile['label']}");
                }
            }
        }

        // ⓘ প্রতিটা মেনু-সারি একটা টালি — কেবল এই পাতার নিজের সারিটা (মডিউলের ড্যাশবোর্ড) বাদ
        $rows = collect($sales['groups'])->flatten(1)
            ->reject(fn (array $row): bool => $row['route'] === 'module.dashboard')
            ->count();
        $this->assertSame($rows, $tiles);
        $this->assertSame($tiles, substr_count($body, 'data-fiori-tile'));
    }

    public function test_every_space_in_the_menu_opens_a_page_in_the_sap_look(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner->fresh());

        foreach ($menu as $module) {
            $body = $this->pageAs($this->owner, 'tiles', FioriLaunchpad::spaceUrl($module['code']));
            $this->assertStringContainsString('data-fiori-launchpad="page"', $body, "স্পেস {$module['code']}-এর পাতায় টালি নেই");
        }
    }

    public function test_the_tiles_obey_permissions_like_the_menu(): void
    {
        $clerk = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id,
            'is_active' => true,
        ]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.invoice.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $body = $this->pageAs($clerk, 'tiles', route('module.dashboard', ['module' => 'sales']));

        $this->assertStringContainsString('href="'.e(route('sales.invoice.index')).'"', $body, 'চাবি আছে, অথচ ইনভয়েসের টালি নেই');
        $this->assertStringNotContainsString('href="'.e(route('sales.overview')).'"', $body, 'চাবি ছাড়াই বিক্রয়ের হালচালের টালি দেখা যাচ্ছে');

        // ⓘ মালিক একই পাতায় সেটা দেখেন — তাই উপরের অনুপস্থিতি চাবির জন্যই
        $this->assertStringContainsString('href="'.e(route('sales.overview')).'"', $this->pageAs($this->owner, 'tiles', route('module.dashboard', ['module' => 'sales'])));

        // ⓘ যে মডিউল মেনুতে নেই, তার পাতা SAP-এও খোলে না — আগের দরজাই উত্তর দেয়
        $this->actingAs($clerk->fresh());
        $clerk->forceFill(['ui' => 'tiles'])->save();
        $this->assertNull(FioriLaunchpad::space(app(MenuBuilder::class)->forUser($clerk->fresh()), 'hr'));
        $this->assertContains($this->get(route('module.dashboard', ['module' => 'hr']))->getStatusCode(), [403, 404]);
    }

    public function test_the_sap_home_is_a_launchpad_of_spaces(): void
    {
        $body = $this->pageAs($this->owner, 'tiles', route('dashboard'));

        $this->assertStringContainsString('data-fiori-launchpad="home"', $body);

        foreach (app(MenuBuilder::class)->forUser($this->owner->fresh()) as $module) {
            $this->assertStringContainsString('data-fiori-space-tile="'.$module['code'].'"', $body, "হোমে স্পেস {$module['code']}-এর টালি নেই");
        }
    }

    public function test_the_sap_home_tiles_wear_one_colour_icons(): void
    {
        $body = $this->pageAs($this->owner, 'tiles', route('dashboard'));
        $launchpad = substr($body, (int) strpos($body, 'data-fiori-launchpad="home"'));
        $launchpad = substr($launchpad, 0, (int) strpos($launchpad, 'data-home-units'));

        foreach (app(MenuBuilder::class)->forUser($this->owner->fresh()) as $module) {
            // ⓘ মডিউলের কোড ধরে তালিকাটা config-এ (কোর মডিউলের নাম হাতে লেখে না) — পড়া হয় একই দরজা দিয়ে
            $icon = FioriLaunchpad::spaceIcon($module['code']);
            $this->assertMatchesRegularExpression(
                '/data-fiori-space-tile="'.preg_quote($module['code'], '/').'".*?data-licon="'.preg_quote($icon, '/').'"/s',
                $launchpad,
                "হোমে স্পেস {$module['code']}-এর টালিতে এক-রঙা চিহ্ন নেই",
            );
        }

        // ⓘ এক-রঙা মানে লেখার রং নেয় (currentColor) — রঙিন মডিউল-চিহ্নের ছবি বা নিজের রং নয়
        $this->assertStringNotContainsString('<img', $launchpad, 'হোমের টালিতে রঙিন ছবি-চিহ্ন বসেছে');
        $this->assertDoesNotMatchRegularExpression('/fill="#|style="[^"]*color/i', $launchpad, 'হোমের টালির চিহ্নে নিজের রং');
    }

    public function test_the_sap_look_has_no_breadcrumb_row_and_the_shell_carries_a_back_arrow(): void
    {
        $crumbs = 'aria-label="'.e(__('core.a11y.breadcrumb')).'"';

        $list = $this->pageAs($this->owner, 'tiles', route('sales.invoice.index'));
        $this->assertStringNotContainsString($crumbs, $list, 'SAP রূপে breadcrumb-পটি ফিরে এসেছে');
        $this->assertStringContainsString('data-fiori-back', $list, 'SAP রূপে তালিকার পাতায় শেল বারের ফেরার তীর নেই');
        $this->assertLessThan(strpos($list, 'data-fiori-shell'), strpos($list, 'data-fiori-back'), 'ফেরার তীর লোগোর বাঁয়ে নয়');

        $home = $this->pageAs($this->owner, 'tiles', route('dashboard'));
        $this->assertStringNotContainsString('data-fiori-back', $home, 'হোমে ফেরার তীর — ফেরার জায়গা নেই');
        $this->assertStringNotContainsString($crumbs, $home);

        // ⓘ Dynamic-এর মাথার সারির নিয়ম আগের মতো (`crumbs`), আর ফেরার তীর পায় না
        $this->assertSame('none', \App\Core\Support\Ui::pathBar('tiles'));
        $this->assertSame('crumbs', \App\Core\Support\Ui::pathBar('dynamic'), 'Dynamic রূপের মাথার সারির নিয়ম বদলেছে');
        $dynamic = $this->pageAs($this->owner, 'dynamic', route('sales.invoice.index'));
        $this->assertStringNotContainsString('data-fiori-back', $dynamic);
    }

    public function test_dynamic_and_the_other_looks_draw_no_fiori_markup_and_keep_their_own_nav(): void
    {
        foreach (['dynamic', 'rose', 'suite', 'classic'] as $look) {
            foreach ([route('dashboard'), route('module.dashboard', ['module' => 'sales']), route('sales.invoice.index')] as $url) {
                $body = $this->pageAs($this->owner, $look, $url);
                $this->assertStringNotContainsString('data-fiori-', $body, "{$look} রূপে Fiori-র markup ঢুকেছে: {$url}");
            }
        }

        // ⓘ Dynamic (Business Central) — নিজের নেভিগেশন, উপরের মডিউল-পটি নয়
        $dynamic = $this->pageAs($this->owner, 'dynamic', route('dashboard'));
        $this->assertSame('', $this->topnav($dynamic), 'Dynamic রূপে উপরের মডিউল-পটি আঁকা হয়েছে');
        $this->assertStringContainsString('data-waffle', $dynamic);

        // ⓘ NetSuite আর ক্লাসিক — মডিউলের ট্যাবে তালিকা আগের মতোই খোলে
        foreach (['suite', 'classic'] as $look) {
            $nav = $this->topnav($this->pageAs($this->owner, $look, route('dashboard')));
            $this->assertStringContainsString('x-data="topnavMenu"', $nav, "{$look} রূপের মডিউল-তালিকা হারিয়েছে");
        }
    }

    public function test_outside_the_sap_look_a_module_without_a_board_still_answers_404(): void
    {
        $engine = app(DashboardEngine::class);

        foreach (app(MenuBuilder::class)->forUser($this->owner->fresh()) as $module) {
            if ($engine->has($module['code'])) {
                continue;
            }

            $this->owner->forceFill(['ui' => 'rose'])->save();
            $this->actingAs($this->owner->fresh())
                ->get(route('module.dashboard', ['module' => $module['code']]))
                ->assertNotFound();
        }

        $this->addToAssertionCount(1);
    }

    private function pageAs(User $user, string $look, string $url): string
    {
        $user->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($user->fresh())->get($url)->assertOk()->getContent();
    }

    private function topnav(string $body): string
    {
        return preg_match('/<nav class="topnav\b.*?<\/nav>/s', $body, $m) ? $m[0] : '';
    }
}
