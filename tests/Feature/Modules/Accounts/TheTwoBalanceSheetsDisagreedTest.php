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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * দুই স্থিতিপত্র — পর্দার আর ইঞ্জিনের (ফোন, নির্ধারিত রিপোর্ট) — একই খাতায় আলাদা মোট বলত — পুরো-ERP পুনঃঅডিট,
 * ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৮)।
 *
 * ⛔ আগে: পর্দা পাওনা-দেনা মোট দেখায় আর অগ্রিম আলাদা লাইনে ([[BalanceSheetService]]); ইঞ্জিন নিট — তাই অগ্রিম থাকলেই
 * মোট সম্পদ আর মোট দায় দুই জায়গায় দুই রকম।
 * ⭐ এখন ইঞ্জিনেও অগ্রিম নিজের লাইনে ([[CoreReports::sheetWithAdvancesApart()]]) — সম্পদ, দায়, মূলধন তিনটাই এক।
 */
final class TheTwoBalanceSheetsDisagreedTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_versions_give_the_same_totals_on_the_same_books(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $cash = (int) DB::table('accounts')->where('company_id', $company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        $income = (int) DB::table('accounts')->where('company_id', $company->id)->where('is_group', false)->where('type', 'income')->orderBy('id')->value('id');
        $rec = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $pay = (int) StandardChart::find(StandardChart::PAYABLE)->id;

        // ⓘ গ্রাহক ক ১,০০০ দেনদার, খ ৩০০ অগ্রিম; সরবরাহকারী গ-কে ২০০ অগ্রিম, ঘ-এর কাছে ৫০০ দেনা
        foreach ([
            [['account_id' => $rec, 'debit' => '1000', 'party_type' => 'customer', 'party_id' => 900001], ['account_id' => $income, 'credit' => '1000']],
            [['account_id' => $cash, 'debit' => '300'], ['account_id' => $rec, 'credit' => '300', 'party_type' => 'customer', 'party_id' => 900002]],
            [['account_id' => $pay, 'debit' => '200', 'party_type' => 'supplier', 'party_id' => 900003], ['account_id' => $cash, 'credit' => '200']],
            [['account_id' => (int) StandardChart::find(StandardChart::INVENTORY)->id, 'debit' => '500'], ['account_id' => $pay, 'credit' => '500', 'party_type' => 'supplier', 'party_id' => 900004]],
        ] as $n => $lines) {
            app(PostingEngine::class)->post(sourceType: 'test_two_sheets', sourceId: $n + 1, trxDate: now()->toDateString(), lines: $lines,
                branchId: $company->defaultBranch()?->id);
        }

        $web = app(BalanceSheetService::class)->build(now()->toDateString());

        $result = app(ReportEngine::class)->run('accounts.balance_sheet', ['to' => now()->toDateString()], 1, 1000);
        $rows = collect($result->rows);
        $side = fn (string $type) => $rows->where('type', $type)->reduce(fn ($sum, $r) => bcadd($sum, (string) $r['net'], 4), '0');
        $summary = (app(ReportEngine::class)->get('accounts.balance_sheet')->summary)($result->totals);
        $profit = $summary['good'] ? $summary['value'] : bcmul($summary['value'], '-1', 4);

        $this->assertSame(0, bccomp($side(Account::ASSET), $web['totals']['assets'], 2),
            '⛔ মোট সম্পদ দুই স্থিতিপত্রে আলাদা — ইঞ্জিন '.$side(Account::ASSET).', পর্দা '.$web['totals']['assets']);
        $this->assertSame(0, bccomp(bcmul($side(Account::LIABILITY), '-1', 4), $web['totals']['liabilities'], 2),
            '⛔ মোট দায় দুই স্থিতিপত্রে আলাদা — ইঞ্জিন '.bcmul($side(Account::LIABILITY), '-1', 4).', পর্দা '.$web['totals']['liabilities']);
        $this->assertSame(0, bccomp(bcadd(bcmul($side(Account::EQUITY), '-1', 4), $profit, 4), $web['totals']['equity'], 2), '⛔ মোট মূলধন দুই স্থিতিপত্রে আলাদা।');

        // ⓘ অগ্রিম নিজের নামে, নিজের অঙ্কে
        $this->assertSame(0, bccomp((string) $rows->firstWhere('account_name', __('accounts::field.customer_advance'))['credit'], '300', 2), 'ইঞ্জিনে গ্রাহকের অগ্রিমের লাইন নেই।');
        $this->assertSame(0, bccomp((string) $rows->firstWhere('account_name', __('accounts::field.supplier_advance'))['debit'], '200', 2), 'ইঞ্জিনে সরবরাহকারীর অগ্রিমের লাইন নেই।');
    }
}
