<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ একজনের দুইটা দাবি একসাথে অনুমোদন — একই অগ্রিম দুইবার নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ২;
 * [[ExpenseClaimService::approve()]], [[AdvanceBalance::lock()]])।
 *
 * ⓘ অনুমোদন তালা দিত কেবল দাবির সারিতে। একই কর্মীর দুইটা দাবি দুইজন একসাথে সই করলে দুইজনেই একই খোলা অগ্রিম পড়তেন আর দুইজনেই
 * তা থেকে কাটতেন — ৩,০০০ অগ্রিম থেকে ৪,০০০ মেটানো, কর্মীর ১১৩১ ঋণাত্মক।
 *
 * ⓘ দৌড়টা [[TwoPayrollRunsForTheSameMonthTest]]-এর ছাঁচে, সত্যিকারের দুই সংযোগ: আমাদের অনুমোদন খোলা অগ্রিম পড়ার মুহূর্তে অন্যজন
 * আরেক সংযোগে দ্বিতীয় দাবি অনুমোদন করতে যান।
 */
final class TwoClaimsTakeTheSameAdvanceTest extends TestCase
{
    use DatabaseMigrations;
    use PutsMoneyInTheTill;

    private const SECOND = 'claims_two';

    private Employee $employee;

    private User $worker;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->worker = User::factory()->create(['email' => 'worker@race.test', 'current_company_id' => $company->id, 'is_active' => true]);
        $this->worker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $this->worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-RACE', 'name_en' => 'Field Worker', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();
        $this->expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        // ⓘ ৩,০০০ অগ্রিম কর্মীর নামে
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($till->account, '100000', now()->subDays(2)->toDateString());
        app(PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->subDay()->toDateString(), lines: [
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'debit' => '3000', 'party_type' => 'employee', 'party_id' => $this->employee->id],
            ['account_id' => (int) $till->account_id, 'credit' => '3000'],
        ], branchId: $company->defaultBranch()?->id);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoPayrollRunsForTheSameMonthTest::tearDown()]]-এর কারণেই। */
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

    public function test_a_claim_approved_while_another_reads_the_same_advance_waits_and_the_advance_never_goes_negative(): void
    {
        $first = $this->claim('2000');
        $second = $this->claim('2000');

        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main, $second): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_contains($query->sql, 'ledger_entries') || ! str_contains($query->sql, 'SUM(debit)')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                app(ExpenseClaimService::class)->approve(ExpenseClaim::query()->findOrFail($second->id));
            } catch (QueryException|ValidationException) {
                // ⭐ কর্মীর সারি আমাদের হাতে — অন্যজন অপেক্ষায় আটকে ফিরে গেলেন
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        app(ExpenseClaimService::class)->approve($first);

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — অন্যজনের অনুমোদন কখনো চলেনি।');
        $this->assertGreaterThanOrEqual(0, bccomp($this->open(), '0', 2), '⛔ কর্মীর অগ্রিম ঋণাত্মক: '.$this->open());
        $taken = bcadd((string) ExpenseClaim::query()->sum('from_advance'), '0', 2);
        $this->assertSame(1, bccomp('3000.01', $taken, 2), '⛔ ৩,০০০ অগ্রিম থেকে '.$taken.' মেটানো হল — একই অগ্রিম দুইবার');

        // ⓘ যে ফিরে গেল সে পরে আবার চাপলে বাকি ১,০০০ অগ্রিম থেকে, বাকিটা নগদে
        if (ExpenseClaim::query()->findOrFail($second->id)->status === ExpenseClaim::SUBMITTED) {
            app(ExpenseClaimService::class)->approve(ExpenseClaim::query()->findOrFail($second->id));
            $this->assertSame('1000.00', bcadd((string) ExpenseClaim::query()->findOrFail($second->id)->from_advance, '0', 2));
        }

        $this->assertSame('0.00', $this->open(), '⛔ অগ্রিমের জের মেলে না');
    }

    private function claim(string $amount): ExpenseClaim
    {
        return app(ExpenseClaimService::class)->submit($this->worker, ['kind' => ExpenseClaim::EXPENSE, 'amount' => $amount,
            'reason' => 'Market visit', 'spent_on' => now()->toDateString(), 'expense_account_id' => $this->expense->id]);
    }

    private function open(): string
    {
        return bcadd((string) LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)
            ->where('party_type', 'employee')->where('party_id', $this->employee->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n'), '0', 2);
    }
}
