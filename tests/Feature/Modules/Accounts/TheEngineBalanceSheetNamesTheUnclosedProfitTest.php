<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ দুই রকম স্থিতিপত্র — ইঞ্জিনেরটা কখনো মিলত না (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, হিসাব ⚠️১১)।
 *
 * ⓘ রিপোর্ট-ইঞ্জিনের `accounts.balance_sheet` (ফোন আর নির্ধারিত রিপোর্টে খোলে) কেবল সম্পদ-দায়-মূলধনের খাত দেখায়; বছর বন্ধের আগে
 * সেগুলোর ফারাক — চলতি বছরের লাভ — কোথাও বলা হত না। এখন সারাংশে নাম ধরে, পাতার স্থিতিপত্রের ([[BalanceSheetService]]) একই অঙ্কে।
 */
final class TheEngineBalanceSheetNamesTheUnclosedProfitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gap_is_the_years_profit_and_the_web_page_agrees(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $income = Account::query()->where('type', Account::INCOME)->where('is_group', false)->orderBy('code')->firstOrFail();
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->orderBy('code')->firstOrFail();
        $payable = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        $this->book('2026-08-05', $payable, $income->id, '9000');
        $profit = $this->summary();
        $this->assertTrue($profit['good']);
        $this->assertSame(__('accounts::message.unclosed_profit'), $profit['label']);
        $this->assertSame(0, bccomp((string) app(BalanceSheetService::class)->build(now()->toDateString())['profit'], $profit['value'], 4),
            '⛔ ইঞ্জিনের স্থিতিপত্রের লাভ পাতার স্থিতিপত্রের লাভের সাথে মেলে না');

        // ⓘ খরচ আয় ছাড়ালে "ক্ষতি", ধনাত্মক অঙ্কে
        $this->book('2026-08-06', $expense->id, $payable, '1000000');
        $loss = $this->summary();
        $this->assertFalse($loss['good']);
        $this->assertSame(__('accounts::message.unclosed_loss'), $loss['label']);
        $this->assertSame(0, bccomp(bcmul((string) app(BalanceSheetService::class)->build(now()->toDateString())['profit'], '-1', 4), $loss['value'], 4));
    }

    /** @return array{label: string, value: string, good: bool} */
    private function summary(): array
    {
        $report = app(ReportEngine::class)->get('accounts.balance_sheet');
        $this->assertNotNull($report->summary, '⛔ ইঞ্জিনের স্থিতিপত্র কোনো সারাংশ বলে না');

        return ($report->summary)(app(ReportEngine::class)->run('accounts.balance_sheet', [
            'from' => '2000-01-01', 'to' => now()->toDateString(),
        ])->totals);
    }

    private function book(string $on, int $debit, int $credit, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $on, lines: [
            ['account_id' => $debit, 'debit' => $amount],
            ['account_id' => $credit, 'credit' => $amount],
        ]);
    }
}
