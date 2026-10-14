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
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ভাড়ার জামানতের খাত যেকোনো খাত হতে পারত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (ⓘ৫)।
 *
 * ⓘ নগদ বাছলে জামানত Dr নগদ / Cr নগদ, আর মাসের কাটা নগদে ক্রেডিট; খরচের খাত বা ২১৪১ বাছলেও খাতা ভুল। ⭐ এখন সম্পদের খাতই,
 * টাকার খাত নয় — বাকি সব যাচাইয়ের বার্তায় ফেরে, ৪০৪ নয়।
 */
final class TheRentalDepositCouldSitInTheCashAccountTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $this->putMoneyIn($this->till, '1000000', now()->startOfMonth()->toDateString());
    }

    public function test_the_deposit_head_must_be_an_asset_that_is_not_money(): void
    {
        $this->assertSame('account_id', $this->refused($this->till->id), '⛔ নগদ খাতকে জামানতের খাত বানানো গেল');
        $this->assertSame('account_id', $this->refused(StandardChart::find(StandardChart::RENT)->id), '⛔ খরচের খাতকে জামানতের খাত বানানো গেল');
        $this->assertSame('account_id', $this->refused(StandardChart::find(StandardChart::RENT_PAYABLE)->id), '⛔ প্রদেয় ভাড়াকে জামানতের খাত বানানো গেল');
        $this->assertSame('account_id', $this->refused(99999999), '⛔ না-থাকা খাত যাচাইয়ের বার্তা দিল না');
        $this->assertSame(0, RentalContract::query()->where('counterparty', 'Deposit head check')->count(), '⛔ ফেরত যাওয়া চুক্তি তবু বসেছে');

        $this->assertNull($this->refused(StandardChart::find(StandardChart::SECURITY_DEPOSIT)->id), 'জামানতের ঠিক খাতও আটকে গেল — যাচাই বেশি চেপেছে');
    }

    private function refused(int $accountId): ?string
    {
        try {
            app(RentalContractService::class)->open([
                'counterparty' => 'Deposit head check', 'subject' => 'Shop', 'deposit_amount' => '240000', 'monthly_rent' => '30000',
                'monthly_adjustment' => '10000', 'starts_on' => now()->startOfMonth()->toDateString(), 'term_months' => 24,
                'money_account_id' => $this->till->id, 'account_id' => $accountId,
            ]);
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }
}
