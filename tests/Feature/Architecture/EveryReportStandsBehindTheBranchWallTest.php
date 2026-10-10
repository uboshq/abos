<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * প্রতিটা নিবন্ধিত রিপোর্ট শাখার দেয়ালের পিছনে — অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩।
 *
 * ── ⛔ কেন এই পাহারা ───────────────────────────────────────────────────
 * রিপোর্টগুলো `DB::table()` দিয়ে লেখা, তাই মডেলের শাখা-ছাঁকনি সেখানে পৌঁছায়
 * না; দেয়ালটা বসে [[ReportEngine::branchWall()]] দিয়ে, প্রতিটা রিপোর্টের
 * নিজের কোয়েরিতে। ⚠️ নতুন একটা রিপোর্ট ওটা ডাকতে ভুলে গেলে ইঞ্জিন
 * শাখায়-আটকানো মানুষের জন্য সেটা চালায়ই না (ফাঁস নয়, ফেরত) — কিন্তু
 * সেটা ধরা পড়ত কেবল যেদিন ঐ মানুষটা পাতাটা খুলতেন। ⭐ এই পাহারা সেটা
 * আজই ধরে: প্রতিটা নিবন্ধিত রিপোর্ট এখানে একবার চলে।
 *
 * ── ছাড় কেবল লেখা কারণসহ ──────────────────────────────────────────────
 * কিছু রিপোর্টে শাখা বলে কিছু নেই, বা গোটা কোম্পানির সংখ্যা শাখায় ভাগ করা
 * যায় না। ⓘ ওগুলো রিপোর্ট নিজে ঘোষণা করে ([[ReportDefinition::$branchless]]),
 * আর **এখানেও** কারণসহ লেখা থাকতে হয় — দুইটা না মিললে লাল। ⛔ কারণ ছাড়া
 * ঘোষণা মানে দেয়ালটা নীরবে তুলে দেওয়া।
 *
 * ⓘ দেয়ালের শাখাগুলো ইচ্ছা করে **অস্তিত্বহীন** আইডি (৯০০০০১/৯০০০০২) —
 * কোম্পানি, তারিখ বা অন্য কোনো বাঁধা মানের সাথে কখনো মেলে না, তাই কোয়েরির
 * বাঁধা মানে ওদের দেখা মানে দেয়ালটাই বসেছে, কাকতাল নয়।
 */
