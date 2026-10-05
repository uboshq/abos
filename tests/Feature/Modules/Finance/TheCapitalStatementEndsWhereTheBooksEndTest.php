<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Reports\CapitalReports;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মূলধন ও বিনিয়োগের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ২ (৫ অক্টোবর ২০২৬, [[CapitalReports]])।
 *
 * ⛔ দাবির মেরুদণ্ড (সমন্বয়কের সিদ্ধান্ত): বিবরণীর জন আর নামহীন সারির শেষ জের = ৩১০০ − ৩২০০, হুবহু; মূলধনের ঘরের যোগ =
 * ৩১০০, উত্তোলনের যোগ = ৩২০০-এর ডেবিট। ⓘ বিপজ্জনক ইনপুট সাথে: খসড়া মূলধন, বাতিল রসিদের মূলধন, বেতন হিসেবে তোলা, খসড়া
 * তোলা, বছর বন্ধের দাখিলা, অন্য শাখা — কোনোটা কারও নামে গোনা হলে বা দুবার গোনা হলে লাল।
 */
final class TheCapitalStatementEndsWhereTheBooksEndTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private Person $karim;

    private Person $rahim;

    private string $start;

    private string $middle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->karim = Person::query()->create(['company_id' => $this->company->id, 'code' => 'CAP-K', 'name_en' => 'Karim Capital', 'name_bn' => 'Karim Capital', 'is_active' => true]);
        $this->rahim = Person::query()->create(['company_id' => $this->company->id, 'code' => 'CAP-R', 'name_en' => 'Rahim Capital', 'name_bn' => 'Rahim Capital', 'is_active' => true]);

        $this->start = now()->subDays(20)->toDateString();
        $this->middle = now()->subDays(10)->toDateString();

        // ⓘ শুরুর আগে করিমের নগদ মূলধন — ময়মনসিংহে
        $this->capital($this->karim, $this->mymensingh, '100000', $this->start, CapitalEntry::CASH);

        // ⓘ সময়ের মধ্যে: রহিমের নতুন মূলধন (নেত্রকোনা), করিমের শুরুর মূলধন আর লাভ থেকে মূলধন, করিমের তোলা
        $this->capital($this->rahim, $this->netrakona, '50000', $this->middle, CapitalEntry::CASH);
        $this->capital($this->karim, $this->mymensingh, '20000', $this->middle, CapitalEntry::OPENING);
        $this->capital($this->karim, $this->mymensingh, '5000', $this->middle, CapitalEntry::PROFIT, from: StandardChart::PROFIT_PAYABLE);
        $this->drawing($this->karim, $this->mymensingh, '8000', $this->middle);

        // ⛔ বিপজ্জনক — কেউ কারও নামে গোনা হবে না
        $this->draftCapital($this->karim, '99999');
        $this->capitalOnACancelledReceipt($this->rahim, '44444');
        $this->drawing($this->karim, $this->mymensingh, '7777', $this->middle, Withdrawal::SALARY, books: false);
        $this->drawing($this->rahim, $this->netrakona, '6666', $this->middle, Withdrawal::DRAWING, status: DocumentStatus::DRAFT, books: false);

        // ⓘ খাতায় আছে, রেজিস্টারে নেই — নামহীন সারিতে যাবে
        $this->books(StandardChart::OWNER_CAPITAL, '3000', $this->netrakona, $this->middle, credit: true);
        $this->books(StandardChart::DRAWINGS, '1500', $this->mymensingh, $this->middle, credit: false);
    }

    public function test_each_person_row_adds_up_and_the_named_and_nameless_rows_end_at_capital_less_drawings(): void
    {
        $rows = $this->changes($this->middle);

        $karim = $this->row($rows, 'Karim Capital');
        $this->assertMoney('100000', $karim['opening_balance'], 'করিমের শুরুর জের');
        $this->assertMoney('0', $karim['new_capital'], 'করিমের নতুন মূলধন — খসড়া গোনা হয়েছে?');
        $this->assertMoney('20000', $karim['opening_capital'], 'করিমের শুরুর মূলধন');
        $this->assertMoney('5000', $karim['from_profit'], 'করিমের লাভ থেকে মূলধন');
        $this->assertMoney('8000', $karim['drawings'], 'করিমের তোলা — বেতন গোনা হয়েছে?');
        $this->assertMoney('117000', $karim['closing_balance'], 'করিমের শেষ জের');

        $rahim = $this->row($rows, 'Rahim Capital');
        $this->assertMoney('0', $rahim['opening_balance'], 'রহিমের শুরুর জের');
        $this->assertMoney('50000', $rahim['new_capital'], 'রহিমের নতুন মূলধন — বাতিল রসিদের মূলধন গোনা হয়েছে?');
        $this->assertMoney('0', $rahim['drawings'], 'রহিমের তোলা — খসড়া গোনা হয়েছে?');
        $this->assertMoney('50000', $rahim['closing_balance'], 'রহিমের শেষ জের');

        // ⭐ নাম + নামহীন = ৩১০০ − ৩২০০; মূলধনের ঘর = ৩১০০; তোলা = ৩২০০-এর ডেবিট
        $named = array_filter($rows, fn (array $r) => $r['person_name'] !== __('finance::capital_report.retained'));
        $capitalSide = '0';
        $drawn = '0';
        $end = '0';

        foreach ($named as $r) {
            $capitalSide = bcadd($capitalSide, bcadd(bcadd(bcadd((string) $r['opening_balance'], '0', 4), (string) $r['new_capital'], 4),
                bcadd((string) $r['opening_capital'], (string) $r['from_profit'], 4), 4), 4);
            $drawn = bcadd($drawn, (string) $r['drawings'], 4);
            $end = bcadd($end, (string) $r['closing_balance'], 4);
        }

        // ⓘ শুরুর জেরে তোলা বিয়োগ হয়ে আছে, তাই মূলধনের দিক = শুরুর আগের ৩১০০ − শুরুর আগের ৩২০০ + সময়ের মূলধন
        $this->assertMoney(bcsub($this->net(StandardChart::OWNER_CAPITAL), $this->net(StandardChart::DRAWINGS, before: $this->middle), 4), $capitalSide,
            '⛔ মূলধনের ঘরের যোগ ৩১০০-এর সাথে মেলে না।');
        $this->assertMoney(bcsub($this->net(StandardChart::DRAWINGS), $this->net(StandardChart::DRAWINGS, before: $this->middle), 4), $drawn,
            '⛔ উত্তোলনের যোগ ৩২০০-এর ডেবিটের সাথে মেলে না।');
        $this->assertMoney(bcsub($this->net(StandardChart::OWNER_CAPITAL), $this->net(StandardChart::DRAWINGS), 4), $end,
            '⛔ শেষ জের ৩১০০ − ৩২০০ নয়।');

        // ⓘ নামহীন সারি = খাতা − রেজিস্টার: রেজিস্টারে না-ওঠা ৩০০০ মূলধন আর ১৫০০ তোলা (ডেমোর নিজের খাতাসহ, তাই খাতা থেকে মেলানো)
        $nameless = $this->row($rows, __('finance::capital_report.nameless'));
        $this->assertMoney(bcsub($this->net(StandardChart::OWNER_CAPITAL, from: $this->middle), '75000', 4), $nameless['new_capital'], 'নামহীন মূলধন');
        $this->assertMoney(bcsub($this->net(StandardChart::DRAWINGS, from: $this->middle), '8000', 4), $nameless['drawings'], 'নামহীন তোলা');
    }

    /** ⭐ একটা শাখা বাছলে কেবল সেই শাখার জন — করিম ময়মনসিংহে, রহিম নেত্রকোনায় */
    public function test_one_branch_shows_only_its_own_people_and_its_own_books(): void
    {
        $rows = $this->changes($this->middle, ['branch_id' => $this->netrakona->id]);

        $this->assertNull($this->find($rows, 'Karim Capital'), '⛔ নেত্রকোনায় করিমের ময়মনসিংহের মূলধন দেখাল।');
        $this->assertMoney('50000', $this->row($rows, 'Rahim Capital')['closing_balance'], 'রহিম');
        $nameless = $this->row($rows, __('finance::capital_report.nameless'));
        $this->assertMoney(bcsub($this->net(StandardChart::OWNER_CAPITAL, from: $this->middle, branch: $this->netrakona), '50000', 4),
            $nameless['new_capital'], 'নেত্রকোনার নামহীন মূলধন');
        $this->assertGreaterThanOrEqual(0, bccomp((string) $nameless['new_capital'], '3000', 4), 'নেত্রকোনার ৩০০০ নামহীন মূলধন নেই');
        $this->assertMoney($this->net(StandardChart::DRAWINGS, from: $this->middle, branch: $this->netrakona), $nameless['drawings'],
            '⛔ ময়মনসিংহের ১৫০০ তোলা নেত্রকোনায় এসেছে?');
    }

    /** ⭐ অবণ্টিত মুনাফা — বছরের আয় − খরচ লাভের ঘরে; বছর বন্ধের দাখিলা কিছুই বদলায় না */
    public function test_undistributed_profit_takes_the_years_result_and_the_year_close_moves_nothing(): void
    {
        $this->books(StandardChart::SALES, '40000', $this->mymensingh, $this->middle, credit: true);
        $this->books(StandardChart::COST_OF_GOODS_SOLD, '10000', $this->mymensingh, $this->middle, credit: false);

        $before = $this->row($this->changes($this->middle), __('finance::capital_report.retained'));

        // ⓘ বছর বন্ধ: আয় খালি করে সঞ্চিত মুনাফায় — একই সারির ভেতরে এক পকেট থেকে আরেক পকেটে
        $this->postLines(YearEndService::CLOSE_SOURCE, $this->middle, $this->mymensingh, [
            ['account_id' => $this->headId(StandardChart::SALES), 'debit' => '40000'],
            ['account_id' => $this->headId(StandardChart::RETAINED_EARNINGS), 'credit' => '40000'],
        ]);

        $after = $this->row($this->changes($this->middle), __('finance::capital_report.retained'));

        foreach (['opening_balance', 'from_profit', 'other_moves', 'closing_balance'] as $column) {
            $this->assertMoney((string) $before[$column], (string) $after[$column], "⛔ বছর বন্ধের দাখিলা অবণ্টিত মুনাফার {$column} বদলাল।");
        }

        $result = bcsub($this->net(StandardChart::SALES, kind: 'pl', from: $this->middle), '0', 4);
        $this->assertMoney($result, (string) $after['from_profit'], 'সময়ের আয় − খরচ');
    }

    /** ⭐ খ — একজনের খাতা: খোলা জের, প্রতিটা সারি, চলমান জের (Cr) */
    public function test_one_persons_ledger_runs_from_the_opening_to_the_end(): void
    {
        $result = app(ReportEngine::class)->run(CapitalReports::LEDGER, ['from' => $this->middle, 'to' => now()->toDateString(), 'person_id' => $this->karim->id]);
        $rows = array_map(fn ($r) => (array) $r, $result->rows);

        $this->assertMoney('100000', (string) $rows[0]['credit'], 'খোলা জের মূলধনের ঘরে');
        $this->assertCount(4, $rows, 'খোলা জের + শুরুর মূলধন + লাভ থেকে + তোলা — খসড়া বা বেতন ঢুকেছে?');
        $this->assertMoney('-117000', (string) end($rows)['balance'], 'শেষ চলমান জের — মূলধন (Cr)');
        $this->assertMoney('-117000', ($result->report->summary)($result->totals)['value'], 'সারাংশ');

        $nobody = app(ReportEngine::class)->run(CapitalReports::LEDGER, ['from' => $this->middle, 'to' => now()->toDateString()]);
        $this->assertSame([], $nobody->rows, 'মানুষ না বাছলে কোনো সারি নয়।');
    }

    /** ⭐ ঙ — রেজিস্টার বনাম খাতা, শাখা ধরে: ফাঁক ঠিক সেই শাখায় */
    public function test_the_register_against_the_books_shows_each_gap_in_its_own_branch(): void
    {
        $result = app(ReportEngine::class)->run(CapitalReports::RECONCILE, ['from' => $this->start, 'to' => now()->toDateString()]);
        $rows = array_map(fn ($r) => (array) $r, $result->rows);

        $ntk = $this->find($rows, $this->netrakona->name(), 'branch_name');
        $mms = $this->find($rows, $this->mymensingh->name(), 'branch_name');

        $this->assertMoney('50000', (string) $ntk['register_capital'], 'নেত্রকোনার রেজিস্টার — বাতিল রসিদের মূলধন গোনা হয়েছে?');
        $this->assertMoney($this->net(StandardChart::OWNER_CAPITAL, branch: $this->netrakona), (string) $ntk['books_capital'], 'নেত্রকোনার ৩১০০');
        $this->assertMoney(bcsub($this->net(StandardChart::OWNER_CAPITAL, branch: $this->netrakona), '50000', 4), (string) $ntk['capital_gap'], 'নেত্রকোনার ফাঁক');
        $this->assertMoney('125000', (string) $mms['register_capital'], 'ময়মনসিংহের রেজিস্টার — খসড়া গোনা হয়েছে?');
        $this->assertMoney('8000', (string) $mms['register_drawings'], 'ময়মনসিংহের তোলার রেজিস্টার — বেতন গোনা হয়েছে?');
        $this->assertMoney($this->net(StandardChart::DRAWINGS, branch: $this->mymensingh), (string) $mms['books_drawings'], 'ময়মনসিংহের ৩২০০');
        $this->assertMoney(bcsub($this->net(StandardChart::DRAWINGS, branch: $this->mymensingh), '8000', 4), (string) $mms['drawings_gap'], 'ময়মনসিংহের তোলার ফাঁক');
        $this->assertGreaterThanOrEqual(0, bccomp((string) $mms['drawings_gap'], '1500', 4), 'ময়মনসিংহের ১৫০০ নামহীন তোলা ফাঁকে নেই');
        $this->assertFalse(($result->report->summary)($result->totals)['good'], '⛔ ফাঁক থাকতেও "মেলে" বলল।');
    }

    /** ⭐ ঘ — মূলধনের আয়: লাভ ÷ গড় মূলধন, শাখা ধরে আর কোম্পানির */
    public function test_the_return_is_profit_over_average_capital(): void
    {
        $this->books(StandardChart::SALES, '25000', $this->netrakona, $this->middle, credit: true);

        $result = app(ReportEngine::class)->run(CapitalReports::RETURN, ['from' => $this->middle, 'to' => now()->toDateString()]);
        $ntk = $this->find(array_map(fn ($r) => (array) $r, $result->rows), $this->netrakona->name(), 'branch_name');

        $start = $this->equity($this->netrakona, before: $this->middle);
        $end = $this->equity($this->netrakona);
        $profit = $this->net(StandardChart::SALES, kind: 'pl', from: $this->middle, branch: $this->netrakona);

        $this->assertMoney($start, (string) $ntk['capital_start'], 'শুরুর মূলধন');
        $this->assertMoney($end, (string) $ntk['capital_end'], 'শেষের মূলধন');
        $this->assertMoney($profit, (string) $ntk['profit'], 'লাভ');
        $this->assertSame(
            bccomp(bcadd($start, $end, 4), '0', 4) > 0 ? \App\Core\Support\Money::round(bcdiv(bcmul($profit, '200', 8), bcadd($start, $end, 4), 8), 2) : null,
            $ntk['return_pct'] === null ? null : bcadd((string) $ntk['return_pct'], '0', 2),
            'আয়ের হার = লাভ ÷ গড় মূলধন',
        );
    }

    /** ⛔ অন্য কোম্পানির মূলধন কখনো নয় */
    public function test_another_companys_capital_never_shows(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CapitalEntry::query()->withoutGlobalScopes()->create([
            'company_id' => $other->id, 'branch_id' => null, 'document_no' => 'CAP-OTHER', 'person_id' => $this->karim->id,
            'contributor_type' => CapitalEntry::OWNER, 'entry_type' => CapitalEntry::CONTRIBUTION, 'in_kind' => CapitalEntry::CASH,
            'trx_date' => $this->middle, 'amount' => '123456', 'status' => CapitalEntry::POSTED, 'posted_at' => now(),
        ]);

        $this->assertMoney('117000', $this->row($this->changes($this->middle), 'Karim Capital')['closing_balance'], '⛔ অন্য কোম্পানির মূলধন যোগ হল।');
    }

    /** ⓘ চারটা পাতা খোলে, মাথায় রিপোর্টের সারি; মূলধনের পাতা থেকেও সারিটা; একজনের খাতায় মানুষ বাছার ঘর */
    public function test_the_four_pages_open_from_the_capital_page(): void
    {
        $this->get(route('finance.capital.index'))->assertOk()->assertSee('data-capital-reports', false)
            ->assertSee(route('finance.capital.report.show', ['slug' => 'changes']));

        foreach (['changes', 'return', 'reconcile'] as $slug) {
            $this->get(route('finance.capital.report.show', ['slug' => $slug, 'from' => $this->start]))->assertOk()
                ->assertSee('data-capital-reports', false)->assertSee($this->mymensingh->name());
        }

        $this->get(route('finance.capital.report.show', ['slug' => 'ledger', 'from' => $this->start, 'person_id' => $this->karim->id]))
            ->assertOk()->assertSee('Karim Capital')->assertSee('Rahim Capital')
            ->assertSee(\App\Core\Support\Money::drCr('-117000'));

        $this->get(route('finance.capital.report.show', ['slug' => 'nothing']))->assertNotFound();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function changes(string $from, array $extra = []): array
    {
        $result = app(ReportEngine::class)->run(CapitalReports::CHANGES, ['from' => $from, 'to' => now()->toDateString(), ...$extra]);

        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function find(array $rows, string $name, string $key = 'person_name'): ?array
    {
        foreach ($rows as $row) {
            if ($row[$key] === $name) {
                return $row;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function row(array $rows, string $name): array
    {
        $row = $this->find($rows, $name);
        $this->assertNotNull($row, "সারি নেই: {$name}");

        return $row;
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 4), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }

    private function headId(string $code): int
    {
        return (int) StandardChart::find($code)->id;
    }

    private function cash(): int
    {
        return (int) Account::query()->money()->where('is_group', false)->firstOrFail()->id;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function postLines(string $source, string $date, Branch $branch, array $lines): void
    {
        app(PostingEngine::class)->post(sourceType: $source, sourceId: random_int(1, 999999999), trxDate: $date, lines: $lines, branchId: (int) $branch->id);
    }

    private function capital(Person $who, Branch $branch, string $amount, string $date, string $kind, string $from = ''): void
    {
        $this->postLines('test:capital', $date, $branch, [
            ['account_id' => $from === '' ? $this->cash() : $this->headId($from), 'debit' => $amount],
            ['account_id' => $this->headId(StandardChart::OWNER_CAPITAL), 'credit' => $amount],
        ]);

        CapitalEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'document_no' => 'CAP-'.random_int(1, 999999),
            'person_id' => $who->id, 'contributor_type' => CapitalEntry::OWNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => $kind, 'trx_date' => $date, 'amount' => $amount, 'status' => CapitalEntry::POSTED, 'posted_at' => now(),
        ]);
    }

    private function draftCapital(Person $who, string $amount): void
    {
        CapitalEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->mymensingh->id, 'document_no' => 'CAP-DRAFT',
            'person_id' => $who->id, 'contributor_type' => CapitalEntry::OWNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH, 'trx_date' => $this->middle, 'amount' => $amount, 'status' => CapitalEntry::DRAFT,
        ]);
    }

    /** ⓘ রসিদ থেকে ওঠা মূলধন, রসিদ পরে বাতিল — খাতায় উল্টো দাখিলা বসেছে, সারি রয়ে গেছে */
    private function capitalOnACancelledReceipt(Person $who, string $amount): void
    {
        $voucher = Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->netrakona->id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => Voucher::RECEIPT,
            'document_no' => 'RCV-CANCELLED', 'trx_date' => $this->middle, 'amount' => $amount, 'status' => DocumentStatus::CANCELLED,
        ]);

        CapitalEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->netrakona->id, 'document_no' => 'CAP-CANCELLED',
            'person_id' => $who->id, 'contributor_type' => CapitalEntry::OWNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH, 'trx_date' => $this->middle, 'amount' => $amount, 'status' => CapitalEntry::POSTED,
            'posted_at' => now(), 'voucher_id' => $voucher->id,
        ]);
    }

    private function drawing(Person $who, Branch $branch, string $amount, string $date, string $kind = Withdrawal::DRAWING,
        string $status = DocumentStatus::CONFIRMED, bool $books = true): void
    {
        if ($books) {
            $this->postLines('test:drawing', $date, $branch, [
                ['account_id' => $this->headId(StandardChart::DRAWINGS), 'debit' => $amount],
                ['account_id' => $this->cash(), 'credit' => $amount],
            ]);
        }

        Withdrawal::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'document_no' => 'WDR-'.random_int(1, 999999),
            'person_id' => $who->id, 'amount' => $amount, 'kind' => $kind, 'in_kind' => 'cash', 'trx_date' => $date,
            'money_account_id' => $this->cash(), 'reason' => 'test', 'status' => $status, 'posted_at' => now(),
        ]);
    }

    /** খাতায় সরাসরি — রেজিস্টার ছাড়া */
    private function books(string $code, string $amount, Branch $branch, string $date, bool $credit): void
    {
        $this->postLines('test:books', $date, $branch, $credit
            ? [['account_id' => $this->cash(), 'debit' => $amount], ['account_id' => $this->headId($code), 'credit' => $amount]]
            : [['account_id' => $this->headId($code), 'debit' => $amount], ['account_id' => $this->cash(), 'credit' => $amount]]);
    }

    /**
     * খাতের জের, খাতা থেকে সরাসরি — মূলধন/আয়: ক্রেডিট − ডেবিট; উত্তোলন: ডেবিট − ক্রেডিট। `kind: 'pl'` = সব আয়-খরচ খাত
     * (বছর বন্ধের দাখিলা ছাড়া), `$code` তখন উপেক্ষিত।
     */
    private function net(string $code, ?string $before = null, string $kind = 'head', ?string $from = null, ?Branch $branch = null): string
    {
        $accounts = $kind === 'pl'
            ? Account::query()->whereIn('type', [Account::INCOME, Account::EXPENSE])->pluck('id')
            : StandardChart::find($code)->selfAndDescendants()->pluck('id');
        $debitSide = $kind === 'head' && $code === StandardChart::DRAWINGS;

        return (string) LedgerEntry::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->whereIn('account_id', $accounts)
            ->when($before, fn ($q) => $q->where('trx_date', '<', $before))
            ->when($from, fn ($q) => $q->where('trx_date', '>=', $from))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($kind === 'pl', fn ($q) => $q->where(fn ($w) => $w->whereNull('source_type')->orWhereNotIn('source_type', YearEndService::closingSources())))
            ->selectRaw($debitSide ? 'COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n' : 'COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n')
            ->value('n');
    }

    /** ৩১০০ − ৩২০০, একটা শাখায় */
    private function equity(Branch $branch, ?string $before = null): string
    {
        return bcsub($this->net(StandardChart::OWNER_CAPITAL, before: $before, branch: $branch),
            $this->net(StandardChart::DRAWINGS, before: $before, branch: $branch), 4);
    }
}
