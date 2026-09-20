<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Services\AccountAnalysis;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খতিয়ান ছিল দুশো সারি, আর প্রশ্নের উত্তর ছিল না — মানচিত্র §৪ "খাত বিশ্লেষণ"।
 *
 * ⭐ একই দাখিলা মাস ধরে: শুরুর জের + নড়াচড়া = শেষ জের, আর শেষটা পরের
 * মাসের শুরু। ⚠️ এই শিকলটা একটা মাসে ভাঙলে বাকি সব মাস ভুল দেখায়, তাই
 * পরীক্ষাটা শিকলটাই দেখে — আর দেখে শেষ জের খতিয়ানের জেরের সমান।
 */
final class TheLedgerWasTwoHundredRowsAndNoAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    public function test_months_chain_and_the_last_closing_equals_the_ledger_balance(): void
    {
        $rent = Account::query()->where('code', StandardChart::RENT)->firstOrFail();

        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toDateString();
        $this->spend($rent, '5000', $lastMonth);
        $this->spend($rent, '7000', now()->toDateString());
        $this->spend($rent, '1000', now()->toDateString());

        $from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $report = app(AccountAnalysis::class)->of($rent, $from, $to);

        $this->assertCount(2, $report['months']);
        [$prev, $this_] = $report['months'];

        $this->assertSame(0, bccomp($prev['debit'], '5000', 2));
        $this->assertSame(0, bccomp($this_['debit'], '8000', 2));
        $this->assertSame(2, $this_['count']);
        $this->assertSame(0, bccomp($prev['closing'], $this_['opening'], 4), 'মাসের শিকল ভেঙেছে।');
        $this->assertSame(0, bccomp($report['closing'], $rent->balanceOn($to), 4),
            'বিশ্লেষণের শেষ জের আর খতিয়ানের জের দুই কথা বলছে।');

        $page = $this->get(route('finance.account_analysis.index', [
            'account_id' => $rent->id, 'period' => 'custom', 'from' => $from, 'to' => $to,
        ]))->assertOk();

        $page->assertSee(route('accounts.report.show', [
            'slug' => 'ledger', 'account_id' => $rent->id, 'from' => $from,
            'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]));
    }

    public function test_without_an_account_the_page_asks_for_one(): void
    {
        $this->get(route('finance.account_analysis.index'))->assertOk()
            ->assertSee(__('finance::account_analysis.pick_account'));
    }

    public function test_a_credit_natured_account_counts_credits_as_growth(): void
    {
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $rent = Account::query()->where('code', StandardChart::RENT)->firstOrFail();

        $before = $capital->balanceOn(now()->toDateString());
        $this->spend($rent, '2500', now()->toDateString(), credit: $capital);

        $report = app(AccountAnalysis::class)->of($capital, now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(0, bccomp(bcsub($report['closing'], $before, 4), '2500', 2),
            'ক্রেডিট প্রকৃতির খাতে ক্রেডিট জের বাড়ায়নি — চিহ্ন উল্টো।');
    }

    private function spend(Account $debit, string $amount, string $date, ?Account $credit = null): void
    {
        $credit ??= Account::query()->money()->postable()->active()->firstOrFail();

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'analysis test'],
            [
                ['account_id' => $debit->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $credit->id, 'debit' => '0', 'credit' => $amount],
            ],
        ));
    }
}
