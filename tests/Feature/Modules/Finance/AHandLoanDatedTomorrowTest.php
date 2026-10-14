<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * হাতধার ভবিষ্যতের দিনে বসত, আর চিঠির ভুল তারিখে ৫০০ (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবিগুলো:
 *   · হাতধারের দেওয়া-নেওয়া আজকের পরের দিনে নয়।
 *   · হাতধারের চিঠিতে "?as_of=abc" — আজকের দিনের চিঠি, ৫০০ নয়।
 *   · মেটানো হাতধারে আর টাকা বসে না (তালার নিচে আবার দেখা)।
 */
final class AHandLoanDatedTomorrowTest extends TestCase
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
        $this->putMoneyIn($this->cash(), '100000', now()->subDays(5)->toDateString());
    }

    public function test_a_hand_loan_is_not_dated_after_today_and_its_letter_takes_a_bad_date(): void
    {
        $person = Person::query()->create(['code' => 'HL-T', 'name_en' => 'Karim', 'name_bn' => 'Karim', 'is_active' => true]);
        $account = app(HandLoanService::class)->open(['person_id' => $person->id]);

        $this->post(route('finance.hand_loan.move', $account), [
            'direction' => HandLoanMovement::OUT, 'amount' => '5000', 'moved_on' => now()->addDay()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ])->assertSessionHasErrors('moved_on');
        $this->assertSame(0, HandLoanMovement::query()->where('account_id', $account->id)->count(), '⛔ কালকের দিনে হাতধার বসল');

        $this->get(route('finance.hand_loan.letter', ['person' => $person->id, 'as_of' => 'abc']))->assertOk();
    }

    public function test_a_settled_hand_loan_takes_no_more_money(): void
    {
        $person = Person::query()->create(['code' => 'HL-S', 'name_en' => 'Rahim', 'name_bn' => 'Rahim', 'is_active' => true]);
        $account = app(HandLoanService::class)->open(['person_id' => $person->id]);
        $stale = $account->fresh();

        app(HandLoanService::class)->settle($account->fresh());

        try {
            // ⓘ অন্য ট্যাবের বাসি কপি — "চালু" দেখায়
            app(HandLoanService::class)->move($stale, ['direction' => HandLoanMovement::OUT, 'amount' => '1000',
                'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id]);
            $this->fail('⛔ মেটানো হাতধারে বাসি কপি দিয়ে টাকা বসল');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame(0, HandLoanMovement::query()->where('account_id', $account->id)->count());
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
