<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
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
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ শেষ হওয়া চুক্তির বসানো অথচ না-দেওয়া মাস আর দেওয়াই যেত না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ২)।
 *
 * ⓘ মাসের শুরুতে ভাড়া প্রদেয় বসে (Dr খরচ / Cr ২১৪১)। চুক্তি শেষ করার সময় সেটা দেখা হত না, আর শেষ হওয়া চুক্তিতে মাসের
 * পরিশোধ "চুক্তি শেষ" বলে থামত — বাড়িওয়ালার পাওনা ২১৪১-এ চিরকাল ঝুলত।
 *
 * ⭐ দাবি:
 *   · বসানো অথচ না-দেওয়া মাস থাকলে চুক্তি শেষ হয় না — কোন মাস, কত, কী করতে হবে বলে
 *   · শেষ হওয়া চুক্তিতে (পুরনো দিনের, বা ফেরতের সইয়ের মাঝে জমা চললে) ঐ মাস দেওয়া যায় — ২১৪১ শূন্যে নামে, পাতায় ফর্মটা থাকে
 *   · শেষ হওয়া চুক্তিতে নতুন মাস নয়
 */
final class TheClosedContractsBookedMonthCouldNotBePaidTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', now()->startOfMonth()->subMonths(2)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_contract_with_a_booked_unpaid_month_does_not_close_quietly(): void
    {
        $contract = $this->contractWithThisMonthBooked();

        try {
            app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);
            $this->fail('⛔ বাড়িওয়ালার পাওনা মাস রেখেই চুক্তি শেষ হলো — ২১৪১-এর দায় চোখের আড়ালে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('closed_on', $e->errors(), 'অন্য কারণে থেমেছে: '.implode(', ', array_keys($e->errors())));
            $this->assertStringContainsString(now()->translatedFormat('F Y'), $e->errors()['closed_on'][0], '⛔ বার্তায় কোন মাস তা নেই');
        }

        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status);

        // ⓘ মাসটা দিলে তারপর শেষ হয়
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);
        $this->assertSame(RentalContract::CLOSED, $contract->fresh()->status);
        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), '⛔ ২১৪১ শূন্যে নামেনি');
    }

    public function test_a_booked_month_on_a_closed_contract_can_still_be_paid_but_no_new_month(): void
    {
        $contract = $this->contractWithThisMonthBooked();

        // ⓘ আগের দিনের মতো বন্ধ — মাসটা বসানো, দেওয়া হয়নি (লাইভে এমন চুক্তি আছে)
        $contract->forceFill(['status' => RentalContract::CLOSED, 'closed_on' => now()->toDateString()])->save();
        $this->assertMoney('-10000', $this->net(StandardChart::RENT_PAYABLE), 'দৃশ্যটাই বানানো যায়নি — ২১৪১-এ মাসের পাওনা নেই');

        $this->get(route('finance.rental.show', $contract))->assertOk()
            ->assertSee(route('finance.rental.adjust', $contract), false);

        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);

        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), '⛔ শেষ হওয়া চুক্তির পাওনা মাস দেওয়া গেল না');
        $this->assertSame(RentalContract::CLOSED, $contract->fresh()->status);

        try {
            app(RentalContractService::class)->adjustMonth($contract->fresh(), [
                'for_month' => now()->startOfMonth()->addMonthNoOverflow()->toDateString(), 'rent' => '10000', 'from_deposit' => '0',
                'money_account_id' => $this->cash()->id,
            ]);
            $this->fail('⛔ শেষ হওয়া চুক্তিতে নতুন মাস বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    private function contractWithThisMonthBooked(): RentalContract
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Owed Landlord', 'subject' => 'Owed shop', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 24, 'starts_on' => now()->startOfMonth()->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);

        app(RentalAccrualService::class)->run(now()->startOfMonth());

        return $contract->fresh();
    }

    private function net(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
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
