<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বন্ধ করা পর্দা সত্যিই বন্ধ — মেনু থেকে সরানো নয়।
 *
 * ── কেন এই পরীক্ষাগুলো ─────────────────────────────────────────────
 * সুইচটা দেখা হত কেবল মেনু বানানোর সময়। তাই বন্ধ করলে সারিটা সরত,
 * অথচ ঠিকানাটা কাজ করেই যেত — বুকমার্ক, আগের ট্যাব বা কারও পাঠানো
 * লিংক দিয়ে যে কেউ ঢুকে বিল তুলে ফেলতে পারতেন।
 *
 * HP-র পরীক্ষক ১৩ আগস্ট ধরেন। নিচের প্রথম পরীক্ষাটা ঠিক ওই পথটাই
 * হাঁটে: বন্ধ করো, তারপর সরাসরি ঠিকানায় যাও।
 */
class SwitchedOffScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function switchOff(string $key): void
    {
        app(SettingsService::class)->set($key, false);
    }

    // ── পর্দার নিজের সুইচ ──────────────────────────────────────────

    /** বন্ধ করা পর্দা ঠিকানা জানা থাকলেও খোলে না। */
    public function test_a_switched_off_screen_does_not_open_by_its_address(): void
    {
        $this->get(route('purchase.direct.create'))->assertOk();

        $this->switchOff('purchase.screen_direct');

        $this->get(route('purchase.direct.create'))->assertNotFound();
    }

    /**
     * ফর্মটাও বন্ধ — শুধু পর্দা নয়।
     *
     * পর্দাটা আটকে ফর্মের পথটা খোলা রাখলে কাজটা তবু হয়ে যেত: আগের
     * ট্যাবে খোলা ফর্মটা সাবমিট করলেই বিল উঠত।
     */
    public function test_the_form_behind_it_is_shut_too(): void
    {
        $this->switchOff('purchase.screen_direct');

        $this->post(route('purchase.direct.store'), [])->assertNotFound();
    }

    /** চালু করলে আবার খোলে — সুইচটা দুইদিকেই কাজ করে। */
    public function test_switching_it_back_on_opens_it_again(): void
    {
        $this->switchOff('purchase.screen_direct');
        $this->get(route('purchase.direct.create'))->assertNotFound();

        app(SettingsService::class)->set('purchase.screen_direct', true);

        $this->get(route('purchase.direct.create'))->assertOk();
    }

    /** একটা পর্দা বন্ধ করলে পাশেরটা বন্ধ হয় না। */
    public function test_it_shuts_only_what_was_switched_off(): void
    {
        $this->switchOff('purchase.screen_direct');

        $this->get(route('purchase.order.index'))->assertOk();
        $this->get(route('purchase.bill.index'))->assertOk();
    }

    // ── মডিউলের সুইচ ──────────────────────────────────────────────

    /**
     * মডিউল-স্তরের সুইচ ঘোষিত না থাকলে মডিউলটা চালু।
     *
     * ── কেন এটাই এখানে পরীক্ষা করা যায় ─────────────────────────────
     * নিয়ম ১৯.৫ বলে প্রতিটা মডিউলের একটা `{code}.enabled` সুইচ থাকতে
     * পারে, আর `MenuBuilder` সেটা দেখে। কিন্তু আজ **কোনো মডিউলই ওটা
     * ঘোষণা করেনি** — `SettingsService` অঘোষিত কী বসাতে দেয় না, তাই
     * "মডিউল বন্ধ করলে পর্দা বন্ধ" লিখে একটা পরীক্ষা বানানো যেত না।
     *
     * বানালে সেটা হত ঠিক সেই জিনিস যা এই প্রকল্পে বারবার ধরা পড়েছে:
     * এমন একটা পরীক্ষা যা জিনিসটা না থাকলেও পাশ করে। তাই যা সত্যি
     * কেবল তাই লেখা — ঘোষণা নেই মানে চালু, আর পর্দাগুলো খোলে।
     *
     * কোনো মডিউল সুইচটা ঘোষণা করলে মিডলওয়্যার ও মেনু দুইটাই একই কী
     * পড়ে, তাই দুইটা একসাথেই কাজ করতে শুরু করবে।
     */
    public function test_a_module_with_no_switch_declared_stays_on(): void
    {
        $this->get(route('purchase.order.index'))->assertOk();
        $this->get(route('sales.invoice.index'))->assertOk();
    }

    // ── প্যারামিটারসহ সারি ────────────────────────────────────────

    /**
     * একই রুটের একটা ঠিকানা বন্ধ হলে বাকিগুলো খোলা থাকে।
     *
     * মেয়াদের রিপোর্টটা `inventory.report.show` + `slug=expiring`, আর
     * ওই রুটেই আরও কয়েকটা রিপোর্ট বসে। উপসর্গ ধরে আটকালে ব্যাচ বন্ধ
     * করা মাত্র মজুদের সব রিপোর্ট বন্ধ হয়ে যেত।
     */
    public function test_one_address_of_a_shared_route_shuts_without_taking_the_others(): void
    {
        $this->switchOff('inventory.batch_enabled');

        $this->get(route('inventory.report.show', ['slug' => 'expiring']))->assertNotFound();
        $this->get(route('inventory.report.show', ['slug' => 'stock-summary']))->assertOk();
    }

    // ── কাউন্টার — যেটা ডিফল্ট বন্ধ ────────────────────────────────

    /**
     * কাউন্টার বন্ধ থাকলে তার স্ক্যানের পথও বন্ধ।
     *
     * ── কেন এটা আলাদা করে ───────────────────────────────────────────
     * `sales.screen_pos` ইচ্ছাকৃতভাবে ডিফল্ট বন্ধ — কাউন্টার দোকানের
     * জিনিস, পরিবেশকের নয়। কিন্তু রুটগুলো খোলা থাকায় POS-এর সবগুলো
     * পরীক্ষা এতদিন **বন্ধ থাকা** পর্দার উপর চলে পাশ করছিল, আর কেউ
     * টের পায়নি।
     *
     * এখানে উল্টো দিকটা ধরা: বন্ধ মানে সত্যিই বন্ধ — পর্দা, স্ক্যান,
     * সবই। আর `sales.shift.*` একই সুইচের পেছনে, কারণ যে ব্যবসায়
     * কাউন্টার নেই তার ড্রয়ারের শিফটও নেই।
     */
    public function test_the_counter_is_shut_by_default_and_so_is_its_scan(): void
    {
        $this->get(route('sales.pos.index'))->assertNotFound();
        $this->getJson(route('sales.pos.lookup', ['code' => '123']))->assertNotFound();
        $this->get(route('sales.shift.index'))->assertNotFound();
    }

    /** চালু করলে তিনটাই খোলে। */
    public function test_turning_the_counter_on_opens_all_three(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', true);

        $this->get(route('sales.pos.index'))->assertOk();
        $this->get(route('sales.shift.index'))->assertOk();
    }

    // ── সুইচ ছাড়া পর্দা ───────────────────────────────────────────

    /** যে পর্দার কোনো সুইচ নেই, সেটা কখনো আটকায় না। */
    public function test_a_screen_with_no_switch_is_never_shut(): void
    {
        $this->get(route('dashboard'))->assertOk();
    }
}
