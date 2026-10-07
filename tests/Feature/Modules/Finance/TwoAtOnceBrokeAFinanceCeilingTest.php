<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Models\WithdrawalLimit;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
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
 * দুইজন একসাথে, আর Finance-এর একটা ছাদ পার — চূড়ান্ত অডিট ⛔১১, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * দুইটা ছাদ দেখা হত লেনদেনের বাইরে, তালা ছাড়া:
 *   • [[ProfitDistribution::declare()]] — সঞ্চিত মুনাফার বেশি ঘোষণা নয়। দুইজন একসাথে
 *     ৬০,০০০ করে ঘোষণা করলে দুইজনেই "১,০০,০০০ আছে" দেখতেন — সঞ্চিত মুনাফা ঋণাত্মক।
 *   • [[WithdrawalService::request()]] — মাসের উত্তোলনের ছাদ। দুইটা ৬,০০০ টাকার অনুরোধ
 *     একসাথে এলে ১০,০০০ ছাদ পার।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের ভিতরে তালা (সঞ্চিত মুনাফার খাত / মানুষের সারি), তারপর ছাদ আবার।
 *
 * ── ⓘ দুই রকম দৌড়, দুইটা আলাদা প্রশ্ন ──────────────────────────────
 *   • অন্যজন আমাদের লেনদেন **শুরুর মুহূর্তে** কমিট করেন → ভিতরের যাচাই তাজা দেখে কি না।
 *   • অন্যজন আমরা **পড়ার ঠিক পরে** চেষ্টা করেন → তালা তাঁকে আটকায় কি না।
 * [[TwoCountersSoldPastTheLimitTest]]-এর ছাঁচ: সত্যিকারের দুই সংযোগ।
 */
