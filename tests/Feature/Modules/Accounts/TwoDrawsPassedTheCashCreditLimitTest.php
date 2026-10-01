<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanMovement;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
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
 * দুইটা তোলা একসাথে, আর CC-র সীমা পার — চূড়ান্ত অডিট ⛔৮, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[LoanService::drawDown()]] সীমা দেখত লেনদেন আর তালা ছাড়া। ১,০০০ সীমার CC থেকে দুইজন
 * একসাথে ৬০০ করে তুললে দুইজনেই "১,০০০ খালি" দেখতেন — কেউ তখনো লেখেননি — আর দুইজনেই
 * লিখতেন: খাতায় ১,২০০, অথচ ব্যাংক ৪০০-র বেশি দেবে না। আর পুরো কাজটা লেনদেনে মোড়া
 * ছিল না — খাতায় বসানো আটকালে তোলার সারিটা একা পড়ে থাকত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * পুরো তোলা এক লেনদেনে; শুরুতেই ঋণের সারিতে তালা, তারপর তাজা বাকি দেখে সীমা।
 *
 * ── ⓘ দৌড়টা কীভাবে সাজানো ────────────────────────────────────────────
 * [[TwoCountersSoldPastTheLimitTest]]-এর ছাঁচ: আমাদের তোলার প্রথম লেনদেন শুরু হওয়ার মুহূর্তে
 * অন্যজন আরেক সংযোগে ৬০০ তুলে কমিট করেন।
 */
final class TwoDrawsPassedTheCashCreditLimitTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'loan_two';

    private Loan $loan;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; সারাই যেন তাঁকেও না আটকায়
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->cash = app(CashTillService::class)->ensurePrimaryTill()->account;

        $this->loan = app(LoanService::class)->create([
            'lender' => 'Islami Bank',
            'kind' => Loan::CC,
            'sanctioned' => '1000',
            'interest_rate' => '0',
            'tenure_months' => 12,
            'interest_method' => 'flat',
            'start_date' => now()->startOfMonth()->toDateString(),
            'principal_account_id' => (int) StandardChart::find(StandardChart::PAYABLE)?->id,
            'interest_account_id' => (int) Account::query()->postable()->where('type', 'expense')->value('id'),
        ]);

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

    /** ⭐ সীমার ভিতরে একা তোলা — মালিকের হাতে চলে। */
    public function test_a_draw_within_the_limit_is_taken(): void
    {
        app(LoanService::class)->drawDown($this->loan, '600', (int) $this->cash->id);

        $this->assertSame(0, bccomp('600', $this->loan->fresh()->outstanding(), 4));
    }

    /** ⛔ অন্যজন ঠিক এই মুহূর্তে ৬০০ তুললেন — আমাদের ৬০০ ফিরে যায়, আর সীমা পার হয় না। */
    public function test_the_second_draw_is_refused_when_the_first_committed_meanwhile(): void
    {
        $this->whileSomeoneElseDraws(fn () => app(LoanService::class)->drawDown(
            Loan::query()->findOrFail($this->loan->id), '600', (int) $this->cash->id,
        ));

        $refused = false;

        try {
            app(LoanService::class)->drawDown($this->loan, '600', (int) $this->cash->id);
        } catch (ValidationException $e) {
            $refused = array_key_exists('amount', $e->errors());
        }

        $outstanding = $this->loan->fresh()->outstanding();

        $this->assertLessThanOrEqual(0, bccomp($outstanding, '1000', 4), "⛔ CC-র সীমা পার: খাতায় {$outstanding}, সীমা ১,০০০।");
        $this->assertTrue($refused, '⛔ দ্বিতীয় তোলা "সীমার বাইরে" বলে ফেরেনি।');
        $this->assertSame(1, LoanMovement::query()->where('loan_id', $this->loan->id)->where('kind', LoanMovement::DRAW)->count(),
            '⛔ ফেরানো তোলার সারিও রয়ে গেছে।');
    }

    /**
     * ⛔ আমরা লেনদেনের ভিতরে বাকি পড়লাম, ঠিক তখনই অন্যজন ৬০০ তুলতে গেলেন।
     *
     * ⓘ তালাটাই এখানে কাজ করে: ঋণের সারি আমাদের হাতে, তাই অন্যজন অপেক্ষায় আটকান (এক সেকেন্ড)।
     * তালা না থাকলে তাঁরটা কমিট হত, আর আমাদের পড়া বাকি তখন বাসি।
     */
    public function test_a_draw_started_while_ours_holds_the_loan_waits_and_is_turned_away(): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_contains($query->sql, 'ledger_entries')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                app(LoanService::class)->drawDown(Loan::query()->findOrFail($this->loan->id), '600', (int) $this->cash->id);
            } catch (QueryException|ValidationException) {
                // ⭐ তালা ধরে আছে — অন্যজন অপেক্ষায় আটকালেন
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        try {
            app(LoanService::class)->drawDown($this->loan, '600', (int) $this->cash->id);
        } catch (ValidationException) {
            // দুইজনের একজন ফিরবেন — কোনজন, সেটা প্রশ্ন নয়
        }

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের তোলা কখনো চলেনি।');

        $outstanding = $this->loan->fresh()->outstanding();
        $this->assertLessThanOrEqual(0, bccomp($outstanding, '1000', 4), "⛔ CC-র সীমা পার: খাতায় {$outstanding}, সীমা ১,০০০।");
    }

    /** আমাদের প্রথম লেনদেন শুরুর মুহূর্তে অন্যজনের কাজ — আরেক সংযোগে, কমিটসহ, একবারই। */
    private function whileSomeoneElseDraws(callable $other): void
    {
        /*
         * ⚠️ অনুমতি আর ভূমিকা আগেই পড়া থাক — ৩০ সেপ্টেম্বর ২০২৬-এ মেপে পাওয়া।
         *
         * লগইন করা ব্যবহারকারীর মডেলটা প্রধান সংযোগে লোড করা, তাই অন্যজনের কাজের মাঝে তাঁর
         * অনুমতি পড়া হত **আমাদের** লেনদেনে — আর সেই পড়াই আমাদের "ছবি" অন্যজনের কমিটের আগে
         * তুলে ফেলত। আসলে দুই অনুরোধ দুই প্রক্রিয়ায় চলে, এমন হয় না; এক প্রক্রিয়ার পরীক্ষাই
         * এটা বানাত। ⓘ আগে পড়ে রাখলে দৌড়টা বাস্তবের মতো থাকে।
         */
        auth()->user()?->loadMissing(['roles', 'permissions']);
        auth()->user()?->getAllPermissions();

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
}
