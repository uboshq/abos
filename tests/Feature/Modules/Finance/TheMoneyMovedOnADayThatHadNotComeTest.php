<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ হাতধার আর জমার টাকা আগামী দিনের তারিখে, আর জমা খোলার আগের দিনে নড়ত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⭐ দাবি: হাতধারের দেওয়া-নেওয়া, জমা খোলা আর জমার কিস্তি/মুনাফা/ভাঙা — আগামী দিনে নয়; জমার চলাচল জমা খোলার আগে নয়।
 */
final class TheMoneyMovedOnADayThatHadNotComeTest extends TestCase
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
        app(DepositKindInstaller::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($this->cash(), '500000', now()->subDays(5)->toDateString());
    }

    public function test_a_hand_loan_cannot_move_tomorrow(): void
    {
        $person = Person::query()->create(['code' => 'HL-FUT', 'name_en' => 'Future Lender', 'name_bn' => 'Future Lender', 'is_active' => true]);
        $loan = app(HandLoanService::class)->open(['person_id' => $person->id]);

        $this->post(route('finance.hand_loan.move', $loan), [
            'direction' => 'out', 'amount' => '20000', 'moved_on' => now()->addDay()->toDateString(), 'money_account_id' => $this->cash()->id,
        ])->assertSessionHasErrors('moved_on');
    }

    public function test_a_deposit_is_not_opened_tomorrow_nor_moved_before_it_opened(): void
    {
        $kind = DepositKind::query()->where('code', 'FDR')->firstOrFail();

        $this->post(route('finance.deposit.store', ['issuer' => $kind->issuer]), [
            'kind_id' => $kind->id, 'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'return_word' => 'interest', 'opened_on' => now()->addDay()->toDateString(), 'funded_from_account_id' => $this->cash()->id,
        ])->assertSessionHasErrors('opened_on');

        $deposit = app(DepositService::class)->open([
            'kind_id' => $kind->id, 'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'return_word' => 'interest', 'opened_on' => now()->subDays(2)->toDateString(), 'funded_from_account_id' => $this->cash()->id,
        ]);

        $this->post(route('finance.deposit.movement', ['issuer' => $kind->issuer, 'deposit' => $deposit]), [
            'kind' => DepositMovement::PAYOUT, 'amount' => '500', 'moved_on' => now()->subDays(4)->toDateString(), 'money_account_id' => $this->cash()->id,
        ])->assertSessionHasErrors('moved_on');

        $this->post(route('finance.deposit.close', ['issuer' => $kind->issuer, 'deposit' => $deposit]), [
            'amount' => '100000', 'moved_on' => now()->addDay()->toDateString(), 'money_account_id' => $this->cash()->id,
        ])->assertSessionHasErrors('moved_on');

        $this->assertSame(Deposit::ACTIVE, $deposit->fresh()->status);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }
}
