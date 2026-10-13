<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ হেডারে অন্য শাখা বাছা থাকলে জামানত পুরোটা "পড়ে আছে" দেখাত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⛔১)।
 *
 * ⓘ ভাউচার বসে চুক্তির শাখায়, আর ভাউচারে শাখা-দেয়াল ([[ScopedToUserBranch]])। জামানতের কাটা ([[RentalContract::adjustedSoFar()]]),
 * আগাম মাসের ফেরত ([[RentalContractService::prepaidToRefund()]]) আর আগাম মাস খরচে সরানো ([[RentalAccrualService::release()]])
 * দেয়ালের ভেতর থেকে ভাউচার পড়ত — অন্য শাখা দেখানো অবস্থায় তিনটাই "কিছু নেই" পড়ত:
 *   · চুক্তি শেষে খাতায় যা নেই সেই জামানতও ফেরত বসত, ১১৪০ ঋণাত্মক
 *   · আগাম দেওয়া মাস ফেরত আসত না, ১১৩৭ চিরকাল সম্পদ
 *   · আগাম মাস এলে খরচে যেত না
 */
final class TheDepositLeftLookedFullWhenTheHeaderShowedAnotherBranchTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Branch $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->elsewhere = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'RNT-V', 'name_en' => 'Viewed Branch']);

        // ⓘ আজ = মাসের ১০ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', $this->month(-3)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_deposit_left_and_the_closing_refund_read_the_contracts_own_vouchers(): void
    {
        $contract = $this->open('Viewed Landlord', $this->month(-1));

        // ⓘ এই মাস পুরোটা জামানত থেকে; পরের মাস আগাম, নগদে
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => $this->month(0)->toDateString(), 'rent' => '10000', 'from_deposit' => '10000', 'money_account_id' => $this->cash()->id,
        ]);
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => $this->month(1)->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        $this->assertMoney('10000', $this->net(StandardChart::PREPAID_RENT), 'দৃশ্যটাই বানানো যায়নি — আগাম মাস ১১৩৭-এ নেই');

        $this->lookAtTheOtherBranch();
        $this->assertFalse(Voucher::query()->where('against_type', RentalContract::drillSourceType())->where('against_id', $contract->id)->exists(),
            'দৃশ্যটাই বানানো যায়নি — হেডারের শাখা চুক্তির ভাউচার আড়াল করছে না');

        $this->assertMoney('90000', $contract->fresh()->depositLeft(), '⛔ অন্য শাখা দেখানো অবস্থায় জামানতের কাটা মাস চোখ এড়াল');

        app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);

        $this->assertSame(RentalContract::CLOSED, $contract->fresh()->status);
        $this->assertMoney('0', $this->net(StandardChart::SECURITY_DEPOSIT), '⛔ খাতায় যা নেই সেই জামানতও ফেরত বসল — ১১৪০ শূন্য নয়');
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ আগাম দেওয়া মাস ফেরত এল না — ১১৩৭ সম্পদ হয়ে রইল');
    }

    public function test_a_month_paid_ahead_goes_to_expense_whichever_branch_the_header_shows(): void
    {
        // ⓘ গত মাসে বসে এই মাসের ভাড়া আগাম দেওয়া
        Carbon::setTestNow(now()->subMonth());
        $contract = $this->open('Ahead Landlord', now()->startOfMonth());
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->addMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        Carbon::setTestNow(now()->addMonth());
        $this->assertMoney('10000', $this->net(StandardChart::PREPAID_RENT), 'দৃশ্যটাই বানানো যায়নি — আগাম মাস ১১৩৭-এ নেই');

        $this->lookAtTheOtherBranch();
        app(RentalAccrualService::class)->run($this->month(0));

        $this->assertTrue(RentalAccrual::query()->where('rental_contract_id', $contract->id)->whereDate('for_month', $this->month(0)->toDateString())->exists(),
            '⛔ অন্য শাখা দেখানো অবস্থায় আগাম মাস খরচে সরল না');
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ আগাম মাস এসে গেল, অথচ ১১৩৭ থেকে নড়ল না');
    }

    /** ⓘ মালিক "সব শাখা" নয়, হেডারে অন্য একটা শাখা বেছেছেন */
    private function lookAtTheOtherBranch(): void
    {
        auth()->user()->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->elsewhere->id])->save();
        CompanyContext::set($this->company->id, $this->elsewhere->id);
    }

    private function open(string $who, Carbon $starts): RentalContract
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => $who, 'subject' => 'Godown', 'deposit_amount' => '100000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '10000', 'term_months' => 10, 'starts_on' => $starts->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $this->assertSame($this->home->id, (int) $contract->branch_id, 'দৃশ্যটাই বানানো যায়নি — চুক্তি বাড়ির শাখায় নয়');
        $this->assertSame(RentalContract::ACTIVE, $contract->status, 'দৃশ্যটাই বানানো যায়নি — চুক্তি চালু নয়');

        return $contract;
    }

    private function month(int $offset): Carbon
    {
        return now()->startOfMonth()->addMonths($offset);
    }

    private function net(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->where('branch_id', $this->home->id)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
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