final class TwoAtOnceBrokeAFinanceCeilingTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'finance_two';

    private Person $partner;

    /** অন্যজনের ফল — `null` মানে তিনি চলেননি; নইলে 'done' বা ত্রুটির নাম */
    private ?string $other = null;

    /**
     * ঘোষণার আগে খাতার আসল সঞ্চিত মুনাফা — ডেমোরটা সহ।
     *
     * ⚠️ ১ অক্টোবর ২০২৬-এ মেপে পাওয়া: দাবিটা আগে ধরে নিত আয় ঠিক ১,০০,০০০, অথচ ডেমোর খাতায় আগে থেকেই
     * সঞ্চিত মুনাফা আছে। তখন দুইটা ৬০,০০০ আইনত চলত, আর "লাল" প্রমাণটা ছিল সংখ্যাটার, কোডের নয়।
     * ⭐ এখন প্রতিটা ঘোষণা আসল অঙ্কের ৬০% — দুইটা মিলে সত্যিই সীমা পার।
     */
    private string $available = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; সারাই যেন তাঁকেও না আটকায়
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->partner = $this->contribute('RACE-A', 'Partner A', '600000');
        $this->contribute('RACE-B', 'Partner B', '400000');
        $this->earn('100000');

        WithdrawalLimit::query()->create(['person_id' => $this->partner->id, 'monthly_cap' => '10000']);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        /*
         * ⚠️ অনুমতি আগেই পড়া থাক ([[TwoDrawsPassedTheCashCreditLimitTest]]-এ মেপে পাওয়া): লগইন
         * করা মানুষের মডেল প্রধান সংযোগের, তাই অন্যজনের কাজের মাঝে তাঁর অনুমতি পড়া হত
         * আমাদের লেনদেনে — আর আমাদের "ছবি" আগেভাগে উঠে যেত। বাস্তবে এমন হয় না।
         */
        auth()->user()?->loadMissing(['roles', 'permissions']);
        auth()->user()?->getAllPermissions();

        $this->available = StandardChart::find(StandardChart::RETAINED_EARNINGS)?->balanceOn() ?? '0';
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

    /**
     * ⓘ ঘোষণায় কেবল এই দৌড়টা — "আমরা পড়ার পরে অন্যজন" নয়: লেনদেনের ভিতরে নম্বর-সিরিজের
     * তালা দ্বিতীয় ঘোষণাকে এমনিতেই আটকায় (৩০ সেপ্টেম্বর ২০২৬-এ মেপে দেখা)। ⚠️ আসল ফাঁক ছিল
     * যাচাইটা লেনদেনের **বাইরে** — দুইজনেই আগে "১,০০,০০০ আছে" দেখে তারপর পালা করে লিখতেন।
     */
    public function test_a_declaration_made_meanwhile_is_seen_before_ours_posts(): void
    {
        $this->atOurBegin(fn () => $this->declare($this->share()));

        $this->tryTo(fn () => $this->declare($this->share()));

        $this->assertSame('done', $this->other, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের ঘোষণা হয়নি: '.var_export($this->other, true));
        $this->assertDeclaredWithinEarned();
    }

    /**
     * ⛔ আমরা লেনদেনের ভিতরে সঞ্চিত মুনাফা পড়লাম, ঠিক তখনই অন্যজন ঘোষণা করতে গেলেন — তালা তাঁকে আটকায়।
     *
     * ⓘ তালা না থাকলে অন্যজনের যাচাই আমাদের কমিটের আগেই "১,০০,০০০ আছে" দেখত, আর দুইজনে পালা করে
     * নম্বর-সিরিজের তালা পেরিয়ে লিখতেন — যাচাইয়ের পরের তালা দ্বিতীয়জনকে বাঁচায় না।
     */
    public function test_a_declaration_started_after_our_read_waits_for_our_lock(): void
    {
        $this->afterOurRead('ledger_entries', fn () => $this->declare($this->share()));

        $this->tryTo(fn () => $this->declare($this->share()));

        $this->assertNotNull($this->other, 'দৃশ্যটাই বানানো যায়নি — আমাদের লেনদেনে সঞ্চিত মুনাফার পড়াই হয়নি।');
        $this->assertDeclaredWithinEarned();
    }

    public function test_a_withdrawal_requested_meanwhile_is_counted_against_the_cap(): void
    {
        $this->atOurBegin(fn () => $this->request('6000'));

        $this->tryTo(fn () => $this->request('6000'));

        $this->assertSame('done', $this->other, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের অনুরোধ বসেনি: '.var_export($this->other, true));
        $this->assertWithinTheCap();
    }

    public function test_a_withdrawal_started_after_our_read_waits_for_our_lock(): void
    {
        $this->afterOurRead('fin_withdrawals', fn () => $this->request('6000'));

        $this->tryTo(fn () => $this->request('6000'));

        $this->assertNotNull($this->other, 'দৃশ্যটাই বানানো যায়নি — আমাদের লেনদেনে উত্তোলনের পড়াই হয়নি, অন্যজন চলেননি।');
        $this->assertWithinTheCap();
    }

    // ── দৌড় ────────────────────────────────────────────────────────────

    /** আমাদের লেনদেন শুরুর মুহূর্তে অন্যজনের কাজ — আরেক সংযোগে, কমিটসহ। */
    private function atOurBegin(callable $other): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $main, $other): void {
            if (! $armed || $event->connectionName !== $main) {
                return;
            }

            $armed = false;
            $this->onTheSecond($main, $other);
        });
    }

    /** আমাদের লেনদেন `$table` পড়ার ঠিক পরে অন্যজনের চেষ্টা — তালা থাকলে তিনি আটকান। */
    private function afterOurRead(string $table, callable $other): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main, $other, $table): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, $table)) {
                return;
            }

            $armed = false;
            $this->onTheSecond($main, $other);
        });
    }

    private function onTheSecond(string $main, callable $other): void
    {
        DB::setDefaultConnection(self::SECOND);

        try {
            $other();
            $this->other = 'done';
        } catch (QueryException|ValidationException $e) {
            // ⭐ তালায় আটকালেন বা ছাদে থামলেন — অন্যজন ফিরে গেলেন
            $this->other = class_basename($e).': '.mb_substr($e->getMessage(), 0, 160);
        } finally {
            DB::setDefaultConnection($main);
        }
    }

    private function tryTo(callable $ours): void
    {
        try {
            $ours();
        } catch (ValidationException) {
            // দুইজনের একজন ফিরবেন — কোনজন, সেটা প্রশ্ন নয়
        }
    }

    // ── কাজ আর দাবি ─────────────────────────────────────────────────────

    /** আসল সঞ্চিত মুনাফার ৬০% — দুইটা মিলে ১২০%। */
    private function share(): string
    {
        return bcdiv(bcmul($this->available, '60', 4), '100', 2);
    }

    private function declare(string $profit): void
    {
        app(ProfitDistribution::class)->declare(['profit' => $profit, 'trx_date' => now()->toDateString()]);
    }

    private function request(string $amount): void
    {
        app(WithdrawalService::class)->request([
            'person_id' => $this->partner->id,
            'amount' => $amount,
            'trx_date' => now()->toDateString(),
        ]);
    }

    /** ঘোষিত মোট — খাতায় উঠুক বা সই-এর অপেক্ষায় থাকুক — আয় করা ১,০০,০০০-এর বেশি নয়। */
    private function assertDeclaredWithinEarned(): void
    {
        $declared = (string) ProfitShare::query()
            ->whereHas('voucher', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('amount');

        $this->assertLessThanOrEqual(0, bccomp($declared, $this->available, 4), "⛔ ঘোষিত মুনাফা {$declared}, অথচ সঞ্চিত মুনাফা ছিল {$this->available} — যা আয়ই হয়নি তাও ভাগ হচ্ছে।");
    }

    private function assertWithinTheCap(): void
    {
        $taken = (string) Withdrawal::query()->where('person_id', $this->partner->id)->sum('amount');

        $this->assertLessThanOrEqual(0, bccomp($taken, '10000', 4), "⛔ মাসের উত্তোলন {$taken}, ছাদ ১০,০০০।");
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** মূলধনদাতা — [[TheProfitDeclaredWasMoreThanWasEverEarnedTest]]-এর একই উপায়। */
    private function contribute(string $code, string $name, string $amount): Person
    {
        $person = Person::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name, 'is_active' => true]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);

        return $person;
    }

    /** খাতায় সত্যিকারের সঞ্চিত মুনাফা — পোস্টিং ইঞ্জিন দিয়েই। */
    private function earn(string $amount): void
    {
        $cash = Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();

        app(PostingEngine::class)->post(
            sourceType: 'test:earned',
            sourceId: 1,
            trxDate: now()->subDays(2)->toDateString(),
            lines: [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'debit' => '0', 'credit' => $amount],
            ],
        );
    }
}
