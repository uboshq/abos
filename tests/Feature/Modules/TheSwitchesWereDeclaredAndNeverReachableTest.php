<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সুইচগুলো ঘোষিত ছিল, নাগালে ছিল না।
 *
 * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * মডিউলগুলো `module.php`-তে ৭৪টা সুইচ ঘোষণা করত, আর **৬৯টার কোনো পর্দাই
 * ছিল না** — কেবল Accounts-এর পাঁচটার ছিল।
 *
 * ⓘ লাইভের `settings` টেবিলে **মোট তিনটা সারি**; কারণটা এটাই। ⚠️ সুইচ
 * থাকা আর সুইচ টেপা যাওয়া এক জিনিস নয়।
 *
 * ── কেন এটা রুচির কথা নয় ────────────────────────────────────────────
 * মালিক বললেন *"limit nai bill hobe na"*, আর নিয়মটার সুইচ
 * (`customer.zero_limit_blocks`) আগে থেকেই ছিল — **শুধু টেপার জায়গা
 * ছিল না**। ⛔ সার্ভার থেকে টেপা যেত, এই এক ইনস্ট্যান্সে, একবার।
 *
 * ⚠️ মালিকের নিজের নিয়ম: *"যে ধাপটা কেবল মালিক করতে পারেন, সেটা প্রতিটা
 * ক্রেতার জন্য অসমাপ্ত।"*
 *
 * ── ⭐ এই ফাইলটা যা পাহারা দেয় ──────────────────────────────────────
 * পর্দাটা আছে — সেটা এক কথা। ⓘ আসল পাহারা দুইটা:
 *
 *     ১  নতুন কোনো সুইচ **অনুবাদ ছাড়া** পর্দায় উঠে আসে না
 *     ২  Control Panel-এর সুইচগুলো এখানে **আসে না** — ওখানে একটা
 *        পাহারা আছে (`holds`) যা এখানে নেই
 *
 * ⛔ দ্বিতীয়টা নিরাপত্তার: ওগুলো এখানে তুললে দশটা ঝুলন্ত চালানওয়ালা
 * কোম্পানির চালান-পর্দা কেউ বন্ধ করে দিতে পারতেন, আর ওই কাগজগুলোর আর
 * কোনো দরজা থাকত না।
 */
