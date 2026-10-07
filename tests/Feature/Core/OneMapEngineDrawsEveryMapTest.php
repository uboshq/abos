<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Map\MapEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Support\FinancePlan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * মানচিত্রের ইঞ্জিন — একটাই, সব মডিউলের জন্য (রিপোর্ট সেন্টার ধাপ ১; মালিক, ১ অক্টোবর ২০২৬: *"ফিন্যান্স মানচিত্রের
 * মতো সব জায়গায়"*)।
 *
 * ⭐ তিনটা কথা: (১) তৈরি হলে লাইন নিজে লিংক — ⛔ কিন্তু নাম-না-জানা স্লাগে মৃত লিংক নয়; (২) "বাকি" কেবল মালিক/অ্যাডমিন;
 * (৩) যে পর্দা কেউ খুলতে পারেন না তার লাইন তাঁর কাছে নেই। আর অর্থের মানচিত্র ইঞ্জিনে উঠেও আগের লিংকগুলোই দেয়।
 */
final class OneMapEngineDrawsEveryMapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⛔ রুট আছে বলেই স্লাগ আছে নয় — সাক্ষী লাগে: মডিউলের মেনু-সারি, বা রুটের নিজের বাঁধন। */
    public function test_a_slug_nobody_declared_is_pending_not_a_dead_link(): void
    {
        $maps = app(MapEngine::class);

        $this->assertTrue($maps->exists('sales.report.show:by-customer'), 'মেনুতে ঘোষিত রিপোর্টটাই "তৈরি" নয়।');
        $this->assertFalse($maps->exists('sales.report.show:no-such-report'),
            '⛔ কেউ বানায়নি এমন স্লাগ "তৈরি" — মানচিত্রে ৪০৪-এর লিংক বসত।');

        $this->assertTrue($maps->exists('finance.deposit.index:bank'), 'রুটের বাঁধন মানা স্লাগটা "তৈরি" নয়।');
        $this->assertFalse($maps->exists('finance.deposit.index:nowhere'), '⛔ রুটের বাঁধনের বাইরের স্লাগ "তৈরি"।');

        $this->assertTrue($maps->exists('reports.center'));
        $this->assertFalse($maps->exists('no.such.screen'));
        $this->assertFalse($maps->exists('sales.report.show'), '⛔ স্লাগ ছাড়া রিপোর্টের নাম "তৈরি" — লিংক বানাতে গিয়ে ভাঙত।');
    }

    /** ⭐ পর্দাটা বানানো হলে লাইনটা নিজে লিংক হয় — মানচিত্রের লেখা ছোঁয়া ছাড়াই। */
    public function test_a_line_links_itself_once_its_screen_exists(): void
    {
        $sections = [['title' => 'পরীক্ষা', 'items' => [['নতুন পর্দা', 'map.probe:ready', null]]]];
        $owner = auth()->user();

        $before = app(MapEngine::class)->draw($sections, $owner);
        $this->assertNull($before->sections[0]['items'][0]['url'], 'পর্দা নেই, তবু লিংক।');

        Route::get('/_map-probe/{kind}', fn () => 'ok')->whereIn('kind', ['ready'])->name('map.probe');
        app('router')->getRoutes()->refreshNameLookups();

        $after = app(MapEngine::class)->draw($sections, $owner);
        $this->assertSame(url('/_map-probe/ready'), $after->sections[0]['items'][0]['url'],
            '⛔ পর্দা বানানোর পরেও লাইনটা "বাকি" — নিজে থেকে লিংক হয়নি।');
        $this->assertSame(1, $after->done);
    }

    /** ⛔ একই মানুষ: অ্যাডমিন নন — অর্থের মানচিত্রে "বাকি" লাইন নেই; সুপার অ্যাডমিন হলে আছে। */
    public function test_pending_lines_show_only_to_the_super_admin(): void
    {
        $viewer = $this->member();
        $viewer->givePermissionTo('finance.plan.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('finance.plan'))->assertOk()
            ->assertDontSee('data-map-pending', false)
            ->assertDontSee('data-map-tally', false);

        CompanyContext::forCompany($this->company->id, fn () => $viewer->fresh()->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('finance.plan'))->assertOk()
            ->assertSee('data-map-pending', false)
            ->assertSee('data-map-tally', false);
    }

    /** ⛔ একই মানুষ: পর্দার চাবি নেই — লাইনটাই নেই (৪০৩-এর লিংক নয়); চাবি দিলে আসে। */
    public function test_a_line_the_viewer_cannot_open_is_not_drawn(): void
    {
        $viewer = $this->member();
        $viewer->givePermissionTo('finance.plan.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ⓘ মানচিত্রের লাইনটাই মাপা হয় — সাইডবারেও একই লিংক থাকতে পারে
        $capital = '<a href="'.e(route('finance.capital.index')).'" data-map-done';

        $this->actingAs($viewer->fresh())->get(route('finance.plan'))->assertOk()->assertDontSee($capital, false);

        $viewer->givePermissionTo('finance.capital.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('finance.plan'))->assertOk()->assertSee($capital, false);
    }

    /**
     * ⭐ অর্থের মানচিত্র ইঞ্জিনে উঠেও আগের লিংকগুলোই দেয় — প্রতিটা লাইন, একই ঠিকানা।
     *
     * ⓘ "আগে" এখানে পুরনো নিয়মের হুবহু নকল (রুট থাকলেই লিংক, প্যারামিটারের নাম হাতে লেখা), আর লাভ-ক্ষতি, স্থিতিপত্র ও
     * নগদ প্রবাহ — যাদের নাম বদলেছে — পুরনো নামে মাপা।
     */
    public function test_the_finance_map_links_exactly_what_it_linked_before(): void
    {
        $renamed = [
            'accounts.report.final.profit_loss' => 'accounts.report.show:profit-loss',
            'accounts.balance_sheet' => 'accounts.report.show:balance-sheet',
            'accounts.report.final.cash_flow' => 'accounts.report.show:cash-flow',
        ];

        $differ = [];
        $linked = 0;

        foreach (FinancePlan::sections() as $section) {
            foreach ($section['items'] as [$label, $route]) {
                $before = $this->oldUrlFor($renamed[$route] ?? $route);
                $now = FinancePlan::urlFor($route);

                if ($before !== $now) {
                    $differ[] = "§{$section['no']} {$label}: আগে ".($before ?? 'বাকি').', এখন '.($now ?? 'বাকি');
                }

                $linked += $now === null ? 0 : 1;
            }
        }

        $this->assertGreaterThan(100, $linked, 'অর্থের মানচিত্রের লিংক মাপাই হয়নি।');
        $this->assertSame([], $differ, "⛔ অর্থের মানচিত্র ইঞ্জিনে উঠে অন্য কথা বলছে:\n".implode("\n", $differ));
    }

    /** ⓘ পুরনো `FinancePlan::urlFor()` — ২ অক্টোবর ২০২৬-এর আগের, হুবহু। */
    private function oldUrlFor(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        [$name, $param] = array_pad(explode(':', $route, 2), 2, null);

        if (! Route::has($name)) {
            return null;
        }

        return match (true) {
            $param === null => route($name),
            str_ends_with($name, 'report.show') => route($name, ['slug' => $param]),
            str_ends_with($name, 'voucher.index') => route($name, ['type' => $param]),
            default => route($name, [$param]),
        };
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }
}
