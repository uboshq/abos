<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রশাসনের মডিউলটা বন্ধ করা যায় না — আর দরজাটা ভিতর থেকেও নয়।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"সিস্টেম এডমিন বাই ডিফল্ট কোনোভাবে বন্ধ হবে না। একটা লক করে দিও।"*
 *
 * ── ⛔ কী ভাঙত, আর কতটা নীরবে ─────────────────────────────────────────
 * প্রতিটা মডিউল নিজে থেকেই একটা `<code>.enabled` সুইচ পায়
 * ([[MenuSwitches::forModule()]]), আর কন্ট্রোল প্যানেলের প্রথম ট্যাবে
 * তার চেকবক্সও আঁকা হত। ⚠️ ওটা তুলে সংরক্ষণ করলে
 * [[RefuseSwitchedOffScreens]] `system_admin.` উপসর্গের **প্রতিটা** রুটে
 * ৪০৪ দিত — আর কন্ট্রোল প্যানেল নিজেই ঐ উপসর্গের ভিতরে।
 *
 * ⓘ অর্থাৎ এক ক্লিকে ইউজার, রোল, কোম্পানি, সেটিংস — সবটার দরজা বন্ধ,
 * চাবিটা ভিতরে। ⛔ ডাটাবেসে হাত না দিয়ে ফেরার কোনো পথ ছিল না।
 *
 * ── ⚠️ কেন চারটা দাবি, একটা নয় ───────────────────────────────────────
 * ⛔ একটা তালা যেটা **সব** সুইচ আটকায়, সে প্রথম দুইটা দাবিই পাস করত —
 * আর তখন ক্রেতা আর কোনো মডিউলই বন্ধ করতে পারতেন না। ⓘ শেষ দুইটা দাবি
 * ঠিক সেটাই মাপে: তালাটা যেন তালা হয়, দেয়াল না হয়।
 */
final class TheDoorCouldBeLockedFromTheInsideTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'system_admin.enabled';

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->settings = app(SettingsService::class);
    }

    /**
     * ⛔ ঠিক যে পাঠানোটা দরজাটা বন্ধ করত, সেটাই পাঠানো হয়।
     *
     * ── ⚠️ কেন ফর্মটা হুবহু নকল করতে হয় ──────────────────────────────
     * ⓘ চেকবক্স তুলে নিলে ব্রাউজার `settings[…]` পাঠায়ই না — কেবল
     * `scope[]`-এ নামটা যায়, আর সার্ভার অনুপস্থিতিকেই *"বন্ধ"* ধরে।
     * ⛔ তাই এই পাঠানোটা *"কিছু পাঠাইনি"* নয়, এটাই **বন্ধ করার
     * আদেশ** — আর পাহারাটা ঠিক এইটাই দেখতে বাধ্য।
     */
    public function test_the_control_panel_refuses_to_switch_off_its_own_module(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [self::KEY],
            'settings' => [],
        ])->assertRedirect();

        $this->settings->flush();

        $this->assertTrue(
            (bool) $this->settings->get(self::KEY, true),
            '⛔ প্রশাসনের মডিউলটা বন্ধ হয়ে গেছে — কন্ট্রোল প্যানেলের নিজের দরজাটাই।',
        );

        $this->get(route('system_admin.control-panel'))
            ->assertOk();
    }

    /**
     * ⭐ আর চাবিটা আগে থেকেই ডাটাবেসে `false` বসে থাকলেও দরজা খোলে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা করে লাগে ──────────────────────────────
     * ⓘ উপরের পাহারাটা **লেখার** দরজায়। ⛔ কিন্তু যে ডাটাবেসে চাবিটা
     * আগেই বসে গেছে (বা কেউ সরাসরি বসিয়েছেন), সেখানে ঐ পাহারা কিছুই
     * করে না — আর ওটাই ঠিক সেই অবস্থা যেখানে ফেরার পথ নেই।
     */
    public function test_a_switch_already_written_off_no_longer_closes_the_door(): void
    {
        $this->settings->set(self::KEY, false);
        $this->settings->flush();

        $this->get(route('system_admin.control-panel'))->assertOk();
        $this->get(route('system_admin.role.index'))->assertOk();
    }

    /**
     * ⛔ আর এটাই প্রথম পাল্টা-দাবি: তালাটা যেন **দেয়াল** না হয়।
     *
     * ⚠️ একটা কোড যেটা সব মডিউলের সুইচই ফেলে দেয়, সে উপরের দুইটা দাবি
     * পাস করত। ⓘ আর তখন ক্রেতা কন্ট্রোল প্যানেলে যা-ই চাপুন কিছুই
     * বদলাত না — নীরবে, কারণ পর্দা "সংরক্ষিত" বলত।
     */
    public function test_an_ordinary_module_can_still_be_switched_off(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['sales.enabled'],
            'settings' => [],
        ])->assertRedirect();

        $this->settings->flush();

        $this->assertFalse(
            (bool) $this->settings->get('sales.enabled', true),
            '⛔ সাধারণ মডিউলও বন্ধ করা যাচ্ছে না — তালাটা দেয়াল হয়ে গেছে।',
        );
    }

    /**
     * ⛔ আর দ্বিতীয় পাল্টা-দাবি: তালাটা **গোটা মডিউলে**, ভিতরের
     * পর্দাগুলোয় নয়।
     *
     * ⓘ মালিকের কথাটা মডিউল নিয়ে। ⚠️ ভিতরের পর্দাগুলোও আটকে দিলে
     * প্রশাসনের একটাও পর্দা আর বন্ধ করা যেত না — যে ব্যবসায় নোটিশ
     * বা রিপোর্টের সময়সূচি লাগে না, তাকেও ওগুলো বয়ে বেড়াতে হত।
     *
     * ── ⚠️ চাবির নামটা হাতে লেখা হয় না ──────────────────────────────
     * ⛔ `'menu.system_admin.notice.index'` টাইপ করে দেওয়া যেত। ⓘ কিন্তু
     * চাবিটা নিয়ম ধরে বানানো হয় ([[MenuSwitches::forItem()]]), আর কিছু
     * সারির নিজের **ঘোষিত** সুইচ থাকে যেটা নিয়মের চাবিকে হারায়।
     * ⚠️ ভুল একটা নাম টাইপ করলে সার্ভার সেটা *"অচেনা চাবি"* বলে নীরবে
     * ফেলে দিত — আর দাবিটা লাল হত এমন একটা কারণে যার সাথে তালার কোনো
     * সম্পর্ক নেই। ⭐ তাই নামটা ব্যবস্থার কাছ থেকেই নেওয়া হয়।
     */
    public function test_a_screen_inside_the_admin_module_can_still_be_switched_off(): void
    {
        $tree = collect(app(MenuSwitches::class)->tree())
            ->firstWhere('code', 'system_admin');

        $this->assertNotNull($tree, '⛔ প্রশাসনের মডিউলটাই সুইচের গাছে নেই — ভিতরের কিছুই বন্ধ করা যেত না।');

        $key = $tree['groups'][0]['items'][0]['key'];

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [$key],
            'settings' => [],
        ])->assertRedirect();

        $this->settings->flush();

        $this->assertFalse(
            (bool) $this->settings->get($key, true),
            '⛔ প্রশাসনের ভিতরের পর্দাও আর বন্ধ করা যাচ্ছে না — তালাটা দরকারের চেয়ে বড়।',
        );
    }
}