class TheSwitchesWereDeclaredAndNeverReachableTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->admin = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    // ── পর্দাটা আছে, আর খোলে ────────────────────────────────────────────

    public function test_the_screen_opens(): void
    {
        $this->actingAs($this->admin)
            ->get(route('system_admin.settings'))
            ->assertOk();
    }

    /**
     * ⛔ যাঁর চাবি নেই, তাঁর জন্য দরজা বন্ধ।
     *
     * ⓘ এটা একটা প্রতিষ্ঠানের নিয়ম বদলানোর পর্দা — কে কত দিন পিছনের
     * তারিখে লিখতে পারবেন, কার বাকি বন্ধ হবে। ⚠️ পর্দাটা যোগ করার সময়
     * চাবিটা বসাতে ভুলে গেলে **যে কেউ** ওগুলো বদলাতে পারতেন।
     */
    public function test_someone_without_the_key_is_refused(): void
    {
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail())
            ->get(route('system_admin.settings'))
            ->assertForbidden();
    }

    /** ⭐ আর মালিকের সেই সুইচটা সত্যিই ওখানে দেখা যায়। */
    public function test_the_switch_the_owner_asked_for_is_on_the_screen(): void
    {
        $this->actingAs($this->admin)
            ->get(route('system_admin.settings'))
            ->assertSee('settings[customer.zero_limit_blocks]', false)
            ->assertSee(__('customer::settings.zero_limit_blocks'), false);
    }

    // ── টেপা যায়, আর সেটা কোম্পানিতেই থাকে ─────────────────────────────

    /** সুইচ টিপলে সারিটা সত্যিই বসে — চলতি কোম্পানির নামে। */
    public function test_pressing_a_switch_writes_a_row_for_this_company(): void
    {
        $this->assertFalse(
            app(SettingsService::class)->enabled('customer.zero_limit_blocks'),
            'শুরুতেই সুইচটা চালু — তাহলে নিচের দাবিটা কিছুই প্রমাণ করত না।',
        );

        $this->actingAs($this->admin)
            ->put(route('system_admin.settings.update'), [
                'settings' => ['customer.zero_limit_blocks' => '1'],
            ])
            ->assertRedirect();

        $row = Setting::query()
            ->where('company_id', $this->company->id)
            ->where('key', 'customer.zero_limit_blocks')
            ->first();

        $this->assertNotNull($row, 'সুইচ টেপা হলো অথচ কোনো সারি বসেনি।');

        $this->assertTrue(
            app(SettingsService::class)->enabled('customer.zero_limit_blocks'),
            'সারিটা বসেছে কিন্তু ব্যবস্থা তা পড়ছে না।',
        );
    }

    /**
     * ⛔ এক কোম্পানির সিদ্ধান্ত অন্য কোম্পানিকে ছোঁয় না।
     *
     * ⚠️ `settings` টেবিলে `company_id` আছে, কিন্তু লেখার সময় সেটা
     * প্রসঙ্গ থেকে আসে — আর প্রসঙ্গ ভুল হলে **একজনের সুইচ সবার উপর
     * বসত**, নীরবে। ⓘ বহু-টেন্যান্টে এটা সুবিধা নয়, শর্ত।
     */
    public function test_one_companys_choice_leaves_the_other_alone(): void
    {
        $this->actingAs($this->admin)
            ->put(route('system_admin.settings.update'), [
                'settings' => ['customer.zero_limit_blocks' => '1'],
            ])->assertRedirect();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany($other->id, function () {
            $this->assertFalse(
                app(SettingsService::class)->enabled('customer.zero_limit_blocks'),
                "এক কোম্পানিতে সুইচ টেপায় অন্য কোম্পানিতেও চালু হয়ে গেছে।\n"
                .'অর্থাৎ এক ক্রেতার সিদ্ধান্ত আরেক ক্রেতার কাউন্টার বন্ধ করে দিত।',
            );
        });
    }

    // ── ⛔ পাহারা ১ · Control Panel-এর সুইচ এখানে আসে না ───────────────

    /**
     * ⛔ পর্দা লুকানোর সুইচগুলো এখানে **নেই**, আর থাকা চলবে না।
     *
     * ⓘ ওগুলোর সাথে `holds` বাঁধা: [[ControlPanelController]] সারি গুনে
     * দেখে, আর কাগজ থাকলে পর্দাটা আড়াল করতে দেয় না। ⚠️ এই পর্দায়
     * সেই পাহারাটা নেই — তাই সুইচটাও থাকতে পারে না।
     *
     * ⛔ থাকলে কেউ দশটা ঝুলন্ত চালানওয়ালা কোম্পানির চালান-পর্দা বন্ধ
     * করে দিতেন, আর ওই দশটা কাগজের আর কোনো দরজা থাকত না — বাতিলও নয়,
     * শেষও নয়, শুধু অদৃশ্য।
     */
    public function test_the_screen_switches_stay_in_the_control_panel(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('system_admin.settings'))->getContent();

        $leaked = [];

        foreach (app(SettingsService::class)->definitions() as $key => $definition) {
            $isControlPanels = ($definition['menu'] ?? false)
                || ($definition['group'] ?? 'general') === 'screens';

            if ($isControlPanels && str_contains($html, 'settings['.$key.']')) {
                $leaked[] = $key;
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "এই সুইচগুলো কন্ট্রোল প্যানেলের, অথচ এই পর্দায় এসে গেছে।\n"
            ."ওখানে একটা পাহারা আছে (`holds`) যা এখানে নেই — কাগজ ধরা\n"
            .'পর্দাও আড়াল করা যেত, আর ওই কাগজগুলোর কোনো দরজা থাকত না।',
        );
    }

    // ── ⛔ পাহারা ২ · অনুবাদহীন কিছু পর্দায় ওঠে না ─────────────────────

    /**
     * ⭐ প্রতিটা লেবেল ও গ্রুপের নাম অনুবাদ করা আছে।
     *
     * ── কেন এই দাবিটা সবচেয়ে বেশি কাজে লাগবে ────────────────────────
     * ⓘ পর্দাটা `module.php` থেকে নিজে থেকে তৈরি হয়। ⚠️ তাই আগামীকাল
     * কেউ একটা নতুন সুইচ ঘোষণা করলে সেটা **নিজে থেকেই এখানে এসে যাবে** —
     * অনুবাদ থাকুক বা না থাকুক।
     *
     * ⛔ অনুবাদ না থাকলে পর্দায় কাঁচা চাবিটা দেখা যায়
     * (`purchase::settings.some_new_flag`), আর ব্যবহারকারী একটা সুইচ
     * দেখেন যার মানে কেউ কোনোদিন বলেনি।
     *
     * ⓘ এই ভুলটা আজ সত্যিই হয়েছিল — প্রথম খসড়ায় মেনু-সুইচগুলো ছাঁকিনি,
     * আর পর্দায় `menu.system_admin.master` জাতীয় বাইশটা কাঁচা চাবি
     * উঠে এসেছিল। ধরা পড়েছে মেপে, তাকিয়ে নয়।
     */
    public function test_nothing_reaches_the_screen_without_a_translation(): void
    {
        $controller = app(\App\Modules\SystemAdmin\Http\Controllers\SettingsController::class);
        $method = new \ReflectionMethod($controller, 'byModule');

        $raw = [];

        foreach ($method->invoke($controller) as $module) {
            foreach ($module['groups'] as $group => $settings) {
                $groupKey = 'core.settings_group.'.$group;

                if (__($groupKey) === $groupKey) {
                    $raw[] = 'গ্রুপ: '.$group;
                }

                foreach ($settings as $setting) {
                    if (__($setting['label']) === $setting['label']) {
                        $raw[] = 'লেবেল: '.$setting['key'];
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($raw)),
            "এগুলোর অনুবাদ নেই, তাই পর্দায় কাঁচা চাবিটাই দেখা যাবে।\n"
            ."নতুন সুইচ ঘোষণা করলে তার লেবেলটা একই সাথে লিখতে হয় —\n"
            .'গ্রুপের নাম `lang/*/core.php`-এর `settings_group`-এ।',
        );
    }

    /**
     * ⓘ পাহারাটা শূন্য জায়গা পাহারা দিচ্ছে না।
     *
     * ⚠️ উপরের দুইটা দাবি খালি তালিকাতেও সবুজ থাকত — যদি পর্দাটা
     * **কিছুই** না দেখাত। ⛔ তখন সুইচগুলো ঠিক আগের মতোই নাগালের বাইরে
     * থাকত, অথচ সুইট বলত সব ঠিক আছে।
     */
    public function test_the_screen_actually_shows_most_of_the_declared_switches(): void
    {
        $controller = app(\App\Modules\SystemAdmin\Http\Controllers\SettingsController::class);
        $method = new \ReflectionMethod($controller, 'byModule');

        $shown = 0;
        $modules = 0;

        foreach ($method->invoke($controller) as $module) {
            $modules++;

            foreach ($module['groups'] as $settings) {
                $shown += count($settings);
            }
        }

        $this->assertGreaterThanOrEqual(60, $shown,
            "পর্দাটা প্রায় কিছুই দেখাচ্ছে না — ৭ সেপ্টেম্বরে ছিল ৬৬টা।\n"
            .'সুইচ কমে থাকলে সংখ্যাটা নামান; ছাঁকনি ভেঙে থাকলে ছাঁকনিটা দেখুন।');

        $this->assertGreaterThanOrEqual(8, $modules,
            'দশটার কম মডিউল দেখাচ্ছে — অথচ দশটা মডিউল সুইচ ঘোষণা করে।');
    }
}
