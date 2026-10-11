<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অন্য কোম্পানির আইডি অর্থের ফর্মে — পুরো-ERP অডিট, অর্থ, ১০ অক্টোবর ২০২৬।
 *
 * ⛔ হাতধারের সাথে জোড়া পক্ষ কেবল ছাঁদে যাচাই হত ("customer:12"), আর জমা, হাতধার আর উত্তোলনের খাত-ঘরে `exists:accounts,id` —
 * কোম্পানি না দেখে। অন্য কোম্পানির ডিলার বা টাকার খাতের আইডি বসানো যেত।
 * ⭐ পক্ষ যায় [[PartyRegistry::exists()]] দিয়ে; খাতের ঘরে কোম্পানির ছাঁকনি ([[EveryExistsRuleNamesItsCompanyTest]]-এর NOT_YET থেকে তিনটা ফাইল বাদ)।
 */
final class AnotherCompanysIdSlippedIntoTheFinanceFormsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('id', '!=', $this->company->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_hand_loan_links_only_a_party_of_this_company(): void
    {
        $person = Person::query()->create(['code' => 'P-HLX', 'name_en' => 'Link Karim', 'name_bn' => 'Link Karim', 'is_active' => true]);
        $ours = Customer::query()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
        $theirs = Customer::query()->withoutGlobalScopes()->where('company_id', $this->other->id)->orderBy('id')->first()
            ?? Customer::query()->withoutGlobalScopes()->create(['company_id' => $this->other->id, 'code' => 'X-1', 'name_en' => 'Other Dealer', 'is_active' => true]);

        $this->post(route('finance.hand_loan.store'), ['person_id' => $person->id, 'party' => 'customer:'.$theirs->id])
            ->assertSessionHasErrors('party');
        $this->assertSame(0, HandLoanAccount::query()->where('partner_id', $theirs->id)->count(), '⛔ অন্য কোম্পানির ডিলার হাতধারে জোড়া লাগল');

        $this->post(route('finance.hand_loan.store'), ['person_id' => $person->id, 'party' => 'customer:'.$ours->id])
            ->assertSessionHasNoErrors();
        $account = HandLoanAccount::query()->where('person_id', $person->id)->latest('id')->firstOrFail();
        $this->assertSame((int) $ours->id, (int) $account->partner_id);

        // ⓘ জোড়া বদলের পথেও একই নিয়ম
        $this->post(route('finance.hand_loan.link', $account), ['party' => 'customer:'.$theirs->id])->assertSessionHasErrors('party');
        $this->assertSame((int) $ours->id, (int) $account->fresh()->partner_id, '⛔ জোড়া বদলে অন্য কোম্পানির ডিলার বসল');
    }

    public function test_a_withdrawal_takes_money_only_from_an_account_of_this_company(): void
    {
        $theirs = Account::query()->withoutGlobalScopes()->where('company_id', $this->other->id)->where('is_group', false)->orderBy('id')->firstOrFail();
        $withdrawal = app(WithdrawalService::class)->request([
            'person_id' => Person::query()->create(['code' => 'P-WDX', 'name_en' => 'Draw Owner', 'name_bn' => 'Draw Owner', 'is_active' => true])->id,
            'amount' => '1000', 'trx_date' => now()->toDateString(),
        ]);

        $this->post(route('finance.withdrawal.post', $withdrawal), ['money_account_id' => $theirs->id])->assertSessionHasErrors('money_account_id');
        $this->assertNull($withdrawal->fresh()->voucher_id, '⛔ অন্য কোম্পানির খাত থেকে উত্তোলন বসল');
    }
}
