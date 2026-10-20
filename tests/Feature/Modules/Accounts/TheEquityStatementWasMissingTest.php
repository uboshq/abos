<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মালিকানা-স্বত্বের পরিবর্তনের বিবরণী ছিলই না (ERP ১০০%-এর হিসাবের ফাঁক #৬, ২০ অক্টোবর ২০২৬)।
 *
 * <p>IAS 1.10(c) আর 1.106: পূর্ণ আর্থিক বিবরণীর একটা অংশ — প্রতিটা অংশের শুরুর জের, এই সময়ের লাভ, মালিকের টাকা আনা ও
 * তোলা, আর শেষের জের। আগে কেবল লাভ-ক্ষতি, স্থিতিপত্র আর নগদ প্রবাহ ছিল; মূলধন কেন বাড়ল-কমল কেউ এক পাতায় দেখতেন না।
 *
 * <p>⛔ শর্ত (fe, ২০ অক্টোবর): প্রতিটা কলামের শেষের জের স্থিতিপত্রের মালিকানার সাথে পয়সা পর্যন্ত মেলে, আর বছর বন্ধের আগে ও
 * পরে দুই অবস্থাতেই — বন্ধের পরে লাভ "বছর শেষের স্থানান্তর" সারিতে সঞ্চিত মুনাফায় যায়, মোট মালিকানা বদলায় না।
 */
final class TheEquityStatementWasMissingTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id)->id;
        $income = Account::query()->where('type', Account::INCOME)->where('is_group', false)->orderBy('code')->firstOrFail()->id;
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->orderBy('code')->firstOrFail()->id;

        // মালিক আনলেন ১,০০,০০০; বিক্রি ৩০,০০০; খরচ ১০,০০০.৫০; উত্তোলন ৫,০০০
        // ⓘ বছরের প্রথম দিনেই — শুরুর জের আগের দিনের, তাই এটা "অন্য চলাচল"-এ আসে, শুরুর জেরে নয়
        $this->post(0, $till, StandardChart::find(StandardChart::OWNER_CAPITAL)->id, '100000');
        $this->post(5, $till, $income, '30000');
        $this->post(7, $expense, $till, '10000.50');
        $this->post(9, StandardChart::find(StandardChart::DRAWINGS)->id, $till, '5000');
    }

    public function test_before_the_close_every_column_runs_from_opening_to_closing_and_ends_on_the_balance_sheet(): void
    {
        $statement = $this->statementThroughTheDoor();

        $profit = $this->column($statement, __('accounts::field.profit_not_closed'));
        $this->assertSame(0, bccomp($profit['profit'], '19999.5', 4), '⛔ এই সময়ের লাভ ৩০,০০০ − ১০,০০০.৫০ নয়');
        $this->assertSame(0, bccomp($profit['transfer'], '0', 4), 'বছর বন্ধ হয়নি — স্থানান্তর শূন্য');
        $this->assertSame(0, bccomp($this->column($statement, StandardChart::find(StandardChart::OWNER_CAPITAL)->name())['other'], '100000', 4),
            '⛔ মালিকের আনা টাকা মূলধনের কলামে নেই');
        $this->assertSame(0, bccomp($this->column($statement, StandardChart::find(StandardChart::DRAWINGS)->name())['other'], '-5000', 4),
            '⛔ উত্তোলন উত্তোলনের কলামে কমায় না');

        $this->assertEndsOnTheBalanceSheet($statement);
    }

    public function test_after_the_close_the_profit_moves_to_retained_earnings_and_total_equity_is_unchanged(): void
    {
        $before = $this->statementThroughTheDoor();

        app(YearEndService::class)->close($this->year);

        $after = $this->statementThroughTheDoor();
        $profit = $this->column($after, __('accounts::field.profit_not_closed'));
        $retained = $this->column($after, StandardChart::find(StandardChart::RETAINED_EARNINGS)->name());

        $this->assertSame(0, bccomp($profit['profit'], '19999.5', 4), '⛔ বন্ধের দাখিলা "এই সময়ের লাভ"-এ ঢুকল');
        $this->assertSame(0, bccomp($profit['transfer'], bcmul($profit['profit'], '-1', 4), 4), '⛔ বছরের পুরো লাভ বন্ধে সরেনি');
        $this->assertSame(0, bccomp($retained['transfer'], $profit['profit'], 4), '⛔ বছর শেষের স্থানান্তর সঞ্চিত মুনাফায় ওঠেনি');
        $this->assertSame(0, bccomp($after['total']['transfer'], '0', 4), '⛔ বছর বন্ধ মোট মালিকানা বদলাল');
        $this->assertSame(0, bccomp($after['total']['closing'], $before['total']['closing'], 4), '⛔ বন্ধের আগে আর পরে মোট মালিকানা আলাদা');

        $this->assertEndsOnTheBalanceSheet($after);
    }

    /** @return array<string, mixed> */
    private function statementThroughTheDoor(): array
    {
        $query = ['from' => Carbon::parse($this->year->starts_on)->toDateString(), 'to' => Carbon::parse($this->year->ends_on)->toDateString()];
        $response = $this->get(route('accounts.equity_statement', $query))->assertOk();
        $response->assertSee('data-equity-agrees="1"', false);

        /** @var array<string, mixed> $statement */
        $statement = $response->viewData('statement');
        $this->assertTrue($statement['agrees'], '⛔ কোনো কলাম শুরু + চলাচল = শেষ মেলে না');
        $this->assertStringContainsString(Money::format($statement['total']['closing']), (string) $response->getContent());

        return $statement;
    }

    /** ⛔ প্রতিটা কলামের শেষ = স্থিতিপত্রের সেই মালিকানার মাথা, আর মোট = স্থিতিপত্রের মোট মালিকানা — পয়সা পর্যন্ত */
    private function assertEndsOnTheBalanceSheet(array $statement): void
    {
        $sheet = app(BalanceSheetService::class)->build($statement['to']);

        foreach ($sheet['equity'] as $group) {
            $this->assertSame(0, bccomp($this->column($statement, $group['head']->name())['closing'], $group['total'], 4),
                '⛔ '.$group['head']->name().'-এর শেষের জের স্থিতিপত্রের সাথে মেলে না');
        }

        $this->assertSame(0, bccomp($this->column($statement, __('accounts::field.profit_not_closed'))['closing'], $sheet['profit'], 4),
            '⛔ বন্ধ না হওয়া লাভ স্থিতিপত্রের লাভের সারির সাথে মেলে না');
        $this->assertSame(0, bccomp($statement['total']['closing'], $sheet['totals']['equity'], 4), '⛔ মোট মালিকানা স্থিতিপত্রের সাথে মেলে না');
    }

    /** @return array<string, mixed> */
    private function column(array $statement, string $label): array
    {
        $column = collect($statement['columns'])->firstWhere('label', $label);
        $this->assertNotNull($column, 'কলাম নেই: '.$label);

        return $column;
    }

    private function post(int $day, int $debit, int $credit, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999),
            trxDate: Carbon::parse($this->year->starts_on)->addDays($day)->toDateString(), lines: [
                ['account_id' => $debit, 'debit' => $amount],
                ['account_id' => $credit, 'credit' => $amount],
            ]);
    }
}