final class EveryReportStandsBehindTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private const WALL = [900001, 900002];

    private const WHOLE = ReportDefinition::WHOLE_COMPANY;

    private const NONE = ReportDefinition::NO_BRANCH_DATA;

    /**
     * ⛔ ফেরানো রিপোর্ট — প্রতিটার কারণ লেখা।
     *
     * সংখ্যাগুলো শাখার নথি থেকে আসে, কিন্তু যে টেবিল থেকে গোনা হয় তাতে শাখা
     * নেই, বা একটা সারি কয়েক শাখার নথি জোড়ে → শাখায় আটকানো মানুষকে রিপোর্টটা
     * **ফেরানো হয়** (৪০৩), কম-বেশি দেখানো হয় না ([[ReportDefinition::WHOLE_COMPANY]])।
     *
     * ⓘ এটা ছাড় নয়, তার উল্টো: এখানে নাম লেখা মানে রিপোর্টটা **বেশি** মানুষের
     * কাছে বন্ধ — তাই `EveryExcuseWeGrantedIsCountedTest`-এর গোনায় নেই।
     *
     * @var array<string, string>
     */
    private const REFUSED = [
        'approval.pending' => '`approvals` has no branch_id; each row is a document of some branch, with its amount',
        // ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৪
        'notification.channel_availability' => '`notification_channels` is company-level configuration with no branch_id; attempts are counted for the whole company',
        'notification.audit_report' => '`notification_audit_logs` has no branch_id; it records actions on notifications of every branch',
        'approval.approved' => '`approvals` has no branch_id; each row is a document of some branch, with its amount',
        'approval.rejected' => '`approvals` has no branch_id; each row is a document of some branch, with its amount',
        'approval.by_user' => 'counts decisions on every branch\'s documents; `approval_decisions` has no branch_id',
        'approval.bottleneck' => 'counts waiting documents of every branch; `approvals` has no branch_id',
        'approval.why_rejected' => 'counts rejections of every branch\'s documents; `approval_decisions` has no branch_id',
        'inventory.replenishment' => 'reorder level and max stock are per product, company-wide; one branch against them gives a wrong suggestion',
        'inventory.stock_value' => 'valued from `inv_cost_layers`, which are company-wide (costing is per company, not per branch)',
        'purchase.settlement' => 'one row joins goods-in, sales, payments and balance of different branches (see the report\'s own note)',
        'purchase.return_on_capital' => 'same four-document join as settlement; no single branch owns a row',
        'sales.collection_target' => 'a dealer has one monthly target for the whole company, and its achievement is counted company-wide like the bill reminder (CustomerTargetService is a CHECKS ledger reader)',
        'promotion.active' => '`given_worth` sums `promotion_applications`, which carry no branch',
        'promotion.expired' => '`given_worth` sums `promotion_applications`, which carry no branch',
        'promotion.cancelled_offers' => 'application count and given worth come from `promotion_applications`, which carry no branch',
        'promotion.utilization' => 'sums `promotion_applications`, which carry no branch (source is polymorphic)',
        'promotion.by_customer' => 'sums `promotion_applications`, which carry no branch (source is polymorphic)',
        'promotion.by_product' => 'sums `promotion_applications`, which carry no branch (source is polymorphic)',
        'promotion.discounts' => 'lists `promotion_applications`, which carry no branch (source is polymorphic)',
        'promotion.budgets' => '`used` sums `promotion_applications`, which carry no branch',
        'promotion.overrides' => 'lists `promotion_applications`, which carry no branch (source is polymorphic)',
        'promotion.reversals' => 'lists `promotion_applications`, which carry no branch (source is polymorphic)',
    ];

    /**
     * ⚠️ সবার জন্য একই রিপোর্ট — এটাই আসল ছাড়, আর তাই গোনায় আছে।
     *
     * সারিগুলো কোনো শাখার জিনিসই নয় ([[ReportDefinition::NO_BRANCH_DATA]]),
     * তাই দেয়াল ছাড়াই চলে, শাখায় আটকানো মানুষের জন্যও।
     *
     * @var array<string, string>
     */
    private const SAME_FOR_EVERYONE = [
        'promotion.register' => 'the offer register: code, name, dates, who approved — company-wide setup, no figures from any branch',
        'system_admin.notice_register' => 'notices are company-wide announcements; `notices` has no branch_id and no money',
        'system_admin.notice_signatures' => 'read/sign counts of company-wide notices; no branch owns a notice',
        'governance.periods' => 'months closed and reopened: a period lock is company-wide (period_locks has no branch_id), so every branch sees the same locks',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        foreach (self::WALL as $branch) {
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $branch,
            ]);
        }

        app(DataScope::class)->forget();
        $this->actingAs($user);
    }

    /**
     * ⛔ ছাড় দুই জায়গায় লেখা, আর দুইটা মেলে — রিপোর্টের ঘোষণা আর এখানকার
     * কারণ। ⓘ বাসি সারিও লাল: যে রিপোর্ট আর নেই তার কারণ এখানে পড়ে থাকলে
     * তালিকাটা আর বিশ্বাসযোগ্য থাকে না।
     */
    public function test_every_exemption_is_declared_by_the_report_and_written_here_with_a_reason(): void
    {
        $engine = app(ReportEngine::class);
        $keys = $engine->keys();

        $this->assertGreaterThan(50, count($keys), 'নিবন্ধিত রিপোর্ট মাত্র '.count($keys).'টা — খোঁজাটাই ভেঙেছে।');

        $mismatch = [];

        foreach ($keys as $key) {
            $declared = $engine->get($key)->branchless;
            $written = self::exempt()[$key][0] ?? null;

            if ($declared !== $written) {
                $mismatch[] = $key.': report says '.var_export($declared, true).', guard says '.var_export($written, true);
            }
        }

        foreach (self::exempt() as $key => [$kind, $reason]) {
            if (! in_array($key, $keys, true)) {
                $mismatch[] = $key.': exempted here but no longer registered';
            }

            if (trim($reason) === '') {
                $mismatch[] = $key.': exempted without a reason';
            }
        }

        $this->assertSame([], $mismatch, implode("\n", [
            'শাখার দেয়ালের ছাড় রিপোর্ট আর পাহারায় মেলে না:', '', ...$mismatch, '',
            '⛔ ছাড় দিতে হলে রিপোর্টে `branchless:` আর এখানে কারণ — দুইটাই।',
        ]));
    }

    /**
     * ⭐ ছাড়হীন প্রতিটা রিপোর্ট শাখায়-আটকানো মানুষের জন্য চলে, আর তার কোয়েরি
     * সত্যিই দেয়ালের শাখাগুলো বাঁধে।
     *
     * ⓘ চালানো হয় সত্যিকারের ডাটাবেজে — `whereIn` বসানোর পরেও SQL-টা
     * বৈধ কি না (লাইভের MySQL কড়া) সেটাও এখানে মাপা হয়।
     */
    public function test_every_other_report_lays_the_branch_wall_on_its_query(): void
    {
        $engine = app(ReportEngine::class);
        $open = array_values(array_diff($engine->keys(), array_keys(self::exempt())));

        $this->assertGreaterThan(30, count($open), 'দেয়াল-বাধ্য রিপোর্ট মাত্র '.count($open).'টা — খোঁজাটাই ভেঙেছে।');

        $broken = [];

        foreach ($open as $key) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $engine->run($key, $this->range());
            } catch (Throwable $e) {
                $broken[] = $key.': '.$e::class.' — '.$e->getMessage();

                continue;
            } finally {
                DB::disableQueryLog();
            }

            $bound = [];

            foreach (DB::getQueryLog() as $query) {
                foreach ($query['bindings'] as $value) {
                    $bound[] = is_numeric($value) ? (int) $value : $value;
                }
            }

            foreach (self::WALL as $branch) {
                if (! in_array($branch, $bound, true)) {
                    $broken[] = $key.': ran, but no query bound branch '.$branch;
                }
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            'এই রিপোর্টগুলো শাখার দেয়ালের পিছনে নেই:', '', ...$broken, '',
            '⭐ কোয়েরিতে `->tap(ReportEngine::branchWall($f, \'<table>.branch_id\'))`,',
            'অথবা শাখা সত্যিই না থাকলে `branchless:` + এখানে কারণ।',
        ]));
    }

    /** ⛔ গোটা কোম্পানির প্রতিটা রিপোর্ট শাখায়-আটকানো মানুষকে ফেরায়। */
    public function test_every_whole_company_report_is_refused_to_a_branch_limited_user(): void
    {
        $engine = app(ReportEngine::class);
        $leaked = [];

        foreach (self::exempt() as $key => [$kind]) {
            if ($kind !== self::WHOLE) {
                continue;
            }

            try {
                $engine->run($key, $this->range());
                $leaked[] = $key;
            } catch (AuthorizationException) {
                // ⭐ ঠিক এটাই
            }
        }

        $this->assertSame([], $leaked, "⛔ গোটা কোম্পানির রিপোর্ট শাখায়-আটকানো মানুষ পেয়েছেন:\n".implode("\n", $leaked));
    }

    /** ⭐ শাখাহীন রিপোর্ট সবার জন্য একই — আটকানো মানুষের জন্যও চলে। */
    public function test_every_report_without_branch_data_still_runs_for_a_branch_limited_user(): void
    {
        $engine = app(ReportEngine::class);
        $ran = 0;

        foreach (self::exempt() as $key => [$kind]) {
            if ($kind === self::NONE) {
                $engine->run($key, $this->range());
                $ran++;
            }
        }

        $this->assertGreaterThan(0, $ran, 'শাখাহীন একটা রিপোর্টও চলেনি — দাবিটা কিছু মাপছে না।');
    }

    /**
     * ⛔ পাহারাটা সত্যিই কামড়ায়: দেয়াল না বসানো একটা নতুন রিপোর্ট আটকানো
     * মানুষের জন্য **চলে না** — ইঞ্জিন ফেরায়, সারি ফাঁস করে না।
     * ⭐ ঐ একই রিপোর্ট, একই মানুষ, সীমা তুলে নিলে চলে — ফেরানোটা দেয়ালের
     * জন্যই, অন্য কিছুর জন্য নয়।
     */
    public function test_a_report_that_forgets_the_wall_is_refused_not_leaked(): void
    {
        $engine = app(ReportEngine::class);

        $engine->register(new ReportDefinition(
            key: 'guard.forgot_the_wall',
            title: 'Forgot the wall',
            query: fn (array $f) => DB::table('sal_invoices')->where('company_id', $f['company_id'])->select('id'),
            columns: [['key' => 'id', 'label' => 'Id', 'type' => ReportColumn::TEXT]],
            filters: ['branch'],
        ));

        try {
            $engine->run('guard.forgot_the_wall');
            $this->fail('⛔ দেয়াল ছাড়া রিপোর্ট শাখায়-আটকানো মানুষের জন্য চলে গেছে।');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('guard.forgot_the_wall', $e->getMessage());
        }

        UserDataScope::query()->withoutGlobalScopes()->where('user_id', auth()->id())->delete();
        app(DataScope::class)->forget();

        $this->assertSame(1, $engine->run('guard.forgot_the_wall')->page);
    }

    /** @return array<string, array{0: string, 1: string}> রিপোর্ট → [ধরন, কারণ] */
    private static function exempt(): array
    {
        return [
            ...array_map(fn (string $why): array => [self::WHOLE, $why], self::REFUSED),
            ...array_map(fn (string $why): array => [self::NONE, $why], self::SAME_FOR_EVERYONE),
        ];
    }

    /** @return array{from: string, to: string} */
    private function range(): array
    {
        return ['from' => now()->subMonths(3)->toDateString(), 'to' => now()->toDateString()];
    }
}
