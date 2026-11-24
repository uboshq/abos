<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ ড্যাশবোর্ডের নিরীক্ষা, ২৩ নভেম্বর ২০২৬ (ডেমো 73ede568b বনাম মালিকের অনুমোদিত নমুনা RoPEayT7 v2) — দশটা মডিউল:
 *   অফার, অনুমোদন, বাকি নিয়ন্ত্রণ, ডকুমেন্ট, মাস্টার ডেটা, ব্যাকআপ, সিস্টেম প্রশাসন, বিজ্ঞপ্তি, কর্মী ও বেতন, গ্রাহক।
 *
 * কী ভুল ছিল: কয়েকটা কার্ডের শিরোনাম নমুনার নয় ("এই সপ্তাহ" বনাম "এ সপ্তাহ", "ছাড় — 6 মাস" বনাম "ছাড় ও উপহার — 6 মাস" …);
 * আর গ্রাফ কোনো সংখ্যাকে কেবল % বা ভাগে নামিয়ে আনলে আগের ঘরটা "গ্রাফে এসেছে" বলে ঢাকা পড়ত — অথচ টাকা বা গোনাটা কোথাও নেই
 * (অফারের 7/15/30 দিনের গোনা, বাজেটের টাকা, এ পর্যন্ত অনুমোদিত/নাকচ, শাখা ধরে বাকির টাকা, নথির অবস্থা আর ধরনের গোনা,
 * বন্ধ সারির গোনা, যাচাই-করা বনাম না-করা রান, সব নীতির তালিকা, বদলের ধরন, মাসে মাসে নতুন গ্রাহক …)।
 *
 * দাবি, ডেমোর মালিক (সব চাবি), নতুন ড্যাশবোর্ড চালু:
 *   ১. প্রতিটা মডিউলের পাতায় নমুনার ছয়টা কার্ডের শিরোনাম, হুবহু (বাংলা লেখা নমুনার সাথে মেলানো);
 *   ২. ফিরিয়ে আনা প্রতিটা অংশ পুরো সংজ্ঞায় থাকলে পাতাতেও থাকে (ঢাকা পড়ে না) — গ্রাফের নিচে;
 *   ৩. কোনো কার্ডের শিরোনাম নিচের কোনো ঘর, চার্ট বা তালিকার নামের সাথে এক নয় — একই জিনিস দুই জায়গায় নয়;
 *   ৪. গ্রাফের দুই ভাগ করা সাহায্য: 99.9% কখনো "100%" নয়, আর স্তূপ-এলাকা মাথায় সংখ্যা লেখে।
 */
