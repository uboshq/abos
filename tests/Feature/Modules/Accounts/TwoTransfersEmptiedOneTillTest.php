<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * দুইটা স্থানান্তর একসাথে, আর একটা টিল শূন্যের নিচে — চূড়ান্ত অডিট ⛔৭, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[MoneyTransferService::initiate()]] টিলের জের দেখত লেনদেনের ভিতরে, কিন্তু তালা ছাড়া।
 * ১,০০০ টাকার টিল থেকে দুইজন একসাথে ৬০০ করে পাঠালে দুইজনেই "১,০০০ আছে" দেখতেন আর
 * দুইজনেই পাঠাতেন — টিল −২০০, অথচ ড্রয়ারে কখনো ১,২০০ ছিল না।
 * আর গ্রহণ ([[MoneyTransferService::confirm()]]) অবস্থা দেখত লেনদেনের বাইরে — একই স্থানান্তর
 * দুইবার "গ্রহণ" চাপলে দ্বিতীয়টা খাতার দরজায় ভেঙে পড়ত (৫০০), পরিষ্কার কথা নয়।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * পাঠানোয় টিলের খাতে তালা ([[CashOnHand::lock()]]), তারপর জের; গ্রহণে স্থানান্তরের সারিতে
 * তালা দিয়ে অবস্থা আবার ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
 *
 * ── ⓘ দৌড়টা কীভাবে সাজানো ────────────────────────────────────────────
 * আমাদের লেনদেন টিলের জের **পড়ার ঠিক পরে** অন্যজন আরেক সংযোগে একই টিল থেকে পাঠানোর চেষ্টা
 * করেন। তালা না থাকলে তাঁরটা কমিট হয়ে যায় আর আমাদের পড়া বাসি; তালা থাকলে তিনি অপেক্ষায়
 * আটকান (এক সেকেন্ড), আর আমাদের পাঠানো একাই যায়।
 */
final class TwoTransfersEmptiedOneTillTest extends TestCase
{
    use DatabaseMigrations;
    use PutsMoneyInTheTill;

    private const SECOND = 'till_two';

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; সারাই যেন তাঁকেও না আটকায়
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        app(StandardChart::class)->install();

        $tills = app(CashTillService::class);
        $this->from = $tills->create(['name_en' => 'Counter', 'holder_id' => $owner->id]);
        $this->to = $tills->create(['name_en' => 'Safe', 'holder_id' => $owner->id]);
        $this->putMoneyIn($this->from->account, '1000');

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

    /** ⭐ টিলে যা আছে তার ভিতরে একা পাঠানো আর গ্রহণ — মালিকের হাতে চলে। */
    public function test_one_transfer_is_sent_and_received(): void
    {
        $transfer = app(MoneyTransferService::class)->confirm($this->send('600'));

        $this->assertTrue($transfer->isConfirmed());
        $this->assertSame(0, bccomp('400', $this->from->fresh()->balance(), 4));
    }

    /** ⛔ আমরা জের পড়লাম, ঠিক তখনই অন্যজন একই টিল থেকে ৬০০ পাঠাতে গেলেন — টিল শূন্যের নিচে নয়। */
    public function test_two_sends_at_once_do_not_take_the_till_below_zero(): void
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
                $this->send('600');
            } catch (QueryException) {
                // ⭐ তালা ধরে আছে — অন্যজন অপেক্ষায় আটকালেন, পাঠাতে পারলেন না
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        try {
            $this->send('600');
        } catch (ValidationException) {
            // দুইজনের একজন "টাকা নেই" শুনবেন — কোনজন, সেটা প্রশ্ন নয়
        }

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের পাঠানো কখনো চলেনি।');

        $balance = $this->from->fresh()->balance();
        $this->assertGreaterThanOrEqual(0, bccomp($balance, '0', 4), "⛔ টিল শূন্যের নিচে: {$balance}।");
        $this->assertSame(1, MoneyTransfer::query()->where('from_till_id', $this->from->id)->count(),
            '⛔ ১,০০০ টাকার টিল থেকে দুইটা ৬০০ টাকার স্থানান্তর বসেছে।');
    }

    /** ⛔ পুরনো কপিতে দ্বিতীয় "গ্রহণ" — পরিষ্কার কথা, খাতার দরজায় ভেঙে পড়া নয়, আর দ্বিতীয় দাখিলা নয়। */
    public function test_receiving_twice_from_a_stale_copy_says_so_plainly(): void
    {
        $first = $this->send('600');
        $stale = MoneyTransfer::query()->findOrFail($first->id);

        app(MoneyTransferService::class)->confirm($first);

        $field = null;

        try {
            app(MoneyTransferService::class)->confirm($stale);
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());
        } catch (PostingException $e) {
            $field = 'posting: '.$e->getMessage();
        }

        $this->assertSame('status', $field, '⛔ দ্বিতীয় গ্রহণ পরিষ্কার কথায় ফেরেনি: '.var_export($field, true));
        $this->assertSame(2, LedgerEntry::query()
            ->where('source_type', MoneyTransfer::drillSourceType())
            ->where('source_id', $first->id)
            ->count(), '⛔ গ্রহণের দাখিলা দুইবার বসেছে।');
    }

    /** ⛔ পুরনো কপিতে দ্বিতীয় "বাতিল" — পরিষ্কার কথা, আর বিপরীত দাখিলা একবারই। */
    public function test_cancelling_twice_from_a_stale_copy_says_so_plainly(): void
    {
        $first = $this->send('600');
        $stale = MoneyTransfer::query()->findOrFail($first->id);

        app(MoneyTransferService::class)->cancel($first, 'ভুল টিল');

        $field = null;

        try {
            app(MoneyTransferService::class)->cancel($stale, 'আবার');
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());
        } catch (\Throwable $e) {
            $field = class_basename($e).': '.$e->getMessage();
        }

        $this->assertSame('status', $field, '⛔ দ্বিতীয় বাতিল পরিষ্কার কথায় ফেরেনি: '.var_export($field, true));
        $this->assertSame(0, bccomp('1000', $this->from->fresh()->balance(), 4),
            '⛔ বাতিলের বিপরীত দাখিলা দুইবার বসে টিলের জের বদলেছে: '.$this->from->fresh()->balance());
    }

    private function send(string $amount): MoneyTransfer
    {
        return app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'amount' => $amount,
            'trx_date' => now()->toDateString(),
        ]);
    }
}
