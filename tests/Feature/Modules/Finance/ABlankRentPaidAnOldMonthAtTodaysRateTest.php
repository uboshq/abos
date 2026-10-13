<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ভাড়ার ঘর খালি রেখে পুরনো মাস দিলে আজকের দরে যেত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৪)।
 *
 * ⓘ গত মাস ৩০,০০০-এ বসার পর এ মাস থেকে ৩৫,০০০ হলে, খালি ঘরে গত মাস দিলে ৩৫,০০০ যেত (Dr ২১৪১ ৩০,০০০ + Dr খরচ ৫,০০০) —
 * বাড়িওয়ালা ৫,০০০ বেশি পেতেন। জামানতের কাটাও আজকের অঙ্কে হত। ⭐ এখন বসানো মাসে বসানো অঙ্ক, না বসানো মাসে সেই মাসের শর্ত।
 */
final class ABlankRentPaidAnOldMonthAtTodaysRateTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⓘ আজ = মাসের ১০ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', $this->month(-3)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_blank_rent_pays_what_that_month_was_owed(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Old Rate Landlord', 'subject' => 'Godown', 'deposit_amount' => '240000', 'monthly_rent' => '30000',
            'monthly_adjustment' => '10000', 'term_months' => 24, 'starts_on' => $this->month(-2)->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $this->assertSame(RentalContract::ACTIVE, $contract->status, 'দৃশ্যটাই বানানো যায়নি — চুক্তি চালু নয়');

        app(RentalAccrualService::class)->run($this->month(-1));
        app(RentalContractService::class)->reviseTerms($contract->fresh(), [
            'monthly_rent' => '35000', 'monthly_adjustment' => '5000', 'effective_from' => $this->month(0)->format('Y-m'),
        ]);
        $this->assertSame(0, bccomp('35000', (string) $contract->fresh()->monthly_rent, 2), 'দৃশ্যটাই বানানো যায়নি — নতুন দর বসেনি');

        // ⓘ বসানো মাস — বসানো অঙ্কটুকুই, খরচে কিছু নয়
        $owed = $this->pay($contract, $this->month(-1));
        $this->assertMoney('30000', $owed->rent, '⛔ বসানো মাস আজকের দরে দেওয়া হলো');
        $this->assertMoney('10000', $owed->from_deposit, '⛔ জামানতের কাটা আজকের অঙ্কে হলো, সেই মাসের নয়');
        $this->assertMoney('20000', $this->line($owed, $this->cash()->id, 'credit'), '⛔ বাড়িওয়ালা বেশি নগদ পেলেন');
        $this->assertMoney('0', $this->line($owed, (int) $contract->expense_account_id, 'debit'), '⛔ বসানো মাসের তফাত খরচে পড়ল');

        // ⓘ না বসানো মাস — সেই মাসের শর্তে
        $old = $this->pay($contract, $this->month(-2));
        $this->assertMoney('30000', $old->rent, '⛔ না-বসানো পুরনো মাস আজকের দরে দেওয়া হলো');
        $this->assertMoney('10000', $old->from_deposit, '⛔ পুরনো মাসের জামানতের কাটা আজকের অঙ্কে হলো');
        $this->assertMoney('20000', $this->line($old, $this->cash()->id, 'credit'), '⛔ পুরনো মাসে বাড়িওয়ালা বেশি নগদ পেলেন');
    }

    private function pay(RentalContract $contract, Carbon $month): RentalAdjustment
    {
        return app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => $month->toDateString(), 'money_account_id' => $this->cash()->id,
        ])->load('voucher.lines');
    }

    private function line(RentalAdjustment $row, int $accountId, string $side): string
    {
        return (string) $row->voucher->lines->where('account_id', $accountId)->sum($side);
    }

    private function month(int $offset): Carbon
    {
        return now()->startOfMonth()->addMonths($offset);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