final class TheBoardsWearTheSampleTitlesAndLoseNothingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * নমুনার শিরোনাম — মডিউল => [ভাষার চাবি => নমুনার বাংলা লেখা]।
     */
    private const TITLES = [
        'promotion' => [
            'promotion::dashboard.viz_where' => 'অফারগুলো কোথায়',
            'promotion::dashboard.viz_ends' => 'এখন চলছে — কোন অফার কবে শেষ',
            'promotion::dashboard.viz_budget' => 'বাজেট বনাম খরচ — চালু অফার',
            'promotion::dashboard.coupons' => 'কুপন — দেওয়া বনাম ভাঙানো',
            'promotion::dashboard.viz_discount' => 'ছাড় ও উপহার — 6 মাস',
            'promotion::dashboard.viz_used' => 'ক্যাম্পেইনের ফল — এ মাস',
        ],
        'approval' => [
            'approval::dashboard.by_module' => 'অনুমোদনের অপেক্ষায় — মডিউল ধরে',
            'approval::dashboard.how_long' => 'কত দিন ধরে অপেক্ষায়',
            'approval::dashboard.by_person' => 'কার কাছে আটকে',
            'approval::dashboard.decisions_14' => 'সিদ্ধান্ত — 14 দিন',
            'approval::dashboard.average_time' => 'সিদ্ধান্তে গড় সময়',
            'approval::dashboard.when_requests' => 'অনুরোধ আসে কখন',
        ],
        'credit_control' => [
            'credit_control::dashboard.viz_used' => 'সীমার কতটা ব্যবহার হয়েছে',
            'credit_control::dashboard.ageing' => 'মেয়াদ ধরে খোলা বিল',
            'credit_control::dashboard.viz_by_branch' => 'শাখা ধরে ব্যবহৃত বাকি',
            'credit_control::dashboard.by_salesperson' => 'বিক্রয়কর্মী ধরে ব্যবহৃত বাকি',
            'credit_control::dashboard.viz_stopped' => 'বাকি বন্ধ গ্রাহক',
            'credit_control::dashboard.viz_changes' => 'সীমা বদল — 6 মাস',
        ],
        'documents' => [
            'documents::dashboard.viz_status' => 'ডকুমেন্টের অবস্থা',
            'documents::dashboard.doc_activity' => 'গত 14 দিনের কাজ',
            'documents::dashboard.viz_kinds' => 'নথির ধরন',
            'documents::dashboard.storage' => 'জায়গা নিয়েছে',
            'documents::dashboard.expiry_soon' => 'মেয়াদ — শিগগির শেষ',
            'documents::dashboard.by_module' => 'কোন কাজে কত নথি',
        ],
        'master_data' => [
            'master_data::dashboard.complete_by_list' => 'তালিকাগুলো কতটা ভরা',
            'master_data::dashboard.quality_score' => 'ডেটার মান',
            'master_data::dashboard.missing' => 'অসম্পূর্ণ সারি — কোন তালিকায়',
            'master_data::dashboard.same' => 'একই ফোন বা নাম — সন্দেহজনক জোড়া',
            'master_data::dashboard.new_month' => 'এ মাসে নতুন — তালিকা ধরে',
            'master_data::dashboard.viz_inactive' => 'বন্ধ সারি — কোন তালিকায়',
        ],
        'system_admin' => [
            'system_admin::dashboard.who_gets_in' => 'ব্যবহারকারীর অবস্থা',
            'system_admin::dashboard.two_step' => 'দ্বিতীয় ধাপ — কার চালু',
            'system_admin::dashboard.viz_access_changes' => 'ব্যবহারকারী আর অনুমতির বদল — এ সপ্তাহ',
            'system_admin::dashboard.jobs' => 'পেছনের কাজ',
            'system_admin::dashboard.errors_week' => 'ব্যবস্থার ভুল — এ সপ্তাহ',
            'system_admin::dashboard.last_backup' => 'শেষ ব্যাকআপ',
        ],
        'notification' => [
            'notification::dashboard.viz_funnel' => 'পাঠানো → পৌঁছানো → পড়া',
            'notification::dashboard.viz_by_channel' => 'মাধ্যম ধরে',
            'notification::dashboard.daily' => 'প্রতিদিনের বিজ্ঞপ্তি — 30 দিন',
            'notification::dashboard.viz_health' => 'মাধ্যমের অবস্থা',
            'notification::dashboard.by_module' => 'কোন কাজের বিজ্ঞপ্তি',
            'notification::dashboard.when_read' => 'কখন পড়া হয়',
        ],
        'backup' => [
            'backup::dashboard.viz_runs' => 'গত 30 দিনের রান',
            'backup::dashboard.last_verified' => 'ফিরিয়ে আনা যায়?',
            'backup::dashboard.size_by_month' => 'মাসে মাসে ব্যাকআপের মাপ',
            'backup::dashboard.viz_destinations' => 'গন্তব্যগুলো কেমন আছে',
            'backup::dashboard.viz_schedule' => 'ব্যাকআপের সময়সূচি',
            'backup::dashboard.flow' => 'কোথায় কোন কপি',
        ],
        'hr' => [
            'hr::dashboard.todays_roll' => 'আজকের হাজিরা',
            'hr::dashboard.viz_salary' => 'বেতন খরচ — 6 মাস',
            'hr::dashboard.viz_departments' => 'বিভাগ ধরে কর্মী ও বেতন',
            'hr::dashboard.leave_this_month' => 'ছুটির আবেদন — এ মাস',
            'hr::dashboard.viz_movement' => 'কর্মী চলাচল — 6 মাস',
            'hr::dashboard.viz_heads' => 'ভাতা ও কর্তন — এ মাস',
        ],
        'customer' => [
            'customer::dashboard.viz_growth' => 'গ্রাহক বৃদ্ধি — 6 মাস',
            'customer::dashboard.viz_state' => 'গ্রাহকের অবস্থা',
            'customer::dashboard.viz_ageing' => 'বাকির বয়স',
            'customer::dashboard.viz_area' => 'এলাকা ধরে গ্রাহক',
            'customer::dashboard.viz_top_due' => 'সবচেয়ে বেশি বকেয়া',
            'customer::dashboard.viz_returning' => 'ফিরে আসা গ্রাহক',
        ],
    ];

    /**
     * যা ফিরিয়ে আনা হলো — মডিউল => ভাষার চাবি; পুরো সংজ্ঞায় থাকলে পাতাতেও থাকতে হবে (গ্রাফের নিচে, ঢাকা নয়)।
     */
    private const RESTORED = [
        'promotion' => ['promotion::dashboard.ending_soon', 'promotion::dashboard.live_list', 'promotion::dashboard.budget_used'],
        'approval' => ['approval::dashboard.approved', 'approval::dashboard.rejected', 'approval::dashboard.waiting_now',
            'approval::dashboard.delegations_today', 'approval::dashboard.my_queue'],
        'credit_control' => ['credit_control::dashboard.by_branch', 'credit_control::dashboard.recent_changes', 'credit_control::dashboard.recent_blocks'],
        'documents' => ['documents::dashboard.doc_status', 'documents::dashboard.by_kind', 'documents::dashboard.new_today',
            'documents::dashboard.shared', 'documents::dashboard.recycled', 'documents::dashboard.doc_mine',
            'documents::dashboard.doc_recent', 'documents::dashboard.recent'],
        'master_data' => ['master_data::dashboard.inactive', 'master_data::menu.brands', 'master_data::dashboard.new_today',
            'master_data::dashboard.recently_changed'],
        'system_admin' => ['system_admin::dashboard.access_changes', 'system_admin::menu.companies', 'system_admin::menu.roles',
            'system_admin::dashboard.newest_users'],
        'notification' => ['notification::dashboard.recent'],
        'backup' => ['backup::dashboard.last_30_days', 'backup::dashboard.schedule', 'backup::dashboard.destination_list',
            'backup::dashboard.runs_list'],
        'hr' => ['hr::dashboard.salary_cost', 'hr::dashboard.allowance_deduction', 'hr::dashboard.pending_leave'],
        'customer' => ['customer::dashboard.growth', 'customer::dashboard.newest'],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00'));
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_sample_titles_are_written_word_for_word_in_bengali(): void
    {
        foreach (self::TITLES as $module => $titles) {
            foreach ($titles as $key => $sample) {
                $this->assertSame($sample, __($key, [], 'bn'), "⛔ {$module}: কার্ডের শিরোনাম নমুনার নয় ({$key})।");
            }
        }
    }

    public function test_promotion(): void
    {
        $this->board('promotion');
    }

    public function test_approval(): void
    {
        $this->board('approval');
    }

    public function test_credit_control(): void
    {
        $this->board('credit_control');
    }

    public function test_documents(): void
    {
        $this->board('documents');
    }

    public function test_master_data(): void
    {
        $this->board('master_data');

        // ⓘ একক, কর, শ্রেণির গোনা উপরের ঘরে — নিচের "কতগুলো" চার্ট পাতায় আর নয় (দুইবার নয়), তবে হোম আর ফোনের জন্য সংজ্ঞায় থাকে
        [, $page, $full] = $this->labels('master_data');
        $this->assertContains(__('master_data::dashboard.how_full'), $full, 'দাবির ভিত্তি নেই — "কতগুলো" চার্ট সংজ্ঞাতেই নেই।');
        $this->assertNotContains(__('master_data::dashboard.how_full'), $page, '⛔ একক, কর, শ্রেণির গোনা পাতায় দুইবার।');
    }

    public function test_system_admin(): void
    {
        $this->board('system_admin');
    }

    public function test_notification(): void
    {
        $this->board('notification');
    }

    public function test_backup(): void
    {
        $this->board('backup');
    }

    public function test_hr(): void
    {
        $this->board('hr');
    }

    public function test_customer(): void
    {
        $this->board('customer');
    }

    public function test_a_percentage_under_100_never_shows_as_100_and_the_stacked_area_prints_its_numbers(): void
    {
        $radial = (string) Svg::render('radial', ['p' => [['A', 0.999], ['B', 1], ['C', 0.29]]]);
        $this->assertStringContainsString('>99%</text>', $radial, '⛔ 99.9% গোল হয়ে অন্য কিছু।');
        $this->assertStringContainsString('>100%</text>', $radial, 'পুরো ভরা তালিকা 100% নয়।');
        $this->assertSame(1, substr_count($radial, '>100%</text>'), '⛔ 99.9% গোল হয়ে "100%"।');
        $this->assertStringContainsString('>29%</text>', $radial, '⛔ ভাসমান বিন্দুর ভুলে 29% হয়ে গেল 28%।');

        $area = (string) Svg::render('stackedArea', ['series' => [[1, 2, 3], [4, 5, 6]], 'l' => ['a', 'b', 'c'], 'top' => ['5', '', '9']]);
        $this->assertStringContainsString('>5</text>', $area, '⛔ স্তূপ-এলাকার মাথায় সংখ্যা নেই।');
        $this->assertStringContainsString('>9</text>', $area);
        $this->assertStringNotContainsString('></text>', $area, '⛔ ফাঁকা লেখা আঁকা হয়েছে।');
    }

    /**
     * এক মডিউলের তিন দাবি — নমুনার ছয় শিরোনাম পাতায়, ফেরানো অংশ ঢাকা নয়, কার্ড আর নিচের নাম এক নয়।
     */
    private function board(string $module): void
    {
        [$cards, $page, $full] = $this->labels($module);

        foreach (self::TITLES[$module] as $key => $sample) {
            $this->assertContains(__($key), $cards, "⛔ {$module}: নমুনার কার্ড \"{$sample}\" পাতায় নেই।");
        }

        foreach (self::RESTORED[$module] as $key) {
            if (in_array(__($key), $full, true)) {
                $this->assertContains(__($key), $page, "⛔ {$module}: \"".__($key).'" সংজ্ঞায় আছে, অথচ পাতায় ঢাকা — কিছু হারাল।');
            }
        }

        $this->assertSame([], array_values(array_intersect($cards, $page)),
            "⛔ {$module}: একই নাম গ্রাফে আর নিচে — একই জিনিস দুই জায়গায়।");
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} [গ্রাফের কার্ডের শিরোনাম, পাতার নিচের নাম, পুরো সংজ্ঞার নাম]
     */
    private function labels(string $module): array
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => $module]))->assertOk()->getContent();
        $cards = [];
        foreach (array_slice(explode('class="viz-card"', $html), 1) as $chunk) {
            preg_match('~<h2>(.*?)</h2>~su', $chunk, $m);
            $cards[] = html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
        }
        $this->assertNotSame([], $cards, "দাবির ভিত্তি নেই — {$module}-এর পাতায় গ্রাফের বোর্ড নেই।");

        $definition = app(DashboardEngine::class)->for($module, $this->owner);

        return [$cards, $this->names($definition->forPage()), $this->names($definition)];
    }

    /** @return list<string> */
    private function names(DashboardDefinition $d): array
    {
        return array_values(array_map(fn ($item) => (string) $item->label, [...$d->stats, ...$d->panels, ...$d->listings]));
    }
}
