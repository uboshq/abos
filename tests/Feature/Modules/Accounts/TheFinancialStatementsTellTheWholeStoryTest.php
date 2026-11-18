<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\FinancialStatements;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⭐ স্থিতিপত্র আর পূর্ণ সেট NEXUS-এর নমুনার মানে, আমাদের নিয়মে (মালিক, ১৭ নভেম্বর ২০২৬ "ok"; [[FinancialStatements]])।
 *
 *   খাতা (ডেমো + এই পরীক্ষা): ১ আগস্ট গাড়ি কেনা ৬,০০,০০০ (ব্যাংক থেকে); ৩১ অক্টোবর অবচয় ১০,০০০; ১ জুলাই ২৪ কিস্তির মেয়াদি ঋণ
 *   ২,৪০,০০০ (২২১০, ব্যাংকে); ৩১ অক্টোবর আয়করের সঞ্চিতি ৫০,০০০ (৫৫০০ ↔ ২১২৬); গত বছরের ১০ আগস্ট ৭০,০০০ বিক্রি।
 *   - প্রতিটা লাইন খাতার সাথে মেলে; সম্পদ = মালিকানা স্বত্ব + দায়; মোট পর্দার স্থিতিপত্রের সাথে মেলে
 *   - পরের ১২ মাসের কিস্তির আসল চলতি দায়ে (IAS 1.69), বাকিটা অ-চলতি
 *   - আয়করের সঞ্চিতির লাইন খাতার জের থেকে, নোট ৫
 *   - পাশে আগের বছর-শেষ, পরিবর্তন আর %; চলতি অনুপাত, চলতি মূলধন, দায় ÷ স্বত্ব
 *   - লাভ-ক্ষতি পাশে গত বছরের একই সময়; নগদ প্রবাহে শুরু + পরিবর্তন = শেষ; মালিকানা স্বত্বের শেষ = স্থিতিপত্রের স্বত্ব
 *   - চার সই সেটিংসে চালু/বন্ধ; বাংলা ও ইংরেজি কাগজ, কাঁচা চাবি নয়; কাগজ PDF; চাবি ছাড়া ৪০৩
 */
final class TheFinancialStatementsTellTheWholeStoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 12:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $id = fn (string $c) => (int) Account::query()->where('code', $c)->value('id');
        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-FS1', 'name_en' => 'FS Bank', 'name_bn' => 'বিবরণীর ব্যাংক',
            'parent_id' => $id(StandardChart::BANK), 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_group' => false,
        ]);
        $post = fn (string $type, int $n, string $date, array $lines) => app(PostingEngine::class)->post(sourceType: $type, sourceId: $n, trxDate: $date, lines: $lines);

        $post('test.fs_capital', 1, '2026-07-02', [['account_id' => $bank->id, 'debit' => '1000000'], ['account_id' => $id('3100'), 'credit' => '1000000']]);
        $post('test.fs_vehicle', 1, '2026-08-01', [['account_id' => $id('1202'), 'debit' => '600000'], ['account_id' => $bank->id, 'credit' => '600000']]);
        $post('test.fs_dep', 1, '2026-10-31', [['account_id' => $id('5212'), 'debit' => '10000'], ['account_id' => $id('1290'), 'credit' => '10000']]);
        $post('test.fs_tax', 1, '2026-10-31', [['account_id' => $id(StandardChart::INCOME_TAX_EXPENSE), 'debit' => '50000'], ['account_id' => $id(StandardChart::INCOME_TAX_PROVISION), 'credit' => '50000']]);

        $this->loan = app(LoanService::class)->create(data: [
            'lender' => 'Sonali Bank', 'kind' => Loan::TERM, 'interest_method' => LoanSchedule::FLAT,
            'sanctioned' => '240000', 'interest_rate' => '0', 'tenure_months' => 24,
            'start_date' => '2026-07-01', 'first_instalment_on' => '2026-08-01',
            'principal_account_id' => $id('2210'),
            'interest_account_id' => $id(StandardChart::INTEREST_EXPENSE),
        ], intoAccountId: $bank->id);
    }

    public function test_every_line_is_the_books_and_the_sheet_agrees(): void
    {
        $fs = app(FinancialStatements::class)->position('2026-11-16', null);
        $sheet = app(BalanceSheetService::class)->build('2026-11-16', null);

        $this->assertTrue($fs['agrees'], '⛔ সম্পদ ≠ মালিকানা স্বত্ব + দায় — পার্থক্য '.$fs['difference']);
        $this->assertSame(0, bccomp($fs['totals']['assets'][0], $sheet['totals']['assets'], 4), '⛔ মোট সম্পদ পর্দার স্থিতিপত্রের সাথে মেলে না');
        $this->assertSame(0, bccomp($fs['totals']['funding'][0], $sheet['totals']['funding'], 4), '⛔ মোট মালিকানা স্বত্ব ও দায় পর্দার সাথে মেলে না');

        $prior = app(BalanceSheetService::class)->build('2026-06-30', null);
        $this->assertSame('2026-06-30', $fs['prior_as_of'], '⛔ তুলনার দিন আগের অর্থবছরের শেষ নয়');
        $this->assertSame(0, bccomp($fs['totals']['assets'][1], $prior['totals']['assets'], 4), '⛔ আগের বছর-শেষের মোট সম্পদ ভুল');

        // ⓘ স্থায়ী সম্পদ (বইমূল্য) = ১২০০-এর নিচের সব খাতের নিট — খাতা থেকে
        $ppe = collect($fs['assets']['non_current'])->firstWhere('kind', 'ppe');
        $this->assertSame(0, bccomp($ppe['now'], $this->net(['1201', '1202', '1203', '1204', '1290']), 4), '⛔ স্থায়ী সম্পদের বইমূল্য খাতার সাথে মেলে না');
        $this->assertSame(FinancialStatements::NOTE_PPE, $ppe['note']);

        $cash = collect($fs['assets']['current'])->firstWhere('kind', 'cash');
        $money = Account::query()->whereNotNull('money_kind')->where('is_group', false)->pluck('code')->all();
        $this->assertSame(0, bccomp($cash['now'], $this->net($money), 4), '⛔ নগদ ও নগদ সমতুল্য খাতার টাকার খাতগুলোর যোগ নয়');

        $tax = collect($fs['liabilities']['current'])->firstWhere('kind', 'tax');
        $this->assertSame('50000.0000', $tax['now'], '⛔ আয়করের সঞ্চিতি খাতার জের ৫০,০০০ নয়');
        $this->assertSame(FinancialStatements::NOTE_TAX, $tax['note']);
        $this->assertSame('0', $tax['then']);
        $this->assertNull($tax['pct'], '⛔ আগে শূন্য — % "নতুন" হওয়ার কথা');
    }

    public function test_the_loan_due_within_a_year_sits_in_current_liabilities(): void
    {
        $fs = app(FinancialStatements::class)->position('2026-11-16', null);

        // ⓘ ২৪ কিস্তি আগস্ট ২০২৬ থেকে; ১৬ নভেম্বর ২০২৭-এর মধ্যে পড়ে যেগুলো (আগস্ট ২৬ – নভেম্বর ২৭) — কোনোটা দেওয়া হয়নি
        $due = $this->loan->instalments()->get()
            ->filter(fn ($i) => $i->due_date->toDateString() <= '2027-11-16')
            ->reduce(fn (string $t, $i) => bcadd($t, (string) $i->principal, 4), '0');
        $this->assertSame(0, bccomp($due, '160000', 4), 'দৃশ্যটাই বানানো যায়নি — ১৬ কিস্তি × ১০,০০০ নয়: '.$due);

        $current = collect($fs['liabilities']['current'])->firstWhere('kind', 'loan_current');
        $this->assertNotNull($current, '⛔ ঋণের চলতি অংশের লাইন নেই (IAS 1.69)');
        $this->assertSame(0, bccomp($current['now'], '160000', 4), '⛔ পরের ১২ মাসের কিস্তি চলতি দায়ে ১,৬০,০০০ নয়');

        $long = collect($fs['liabilities']['non_current'])->firstWhere('label', Account::query()->where('code', '2210')->first()->name());
        $this->assertSame(0, bccomp($long['now'], '80000', 4), '⛔ অ-চলতি অংশ ২,৪০,০০০ − ১,৬০,০০০ নয়');
        $this->assertSame(FinancialStatements::NOTE_LOANS, $long['note']);
        $this->assertSame(0, bccomp($fs['notes'][FinancialStatements::NOTE_LOANS]['total'], '240000', 4), '⛔ ঋণের নোটে মোট বকেয়া ভুল');
    }

    public function test_change_percent_and_the_ratio_strip(): void
    {
        $fs = app(FinancialStatements::class)->position('2026-11-16', null);
        $t = $fs['totals'];

        $this->assertSame(number_format((float) bcdiv($t['current_assets'][0], $t['current_liabilities'][0], 6), 2, '.', ''), $fs['ratios']['current'], '⛔ চলতি অনুপাত ভুল');
        $this->assertSame(0, bccomp(bcsub($t['current_assets'][0], $t['current_liabilities'][0], 4), $fs['ratios']['working_capital'], 4), '⛔ চলতি মূলধন ভুল');
        $this->assertSame(number_format((float) bcdiv($t['liabilities'][0], $t['equity'][0], 6), 2, '.', ''), $fs['ratios']['debt_equity'], '⛔ দায় ÷ স্বত্ব ভুল');

        $ppe = collect($fs['assets']['non_current'])->firstWhere('kind', 'ppe');
        $this->assertSame(0, bccomp(bcsub($ppe['now'], $ppe['then'], 4), $ppe['change'], 4), '⛔ পরিবর্তন = এখন − আগে নয়');
        $this->assertSame('-25.0', FinancialStatements::percent('75', '100'));
        $this->assertSame('50.0', FinancialStatements::percent('-50', '-100'), '⛔ ঋণাত্মক আগের অঙ্কে % উল্টো দিকে');
        $this->assertNull(app(FinancialStatements::class)->ratio('10', '0'), '⛔ শূন্য দিয়ে ভাগ — "প্রযোজ্য নয়" হওয়ার কথা');

        $note = $fs['notes'][FinancialStatements::NOTE_PPE];
        $this->assertSame(0, bccomp($note['additions'], '600000', 4), '⛔ নোট ১: এ সময়ে কেনা ৬,০০,০০০ নয়');
        $this->assertSame(0, bccomp($note['charge'], '10000', 4), '⛔ নোট ১: এ সময়ের অবচয় ১০,০০০ নয়');
        $this->assertSame(0, bccomp($note['nbv'], $ppe['now'], 4), '⛔ নোট ১-এর বইমূল্য বিবরণীর লাইনের সাথে মেলে না');

        $re = $fs['notes'][FinancialStatements::NOTE_RE];
        $this->assertSame(0, bccomp($re['profit'], app(BalanceSheetService::class)->build('2026-11-16', null)['profit_this_year'], 4), '⛔ নোট ৩-এর মুনাফা চলতি বছরের লাভ নয়');
    }

    public function test_profit_or_loss_sits_beside_the_same_period_last_year(): void
    {
        // ⓘ গত বছরের একই সময়ে একটা বিক্রি — তুলনার কলামে আসার কথা, এ বছরের কলামে নয়
        \App\Models\FinancialYear::query()->create(['company_id' => $this->company->id, 'name' => '2025-2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        app(PostingEngine::class)->post(sourceType: 'test.fs_last_year', sourceId: 1, trxDate: '2025-08-10', lines: [
            ['account_id' => (int) Account::query()->where('code', '1110')->value('id'), 'debit' => '70000'],
            ['account_id' => (int) Account::query()->where('code', StandardChart::SALES)->value('id'), 'credit' => '70000'],
        ]);

        $statements = app(FinancialStatements::class);
        $pl = $statements->profitOrLoss('2026-07-01', '2026-11-16', null);

        $this->assertSame('2025-07-01', $pl['prior_from'], '⛔ তুলনার সময় গত বছরের একই সময় নয় (শুরু)');
        $this->assertSame('2025-11-16', $pl['prior_to'], '⛔ তুলনার সময় গত বছরের একই সময় নয় (শেষ)');
        $this->assertSame(0, bccomp($pl['totals']['revenue']['then'], '70000', 4), '⛔ গত বছরের একই সময়ের বিক্রি তুলনার কলামে নেই');
        $this->assertSame(0, bccomp($pl['totals']['income_tax']['now'], '-50000', 4), '⛔ আয়কর খরচ নিজের লাইনে (৫০,০০০) নেই');

        $sheet = app(BalanceSheetService::class)->build('2026-11-16', null);
        $this->assertSame(0, bccomp($pl['totals']['profit']['now'], $sheet['profit_this_year'], 4), '⛔ লাভ-ক্ষতির মুনাফা স্থিতিপত্রের চলতি বছরের লাভের সাথে মেলে না');
        $this->assertSame(0, bccomp(bcadd($pl['totals']['profit_before_tax']['now'], $pl['totals']['income_tax']['now'], 4), $pl['totals']['profit']['now'], 4));

        $cf = $statements->cashFlow('2026-07-01', '2026-11-16', null);
        $this->assertSame(0, bccomp($cf['gap'], '0', 2), '⛔ নগদ প্রবাহ: শুরু + পরিবর্তন ≠ শেষ — '.$cf['gap']);
        $this->assertTrue($cf['direct_agrees'], '⛔ পরোক্ষ পদ্ধতির পরিচালন নগদ প্রত্যক্ষের সাথে মেলে না');
        $this->assertSame(0, bccomp(bcadd($cf['operating'], bcadd($cf['investing_total'], $cf['financing_total'], 4), 4), $cf['change'], 4),
            '⛔ নগদের নিট পরিবর্তন তিন কার্যক্রমের যোগ নয়');

        $soce = $statements->equity('2026-07-01', '2026-11-16', null);
        $fs = $statements->position('2026-11-16', null);
        $this->assertSame(0, bccomp($soce['total']['closing'], $fs['totals']['equity'][0], 4), '⛔ মালিকানা স্বত্বের পরিবর্তনের শেষ ≠ স্থিতিপত্রের মোট স্বত্ব');
    }

    public function test_the_four_signatures_follow_the_setting_and_both_languages_print(): void
    {
        $seen = [];
        View::composer('accounts::print.statements', function ($view) use (&$seen): void {
            $seen[] = $view->getData();
        });

        $url = route('accounts.financial_statements', ['as_of' => '2026-11-16', 'export' => 'print']);
        $pdf = $this->get($url.'&lang=bn')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'), '⛔ পূর্ণ সেটের কাগজ PDF নয়');
        $this->assertStringStartsWith('inline;', (string) $pdf->headers->get('content-disposition'));
        $this->get($url.'&lang=en')->assertOk();
        $this->assertTrue($seen[0]['board'], '⛔ চার সইয়ের সেটিং ডিফল্টে চালু নয়');

        app(SettingsService::class)->set('accounts.statement_board_signatures', false);
        $this->get($url.'&lang=bn')->assertOk();
        $this->assertFalse($seen[2]['board'], '⛔ সেটিং বন্ধ করলেও চার সই');

        foreach ([[0, 'bn', ['ব্যবস্থাপনা পরিচালক', 'পরিচালক', 'কোম্পানি সচিব', 'প্রধান অর্থ কর্মকর্তা', 'অন্তর্বর্তী, নিরীক্ষিত নয়'], 'data-signs="4"'],
                  [1, 'en', ['Managing Director', 'Company Secretary', 'Chief Financial Officer', 'Interim, unaudited', 'Statement of Cash Flows'], 'data-signs="4"'],
                  [2, 'bn', ['প্রস্তুতকারী', 'অনুমোদনকারী'], 'data-signs="2"']] as [$i, $locale, $words, $signs]) {
            app()->setLocale($locale);
            $html = view('accounts::print.statements', $seen[$i])->render();
            $this->assertStringContainsString($signs, $html, "⛔ ({$locale}) সইয়ের ঘর সেটিং মানেনি");
            foreach ($words as $word) {
                $this->assertStringContainsString($word, $html, "⛔ ({$locale}) কাগজে \"{$word}\" নেই");
            }
            foreach (['data-position', 'data-pl-table', 'data-soce', 'data-cf', 'data-note="1"', 'data-note="5"', 'data-ratios', 'data-identity="agrees"', 'data-cf-check="agrees"'] as $part) {
                $this->assertStringContainsString($part, $html, "⛔ ({$locale}) পূর্ণ সেটে {$part} নেই");
            }
            $this->assertDoesNotMatchRegularExpression('/(accounts|core)::/', $html, "⛔ ({$locale}) কাগজে কাঁচা চাবি");
        }

        $this->assertSame('bn', $seen[0]['locale']);
        $this->assertSame('en', $seen[1]['locale'], '⛔ ?lang=en দিলেও কাগজ ইংরেজি নয়');
    }

    public function test_the_doors_ask_for_the_final_accounts_key_and_the_download_key(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['accounts.report', 'system_admin.list.export']);
        $url = route('accounts.financial_statements', ['as_of' => '2026-11-16', 'export' => 'print']);

        $this->actingAs($clerk)->get($url)->assertForbidden();
        $this->actingAs($clerk)->get(route('accounts.balance_sheet', ['export' => 'print']))->assertForbidden();

        $clerk->givePermissionTo('accounts.report.final');
        $this->actingAs($clerk->fresh())->get($url)->assertOk();

        $clerk->revokePermissionTo('system_admin.list.export');
        $this->actingAs($clerk->fresh())->get($url)->assertForbidden();
    }

    /** @param  list<string>  $codes */
    private function net(array $codes): string
    {
        return bcadd((string) DB::table('ledger_entries')->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $this->company->id)->whereIn('accounts.code', $codes)
            ->where('ledger_entries.trx_date', '<=', '2026-11-16')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit - ledger_entries.credit), 0) as n')->value('n'), '0', 4);
    }
}
