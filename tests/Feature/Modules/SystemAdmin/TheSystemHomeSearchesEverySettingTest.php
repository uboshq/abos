<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ সিস্টেমের হোম — সব সেটিং জুড়ে খোঁজা আর ভাগের কার্ড (কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৩ আর ৪)।
 * ⛔ আগে: খোঁজা কেবল কন্ট্রোল প্যানেলের ভেতরে; হোমের চারটা বোতাম মেনুরই নকল।
 */
final class TheSystemHomeSearchesEverySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_searches_through_the_control_panel_and_its_tiles_are_the_sections(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('module.dashboard', 'system_admin'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form method="GET" action="'.preg_quote(route('system_admin.control-panel'), '/').'" data-dashboard-search/', $html,
            '⛔ সিস্টেমের হোমে সব সেটিং জুড়ে খোঁজার ঘর নেই।');
        $this->assertMatchesRegularExpression('/data-dashboard-search.*?name="find"/s', $html, '⛔ খোঁজা কন্ট্রোল প্যানেলের খোঁজার নামে যায় না।');

        $this->get(route('system_admin.control-panel', ['find' => __('system_admin::settings.date_format')]))->assertOk()
            ->assertSee(__('system_admin::settings.date_format'));

        // ⓘ সপ্তম ভাগ "নিরীক্ষা" — দফা ১ (৭ নভেম্বর ২০২৬; আগে ছয়টা ছিল)
        foreach (['org', 'people', 'modules', 'comms', 'data', 'audit'] as $section) {
            // ⓘ বোতামেই (data-tile) — বাঁয়ের মেনুর ভাগের শিরোনামে একই নাম থাকে
            $this->assertMatchesRegularExpression('/data-tile[^>]*>(?:(?!<\/a>).)*'.preg_quote(e(__('system_admin::menu.section_'.$section)), '/').'/su', $html,
                "⛔ হোমে \"{$section}\" ভাগের কার্ড নেই।");
        }
        // ⓘ কেবল ড্যাশবোর্ডের বোতামে (data-tile) — বাঁয়ের মেনুতে মডিউলের নাম থাকবেই
        $this->assertDoesNotMatchRegularExpression('/data-tile[^>]*>(?:(?!<\/a>).)*'.preg_quote(e(__('system_admin::menu.backup')), '/').'/su', $html, '⛔ হোমে আবার মেনুর নকল বোতাম।');
    }
}
