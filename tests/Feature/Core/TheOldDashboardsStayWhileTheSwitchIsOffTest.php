<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সুইচ বন্ধ মানে পুরনো ড্যাশবোর্ড হুবহু — মালিক, ২ অক্টোবর ২০২৬: *"sob deshboard sesh kore tar por eksathe switch dibe"*।
 *
 * ⓘ দাবি, একই মানুষ দুইবার (সুইচ বন্ধ, তারপর চালু): বন্ধে প্রতিটা মডিউলের ড্যাশবোর্ড পুরনো পাতায় খোলে, আর তার
 * চার্টের সংখ্যা চালুর চেয়ে বেশি নয় (নতুন চার্ট কেবল যোগ হয়, পুরনোটা সরে না); হোমে নতুন তিন ঘর (সময়, ফিল্টার,
 * লেআউট) নেই, আর ফিল্টারের নম্বর দিলেও সংখ্যা বদলায় না। চালুতে নতুন পাতা আর তিন ঘর।
 */
final class TheOldDashboardsStayWhileTheSwitchIsOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_off_shows_the_old_pages_and_switch_on_the_new_ones_for_the_same_owner(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $engine = app(DashboardEngine::class);
        $codes = collect(app(ModuleRegistry::class)->all())->map(fn ($m) => $m->code)
            ->filter(fn (string $code) => $engine->has($code))->values()->all();
        $this->assertGreaterThan(10, count($codes), 'ড্যাশবোর্ড দেওয়া মডিউল প্রায় নেই — দাবিটা কিছু দেখবে না।');

        $panels = [];
        foreach ([false, true] as $on) {
            config(['abos.dashboards_v2' => $on]);

            foreach ($codes as $code) {
                $response = $this->get(route('module.dashboard', ['module' => $code]));
                if ($response->status() !== 200) {
                    continue; // বন্ধ মডিউল বা চাবি নেই — দুই অবস্থায় একই
                }
                $response->assertViewIs($on ? 'dashboard.module-v2' : 'dashboard.module');
                $panels[$code][$on ? 'on' : 'off'] = count($engine->for($code, $owner)->panels);
            }

            $home = $this->get(route('dashboard', ['seller' => $owner->id]))->assertOk()->getContent();
            foreach (['data-period-menu', 'data-home-filter', 'data-layout-menu'] as $mark) {
                $on
                    ? $this->assertStringContainsString($mark, $home, "চালুতে হোমে {$mark} নেই।")
                    : $this->assertStringNotContainsString($mark, $home, "⛔ বন্ধেও হোমে {$mark}।");
            }
            if (! $on) {
                $this->assertStringNotContainsString('data-home-filter-on', $home, '⛔ বন্ধেও ফিল্টার চালু হয়েছে।');
            }
        }

        $this->assertNotEmpty($panels);
        foreach ($panels as $code => $count) {
            $this->assertLessThanOrEqual($count['on'], $count['off'], "⛔ {$code}: বন্ধে নতুনের চেয়েও বেশি চার্ট — কিছু উল্টে গেছে।");
        }
    }
}
