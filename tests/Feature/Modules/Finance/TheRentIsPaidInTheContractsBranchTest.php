<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ভাড়ার টাকা চুক্তির শাখায় বসে, যে শাখা থেকে কাজটা হলো সেখানে নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (⛔৫)।
 *
 * ⓘ আগে পরিশোধ, জামানত বাড়ানো আর চুক্তি শেষের ভাউচার চলতি শাখায় বসত, অথচ মাসের প্রদেয় ([[RentalAccrualService]]) আর
 * জামানত বসেছিল চুক্তির শাখায় — অন্য শাখা থেকে দিলে ২১৪১ বা ১১৪০ শাখা ধরে কখনো মিলত না (কোম্পানির মোট মিলত, তাই চোখে পড়ত না)।
 */
final class TheRentIsPaidInTheContractsBranchTest extends TestCase
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

        $this->elsewhere = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'RNT-B', 'name_en' => 'Other Branch']);

        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', now()->startOfMonth()->subMonths(2)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paying_topping_up_and_closing_from_another_branch_land_in_the_contracts_branch(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Branch Landlord', 'subject' => 'Branch place', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 24, 'starts_on' => now()->startOfMonth()->subMonth()->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $this->assertSame($this->home->id, (int) $contract->branch_id, 'দৃশ্যটাই বানানো যায়নি — চুক্তি বাড়ির শাখায় নয়');
        app(RentalAccrualService::class)->run(now()->startOfMonth());

        // ⓘ এখন অন্য শাখা থেকে কাজ
        CompanyContext::set($this->company->id, $this->elsewhere->id);

        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        app(RentalContractService::class)->addToDeposit($contract->fresh(), ['amount' => '5000', 'money_account_id' => $this->cash()->id]);

        foreach ([StandardChart::RENT_PAYABLE, StandardChart::SECURITY_DEPOSIT] as $code) {
            $this->assertSame(0, LedgerEntry::query()->where('account_id', StandardChart::find($code)->id)->where('branch_id', $this->elsewhere->id)->count(),
                "⛔ {$code}-এর সারি কাজ করা শাখায় বসল, চুক্তির শাখায় নয়");
        }

        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE, $this->home->id), '⛔ বাড়ির শাখায় ২১৪১ শোধ হয়নি');
        $this->assertMoney('35000', $this->net(StandardChart::SECURITY_DEPOSIT, $this->home->id), '⛔ বাড়ানো জামানত বাড়ির শাখায় নেই');

        // ⓘ চুক্তি শেষ — বাকি জামানত ফেরত, তাও বাড়ির শাখায়
        app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);
        $this->assertSame(RentalContract::CLOSED, $contract->fresh()->status);
        $this->assertMoney('0', $this->net(StandardChart::SECURITY_DEPOSIT, $this->home->id), '⛔ ফেরতের ভাউচার অন্য শাখায় বসল');
    }

    private function net(string $code, int $branch): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->where('branch_id', $branch)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
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
