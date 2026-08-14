<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * অ্যাপ শেল — মেনু, লগইন, কোম্পানি সুইচ, ভাষা।
 *
 * ব্রাউজারে চালিয়ে দেখতে গিয়ে দুইটা বাগ ধরা পড়েছিল যা কোড পড়ে ধরা পড়ত না:
 * লগইন বোতাম নিজেকে নিষ্ক্রিয় করে ফর্ম সাবমিট আটকে দিত, আর গাঢ় প্যানেলে
 * হেডিং কালো হয়ে অদৃশ্য হয়ে যেত। এখানকার টেস্টগুলো প্রথমটার পুনরাবৃত্তি
 * ঠেকায়; দ্বিতীয়টা DesignTokenTest-এ।
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        CompanyContext::clear();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function company(): Company
    {
        return Company::query()->where('code', 'TDEPOT')->firstOrFail();
    }

    /**
     * মন্তব্য বাদ দিয়ে শুধু কোডটুকু।
     *
     * এই টেস্টগুলো যা নিষিদ্ধ তা খোঁজে, আর নিষেধটা ব্যাখ্যা করা মন্তব্যেও
     * সেই শব্দগুলো থাকে — প্রথমবার চালিয়ে ঠিক সেটাই ধরা পড়েছিল। নিয়মটা
     * কোড নিয়ে, তাই মন্তব্য সরিয়েই দেখা উচিত।
     */
    private function codeOf(string $path): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
    }

    public function test_the_login_page_renders_without_asking_which_company(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('ABOS', false);

        // সেকশন ১৬.৩ — লগইনের আগে কোম্পানির তালিকা দেখানো Zero Trust ভাঙে:
        // যে কেউ URL খুলেই জেনে যেত সার্ভারে কোন প্রতিষ্ঠানগুলো আছে।
        $response->assertDontSee('Alpha Traders');
        $response->assertDontSee('Beta Distribution');
    }

    public function test_signing_in_actually_works(): void
    {
        // ব্রাউজারে ধরা পড়া বাগটার পাহারা: বোতাম নিজেকে নিষ্ক্রিয় করে
        // ফেলায় POST কখনো যেত না।
        $response = $this->post('/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_the_submit_button_never_disables_itself(): void
    {
        // disabled বোতাম ফর্ম সাবমিট করে না। দ্বিতীয় ক্লিক ঠেকাতে হলে
        // pointer-events, disabled নয়।
        $markup = $this->codeOf(resource_path('views/auth/login.blade.php'));

        $this->assertStringNotContainsString(':disabled="busy"', $markup);
        $this->assertStringContainsString('pointer-events-none', $markup);
    }

    public function test_a_wrong_password_says_the_same_thing_as_a_wrong_name(): void
    {
        $wrongPassword = $this->post('/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'not-the-password',
        ]);

        $noSuchUser = $this->post('/login', [
            'identifier' => 'nobody@abos.test',
            'password' => 'password',
        ]);

        // এক বার্তা, নাহলে আক্রমণকারী আগে ব্যবহারকারীর তালিকা বের করে
        // নেয় (সেকশন ১৬.৫)।
        $this->assertSame(
            $wrongPassword->exception?->getMessage(),
            $noSuchUser->exception?->getMessage(),
        );
        $this->assertGuest();
    }

    public function test_an_inactive_user_cannot_sign_in(): void
    {
        $this->owner()->forceFill(['is_active' => false])->save();

        $this->post('/login', ['identifier' => 'owner@abos.test', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_the_dashboard_needs_a_signed_in_user(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_dashboard_shows_the_company_the_user_is_in(): void
    {
        $response = $this->actingAs($this->owner())->get('/');

        $response->assertOk();
        $response->assertSee('ট্রেড ডিপো', false);
        $response->assertSee('প্রধান ময়মনসিংহ', false);
    }

    public function test_the_menu_is_built_from_module_files_and_is_translated(): void
    {
        $response = $this->actingAs($this->owner())->get('/');

        // মডিউলের নিজের module.php থেকে আসা সারি, কোরে লেখা নয়
        $response->assertSee('গ্রাহক', false);

        // কাঁচা অনুবাদ কী পাতায় থাকা মানে lang ফাইল নেই — নিয়ম ৯।
        $response->assertDontSee('::menu.', false);

        // জাবেদার পর্দাটা এখন সত্যিই আছে, তাই মেনুতেও আছে।
        //
        // কিছুক্ষণ আগে এখানে উল্টো যাচাই ছিল (assertDontSee), কারণ তখন
        // স্ক্রিনটা লেখা হয়নি আর সারিটা planned ছিল। এই দুইটা যাচাই
        // একসাথে প্রমাণ করে যে planned পতাকাটা সত্যিই কাজ করে।
        $response->assertSee('জাবেদা ভাউচার', false);

        // রেওয়ামিলও আছে — Accounts-এর পনেরোটা সারিই এখন তৈরি
        $response->assertSee('রেওয়ামিল', false);

        // "এখনো নেই" ধরনের যাচাই এখানে আর নেই: Accounts-এর প্রতিটা
        // স্ক্রিন লেখা হয়ে গেছে, তাই অনুপস্থিত থাকার মতো কিছু বাকি নেই।
        // planned পতাকাটা সত্যিই কাজ করে কি না সেটা ModuleMenuTest
        // দুই দিক থেকে যাচাই করে — এখানে আবার করার দরকার নেই।
    }

    public function test_the_menu_hides_what_a_role_cannot_reach(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $menu = app(MenuBuilder::class)->forUser($salesman);
        $codes = array_column($menu, 'code');

        // বিক্রয়কর্মীর গ্রাহক দেখার অধিকার আছে, তাই মডিউলটা আছে।
        $this->assertContains('customer', $codes);

        // অধিকার না থাকলে মডিউলটাই তালিকায় নেই — ধূসর করে দেখানো হয় না,
        // সেটা শুধু জানায় সে কী পারে না।
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company(), ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company()->id])->save();

        $this->assertSame([], app(MenuBuilder::class)->forUser($stranger->fresh()));
    }

    /**
     * module.php-তে যে ক্রমেই লেখা হোক, প্রদর্শনের ক্রম এক — সেকশন ১৫.২।
     * নাহলে "একটা মডিউল শিখলে সব চেনা" কথাটা মিথ্যা হয়ে যায়।
     *
     * কোনো একটা মডিউলের নাম ধরে নয়, সব মডিউলের উপর: আগে এটা accounts-এর
     * পাঁচটা গ্রুপ ধরে লেখা ছিল, আর ওই গ্রুপগুলো এখন লুকানো (স্ক্রিন তৈরি
     * হয়নি) — তাই পরীক্ষাটা মডিউলের অগ্রগতির সাথে ভেঙে যেত, নিয়ম ভাঙলে নয়।
     */
    public function test_the_menu_groups_stay_in_the_same_order_everywhere(): void
    {
        $canonical = ModuleDefinition::MENU_GROUPS;

        $menu = app(MenuBuilder::class)->forUser($this->owner());

        $this->assertNotEmpty($menu, 'মালিকের মেনু খালি — তাহলে কিছুই যাচাই হত না।');

        foreach ($menu as $module) {
            $shown = array_keys($module['groups']);

            // যেগুলো দেখানো হচ্ছে সেগুলো নির্দিষ্ট ক্রমেরই একটা উপ-ক্রম
            $this->assertSame(
                array_values(array_intersect($canonical, $shown)),
                $shown,
                "{$module['code']} মডিউলের গ্রুপের ক্রম ঠিক নেই।",
            );
        }
    }

    public function test_switching_company_lands_on_the_dashboard_of_the_new_one(): void
    {
        $owner = $this->owner();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $response = $this->actingAs($owner)->post('/company/switch', ['company_id' => $beta->id]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($beta->id, $owner->fresh()->current_company_id);

        // শাখাও বদলেছে — আগেরটা ধরে রাখলে পরের এন্ট্রি ভুল কোম্পানির
        // শাখায় বসত।
        $branchCompany = Branch::acrossAllCompanies()
            ->whereKey($owner->fresh()->current_branch_id)
            ->value('company_id');

        $this->assertSame($beta->id, $branchCompany);
    }

    public function test_switching_into_a_company_you_do_not_belong_to_fails(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $before = $salesman->current_company_id;

        // withoutExceptionHandling ছাড়া Laravel ব্যতিক্রমটাকে একটা ৫০০
        // রেসপন্সে বদলে দেয়, আর expectException কিছুই দেখে না।
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);

        try {
            $this->actingAs($salesman)->post('/company/switch', ['company_id' => $beta->id]);
        } finally {
            // যাই হোক, কোম্পানি বদলায়নি — এটাই আসল কথা।
            $this->assertSame($before, $salesman->fresh()->current_company_id);
        }
    }

    public function test_the_language_choice_is_stored_on_the_user(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/locale/switch', ['locale' => 'en']);

        // রেকর্ডে, সেশনে নয় — অন্য ডিভাইসে লগইন করলেও একই ভাষা (নিয়ম ৯)।
        $this->assertSame('en', $owner->fresh()->locale);

        // মডিউলের লেবেলও ইংরেজিতে — অনুবাদ পুরো পাতায়, শুধু শিরোনামে নয়।
        //
        // লেবেলটা রেজিস্ট্রি থেকে নেওয়া, হাতে লেখা নয়: আগে এখানে একটা
        // নির্দিষ্ট মেনু সারির নাম লেখা ছিল, আর নতুন একটা মডিউল সক্রিয়
        // হওয়ামাত্র সাইডবারে অন্য মডিউলের সারিগুলো দেখাতে শুরু করায়
        // পরীক্ষাটা ভেঙে গিয়েছিল — নিয়ম ভাঙায় নয়, অগ্রগতিতে।
        $label = app(ModuleRegistry::class)->all()['customer']->name['en'];

        $this->actingAs($owner->fresh())->get('/')->assertSee($label, false);
    }

    public function test_every_module_permission_is_registered_in_the_database(): void
    {
        $drift = app(PermissionSyncer::class)->drift();

        // ঘোষিত অথচ নিবন্ধিত নয় এমন অনুমতি মানে ওই মেনু আইটেম কেউ
        // কোনোদিন দেখবে না, আর কেন দেখবে না তার কোনো চিহ্নও থাকবে না।
        $this->assertSame([], $drift['unregistered']);
    }

    public function test_the_sidebar_head_matches_the_top_bar(): void
    {
        $sidebar = $this->codeOf(resource_path('views/components/shell/sidebar.blade.php'));
        $topbar = $this->codeOf(resource_path('views/components/shell/topbar.blade.php'));

        // একই উচ্চতা ও একই রং — নাহলে লোগোর নিচের রেখা আর টপবারের নিচের
        // রেখা দুই রকম হয়, আর পর্দার দুই অর্ধেক দুইটা আলাদা স্ক্রিনের মতো
        // দেখায়।
        foreach ([$sidebar, $topbar] as $markup) {
            $this->assertStringContainsString('h-(--spacing-header)', $markup);
            $this->assertStringContainsString('bg-(--color-surface-card)', $markup);
        }
    }

    public function test_the_full_product_name_is_never_shown_clipped(): void
    {
        $markup = $this->codeOf(resource_path('views/components/shell/sidebar.blade.php'));

        // নামটা এখন টাইপ করা লেখা নয়, ডিজাইনারের নিজের লেটারিংয়ে আঁকা
        // ওয়ার্ডমার্ক। ছবি প্রস্থ অনুযায়ী ছোট-বড় হয়, কেটে যায় না — তাই
        // "All Business Operating Syste" সমস্যাটার আর অস্তিত্বই নেই।
        // গাঢ় সাইডবারে গাঢ়-জমিনের রূপটাই — সাদা-জমিনের অক্ষর ওখানে মিলিয়ে যেত
        $this->assertStringContainsString('abos-wordmark-dark.png', $markup);
        $this->assertStringContainsString('object-contain', $markup);

        // সরু সাইডবারে (৪৪px) ওয়ার্ডমার্ক ধরে না, তাই সেখানে শুধু মার্ক।
        $this->assertStringContainsString('abos-icon-transparent.png', $markup);

        // স্ক্রিন রিডারের জন্য পূর্ণরূপটা লেখা হিসেবেও থাকে — একটা ছবির
        // alt="ABOS" পড়ে শোনালে পূর্ণরূপটা হারিয়ে যেত।
        $this->assertStringContainsString('sr-only', $markup);
    }

    public function test_the_top_bar_shows_the_companys_own_logo(): void
    {
        $response = $this->actingAs($this->owner())->get('/');

        // গ্রাহকের নিজের লোগো, ABOS-এর নয়: প্রোডাক্টের মার্ক সাইডবারের
        // মাথায়, আর এখানে ব্যবহারকারী দেখে সে কোন প্রতিষ্ঠানের হয়ে
        // কাজ করছে।
        $response->assertSee('logos/Trade Depot.png', false);
    }

    public function test_switching_company_swaps_the_logo_too(): void
    {
        $owner = $this->owner();
        $familyMart = Company::query()->where('code', 'FMART')->firstOrFail();

        $owner->switchCompany($familyMart->id);

        $response = $this->actingAs($owner->fresh())->get('/');

        $response->assertSee('logos/FamilyMart.png', false);

        // Trade Depot-এর লোগো পাতা থেকে উধাও হয় না — সেটা এখন সুইচারের
        // তালিকায়, "কোথায় যেতে পারি" হিসেবে। যা বদলেছে তা হলো কোনটা
        // চলতি: স্ট্যাটাস বার ও শিরোনাম দুটোই নতুন কোম্পানির।
        $response->assertSee('ফ্যামিলি মার্ট', false);
        $response->assertSee('প্রধান কার্যালয়', false);
    }

    public function test_a_company_without_a_logo_still_renders(): void
    {
        // লোগো ঐচ্ছিক — না থাকলে শুধু নামটা দেখাবে, ভাঙা ছবির আইকন নয়।
        Company::query()->update(['logo_path' => null]);

        $this->actingAs($this->owner())->get('/')->assertOk();
    }

    public function test_the_top_bar_has_a_full_screen_button(): void
    {
        $markup = $this->codeOf(resource_path('views/components/shell/topbar.blade.php'));
        $toggle = $this->codeOf(resource_path('views/components/shell/fullscreen-toggle.blade.php'));

        $this->assertStringContainsString('fullscreen-toggle', $markup);

        // অবস্থাটা document থেকে পড়া, নিজে মনে রাখা নয়: Esc বা F11 দিয়েও
        // ফুল-স্ক্রিন ছাড়া যায়, আর তখন নিজের রাখা boolean বাস্তবের সাথে
        // অমিল হয়ে বোতামটা ভুল আইকন দেখাত।
        $this->assertStringContainsString('fullscreenchange', $toggle);
        $this->assertStringContainsString('document.fullscreenElement', $toggle);
    }

    /** রেলে যে মডিউলগুলো সত্যিই বসে — মেনু থেকেই নেওয়া, হাতে লেখা নয়। */
    private function railModules(): array
    {
        return app(MenuBuilder::class)->forUser($this->owner());
    }

    /**
     * রেলে তাকিয়ে মডিউলটা চেনা যায়।
     *
     * ── প্রশ্নটা কী ──────────────────────────────────────────────────
     * রেলে কোনো লেখা নেই। বারোটা চিহ্ন উপর-নিচে বসে, আর ২০px-এ একরঙা
     * আউটলাইনে গুদাম আর বাক্স প্রায় একই ধূসর আকার। তাই দুইটা সংকেত:
     * আলাদা আঁকা, আর আলাদা রং। যেকোনো একটা মিলে গেলে ওই জোড়াটা চেনা
     * যায় না।
     *
     * ── কেন মেনু থেকে মডিউলের তালিকা ───────────────────────────────
     * হাতে লেখা তালিকায় নতুন মডিউল যোগ করতে ভুলে গেলে পরীক্ষাটা সবুজই
     * থাকত, আর রেলে একটা ফাঁকা ঘর বসত।
     */
    public function test_every_module_on_the_rail_can_be_told_apart(): void
    {
        $icons = $this->codeOf(resource_path('views/components/ui/icon.blade.php'));
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        $modules = $this->railModules();
        $this->assertGreaterThanOrEqual(8, count($modules), 'রেলে মডিউলই নেই।');

        $drawings = [];
        $colours = [];

        foreach ($modules as $module) {
            $code = $module['code'];

            // ১ · আঁকাটা আছে, আর ফাঁকা নয়
            $svg = Blade::render('<x-ui.icon name="'.$module['icon'].'" />');
            $this->assertStringContainsString('<svg', $svg, "{$code}: রেলে ফাঁকা ঘর বসবে।");
            $drawings[$code] = $svg;

            // ২ · রঙের টোকেনটা আছে — নাম হুবহু মডিউলের কোড
            $this->assertMatchesRegularExpression(
                '/--color-module-'.preg_quote($code, '/').'\s*:\s*(#[0-9a-f]{3,8})/i',
                $tokens,
                "{$code}: --color-module-{$code} টোকেনটা নেই, তাই টাইলটা ব্র্যান্ড নীলে পড়বে।",
            );

            preg_match('/--color-module-'.preg_quote($code, '/').'\s*:\s*(#[0-9a-f]{3,8})/i', $tokens, $m);
            $colours[$code] = strtolower($m[1]);
        }

        // ৩ · কেউ কারও আঁকা বা রং ভাগ করে না
        $this->assertSame(count($drawings), count(array_unique($drawings)),
            'দুইটা মডিউল একই আঁকা ব্যবহার করছে: '.implode(', ', array_keys($drawings)));
        $this->assertSame(count($colours), count(array_unique($colours)),
            'দুইটা মডিউল একই রং ব্যবহার করছে: '.json_encode($colours));

        $this->assertStringNotContainsString('$modules = [', $icons,
            'আইকন সেটে মডিউলের একটা আলাদা টেবিল ফিরে এসেছে।');
    }

    /**
     * রেলের রং আসে টোকেন থেকে, কোড ধরে — নাম ধরে নয়।
     *
     * শেল কোনো মডিউলের নাম বলতে পারে না (§১৯.৭)। আগে core-এ একটা
     * অনুবাদ টেবিল ছিল (accounts → finance, customer → crm), আর সেটাই
     * নিয়মটা ভাঙত। এখন টোকেনের নামই কোড, তাই শেল শুধু কোডটা বসায়।
     */
    public function test_the_rail_names_no_module_of_its_own(): void
    {
        $markup = $this->codeOf(resource_path('views/components/shell/sidebar.blade.php'));

        $this->assertStringContainsString('--color-module-{{ $module[\'code\'] }}', $markup,
            'রেল রংটা কোড ধরে নিচ্ছে না।');

        // টোকেন না থাকলেও পর্দা ভাঙে না
        $this->assertStringContainsString('var(--color-brand-600)', $markup,
            'টোকেন না থাকলে টাইলটা রংহীন হয়ে যাবে।');

        foreach ($this->railModules() as $module) {
            $this->assertStringNotContainsString("'".$module['code']."'", $markup,
                "সাইডবার {$module['code']} মডিউলের নাম ধরে কথা বলছে (§১৯.৭)।");
        }
    }

    public function test_the_sidebar_stays_put_while_the_page_scrolls(): void
    {
        $markup = $this->codeOf(resource_path('views/components/shell/sidebar.blade.php'));

        // ভেতরের nav-এ overflow-y-auto থাকলেও যথেষ্ট নয়: বাইরের aside-এর
        // উচ্চতা ভিউপোর্টে বাঁধা না থাকলে সেটা কনটেন্টের সাথে লম্বা হয়ে
        // যায় আর পুরো পাতার সাথে উপরে উঠে যায় — লোগো ও মেনু চোখের
        // বাইরে চলে যায়।
        $this->assertStringContainsString('sticky', $markup);
        $this->assertStringContainsString('h-dvh', $markup);
        $this->assertStringContainsString('overflow-y-auto', $markup);
    }

    public function test_the_top_bar_stays_put_too(): void
    {
        $markup = $this->codeOf(resource_path('views/components/shell/topbar.blade.php'));

        $this->assertStringContainsString('sticky', $markup);
    }

    public function test_the_shell_never_asks_the_server_whether_this_is_a_mobile(): void
    {
        // সেকশন ২০.৭ — ট্যাবলেট, ছোট করা উইন্ডো ও zoom সবই ভুল উত্তর দেয়।
        $files = [
            ...glob(resource_path('views/components/**/*.blade.php')),
            ...glob(resource_path('views/**/*.blade.php')),
        ];

        $this->assertNotEmpty($files, 'No Blade files were checked — the glob is wrong.');

        foreach ($files as $file) {
            $markup = $this->codeOf($file);

            $this->assertStringNotContainsString('isMobile', $markup, basename($file));
            $this->assertStringNotContainsString('user_agent', $markup, basename($file));
            $this->assertStringNotContainsString('userAgent', $markup, basename($file));
        }
    }
}
