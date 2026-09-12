<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ইনবক্স পঞ্চাশে বাঁধা — কিন্তু সংখ্যাগুলো নয়।
 *
 * ── কী ভাঙা ছিল, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * অপেক্ষমাণ অনুরোধের তালিকাটার কোনো সীমা ছিল না। নিরীহ মনে হত — সই
 * করার কাগজ তো হাতেগোনা। ⚠️ কিন্তু ধরে নেওয়াটাই ভুল: **ইনবক্স লম্বা
 * হয় ঠিক তখনই যখন কেউ অনুমোদন করছেন না**, আর তখনই পর্দাটা খোলা
 * সবচেয়ে জরুরি। যে কোম্পানিতে ছয় মাস কেউ সই করেননি, সেখানে এটাই
 * সবচেয়ে দামি কোয়েরি হয়ে উঠত।
 *
 * ── ⛔ কেন পাতা ভাগ নয়, সীমা ────────────────────────────────────────
 * উপরের মডিউল-চিপগুলো (ক্রয় ৬০ · বেতন ৫) **পুরো তালিকার** সংখ্যা
 * দেখায়, আর সেটাই ওদের কাজ: চিপ দেখেই মানুষ বোঝেন জটটা কোথায়। পাতা
 * ভাগ বসালে "পাতা ২-এ যান" আর "ক্রয় ৬০" — দুইটা আলাদা গল্প একসাথে
 * বলতে হত।
 *
 * তাই [[App\Modules\Finance\Http\Controllers\ExpenseController]]-এর
 * ছাঁচ: সীমা + আলাদা কোয়েরিতে মোট + পর্দায় স্পষ্ট লেখা কতটা দেখা
 * যাচ্ছে।
 *
 * ── এই ফাইলটা যা পাহারা দেয় ─────────────────────────────────────────
 * সীমা বসানোর **পার্শ্বপ্রতিক্রিয়াগুলো**, কারণ আসল ঝুঁকি ওখানেই। সীমা
 * বসানো সহজ; ভুলটা হয় তার পরে, যখন আশেপাশের প্রতিটা সংখ্যা নীরবে
 * "এই পঞ্চাশটার" হয়ে যায়:
 *
 *   শিরোনামের গোনা   `$approvals->count()` হলে লিখত "৫০টি", যেখানে ৬৫
 *   চিপের সংখ্যা      তালিকা থেকে গুনলে "ক্রয় ৫০", যেখানে ৬০
 *   ছাঁকনির ক্রম      সীমা আগে বসালে ক্রয়ের ৬০টার মাত্র কয়েকটা আসত
 *
 * ⚠️ তিনটাই **নীরব**: পর্দা দেখতে ঠিক আগের মতোই, কেবল সংখ্যাগুলো কম।
 * আর মানুষ ঠিক ওই সংখ্যা দেখেই ঠিক করেন আজ বসে সই করবেন কি না।
 */
class TheInboxSaidFiftyWhenAHundredWereWaitingTest extends TestCase
{
    use RefreshDatabase;

    /** পর্দা যতটা দেখায় — [[ApprovalInboxController::INBOX_LIMIT]]। */
    private const SHOWN = 50;

    private const PURCHASE = 60;

