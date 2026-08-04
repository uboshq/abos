<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertSee('আলফা ট্রেডার্স লিমিটেড', false);
        $response->assertSee('ঢাকা প্রধান কার্যালয়', false);
    }

    public function test_the_menu_is_built_from_module_files_and_is_translated(): void
    {
        $response = $this->actingAs($this->owner())->get('/');

        $response->assertSee('হিসাব ও অর্থ', false);
        $response->assertSee('জাবেদা ভাউচার', false);
        $response->assertSee('রেওয়ামিল', false);

        // কাঁচা অনুবাদ কী পাতায় থাকা মানে lang ফাইল নেই — নিয়ম ৯।
        $response->assertDontSee('::menu.', false);
    }

    public function test_the_menu_hides_what_a_role_cannot_reach(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $menu = app(MenuBuilder::class)->forUser($salesman);
        $codes = array_column($menu, 'code');

        // বিক্রয়কর্মীর accounts.* অনুমতি নেই, তাই মডিউলটাই তালিকায় নেই।
        // ধূসর করে দেখানো হয় না — সেটা শুধু জানায় সে কী পারে না।
        $this->assertContains('customer', $codes);
        $this->assertNotContains('accounts', $codes);
    }

    public function test_the_menu_groups_stay_in_the_same_order_everywhere(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner());
        $accounts = collect($menu)->firstWhere('code', 'accounts');

        // module.php-তে যে ক্রমেই লেখা হোক, প্রদর্শনের ক্রম এক — সেকশন ১৫.২।
        // নাহলে "একটা মডিউল শিখলে সব চেনা" কথাটা মিথ্যা হয়ে যায়।
        $this->assertSame(
            ['dashboard', 'master', 'transactions', 'reports', 'settings'],
            array_keys($accounts['groups']),
        );
    }

    public function test_switching_company_lands_on_the_dashboard_of_the_new_one(): void
    {
        $owner = $this->owner();
        $beta = Company::query()->where('code', 'BETA')->firstOrFail();

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
        $beta = Company::query()->where('code', 'BETA')->firstOrFail();
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

        $this->actingAs($owner->fresh())->get('/')->assertSee('Accounts &amp; Finance', false);
    }

    public function test_every_module_permission_is_registered_in_the_database(): void
    {
        $drift = app(PermissionSyncer::class)->drift();

        // ঘোষিত অথচ নিবন্ধিত নয় এমন অনুমতি মানে ওই মেনু আইটেম কেউ
        // কোনোদিন দেখবে না, আর কেন দেখবে না তার কোনো চিহ্নও থাকবে না।
        $this->assertSame([], $drift['unregistered']);
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
