<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ব্যাংক ওভারড্রাফট নগদ-সমতুল্যের বিয়োগ, অর্থায়ন নয় — IAS 7.8 (মালিক, ১৩ অক্টোবর ২০২৬: "int standrad onuzayi koro")।
 *
 * ⓘ চাহিবামাত্র শোধযোগ্য ওভারড্রাফট নগদ ব্যবস্থাপনার অংশ। ওখান থেকে টানা টাকা "অর্থায়নে টাকা এল" নয় — নগদ প্রবাহে ওভারড্রাফটের
 * খাত কোনো সারি হয় না, টাকার পাশ হয়; আর শুরু-শেষের নগদে ওভারড্রাফট বিয়োগ হয়ে থাকে।
 */
final class OverdraftIsCashNotFinancingTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_drawn_on_an_overdraft_is_not_financing_and_the_cash_lines_net_it(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $before = now()->startOfMonth()->subDay()->toDateString();

        $cashBefore = $this->cashLines($from, $to);

        // ⓘ ৫০০০ ওভারড্রাফট নিয়ে খোলা ব্যাংক — জের যায় ব্যাংকের ওভারড্রাফটের দায়-খাতে
        $bank = app(AccountService::class)->create([
            'code' => '1102-CF', 'name_en' => 'CF Bank', 'name_bn' => 'নগদ প্রবাহের ব্যাংক', 'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'opening_balance' => '5000', 'opening_side' => Account::CREDIT, 'opening_date' => $before,
        ]);
        $overdraft = Account::query()->where('code', StandardChart::BANK_OVERDRAFT.'-'.$bank->id)->firstOrFail();

        // ⓘ মাসের ভিতরে: ওভারড্রাফট থেকে ৩০০০ ব্যাংকে (নগদ→নগদ), আর ওভারড্রাফট থেকে ১০০০ খরচ
        $this->book($to, $bank->id, $overdraft->id, '3000');
        $this->book($to, (int) Account::query()->where('code', '5202')->value('id'), $overdraft->id, '1000');

        $result = app(ReportEngine::class)->run('accounts.cash_flow', ['from' => $from, 'to' => $to], 1, 500);
        $rows = collect($result->rows);

        $this->assertNull($rows->firstWhere('account_id', $overdraft->id),
            '⛔ ওভারড্রাফটের খাত নগদ প্রবাহের সারি হল — টানা টাকা "অর্থায়নে এল" দেখাচ্ছে');
        $this->assertSame(0, bccomp((string) ($rows->firstWhere('account_id', (int) Account::query()->where('code', '5202')->value('id'))['money_out'] ?? '0'), '1000', 4),
            '⛔ ওভারড্রাফট থেকে দেওয়া খরচ পরিচালনে বেরোয়নি');

        $summary = (app(ReportEngine::class)->get('accounts.cash_flow')->summary)($result->totals, $result->filters);
        $lines = collect($summary['lines'] ?? [])->pluck('value', 'label');

        $this->assertSame(0, bccomp(bcsub((string) $lines->get(__('accounts::message.cf_opening_cash')), $cashBefore['open'], 4), '-5000', 4),
            '⛔ শুরুর নগদে ওভারড্রাফট বিয়োগ হয়নি');
        $this->assertSame(0, bccomp(bcsub((string) $lines->get(__('accounts::message.cf_closing_cash')), $cashBefore['close'], 4), '-6000', 4),
            '⛔ শেষের নগদে ওভারড্রাফট বিয়োগ হয়নি, বা খরচটা নগদ কমায়নি');
    }

    /** @return array{open: string, close: string} আগে থেকে থাকা নগদ — ডেমোর টাকা মাপ থেকে বাদ দিতে */
    private function cashLines(string $from, string $to): array
    {
        $result = app(ReportEngine::class)->run('accounts.cash_flow', ['from' => $from, 'to' => $to], 1, 500);
        $summary = (app(ReportEngine::class)->get('accounts.cash_flow')->summary)($result->totals, $result->filters);
        $lines = collect($summary['lines'] ?? [])->pluck('value', 'label');

        return ['open' => (string) ($lines->get(__('accounts::message.cf_opening_cash')) ?? '0'), 'close' => (string) ($lines->get(__('accounts::message.cf_closing_cash')) ?? '0')];
    }

    private function book(string $on, int $debit, int $credit, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $on, lines: [
            ['account_id' => $debit, 'debit' => $amount],
            ['account_id' => $credit, 'credit' => $amount],
        ]);
    }
}
