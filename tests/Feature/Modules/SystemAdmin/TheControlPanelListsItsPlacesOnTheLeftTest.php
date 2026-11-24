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

    public function test_the_left_list_is_the_subjects_then_printing_and_the_phone(): void
    {
        $html = (string) $this->get(route('system_admin.control-panel'))->assertOk()->getContent();
        $aside = $this->part($html, 'data-control-frame', '</aside>');

        foreach (\App\Modules\SystemAdmin\Support\ControlSubjects::ALL as $subject) {
            $this->assertStringContainsString('data-control-tab="subject-'.$subject.'"', $aside, "⛔ বিষয় \"{$subject}\" বাঁয়ের তালিকায় নেই।");
            $this->assertStringNotContainsString('::', __('system_admin::control.subject.'.$subject));
        }
        foreach (['print', 'mobile'] as $key) {
            $this->assertStringContainsString('data-control-tab="'.$key.'"', $aside);
        }
        // ⓘ মডিউলের আর সুইচের ট্যাব তালিকায় নেই
        foreach (['switches', 'accounts', 'sales'] as $key) {
            $this->assertStringNotContainsString('data-control-tab="'.$key.'"', $aside, "⛔ \"{$key}\" এখনো তালিকায়।");
        }
        $this->assertMatchesRegularExpression('/data-control-tab="subject-general"[^>]*aria-current="page"/', $aside, '⛔ চলতি জায়গা চিহ্নিত নয়।');
    }

    public function test_finding_a_switch_says_where_it_lives(): void
    {
        $label = __('accounts::menu.periods');
        $html = (string) $this->get(route('system_admin.control-panel', ['find' => mb_substr($label, 0, 4)]))->assertOk()->getContent();
        $found = $this->part($html, 'data-control-found', '</section>');

        $accounts = ['url' => route('system_admin.module.screens', ['module' => 'accounts']), 'label' => __('system_admin::module_board.screens')];
        $this->assertStringContainsString(e($label), $found, '⛔ খোঁজায় মেনুর সারিটা মেলেনি।');
        $this->assertStringContainsString('href="'.e($accounts['url']).'"', $found, '⛔ মেলা সারির জায়গায় যাওয়ার লিংক নেই।');
        $this->assertStringContainsString(e($accounts['label']), $found, '⛔ কোথায় আছে বলে না।');

        $none = (string) $this->get(route('system_admin.control-panel', ['find' => 'ঝঝঝ-এমন-কিছু-নেই']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('system_admin::control.find_none', ['find' => 'ঝঝঝ-এমন-কিছু-নেই'])), $none);
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
