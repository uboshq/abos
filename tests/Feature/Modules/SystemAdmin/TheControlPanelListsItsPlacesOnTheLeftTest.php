<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Support\ControlPanelTabs;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কন্ট্রোল প্যানেল — বাঁয়ে দলবদ্ধ তালিকা আর খোঁজা (সিস্টেম পর্দার নকশা §১, ১০ অক্টোবর ২০২৬: *"১৯টা ট্যাবের বদলে বাঁয়ে
 * দলবদ্ধ তালিকা আর উপরে খোঁজার ঘর — কোন সুইচ কোথায়, খুঁজলেই পাওয়া যাবে"*)।
 *
 * দাবি:
 *  - প্রতিটা আগের ট্যাব বাঁয়ের তালিকায়, একই ঠিকানায়, তিন দলে — প্রথম দল "মডিউল চালু/বন্ধ"; চলতি জায়গা চিহ্নিত।
 *  - খুঁজলে মেনুর সারি বা সেটিং মেলে, পাশে কোন জায়গায় আর সেখানে যাওয়ার লিংক; না মিললে বলে।
 *  - ছাপার পর্দাও একই কাঠামো পরে।
 */
final class TheControlPanelListsItsPlacesOnTheLeftTest extends TestCase
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
        $this->actingAs($this->owner);
    }

    public function test_every_old_tab_is_on_the_left_list_in_three_groups(): void
    {
        $html = (string) $this->get(route('system_admin.control-panel', ['tab' => 'accounts']))->assertOk()->getContent();
        $aside = $this->part($html, 'data-control-frame', '</aside>');

        foreach (app(ControlPanelTabs::class)->all() as $tab) {
            $this->assertStringContainsString('href="'.e($tab['url']).'" data-control-tab="'.$tab['key'].'"', $aside, "⛔ \"{$tab['label']}\" বাঁয়ের তালিকায় নেই।");
        }

        $first = strpos($aside, e(__('system_admin::control.group_switches')));
        $this->assertNotFalse($first, '⛔ "মডিউল চালু/বন্ধ" দল নেই।');
        $this->assertLessThan(strpos($aside, e(__('system_admin::control.group_modules'))), $first, '⛔ মডিউল চালু/বন্ধ প্রথম দল নয়।');
        $this->assertMatchesRegularExpression('/data-control-tab="accounts"[^>]*aria-current="page"/', $aside, '⛔ চলতি জায়গা চিহ্নিত নয়।');
    }

    public function test_finding_a_switch_says_where_it_lives(): void
    {
        $label = __('accounts::menu.periods');
        $html = (string) $this->get(route('system_admin.control-panel', ['find' => mb_substr($label, 0, 4)]))->assertOk()->getContent();
        $found = $this->part($html, 'data-control-found', '</section>');

        $accounts = collect(app(ControlPanelTabs::class)->all())->firstWhere('key', 'accounts');
        $this->assertStringContainsString(e($label), $found, '⛔ খোঁজায় মেনুর সারিটা মেলেনি।');
        $this->assertStringContainsString('href="'.e($accounts['url']).'"', $found, '⛔ মেলা সারির জায়গায় যাওয়ার লিংক নেই।');
        $this->assertStringContainsString(e($accounts['label']), $found, '⛔ কোথায় আছে বলে না।');

        $none = (string) $this->get(route('system_admin.control-panel', ['find' => 'ঝঝঝ-এমন-কিছু-নেই']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('system_admin::control.find_none', ['find' => 'ঝঝঝ-এমন-কিছু-নেই'])), $none);
    }

    /**
     * ⭐ ব্যবসার মডিউল আর ভিত্তি আলাদা — ভিত্তির টিকই নেই (সার্ভার মানত না, অথচ টিপলে মনে হত বন্ধ হল); ব্যবসার মডিউলে
     * বন্ধ করার প্রভাব সারিতেই লেখা।
     */
    public function test_foundation_modules_have_no_switch_and_business_modules_say_what_turning_off_does(): void
    {
        $tree = app(\App\Core\Services\MenuSwitches::class)->tree();
        $essential = collect($tree)->firstWhere('essential', true);
        $business = collect($tree)->firstWhere('essential', false);
        $this->assertNotNull($essential, 'প্রস্তুতিটাই ভুল — কোনো ভিত্তির মডিউল নেই।');
        $this->assertNotNull($business);

        $html = (string) $this->get(route('system_admin.control-panel'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="settings['.$essential['key'].']"', $html, "⛔ ভিত্তির মডিউল ({$essential['label']}) বন্ধ করার টিক আছে।");
        $this->assertStringContainsString('data-foundation-module="'.$essential['code'].'"', $this->part($html, 'data-foundation', '</section>'),
            '⛔ ভিত্তির মডিউল তালাবদ্ধ ঘরে নেই।');
        $this->assertStringContainsString('name="settings['.$business['key'].']"', $html, '⛔ ব্যবসার মডিউলের টিক হারাল।');
        // ⓘ বন্ধের আগে নিশ্চিত — ফর্ম জমার সময় (forms.js confirmOff, vitest-এ প্রমাণিত); এখানে তারটা জোড়া আছে কিনা
        $this->assertStringContainsString('@submit="confirmOff($event)"', $html, '⛔ মডিউল বন্ধের আগে নিশ্চিত করা হয় না।');
        $this->assertStringContainsString('data-confirm-off="', $html);
        $this->assertMatchesRegularExpression('/name="settings\['.preg_quote($business['key'], '/').'\]"[^>]*data-module-label="'.preg_quote(e($business['label']), '/').'"/s',
            $html, '⛔ মডিউলের টিক নিজের নাম বলে না — নিশ্চিতের প্রশ্নে নাম আসবে না।');

        $screens = array_sum(array_map(fn (array $g) => count($g['items']), $business['groups']));
        $this->assertStringContainsString(e(__('system_admin::control.off_impact', ['screens' => $screens])), $html,
            "⛔ {$business['label']} বন্ধ করলে কয়টা পর্দা সরবে, সারিতে লেখা নেই।");
    }

    /** ⭐ বদলের ইতিহাস — কে, কবে, কোন সেটিং, আগে → পরে (নিরীক্ষা থেকে পড়া, নতুন কিছু লেখা নয়) */
    public function test_a_change_shows_in_the_history_with_who_and_what(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['system.dashboards_v2'],
            'settings' => ['system.dashboards_v2' => '1'],
        ])->assertRedirect();

        $html = (string) $this->get(route('system_admin.control-panel'))->assertOk()->getContent();
        $history = $this->part($html, 'data-control-history', '</details>');

        $this->assertStringContainsString(e(__('system_admin::settings.dashboards_v2')), $history, '⛔ বদলানো সেটিং ইতিহাসে নেই।');
        $this->assertStringContainsString(e(__('system_admin::control.value_on')), $history, '⛔ নতুন মান ইতিহাসে নেই।');
        $this->assertStringContainsString(e($this->owner->name), $history, '⛔ কে বদলালেন, ইতিহাসে নেই।');
    }

    /**
     * ⛔ মডিউল-পেরোনো ট্যাব (মোবাইল) ফাঁকা আঁকা হত — পর্দা প্রতিটা মডিউলকে "এই ট্যাবের নয়" বলে বাদ দিত। ফোনে "সিস্টেম
     * প্রশাসন" বন্ধ করলে ওয়েবে ফেরার সুইচটাই থাকত না (ThePhoneShowedEveryModuleTest, ১০ অক্টোবর ২০২৬)।
     */
    public function test_a_tab_that_crosses_modules_shows_its_switches(): void
    {
        $html = (string) $this->get(route('system_admin.control-panel', ['tab' => 'mobile']))->assertOk()->getContent();

        $this->assertStringContainsString('name="settings[mobile.modules.system_admin]"', $html, '⛔ ফোনের "সিস্টেম প্রশাসন" সুইচ পর্দায় নেই — ফেরার পথ বন্ধ।');
        $this->assertStringContainsString('name="settings[mobile.modules.sales]"', $html, '⛔ মোবাইল ট্যাবে বাকি মডিউলের সুইচও নেই।');
    }

    public function test_the_print_screen_wears_the_same_frame(): void
    {
        $html = (string) $this->get(route('system_admin.print_control'))->assertOk()->getContent();

        $this->assertStringContainsString('data-control-frame', $html, '⛔ ছাপার পর্দায় বাঁয়ের তালিকা নেই।');
        $this->assertMatchesRegularExpression('/data-control-tab="print"[^>]*aria-current="page"/', $html);
    }

    private function part(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "⛔ পাতায় {$from} নেই।");

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }
}