    private const PAYROLL = 5;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'JT', 'name_en' => 'Jam Co']);
        CompanyContext::set($this->company->id);

        $this->manager = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->manager->companies()->attach($this->company->id);

        /*
         * অনুমতির সারি হাতে — `RefreshDatabase` কেবল মাইগ্রেশন চালায়,
         * `PermissionSyncer` নয় ([[TheInboxCanBeNarrowedToOneModuleTest]]-এ
         * পুরো কারণটা লেখা)।
         *
         * ⓘ ডেমো ডেটা ইচ্ছাকৃতভাবে **নেই**: এলে "কয়টা অপেক্ষমাণ" সংখ্যাটা
         * এই টেস্টের বসানো সারির নয়, ডেমোরও হত — আর তখন ৬৫ বনাম ৫০-এর
         * পুরো দাবিটাই অর্থহীন।
         */
        foreach (['approval.view', 'approval.decide'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->manager->givePermissionTo(['approval.view', 'approval.decide']);

        foreach ([['purchase', 'order'], ['hr', 'payroll']] as [$module, $action]) {
            $flow = ApprovalFlow::create(['module' => $module, 'action' => $action]);
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $this->manager->id,
            ]);
        }

        // ক্রয়ে ষাটটা (সীমার বেশি), বেতনে পাঁচটা (সীমার কম) — দুই দিকই
        // একসাথে দেখা যায়, আর চিপ দুইটা আলাদা সংখ্যা বলে
        $this->pileUp('purchase', 'order', self::PURCHASE);
        $this->pileUp('hr', 'payroll', self::PAYROLL);
    }

    /**
     * পর্দায় পঞ্চাশটা সারি — কিন্তু উপরে লেখা থাকে পঁয়ষট্টি।
     */
    public function test_the_screen_shows_fifty_rows_but_says_how_many_are_really_waiting(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index'))
            ->assertOk();

        $this->assertCount(self::SHOWN, $page->viewData('approvals'),
            'সীমাটাই বসেনি — তালিকা এখনো সীমাহীন');

        $this->assertSame(self::PURCHASE + self::PAYROLL, $page->viewData('visibleTotal'),
            'উপরের গোনাটা পাতার হয়ে গেছে — `$approvals->count()` লেখা আছে কি?');

        // আর কাটা পড়ার কথাটা পর্দাতেই, লুকানো নয়
        $page->assertSee(__('approval::message.inbox_capped', [
            'shown' => self::SHOWN,
            'total' => self::PURCHASE + self::PAYROLL,
        ]));
    }

    /**
     * ⭐ চিপের সংখ্যাগুলো পুরো তালিকার — সীমার নয়।
     *
     * এটাই সীমা বসানোর সবচেয়ে সূক্ষ্ম পার্শ্বপ্রতিক্রিয়া। আগে গোনাটা
     * ছিল `$waiting->countBy('module')`, আর তালিকাটা পুরোটা হাতে ছিল
     * বলে সেটা ঠিক কাজ করত। সীমা বসার পর ওভাবে গুনলে চিপে উঠত
     * "ক্রয় ৫০" — অথচ ক্রয়ে ষাটটা ঝুলে আছে।
     */
    public function test_the_module_chips_count_the_whole_list_not_the_shown_fifty(): void
    {
        $modules = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index'))
            ->assertOk()
            ->viewData('modules');

        $this->assertSame(self::PURCHASE, $modules['purchase']['count'],
            'চিপটা সীমার ভেতর থেকে গুনছে — গোনাটা আলাদা কোয়েরিতে হওয়ার কথা');

        $this->assertSame(self::PAYROLL, $modules['hr']['count']);
    }

    /**
     * ছাঁকনি দিলে সীমাটা ছাঁকনির **পরে** বসে।
     *
     * ── কেন এটা আলাদা করে দেখা দরকার ────────────────────────────────
     * ⚠️ ক্রমটা উল্টে গেলে ভুলটা নীরব: ডাটাবেজ সব মডিউল মিলিয়ে প্রথম
     * পঞ্চাশটা দিত, তারপর তার ভেতর থেকে "ক্রয়" বাছা হত। ক্রয়ে ষাটটা
     * থাকা সত্ত্বেও পর্দায় হয়তো কয়েকটা আসত, আর চিপে লেখা থাকত ৬০ —
     * দুইটা সংখ্যা একে অন্যকে মিথ্যা বলত, আর কোনটা সত্যি তা পর্দা দেখে
     * বলার উপায় থাকত না।
     */
    public function test_the_filter_narrows_before_the_limit_cuts(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index', ['module' => 'purchase']))
            ->assertOk();

        $this->assertCount(self::SHOWN, $page->viewData('approvals'),
            'ক্রয়ে ষাটটা আছে, অথচ পঞ্চাশটাও আসেনি — সীমা ছাঁকনির আগে বসেছে');

        // ছাঁকনি দেওয়া অবস্থায় "মোট" মানে ওই মডিউলের মোট
        $this->assertSame(self::PURCHASE, $page->viewData('visibleTotal'));

        // ⚠️ তবু চিপগুলো পুরো তালিকার — নাহলে "ক্রয়" বেছে নেওয়ার পর
        // বেতনের চিপ শূন্য দেখাত, আর ফিরে যাওয়ার পথ হারিয়ে যেত
        $this->assertSame(self::PAYROLL, $page->viewData('modules')['hr']['count']);
    }

    /**
     * সব মডিউল বেছে নিলে ছাঁকনির কোনো সারি বাদ পড়ে না।
     *
     * বেতনে পাঁচটা, সীমার অনেক কম — তাই এখানে সীমার কোনো ভূমিকা নেই,
     * আর কাটা পড়ার কথাটাও পর্দায় থাকার কথা নয়।
     */
    public function test_a_short_module_shows_everything_and_says_nothing_about_a_cap(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index', ['module' => 'hr']))
            ->assertOk();

        $this->assertCount(self::PAYROLL, $page->viewData('approvals'));
        $this->assertSame(self::PAYROLL, $page->viewData('visibleTotal'));

        $page->assertDontSee(__('approval::message.inbox_capped', [
            'shown' => self::PAYROLL,
            'total' => self::PAYROLL,
        ]));
    }

    /**
     * ⭐ গোনাটা ডাটাবেজে হয়, সারি তুলে নয়।
     *
     * ── কেন এটা ইনবক্সের সীমার চেয়েও বড় ────────────────────────────
     * [[ApprovalEngine::pendingFor]] তিন জায়গা থেকে ডাকা হয়, আর
     * **দুইজনের কেবল একটা সংখ্যা দরকার**: [[App\Core\Services\StatusNotices]]
     * আর [[App\Modules\Approval\Dashboard\ApprovalWidgets]]। দুইজনই পুরো
     * তালিকা (সাথে প্রতিটা অনুরোধকারী) মেমরিতে তুলে `count()` করত।
     *
     * ⚠️ আর ওই দুইটা ইনবক্স নয় — **প্রায় প্রতিটা পাতায়** চলে। অর্থাৎ
     * জটে পড়া একটা কোম্পানিতে সবচেয়ে দামি কোয়েরিটা এমন একটা পর্দায়
     * চলত যেখানে একটা সারিও দেখানো হয় না।
     *
     * দাবিটা দুই ভাগে: উত্তরটা আগের মতোই (আচরণ বদলায়নি), আর পথটা
     * বদলেছে (SQL-এ `count(*)`, আর সারি তোলার কোয়েরি নেই)।
     */
    public function test_the_count_happens_in_the_database_and_never_loads_the_rows(): void
    {
        $engine = app(ApprovalEngine::class);

        $this->actingAs($this->manager);

        $expected = self::PURCHASE + self::PAYROLL;

        $this->assertSame($expected, $engine->pendingFor($this->manager)->count(),
            'সেটআপেই সারিগুলো বসেনি — নিচের দাবিগুলো তখন কিছুই প্রমাণ করে না');

        $seen = [];
        DB::listen(function ($query) use (&$seen): void {
            $seen[] = $query->sql;
        });

        $this->assertSame($expected, $engine->pendingQueryFor($this->manager)->count(),
            'কোয়েরি-পথ আর তালিকা-পথ দুই সংখ্যা দিচ্ছে');

        $aggregates = array_filter($seen, fn (string $q) => str_contains(strtolower($q), 'count(*)'));

        $this->assertNotEmpty($aggregates,
            "গোনার জন্য কোনো count(*) কোয়েরিই যায়নি:\n".implode("\n", $seen));

        /*
         * ⛔ আর সারি তোলার কোনো কোয়েরি নয় — এটাই আসল দাবি।
         *
         * `count(*)` থাকা প্রমাণ করে না যে সারিগুলো **তোলা হয়নি**;
         * দুইটাই একসাথে ঘটতে পারত। তাই উল্টো দিকটাও দেখা হয়।
         */
        $hydrating = array_values(array_filter(
            $seen,
            fn (string $q) => (bool) preg_match('/select\s+\*\s+from\s+.approvals./i', $q),
        ));

        $this->assertSame([], $hydrating, 'গোনার পথে সারিগুলো এখনো তোলা হচ্ছে');

        // অনুরোধকারীর eager load-ও নয় — ওটাই ছিল খরচের দ্বিতীয় অর্ধেক
        $requesters = array_values(array_filter(
            $seen,
            fn (string $q) => (bool) preg_match('/from\s+.users.\s+where\s+.users.\..id.\s+in/i', $q),
        ));

        $this->assertSame([], $requesters, 'গোনার পথে requester এখনো eager load হচ্ছে');
    }

    /**
     * একই রকম অনেকগুলো অপেক্ষমাণ অনুরোধ — জটে পড়া একটা কোম্পানি।
     */
    private function pileUp(string $module, string $action, int $many): void
    {
        $asker = User::factory()->create(['current_company_id' => $this->company->id]);

        for ($i = 1; $i <= $many; $i++) {
            $branch = Branch::create([
                'company_id' => $this->company->id,
                'code' => strtoupper(substr($module, 0, 2)).$i,
                'name_en' => ucfirst($module).' branch '.$i,
            ]);

            Approval::create([
                'company_id' => $this->company->id,
                'approvable_type' => Branch::class,
                'approvable_id' => $branch->id,
                'module' => $module,
                'action' => $action,
                'status' => Approval::PENDING,
                'current_level' => 1,
                'requested_by' => $asker->id,
                // পুরনোটা আগে — ক্রমটা যেন সত্যিই মাপা যায়
                'requested_at' => now()->subDays($many - $i),
            ]);
        }
    }
}
