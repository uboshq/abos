<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\FacilityStatement;
use App\Modules\Finance\Reports\BankLoanReports;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক ঋণ — অর্থ-মডিউলের পরিকল্পনা ৩, ৬ অক্টোবর ২০২৬: কিস্তির দিন আর অবস্থা, সব ঋণের কিস্তির রিপোর্ট, সীমার
 * ব্যবহার, দিন পার আর নবায়নের ঘণ্টা ও ড্যাশবোর্ড, আর ব্যাংকের বিবরণী বনাম খাতা।
 *
 * ⭐ দাবি:
 *   · কিস্তির দিন প্রথম কিস্তির দিন থেকে মাসে মাসে (না দিলে মঞ্জুরির পরের মাস); "দেওয়া" খাতা থেকে, বাকিগুলো দিন পার / সামনে
 *   · কিস্তির রিপোর্ট = বাকি কিস্তি, দিন পার আর সামনের ৩০ দিনের; অঙ্ক = আসল + সুদ; "দিন পার" ছাঁকনি কেবল দিন পার
 *   · সীমার ব্যবহার = ঋণের পাতার "তোলা"; খালি = সীমা − তোলা; শতাংশ
 *   · বিবরণী: পাশে সেই দিনের খাতা আর ফাঁক; একই দিনে আবার লিখলে বদলায়, দ্বিতীয় সারি নয়
 *   · ঘণ্টা: দিন পারের কিস্তি আর নবায়ন — সপ্তাহে একবার; ড্যাশবোর্ড = রিপোর্টের "দিন পার"
 */
