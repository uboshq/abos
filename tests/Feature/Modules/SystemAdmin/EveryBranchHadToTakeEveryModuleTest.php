<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * এক শাখায় যে মডিউল লাগে না, সেটাও তাকে নিতে হত।
 *
 * ── ⓘ কেন, ২৮ নভেম্বর ২০২৬ ───────────────────────────────────────────
 * মডিউল বন্ধ করার সুইচ আগে থেকেই ছিল, কিন্তু কেবল **কোম্পানির** স্তরে।
 * ⚠️ ময়মনসিংহের ডিপোতে LC লাগে আর নেত্রকোনায় লাগে না — এই কথাটা বলার
 * কোনো উপায় ছিল না, তাই দুই ডিপোর মানুষই একই ১৯০ সারির মেনু পেতেন।
 *
 * ── ⛔ এই পাহারাটা আসলে কী ধরে ────────────────────────────────────────
 * তিনটা দাবি, আর তিনটাই আলাদাভাবে ভুল হতে পারত:
 *
 *   ১. শাখায় বন্ধ করলে **ঐ শাখার** মেনু থেকে মডিউলটা যায়
 *   ২. **অন্য শাখার** মেনু অক্ষত থাকে ← আসল কথাটা এটাই
 *   ৩. শাখার টিক দিয়ে কোম্পানির বন্ধ সুইচ **খোলা যায় না**
 *
 * ⚠️ (২) ছাড়া (১) অর্থহীন: একটা বাগ যেটা সব শাখার মেনু কেটে দেয়,
 * সেটাও (১) পাস করত।
 *
 * ── ⓘ আর পাহারাটা নিজে অন্ধ কি না ────────────────────────────────────
 * ⛔ `test_the_menu_reader_can_tell_the_two_apart()` — সারি বসানোর
 * **আগে** মডিউলটা মেনুতে আছে কি না সেটাও মাপা হয়। ⚠️ না মাপলে একটা
 * ভুল কোড-নাম (যেটা কোনোদিন কোনো মেনুতেই ছিল না) এই পুরো ফাইলটাকে
 * সবুজ রাখত।
 */
final class EveryBranchHadToTakeEveryModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $here;

    private Branch $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->here = Branch::query()->where('company_id', $company->id)
            ->where('code', 'MMS')->firstOrFail();
        $this->there = Branch::query()->where('company_id', $company->id)
            ->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($company->id, $this->here->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    // ── ১ ও ২ · এক শাখায় বন্ধ, অন্যটায় নয় ────────────────────────────

    public function test_switching_a_module_off_empties_only_that_branch(): void
    {
        $code = $this->aModuleInTheMenu();

        $this->switchOff($code, $this->here);

        $this->assertNotContains($code, $this->menuCodesIn($this->here),
            'শাখায় বন্ধ করার পরেও মডিউলটা ঐ শাখার মেনুতে রয়ে গেছে।');

        $this->assertContains($code, $this->menuCodesIn($this->there),
            'এক শাখায় বন্ধ করায় **অন্য শাখার** মেনুও কেটে গেছে — '
            .'অর্থাৎ সুইচটা শাখার নয়, কোম্পানির মতো আচরণ করছে।');
    }

    /**
     * ⛔ পাহারাটা সত্যিই তাকায় কি না।
     *
     * ⚠️ উপরের পরীক্ষাটার `assertNotContains` একটা **ভুল কোড-নামেও**
     * পাস করত। ⓘ তাই এখানে দুই অবস্থাই মাপা হয়: সারি বসানোর আগে
     * মডিউলটা মেনুতে থাকতেই হবে, নাহলে অনুপস্থিতির কোনো মানে নেই।
     */
    public function test_the_menu_reader_can_tell_the_two_apart(): void
    {
        $code = $this->aModuleInTheMenu();

        $this->assertContains($code, $this->menuCodesIn($this->here),
            'শুরুতেই মডিউলটা মেনুতে নেই — তাহলে পরের পরীক্ষাগুলো কিছুই মাপছে না।');

        $this->switchOff($code, $this->here);

        $this->assertNotContains($code, $this->menuCodesIn($this->here));
    }

    /** ⓘ সারি না থাকা মানে চালু — মাইগ্রেশনটা চালানোমাত্র মেনু খালি হয়নি। */
    public function test_a_branch_with_no_rows_keeps_every_module(): void
    {
        $this->assertSame(0, BranchModule::query()->count());

        $this->assertNotSame([], $this->menuCodesIn($this->here));
        $this->assertSame($this->menuCodesIn($this->here), $this->menuCodesIn($this->there));
    }

    // ── ৩ · শাখা কোম্পানির উপরে উঠতে পারে না ─────────────────────────

    public function test_a_branch_cannot_open_what_the_company_closed(): void
    {
        $code = $this->aModuleInTheMenu();

        // ⓘ কোম্পানির স্তরে বন্ধ, আর শাখায় স্পষ্ট করে **চালু** বসানো।
        app(SettingsService::class)->set($code.'.enabled', false);

        BranchModule::create([
            'branch_id' => $this->here->id,
            'module' => $code,
            'is_enabled' => true,
        ]);

        $this->assertNotContains($code, $this->menuCodesIn($this->here),
            'শাখার টিক কোম্পানির বন্ধ সুইচটা ছাপিয়ে গেছে — '
            .'অর্থাৎ একটা ডিপো এমন মডিউল খুলে ফেলতে পারে যেটা প্রতিষ্ঠান নেয়নি।');
    }

    // ── পর্দাটা ────────────────────────────────────────────────────────

    public function test_the_screen_saves_only_what_it_showed(): void
    {
        $code = $this->aModuleInTheMenu();

        $other = collect($this->menuCodesIn($this->here))
            ->first(fn (string $c) => $c !== $code);

        $this->assertNotNull($other, 'তুলনা করার মতো দ্বিতীয় মডিউল নেই।');

        /*
         * ⚠️ ফর্মে কেবল একটা সারি, আর সেটা বন্ধ করা হচ্ছে। ⓘ `$other`
         * ফর্মেই ছিল না — "চেকবক্স নেই মানে বন্ধ" ধরলে ওটাও নীরবে বন্ধ
         * হয়ে যেত, আর ৩০ আগস্ট ২০২৬-এ কন্ট্রোল প্যানেলে ঠিক সেটাই হয়েছিল।
         */
        $this->actingAs($this->owner)
            ->put(route('system_admin.branch-module.update'), [
                'branch' => $this->here->id,
                'scope' => [$code],
                'modules' => [],
            ])
            ->assertRedirect();

        $this->assertNotContains($code, $this->menuCodesIn($this->here));
        $this->assertContains($other, $this->menuCodesIn($this->here),
            'ফর্মে না থাকা মডিউলটাও বন্ধ হয়ে গেছে।');
    }

    public function test_the_screen_refuses_a_branch_from_another_company(): void
    {
        $elsewhere = Branch::acrossAllCompanies()
            ->where('company_id', '!=', CompanyContext::id())
            ->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('system_admin.branch-module.update'), [
                'branch' => $elsewhere->id,
                'scope' => [$this->aModuleInTheMenu()],
                'modules' => [],
            ])
            ->assertNotFound();

        $this->assertSame(0, BranchModule::acrossAllCompanies()->count());
    }

    /**
     * ⛔ যে মডিউলটা এই পর্দাটাই ধরে আছে, সেটা বন্ধ করা যায় না।
     *
     * ⚠️ পারলে কেউ নিজের শাখায় এটা বন্ধ করতেন, আর তারপর **ফিরিয়ে আনার
     * পর্দাটাই** মেনুতে থাকত না।
     */
    public function test_the_screen_will_not_switch_off_its_own_module(): void
    {
        /*
         * ⓘ নামটা হাতে লেখা নয় — রুটের নামের উপসর্গ থেকেই আসে, ঠিক যেভাবে
         * [[BranchModuleController::myOwnModule()]] বের করে। ⚠️ রুটের নাম
         * বদলালে এই পরীক্ষাটা কোডের সাথেই বদলায়, পিছিয়ে পড়ে না।
         */
        $name = 'system_admin.branch-module';

        $this->assertNotNull(Route::getRoutes()->getByName($name),
            'রুটটার নামই বদলে গেছে — তাহলে কোন মডিউলটা ধরা থাকার কথা, সেটাই জানা যায় না।');

        $mine = (string) strstr($name, '.', true);

        $this->actingAs($this->owner)
            ->put(route('system_admin.branch-module.update'), [
                'branch' => $this->here->id,
                'scope' => [$mine],
                'modules' => [],
            ])
            ->assertRedirect();

        $this->assertSame(0, BranchModule::query()->where('module', $mine)->count(),
            'নিজের পর্দাটাই বন্ধ হয়ে গেছে — ফিরিয়ে আনার পথ আর নেই।');

        $this->assertContains($mine, $this->menuCodesIn($this->here));
    }

    /**
     * ⛔ পর্দাটা সত্যিই আঁকা হয় কি না।
     *
     * ── ⚠️ কেন এটা আলাদা করে দরকার ছিল ──────────────────────────────
     * উপরের পরীক্ষাগুলো সব `PUT` করে, আর নিচেরটা ৪০৩ মাপে — অর্থাৎ
     * **ব্লেডটা একবারও রেন্ডার হয় না**। ⓘ একটা টাইপো, একটা না-থাকা
     * অনুবাদের কী, একটা ভুল কম্পোনেন্টের নাম — সব সবুজ থাকত, আর
     * মালিক পর্দায় গিয়ে সাদা পাতা পেতেন।
     *
     * ⓘ তাই এখানে সত্যিই আঁকা হয়, আর ভিতরের ঘরগুলো গোনা হয়।
     */
    public function test_the_screen_actually_draws(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('system_admin.branch-module'))
            ->assertOk()
            ->getContent();

        // ⓘ দুইটা শাখাই ট্যাব হিসেবে আছে — নাহলে একটাতে যাওয়ার পথই নেই।
        $this->assertStringContainsString($this->here->name(), $html);
        $this->assertStringContainsString($this->there->name(), $html);

        preg_match_all('/name="modules\[([^\]]+)\]"/', $html, $m);

        $onScreen = $m[1];

        $this->assertSame(
            array_keys(app(ModuleRegistry::class)->all()),
            $onScreen,
            'পর্দার সারিগুলো রেজিস্ট্রির সাথে মেলে না — কোনো মডিউলের সুইচই নেই, '
            .'অথবা একটা দুইবার আছে।'
        );

        // ⛔ যে মডিউলটা পর্দাটাই ধরে আছে, তার ঘরটা ধরা (`disabled`) থাকার কথা।
        $this->assertMatchesRegularExpression(
            '/name="modules\[system_admin\]"[^>]*disabled/s',
            $html,
            'নিজের মডিউলের ঘরটা খোলা — পর্দায় বসেই কেউ পর্দাটা বন্ধ করে ফেলতে পারেন।'
        );
    }

    public function test_the_screen_is_behind_the_settings_key(): void
    {
        $stranger = User::query()->where('email', '!=', 'owner@abos.test')
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->get()
            ->first(fn (User $u) => ! $u->can('system_admin.settings.manage'));

        $this->assertNotNull($stranger, 'যার চাবি নেই এমন কেউ নেই — পরীক্ষাটা কিছু মাপছে না।');

        $this->actingAs($stranger)
            ->get(route('system_admin.branch-module'))
            ->assertForbidden();
    }

    /**
     * ⭐ ফোনটাও একই সুইচ মানে — আর সেটা আলাদা করে মাপা হয়।
     *
     * ── ⓘ কেন ধরে নেওয়া যেত না ──────────────────────────────────────
     * [[MeController]] ওয়েবের সেই একই [[MenuBuilder::forUser()]] ডাকে,
     * তাই "এমনিতেই কাজ করবে" বলা যেত। ⚠️ কিন্তু *"একই ফাংশন ডাকে"* আর
     * *"একই উত্তর পায়"* এক কথা নয়: ফোনের উত্তরটা **ব্যবহারকারীর নিজের
     * শাখা** ধরে তৈরি হয় (`current_branch_id`), পর্দার মতো চলতি
     * প্রসঙ্গ ধরে নয়।
     *
     * ⛔ তাই এখানে সত্যিই লগইন করে `/me` চাওয়া হয়। ⓘ না করলে দাবিটা
     * মাপা নয়, অনুমান থাকত — আর ফোনে ভুল মেনু মানে একজন বিক্রয়কর্মী
     * এমন পর্দা পান যেটা তাঁর ডিপোতে নেই।
     */
    public function test_the_phone_honours_the_branch_switch_too(): void
    {
        $phoneUser = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $phoneUser->forceFill([
            'current_company_id' => $this->here->company_id,
            'current_branch_id' => $this->here->id,
        ])->save();

        $before = $this->menuOverTheWire();

        $this->assertNotSame([], $before, 'ফোন কোনো মডিউলই পায়নি — মাপার কিছু নেই।');

        // ⓘ ফোন যেটা সত্যিই পায় তেমন একটা মডিউল, নাহলে অনুপস্থিতির মানে নেই।
        $code = $before[0];

        $this->switchOff($code, $this->here);

        $this->assertNotContains($code, $this->menuOverTheWire(),
            'শাখায় বন্ধ করা মডিউলটা ফোনে এখনো যাচ্ছে।');
    }

    /**
     * ⓘ `/me`-র মেনুতে যে মডিউলগুলো এসেছে।
     *
     * @return list<string>
     */
    private function menuOverTheWire(): array
    {
        /*
         * ⚠️ প্রতিবার গার্ডটা ভুলিয়ে দিতে হয়। ⓘ লগইন সফল হলে Laravel ঐ
         * অনুরোধের গার্ডে মানুষটাকে মনে রেখে দেয়, আর পরের `/me` তখন
         * **আগের অবস্থার** উত্তর ফেরত দিত — দুইটা অনুরোধই ২০০, তাই
         * ভুলটা নীরব ([[ThePhoneCouldNotAskWhoItWasTest]]-এ কারণ লেখা)।
         */
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test',
            'password' => 'password',
            'deviceId' => 'branch-module-test',
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ])->json('accessToken');

        $this->app['auth']->forgetGuards();

        $menu = $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->json('menu');

        return array_values(array_column($menu ?? [], 'code'));
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /**
     * ⓘ একটা মডিউলের কোড যেটা সত্যিই এই কোম্পানির মেনুতে আছে, আর
     * যেটা এই পর্দাটার নিজের মডিউল নয়।
     */
    private function aModuleInTheMenu(): string
    {
        $code = collect($this->menuCodesIn($this->here))
            ->first(fn (string $c) => $c !== 'system_admin');

        $this->assertNotNull($code, 'মেনুতে বন্ধ করার মতো কোনো মডিউলই নেই।');

        return $code;
    }

    private function switchOff(string $code, Branch $branch): void
    {
        BranchModule::create([
            'branch_id' => $branch->id,
            'module' => $code,
            'is_enabled' => false,
        ]);
    }

    /**
     * ⚠️ প্রতিবার **নতুন** [[MenuBuilder]] — পুরনোটা এই অনুরোধের উত্তরটা
     * মনে রেখে দেয় (`$branchOff`), আর তাহলে দ্বিতীয় শাখার মাপটা প্রথম
     * শাখার উত্তরই ফেরত দিত।
     *
     * @return list<string>
     */
    private function menuCodesIn(Branch $branch): array
    {
        $was = CompanyContext::branchId();

        CompanyContext::set($branch->company_id, $branch->id);

        $codes = array_column(app()->make(MenuBuilder::class)->forUser($this->owner), 'code');

        CompanyContext::set($branch->company_id, $was);

        return array_values($codes);
    }
}
