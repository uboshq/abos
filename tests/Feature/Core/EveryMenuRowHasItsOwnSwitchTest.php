<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা মেনু সারির নিজের সুইচ — আর সুইচটা সত্যিই দরজা বন্ধ করে।
 *
 * ── মালিকের নির্দেশ, ৩০ আগস্ট ২০২৬ ───────────────────────────────────
 * *"সব কটা মডিউল অন-অফ করা যাবে, মডিউলের ভিতরে সাবমডিউল, সব মেনু
 * অন-অফ করা যাবে, সাব মেনুও।"*
 *
 * আগে সুইচ পেত কেবল সেই সারিগুলো যারা `module.php`-তে নিজে একটা
 * `setting` ঘোষণা করেছিল — একশোর বেশি সারির মধ্যে হাতেগোনা কয়েকটা।
 *
 * ── আর এই ফাইলটার আসল কাজ ───────────────────────────────────────────
 * সুইচটা যেন **আড়াল না হয়, বাধা হয়**। ১৩ আগস্ট HP ধরেছিল: সারিটা
 * মেনু থেকে সরত, কিন্তু ঠিকানা টাইপ করলে পর্দাটা খুলেই যেত। নতুন
 * একশো সুইচে ওই ভুলটা একশো গুণ হত।
 */
class EveryMenuRowHasItsOwnSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function switches(): MenuSwitches
    {
        return app(MenuSwitches::class);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /** মেনুতে এখন কোন রুটগুলো দেখা যায়। */
    private function visibleRoutes(): array
    {
        $out = [];

        foreach (app(MenuBuilder::class)->forUser($this->user) as $module) {
            foreach ($module['groups'] as $items) {
                foreach ($items as $item) {
                    $out[] = $item['route'];
                }
            }
        }

        return $out;
    }

    /**
     * গাছটা রেজিস্ট্রি থেকেই আসে — হাতে লেখা তালিকা নয়।
     *
     * হাতে লিখলে ১০১তম পর্দাটার দিন কেউ ভুলত, আর ভুলটা কিছুই দেখাত
     * না — সারিটা শুধু চিরকাল চালু থেকে যেত।
     */
    public function test_every_menu_row_gets_a_switch(): void
    {
        $tree = $this->switches()->tree();

        $this->assertNotEmpty($tree, 'কোনো মডিউলই আসেনি।');

        $declared = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    if (isset($item['setting'])) {
                        $declared[(string) $item['setting']] = true;
                    }
                }
            }
        }

        $seen = [];
        $rows = 0;

        foreach ($tree as $module) {
            $this->assertNotEmpty($module['groups'], $module['code'].'-এর কোনো গ্রুপ নেই।');

            foreach ($module['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $rows++;

                    $this->assertNotEmpty($item['key'], $item['route'].'-এর কোনো সুইচ নেই।');

                    /*
                     * দুইটা সারির একই কী হলে একটা বন্ধ করলে দুইটাই বন্ধ
                     * হত। পাঁচ ধরনের ভাউচার একই রুটের পাঁচটা সারি —
                     * প্যারামিটার কী-তে না ঢুকলে ঠিক এই ভুলটাই হত।
                     *
                     * ── একটা ব্যতিক্রম, আর সেটা ইচ্ছাকৃত ─────────────
                     * ঘোষিত সুইচ কখনো কখনো একটা **ফিচার** নিয়ন্ত্রণ করে,
                     * একটা পর্দা নয় — "গাড়ি" বন্ধ করলে গাড়ির তালিকা আর
                     * গাড়ির ধরন দুইটাই যাওয়ার কথা। ওটা মডিউলের
                     * সিদ্ধান্ত, আর সেটা লেখা আছে module.php-তে।
                     */
                    if (isset($declared[$item['key']])) {
                        continue;
                    }

                    $this->assertArrayNotHasKey($item['key'], $seen,
                        'দুইটা মেনু সারি একই সুইচের কী পেয়েছে, অথচ কোনো মডিউল সেটা ঘোষণা করেনি: '
                        .$item['route']);

                    $seen[$item['key']] = true;
                }
            }
        }

        $this->assertGreaterThan(50, $rows, 'সারির সংখ্যা সন্দেহজনকভাবে কম।');
    }

    /**
     * একটা সারি বন্ধ করলে মেনু থেকে যায়, আর ঠিকানাও ৪০৪ দেয়।
     */
    public function test_switching_a_row_off_closes_the_door_too(): void
    {
        $this->assertContains('finance.hand_loan.index', $this->visibleRoutes(),
            'পরীক্ষার আগেই সারিটা মেনুতে নেই।');

        $this->get(route('finance.hand_loan.index'))->assertOk();

        $this->settings()->set('menu.finance.hand_loan.index', false);

        $this->assertNotContains('finance.hand_loan.index', $this->visibleRoutes(),
            'বন্ধ করার পরেও সারিটা মেনুতে আছে।');

        $this->get(route('finance.hand_loan.index'))->assertNotFound();
    }

    /**
     * সাবমডিউল বন্ধ করলে তার সব সারি বন্ধ।
     */
    public function test_switching_a_group_off_takes_its_rows_with_it(): void
    {
        $key = $this->switches()->forGroup('finance', 'transactions');

        $before = $this->visibleRoutes();

        $this->assertContains('finance.expense.index', $before);
        $this->assertContains('finance.hand_loan.index', $before);

        $this->settings()->set($key, false);

        $after = $this->visibleRoutes();

        $this->assertNotContains('finance.expense.index', $after,
            'গ্রুপ বন্ধ করার পরেও তার সারি মেনুতে আছে।');
        $this->assertNotContains('finance.hand_loan.index', $after);

        $this->get(route('finance.expense.index'))->assertNotFound();

        /* অন্য গ্রুপের সারি অক্ষত — একটা গ্রুপ বন্ধ করলে মডিউল নয় */
        $this->assertContains('finance.capital.index', $after,
            'এক গ্রুপ বন্ধ করায় অন্য গ্রুপের সারিও চলে গেছে।');
    }

    /**
     * গ্রুপ বন্ধ থাকলে প্যারামিটারওয়ালা সারিও ৪০৪ দেয়।
     *
     * ── কেন এটার নিজের পরীক্ষা, ৩০ আগস্ট ২০২৬ ───────────────────────
     * পাঁচ ধরনের ভাউচার একই রুটের পাঁচটা সারি, আর ওদের কী প্যারামিটার
     * ধরে আলাদা হয়। মিডলওয়্যারে ওদের জন্য একটা **আলাদা পথ** আছে
     * (`$exact`), আর সেই পথটা দুই জায়গায় ভুল ছিল:
     *
     * ১. গ্রুপের পরীক্ষা হত সারির **পরে** — গ্রুপ বন্ধ অথচ সারি চালু
     *    হলে পর্দাটা মেনু থেকে উধাও, কিন্তু ঠিকানা দিলে খুলত।
     * ২. প্যারামিটারওয়ালা সারি গ্রুপের মানচিত্রে নথিভুক্তই হত না, তাই
     *    যে গ্রুপে কেবল ওই ধরনের সারি আছে তার সুইচ কিছুই আটকাত না।
     *
     * দুইটাই "আড়াল, কিন্তু বাধা নয়" — ঠিক যে ভুলটা ১৩ আগস্ট HP ধরেছিল।
     */
    public function test_a_group_switch_also_closes_a_parameterised_row(): void
    {
        $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk();

        $this->settings()->set($this->switches()->forGroup('accounts', 'transactions'), false);

        $this->assertNotContains('accounts.voucher.index', $this->visibleRoutes(),
            'গ্রুপ বন্ধ করার পরেও ভাউচারের সারি মেনুতে আছে।');

        $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertNotFound();
    }

    /**
     * মডিউল বন্ধ করলে ভেতরের সুইচ যা-ই বলুক, সবটাই বন্ধ।
     *
     * উল্টো নিয়ম করলে "মডিউলটা বন্ধ করেছি, তবু পর্দাটা খোলে" — আর
     * তখন মডিউলের সুইচটাই মিথ্যা।
     */
    public function test_switching_a_module_off_beats_everything_inside(): void
    {
        $this->settings()->set($this->switches()->forModule('finance'), false);

        $routes = $this->visibleRoutes();

        foreach ($routes as $route) {
            $this->assertStringStartsNotWith('finance.', $route,
                'মডিউল বন্ধ, তবু তার সারি মেনুতে আছে: '.$route);
        }

        $this->get(route('finance.capital.index'))->assertNotFound();
    }

    /**
     * ঘোষিত সুইচওয়ালা সারি তার নিজের কী-ই রাখে।
     *
     * ── কেন ────────────────────────────────────────────────────────
     * ঘোষিত সুইচ প্রায়ই একটা সারির চেয়ে বড় কিছু নিয়ন্ত্রণ করে। দুইটা
     * কী থাকলে ব্যবহারকারী একটা বন্ধ করে অবাক হতেন যে পর্দাটা তবু
     * খোলে।
     */
    public function test_a_row_that_declares_its_own_switch_keeps_it(): void
    {
        $declared = ['route' => 'x.y.index', 'setting' => 'master_data.multi_currency'];

        $this->assertSame('master_data.multi_currency', $this->switches()->forItem($declared));

        $plain = ['route' => 'x.y.index'];

        $this->assertSame('menu.x.y.index', $this->switches()->forItem($plain));
    }

    /**
     * প্যারামিটারওয়ালা সারিগুলোর কী আলাদা।
     */
    public function test_rows_that_differ_only_by_parameter_get_different_keys(): void
    {
        $one = ['route' => 'accounts.voucher.index', 'route_params' => ['type' => 'receipt']];
        $two = ['route' => 'accounts.voucher.index', 'route_params' => ['type' => 'payment']];

        $this->assertNotSame(
            $this->switches()->forItem($one),
            $this->switches()->forItem($two),
            'দুই ধরনের ভাউচার একই সুইচ পেয়েছে — একটা বন্ধ করলে দুইটাই বন্ধ হত।',
        );
    }

    /**
     * কন্ট্রোল প্যানেল ট্যাব ধরে খোলে।
     */
    public function test_the_control_panel_opens_by_tab(): void
    {
        $first = $this->get(route('system_admin.control-panel'))->assertOk();

        $this->assertSame('switches', $first->viewData('tab'));
        $this->assertNotEmpty($first->viewData('tree'));
        $this->assertNotEmpty($first->viewData('tabs'));

        $this->get(route('system_admin.control-panel', ['tab' => 'finance']))
            ->assertOk()->assertViewHas('tab', 'finance');
    }

    /**
     * গ্রুপ বন্ধ করে সংরক্ষণ করলে ভেতরের সারিগুলো নিজের অবস্থা রাখে।
     *
     * ── কেন এটার নিজের পরীক্ষা, ৩০ আগস্ট ২০২৬ ───────────────────────
     * ছকে প্রথমে সারির চেকবক্সগুলো গ্রুপ বন্ধ হলে `disabled` হত। কিন্তু
     * **বন্ধ করা চেকবক্স ব্রাউজার পাঠায় না** — অথচ `scope[]`-এ নামটা
     * থেকে যেত, তাই সার্ভার পড়ত "বন্ধ করা হয়েছে"।
     *
     * ফল: গ্রুপটা একবার বন্ধ করে সংরক্ষণ করলেই ভেতরের প্রতিটা সারি
     * চিরতরে বন্ধ হয়ে যেত, আর গ্রুপটা আবার চালু করলে পর্দাগুলো ফিরত না।
     */
    public function test_switching_a_group_off_does_not_erase_its_rows(): void
    {
        $group = $this->switches()->forGroup('finance', 'transactions');
        $row = 'menu.finance.hand_loan.index';

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [$group, $row],
            'settings' => [$row => '1'],
        ])->assertRedirect();

        $this->assertFalse((bool) $this->settings()->get($group, true),
            'গ্রুপটাই বন্ধ হয়নি।');

        $this->assertTrue((bool) $this->settings()->get($row, true),
            'গ্রুপ বন্ধ করায় ভেতরের সারির নিজের সুইচও বন্ধ হয়ে গেছে।');

        /* গ্রুপ ফিরলে সারিটাও ফেরে */
        $this->settings()->set($group, true);

        $this->assertContains('finance.hand_loan.index', $this->visibleRoutes(),
            'গ্রুপ ফিরল, সারিটা ফিরল না।');

        /*
         * আর পর্দাটাও যেন ঘরগুলো নিষ্ক্রিয় না করে।
         *
         * উপরের অংশটা সার্ভার পরীক্ষা করে, কিন্তু ভুলটা ছিল ব্লেডে —
         * সার্ভারে সরাসরি পাঠালে ওটা কোনোদিন ধরা পড়ত না। তাই আঁকা
         * পাতাটাই দেখা হয়।
         */
        $html = (string) $this->get(route('system_admin.control-panel', ['tab' => 'finance']))
            ->getContent();

        $this->assertMatchesRegularExpression('/name="settings\[menu\./', $html,
            'ছকে মেনুর কোনো সুইচই নেই।');

        $this->assertDoesNotMatchRegularExpression(
            '/name="settings\[menu\.[^"]*"[^>]*disabled/',
            $html,
            'মেনুর সারির চেকবক্স নিষ্ক্রিয় করা হয়েছে — নিষ্ক্রিয় ঘর ব্রাউজার পাঠায় না, '
            .'আর সার্ভার সেটাকে "বন্ধ করা হয়েছে" পড়ে।',
        );
    }

    /**
     * এক ট্যাব সেভ করলে অন্য ট্যাবের সুইচ বন্ধ হয়ে যায় না।
     *
     * ── কেন এটার নিজের পরীক্ষা ──────────────────────────────────────
     * চেকবক্স বন্ধ থাকলে ব্রাউজার কিছুই পাঠায় না। সার্ভার যদি সব সুইচের
     * উপর দিয়ে যেত, তাহলে "অনুপস্থিত" মানে "বন্ধ" ধরে নিয়ে **এই
     * পাঠানোয় ছিল না এমন সব সুইচ বন্ধ করে দিত** — নীরবে, আর একবারে।
     */
    public function test_saving_one_tab_does_not_switch_off_the_others(): void
    {
        $handLoan = 'menu.finance.hand_loan.index';
        $capital = 'menu.finance.capital.index';

        $this->settings()->set($handLoan, true);
        $this->settings()->set($capital, true);

        /* কেবল হাতধারের সুইচটা পাঠানো, আর সেটাও বন্ধ করে */
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [$handLoan],
            'settings' => [],
        ])->assertRedirect();

        $this->assertFalse((bool) $this->settings()->get($handLoan, true),
            'যেটা পাঠানো হয়েছে সেটাই বন্ধ হয়নি।');

        $this->assertTrue((bool) $this->settings()->get($capital, true),
            'যে সুইচটা এই পাঠানোয় ছিলই না সেটাও বন্ধ হয়ে গেছে।');
    }

    /**
     * এক মডিউলের ট্যাব সেভ করলে অন্য মডিউলের **ঘোষিত** সেটিং বাঁচে।
     *
     * ── কেন এটার আলাদা পরীক্ষা লাগল, ৩০ আগস্ট ২০২৬ ──────────────────
     * উপরেরটা কেবল মেনুর সুইচ দেখত, আর সেগুলো `scope[]` মানত। ঘোষিত
     * সেটিংসের লুপটা মানত না — সেটা এখনো এক পাতার নিয়মে চলছিল:
     * *চেকবক্স নেই মানে বন্ধ।*
     *
     * ফল ব্রাউজারে ধরা পড়ল: হিসাব ট্যাবে **একটা** সুইচ বদলে সংরক্ষণ
     * করতেই বিক্রয়, ক্রয়, মজুদ, গ্রাহক, মাস্টার ডাটা ও ছাপার **৩৪টা
     * সেটিং নীরবে বন্ধ** হয়ে গেল। পর্দায় কোনো ভুল দেখায়নি।
     *
     * পাশের পরীক্ষাটা সবুজই ছিল, কারণ সে ভুলটার দিকেই তাকায়নি।
     */
    public function test_saving_one_module_tab_spares_another_modules_settings(): void
    {
        $mine = 'accounts.backdate_days';
        $theirs = 'sales.field_free_qty';

        $this->settings()->set($theirs, true);

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [$mine],
            'settings' => [$mine => '9'],
        ])->assertRedirect();

        $this->assertSame(9, (int) $this->settings()->get($mine),
            'যেটা পাঠানো হয়েছে সেটাই সংরক্ষণ হয়নি।');

        $this->assertTrue((bool) $this->settings()->get($theirs),
            'এক মডিউলের ট্যাব সংরক্ষণ করায় অন্য মডিউলের সেটিং বন্ধ হয়ে গেছে।');
    }

    /**
     * `scope[]` ছাড়া পাঠানো হলে কিছুই বদলায় না।
     *
     * ── কেন চুপচাপ কিছু-না-করা, আর পুরনো নিয়মে ফেরা নয় ──────────────
     * পুরনো কোনো পাতা (ব্রাউজারের ক্যাশ, খোলা রাখা ট্যাব) `scope[]`
     * ছাড়াই আসতে পারে। তখন পুরনো নিয়মে ফিরলে ঠিক ওই ৩৪টা সেটিং আবার
     * বন্ধ হত।
     *
     * "কিছু সেভ হলো না" ব্যবহারকারী সাথে সাথে দেখেন আর আবার টেপেন;
     * "৩৪টা সেটিং নীরবে বন্ধ" কেউ মাসের পর মাস দেখেন না।
     */
    public function test_a_submission_without_scope_changes_nothing(): void
    {
        $key = 'sales.field_free_qty';

        $this->settings()->set($key, true);

        $this->put(route('system_admin.control-panel.update'), [
            'settings' => [],
        ])->assertRedirect();

        $this->assertTrue((bool) $this->settings()->get($key),
            'scope ছাড়া পাঠানোতেও সেটিং বদলে গেছে।');
    }
}