final class BankLoanInstalmentsAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = '1200000';

    private const RATE = '12';

    private const MONTHS = 12;

    private BankFacility $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ মঞ্জুরি ৯০ দিন আগে, প্রথম কিস্তি ৬০ দিন আগে → কিস্তি −৬০, −৩০ (দিন পার), ০ (আজ), +৩০ (সামনে)…
        $this->loan = $this->open([
            'sanctioned_on' => now()->subDays(90)->toDateString(),
            'first_instalment_on' => now()->subDays(60)->toDateString(),
        ]);
        $this->journal($this->loan, [
            ['account_id' => $this->equity()->id, 'debit' => self::LIMIT, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => self::LIMIT],
        ]);

        // ⓘ প্রথম কিস্তি দেওয়া
        $first = LoanSchedule::build(self::LIMIT, self::RATE, self::MONTHS)['rows'][0];
        $this->journal($this->loan, [
            ['account_id' => $this->liability()->id, 'debit' => (string) $first['principal'], 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => (string) $first['interest'], 'credit' => '0'],
            ['account_id' => $this->equity()->id, 'debit' => '0', 'credit' => bcadd((string) $first['principal'], (string) $first['interest'], 2)],
        ]);
    }

    public function test_each_instalment_has_its_day_and_its_state(): void
    {
        $rows = app(BankFacilityService::class)->datedSchedule($this->loan)['rows'];
        $first = now()->subDays(60);

        $this->assertSame($first->toDateString(), $rows[0]['due_on']);
        $this->assertSame($first->copy()->addMonthNoOverflow()->toDateString(), $rows[1]['due_on'], 'কিস্তির দিন মাসে মাসে নয়।');
        $this->assertSame(BankFacilityService::PAID, $rows[0]['state'], '⛔ খাতায় শোধ হওয়া কিস্তি "দেওয়া" দেখায়নি।');
        $this->assertSame(BankFacilityService::OVERDUE, $rows[1]['state']);
        $this->assertSame(BankFacilityService::UPCOMING, $rows[3]['state']);
        $this->assertSame(bcadd((string) $rows[1]['principal'], (string) $rows[1]['interest'], 2), $rows[1]['amount']);

        // ⓘ প্রথম কিস্তির দিন না দিলে — মঞ্জুরির পরের মাসের একই দিন
        $plain = $this->open(['sanctioned_on' => now()->subDays(40)->toDateString()]);
        $this->assertSame(now()->subDays(40)->addMonthNoOverflow()->toDateString(),
            app(BankFacilityService::class)->datedSchedule($plain)['rows'][0]['due_on']);
    }

    public function test_the_instalment_report_lists_what_is_overdue_and_due_in_thirty_days(): void
    {
        $service = app(BankFacilityService::class);
        $expected = array_values(array_filter($service->datedSchedule($this->loan)['rows'],
            fn (array $r) => $r['state'] !== BankFacilityService::PAID && $r['due_on'] <= now()->addDays(30)->toDateString()));

        $rows = $this->rowsOf(app(ReportEngine::class)->run(BankLoanReports::INSTALMENTS, [], perPage: 500)->rows);

        $this->assertCount(count($expected), $rows, 'কিস্তির রিপোর্টে বাকি কিস্তির সংখ্যা মেলে না।');
        $this->assertSame(array_column($expected, 'due_on'), array_map(fn (array $r) => substr((string) $r['due_on'], 0, 10), $rows));
        $this->assertSame(0, bccomp((string) $rows[0]['amount'], (string) $expected[0]['amount'], 2));

        $overdue = $this->rowsOf(app(ReportEngine::class)->run(BankLoanReports::INSTALMENTS, ['state' => 'overdue'], perPage: 500)->rows);
        $this->assertCount(count(array_filter($expected, fn (array $r) => $r['state'] === BankFacilityService::OVERDUE)), $overdue,
            '⛔ "দিন পার" ছাঁকনিতে অন্য অবস্থার কিস্তি এল।');

        $this->get(route('finance.report.show', ['slug' => 'bank-loan-instalments']))->assertOk()->assertSee('data-bank-loan-reports', false);
    }

    public function test_limit_usage_is_the_loan_pages_own_drawn_figure(): void
    {
        $standing = app(BankFacilityService::class)->standing(collect([$this->loan]))[$this->loan->id];
        $row = collect(app(ReportEngine::class)->run(BankLoanReports::LIMITS, [], perPage: 500)->rows)
            ->firstWhere('source_id', $this->loan->id);

        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row['used'], $standing['used'], 4), '⛔ সীমার রিপোর্ট আর ঋণের পাতা দুই "তোলা" বলে।');
        $this->assertSame(0, bccomp((string) $row['free'], bcsub(self::LIMIT, $standing['used'], 4), 4));
        $this->assertSame(0, bccomp((string) $row['used_percent'], bcmul(bcdiv($standing['used'], self::LIMIT, 6), '100', 2), 2));

        $this->get(route('finance.report.show', ['slug' => 'bank-limit-usage']))->assertOk();
    }

    public function test_a_statement_sits_beside_the_books_and_one_day_holds_one_figure(): void
    {
        $day = now()->subDays(3)->toDateString();
        $books = app(BankFacilityService::class)->owedOn($this->loan, $day);

        // ⓘ বিবরণীর দিনের পরে আরেকটা শোধ — খাতার জের সেই দিনেরটাই থাকে, আজকেরটা নয়
        $this->journal($this->loan, [
            ['account_id' => $this->liability()->id, 'debit' => '7000', 'credit' => '0'],
            ['account_id' => $this->equity()->id, 'debit' => '0', 'credit' => '7000'],
        ], now()->toDateString());
        $this->assertSame(0, bccomp(app(BankFacilityService::class)->owedOn($this->loan, now()->toDateString()), bcsub($books, '7000', 4), 4));

        $this->post(route('finance.bank_facility.statement', $this->loan), ['statement_on' => $day, 'bank_balance' => bcadd($books, '1500', 2)])
            ->assertSessionHasNoErrors();
        $this->post(route('finance.bank_facility.statement', $this->loan), ['statement_on' => $day, 'bank_balance' => bcadd($books, '2500', 2)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, FacilityStatement::query()->where('bank_facility_id', $this->loan->id)->count(), '⛔ একই দিনের বিবরণী দুই সারি হলো।');

        $gaps = app(BankFacilityService::class)->statementGaps($this->loan);
        $this->assertSame(0, bccomp($gaps[0]['gap'], '2500', 2), 'ফাঁক = ব্যাংক − খাতা নয়।');
        $this->assertSame(0, bccomp($gaps[0]['books'], $books, 4));

        $this->get(route('finance.bank_facility.show', $this->loan))->assertOk()->assertSee('data-facility-statements', false)
            ->assertSee('data-instalment-state="overdue"', false);

        $this->post(route('finance.bank_facility.statement', $this->loan), ['statement_on' => now()->addDay()->toDateString(), 'bank_balance' => '1'])
            ->assertSessionHasErrors('statement_on');
    }

    public function test_overdue_instalments_and_renewals_ring_once_a_week_and_the_dashboard_agrees(): void
    {
        $this->loan->forceFill(['renews_on' => now()->addDays(10)->toDateString()])->save();
        $owner = auth()->user();

        // ⓘ শিডিউলার যা ডাকে ঠিক তাই — কমান্ড, লগইন ছাড়া ([[TheScreenCountedTheDatesAndNobodyWasToldTest]]-এর ধাঁচ)
        $fresh = function (string $type): int {
            $was = (int) Notification::query()->max('id');
            auth()->logout();
            $this->artisan('abos:money-due')->assertSuccessful();

            return Notification::query()->where('id', '>', $was)->where('type', $type)->count();
        };

        $this->assertGreaterThan(0, $fresh(DueNotices::BANK_INSTALMENT_DUE), '⛔ দিন পার কিস্তির ঘণ্টা বাজেনি।');
        $this->assertTrue(Notification::query()->where('type', DueNotices::BANK_RENEWAL_DUE)->exists(), '⛔ নবায়নের ঘণ্টা বাজেনি।');
        $this->assertSame(0, $fresh(DueNotices::BANK_INSTALMENT_DUE), '⛔ একই সপ্তাহে একই ঋণের ঘণ্টা আবার বাজল।');

        $this->actingAs($owner);
        config(['abos.dashboards_v2' => true]);
        $stats = (new \ReflectionMethod(FinanceDashboard::class, 'bankLoansDue'))->invoke(null);
        $overdue = $this->rowsOf(app(ReportEngine::class)->run(BankLoanReports::INSTALMENTS, ['state' => 'overdue'], perPage: 500)->rows);

        $this->assertSame(Money::format(array_reduce($overdue, fn (string $s, array $r) => bcadd($s, (string) $r['amount'], 4), '0')),
            $stats[0]->value, '⛔ ড্যাশবোর্ড আর কিস্তির রিপোর্ট দুই সংখ্যা বলে।');
        $this->assertStringContainsString((string) count($overdue), $stats[0]->hint);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> এই ঋণের সারি */
    private function rowsOf(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $r) => (int) $r['source_id'] === (int) $this->loan->id));
    }

    /** @param  array<string, mixed>  $extra */
    private function open(array $extra): BankFacility
    {
        return app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Sonali Bank',
            'limit_amount' => self::LIMIT,
            'interest_rate' => self::RATE,
            'instalments' => self::MONTHS,
            'instalment_amount' => LoanSchedule::instalment(self::LIMIT, self::RATE, self::MONTHS),
            'liability_account_id' => $this->liability()->id,
            ...$extra,
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function journal(BankFacility $facility, array $lines, ?string $on = null): void
    {
        $vouchers = app(VoucherService::class);

        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => $on ?? now()->subDays(80)->toDateString(),
            'narration' => 'loan test',
            'against_type' => BankFacility::drillSourceType(),
            'against_id' => $facility->id,
        ], $lines));
    }

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }

    private function equity(): Account
    {
        return StandardChart::find(StandardChart::OWNER_CAPITAL);
    }
}
