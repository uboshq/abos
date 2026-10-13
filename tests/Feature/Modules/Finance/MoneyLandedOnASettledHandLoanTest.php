<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ হাতধারের "খোলা কি" যাচাই ছিল হাতের কপিতে, তালা ছাড়া — এক ট্যাবে "চুকে গেছে", আরেক ট্যাবে (পুরনো পাতা) টাকা দেওয়া: টাকাটা চুকে
 * যাওয়া খাতায় বসত (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[HandLoanService::move()]])।
 *
 * ⭐ দাবি: পুরনো কপি হাতে নিয়ে চুকে যাওয়া খাতায় টাকা নড়ে না; চুকে যাওয়া খাতা আবার "চুকে যায়" না।
 */
final class MoneyLandedOnASettledHandLoanTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_a_stale_page_cannot_move_money_on_a_settled_hand_loan(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $cash = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $this->putMoneyIn($cash, '50000', now()->subDay()->toDateString());

        $person = Person::query()->create(['code' => 'HL-STALE', 'name_en' => 'Stale Page', 'name_bn' => 'Stale Page', 'is_active' => true]);
        $stale = app(HandLoanService::class)->open(['person_id' => $person->id]);

        app(HandLoanService::class)->settle(HandLoanAccount::query()->findOrFail($stale->id));
        $this->assertSame(HandLoanAccount::SETTLED, $stale->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — খাতা চুকে যায়নি');

        try {
            app(HandLoanService::class)->move($stale, [
                'direction' => 'out', 'amount' => '10000', 'moved_on' => now()->toDateString(), 'money_account_id' => $cash->id,
            ]);
            $this->fail('⛔ চুকে যাওয়া খাতায় টাকা দেওয়া গেল');
        } catch (ValidationException) {
        }

        $this->assertSame(0, HandLoanMovement::query()->where('account_id', $stale->id)->count(), '⛔ চুকে যাওয়া খাতায় চলাচল বসল');
    }
}
