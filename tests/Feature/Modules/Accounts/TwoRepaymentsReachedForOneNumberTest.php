<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanMovement;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ⓘ ঋণের দুই চলাচল একসাথে একই নম্বরে হাত বাড়াত (a6-র Accounts নিরীক্ষা, fe মারফত, ১৩ অক্টোবর ২০২৬; [[LoanService::movement()]])।
 *
 * ⚠️ নম্বর = ঋণের চলাচল গুনে +১, তালা ছাড়া। দুইজন একসাথে শোধ বসালে দুজনেই একই সংখ্যা গুনতেন; (company_id, document_no) অনন্য, তাই
 * পরে যিনি লিখতেন তিনি "Duplicate entry"-তে ৫০০ পেতেন — বসানো শোধ হারাত। ⭐ এখন গোনার আগে ঋণের সারিতে তালা: দ্বিতীয়জন অপেক্ষা
 * করেন, প্রথমজনের শোধ বসে, নম্বর আলাদা।
 */
final class TwoRepaymentsReachedForOneNumberTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'loan_two';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->loan = app(LoanService::class)->create(data: [
            'lender' => 'City Bank', 'kind' => Loan::CC, 'sanctioned' => '500000', 'interest_rate' => '13.5', 'start_date' => '2026-07-01',
            'principal_account_id' => Account::query()->where('code', '2210')->value('id'),
            'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
        ]);
        app(LoanService::class)->drawDown($this->loan, '100000', $this->bank()->id);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoTransfersEmptiedOneTillTest::tearDown()]]-এর কারণেই। */
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

    public function test_a_second_repayment_counted_at_the_same_moment_does_not_break_the_first(): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        // ⓘ প্রথমজন চলাচল গুনলেন — ঠিক তখনই আরেকজন একই ঋণে শোধ বসাতে গেলেন
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_contains($query->sql, 'loan_movements') || ! str_contains(strtolower($query->sql), 'count(')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                app(LoanService::class)->repay(Loan::acrossBranches()->findOrFail($this->loan->id), '1000', $this->bank()->id);
            } catch (QueryException) {
                // ⭐ তালা ধরা — দ্বিতীয়জন অপেক্ষায় আটকালেন
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        try {
            $first = app(LoanService::class)->repay($this->loan, '2000', $this->bank()->id);
        } catch (QueryException $e) {
            $this->fail('⛔ একই মুহূর্তে আরেকজন গুনলেন, আর প্রথম শোধটাই ভেঙে গেল: '.$e->getMessage());
        }

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — দ্বিতীয় শোধ কখনো চলেনি।');
        $this->assertSame(LoanMovement::POSTED, $first->fresh()->status);

        $numbers = LoanMovement::query()->where('loan_id', $this->loan->id)->pluck('document_no')->all();
        $this->assertSame(count($numbers), count(array_unique($numbers)), '⛔ একই ঋণে দুই চলাচলের একই নম্বর।');
    }

    private function bank(): Account
    {
        return Account::query()->firstOrCreate(['code' => '1102-LOANRACE'], [
            'company_id' => CompanyContext::id(), 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Loan race bank', 'name_bn' => 'ঋণের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);
    }
}
