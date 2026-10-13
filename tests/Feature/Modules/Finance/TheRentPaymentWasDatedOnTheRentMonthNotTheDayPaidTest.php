<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ভাড়ার টাকা বেরোনোর তারিখ ভাড়ার মাসের প্রথম দিন ছিল, দেওয়ার দিন নয় — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৩)।
 *
 * ⓘ আজ আগাম ডিসেম্বর দিলে নগদ বেরোত ১ ডিসেম্বরে, দেরিতে দেওয়া আগের মাস বেরোত সেই মাসের ১ তারিখে। ⭐ দেওয়ার দিন দিলে সেটাই থাকে।
 */
final class TheRentPaymentWasDatedOnTheRentMonthNotTheDayPaidTest extends TestCase
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

        // ⓘ আজ = মাসের ১৩ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(12));
        $this->putMoneyIn($this->cash(), '1000000', now()->startOfMonth()->subMonths(3)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_money_leaves_on_the_day_it_is_paid_unless_a_day_is_given(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Dated Landlord', 'subject' => 'Shop', 'deposit_amount' => '0', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->subMonths(2)->toDateString(), 'rent_day' => 5,
        ]);
        $this->assertSame(RentalContract::ACTIVE, $contract->status, 'দৃশ্যটাই বানানো যায়নি — চুক্তি চালু নয়');

        $ahead = $this->pay($contract, now()->startOfMonth()->addMonths(2));
        $this->assertSame(now()->toDateString(), $ahead->voucher->trx_date->toDateString(), '⛔ আগাম মাসের টাকা ভাড়ার মাসের তারিখে বেরোল, আজ নয়');

        $late = $this->pay($contract, now()->startOfMonth()->subMonths(2));
        $this->assertSame(now()->toDateString(), $late->voucher->trx_date->toDateString(), '⛔ দেরিতে দেওয়া মাসের টাকা পুরনো মাসের তারিখে বেরোল');

        $given = $this->pay($contract, now()->startOfMonth()->subMonth(), ['paid_on' => now()->subDays(2)->toDateString()]);
        $this->assertSame(now()->subDays(2)->toDateString(), $given->voucher->trx_date->toDateString(), '⛔ দেওয়া তারিখটা মানা হলো না');
    }

    /** @param  array<string, mixed>  $extra */
    private function pay(RentalContract $contract, Carbon $month, array $extra = []): \App\Modules\Finance\Models\RentalAdjustment
    {
        return app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => $month->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
            ...$extra,
        ])->load('voucher');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
