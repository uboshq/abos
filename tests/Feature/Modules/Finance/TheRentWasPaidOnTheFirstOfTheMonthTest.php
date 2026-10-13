<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ভাড়া দেওয়ার ভাউচার যে মাসের ভাড়া সেই মাসের প্রথম দিনে বসত, আর খালি ঘরে আজকের দর যেত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর
 * ২০২৬ ([[RentalContractService::adjustMonth()]])।
 *
 * ⭐ দাবি:
 *   · দিন না বললে টাকা দেওয়ার দিন আজ — সেপ্টেম্বরের ভাড়া ১৩ অক্টোবরে দিলে নগদ ১৩ অক্টোবরে কমে
 *   · ভাড়ার ঘর খালি থাকলে সেই মাসের শর্তের দর — অক্টোবরে বাড়ার পরেও সেপ্টেম্বর পুরনো দরে
 */
final class TheRentWasPaidOnTheFirstOfTheMonthTest extends TestCase
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

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->putMoneyIn($this->cash(), '500000', '2026-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_back_month_is_paid_today_at_its_own_rate(): void
    {
        $service = app(RentalContractService::class);
        $contract = $service->open([
            'counterparty' => 'Late Landlord', 'subject' => 'Godown', 'deposit_amount' => '0', 'monthly_rent' => '30000',
            'monthly_adjustment' => '0', 'term_months' => 24, 'starts_on' => '2026-08-01', 'rent_day' => 5,
        ]);
        app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $service->reviseTerms($contract->fresh(), ['monthly_rent' => '33000', 'monthly_adjustment' => '0', 'effective_from' => '2026-10-01']);

        $paid = $service->adjustMonth($contract->fresh(), ['for_month' => '2026-09-01', 'money_account_id' => $this->cash()->id]);

        $voucher = Voucher::acrossBranches()->findOrFail($paid->voucher_id);
        $this->assertSame('2026-10-13', $voucher->trx_date->toDateString(), '⛔ ১৩ অক্টোবরে দেওয়া ভাড়া ১ সেপ্টেম্বরে বসল — নগদের খাতা ভুল');
        $this->assertSame('30000', bcadd((string) RentalAdjustment::query()->findOrFail($paid->id)->rent, '0', 0),
            '⛔ সেপ্টেম্বরের ভাড়া অক্টোবরের নতুন দরে গেল — বাড়িওয়ালা ৩,০০০ বেশি পেলেন');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }
}
