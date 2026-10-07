<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই মাসের বেতন-রান দুইবার — চূড়ান্ত অডিট ⛔১৮, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "এই মাসে আর রান নেই" যাচাইটা ([[PayrollService::build()]]) চলত লেনদেনের **আগে**, আর
 * কিছুতে তালা দিত না। দুইজন একসাথে (বা একজন দুইবার) "রান বানান" চাপলে দুইজনেই "নেই"
 * দেখতেন, আর একই মাসের দুইটা খসড়া রান বসত — দুইটাই নিশ্চিত হলে বেতন দুইবার খাতায়।
 * মাইগ্রেশনে unique নেই (বাতিল রান বাদ দিতে হয়), তাই ডাটাবেজও আটকাত না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের ভিতরে কোম্পানির সারিতে তালা দিয়ে একই যাচাই আবার — দ্বিতীয়জন প্রথমজনের
 * কমিট দেখে ফিরে যান।
 *
 * ── ⓘ দৌড়টা কীভাবে সাজানো ────────────────────────────────────────────
 * [[TwoCountersSoldPastTheLimitTest]]-এর ছাঁচ: সত্যিকারের দুই সংযোগ। আমাদের রানের
 * লেনদেন শুরু হওয়ার মুহূর্তে অন্যজন আরেক সংযোগে একই মাসের রান বানিয়ে কমিট করেন —
 * আমাদের আগের দেখা তখন বাসি।
 */
final class TwoPayrollRunsForTheSameMonthTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'payroll_two';

    private const MONTH = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; সারাই যেন তাঁকেও না আটকায়
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-001',
            'name_en' => 'Rafiq Islam',
            'joining_date' => '2026-01-15',
            'payment_method' => 'cash',
        ]);

        app(SalaryStructureService::class)->set(
            $employee,
            SalaryHead::query()->where('code', 'BASIC')->firstOrFail(),
            '2026-01-15',
            '20000',
        );

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoCountersSoldPastTheLimitTest::tearDown()]]-এর কারণেই। */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge(self::SECOND);

        parent::tearDown();
    }

    /** ⭐ একা একটা রান — মালিকের হাতে চলে। */
    public function test_one_run_for_a_month_is_built(): void
    {
        $run = app(PayrollService::class)->build(self::MONTH);

        $this->assertSame(DocumentStatus::DRAFT, $run->status);
        $this->assertSame(1, $this->liveRuns());
    }

    /** ⛔ অন্যজন ঠিক এই মুহূর্তে একই মাসের রান কমিট করলেন — আমাদেরটা ফিরে যায়। */
    public function test_a_second_run_for_the_same_month_is_refused_when_the_first_committed_meanwhile(): void
    {
        $this->whileSomeoneElseBuilds(fn () => app(PayrollService::class)->build(self::MONTH));

        $refused = false;

        try {
            app(PayrollService::class)->build(self::MONTH);
        } catch (ValidationException $e) {
            $refused = array_key_exists('month', $e->errors());
        }

        $this->assertSame(1, $this->liveRuns(), '⛔ একই মাসের দুইটা বেতন-রান বসে গেছে।');
        $this->assertTrue($refused, '⛔ দ্বিতীয় রান "মাস আগেই চলেছে" বলে ফেরেনি।');
    }

    /**
     * ⛔ আমরা লেনদেনের ভিতরে যাচাই পড়লাম, ঠিক তখনই অন্যজন একই মাসের রান বানাতে গেলেন।
     *
     * ⓘ তালাটাই এখানে কাজ করে: কোম্পানির সারি আমাদের হাতে, তাই অন্যজন অপেক্ষায় আটকান (এক
     * সেকেন্ড) আর ফিরে যান। তালা না থাকলে তাঁরটা কমিট হত, আর আমাদের পড়া তখন বাসি।
     */
    public function test_a_run_started_while_ours_holds_the_month_waits_and_is_turned_away(): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_contains($query->sql, 'payroll_runs')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                app(PayrollService::class)->build(self::MONTH);
            } catch (QueryException|ValidationException) {
                // ⭐ তালা ধরে আছে, বা মাসটা ইতিমধ্যে নেওয়া — অন্যজন ফিরে গেলেন
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        try {
            app(PayrollService::class)->build(self::MONTH);
        } catch (ValidationException) {
            // দুইজনের একজন ফিরবেন — কোনজন, সেটা প্রশ্ন নয়
        }

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের রান কখনো চলেনি।');
        $this->assertSame(1, $this->liveRuns(), '⛔ একই মাসের দুইটা বেতন-রান বসে গেছে।');
    }

    /** আমাদের লেনদেন শুরুর মুহূর্তে অন্যজনের কাজ — আরেক সংযোগে, কমিটসহ, একবারই। */
    private function whileSomeoneElseBuilds(callable $other): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $main, $other): void {
            if (! $armed || $event->connectionName !== $main) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                $other();
            } finally {
                DB::setDefaultConnection($main);
            }
        });
    }

    private function liveRuns(): int
    {
        return PayrollRun::query()
            ->forMonth(self::MONTH)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->count();
    }
}
