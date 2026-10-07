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
 * ভাড়ার চুক্তি না-থাকা খাতের নাম নিত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[RentalContractService]] ফর্মের খাতের সংখ্যাটা সোজা দাখিলায় বসাত: না-থাকা বা টাকার-নয় এমন "টাকার খাত",
 * আর যেকোনো পোস্টযোগ্য খাত "খরচের খাত" হিসেবে (নগদ খাতে ভাড়া খরচ)। ⭐ এখন টাকার খাত এই কোম্পানির নগদ/ব্যাংক/MFS,
 * খরচের খাত খরচের — নইলে পরিষ্কার কথা, আর চুক্তি খোলে না।
 */
final class ARentalContractNamedAnAccountThatWasNotThereTest extends TestCase
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

    public function test_a_missing_money_account_or_a_non_expense_head_is_refused(): void
    {
        $this->assertSame('money_account_id', $this->refused(['money_account_id' => 99999999]),
            '⛔ না-থাকা টাকার খাতে জামানত দেওয়া গেছে।');
        $this->assertSame('money_account_id', $this->refused(['money_account_id' => StandardChart::find(StandardChart::RENT)->id]),
            '⛔ খরচের খাতকে "টাকার খাত" ধরে জামানত দেওয়া গেছে।');
        $this->assertSame('expense_account_id', $this->refused(['expense_account_id' => $this->till->id]),
            '⛔ নগদ খাতকে ভাড়ার "খরচের খাত" বানানো গেছে।');

        $this->assertSame(0, RentalContract::query()->where('counterparty', 'Account check')->count(), '⛔ ফেরত যাওয়া চুক্তি তবু বসেছে।');

        $this->assertNull($this->refused([]), 'ঠিক খাতের চুক্তিও আটকে গেছে — যাচাই বেশি চেপেছে।');
    }

    /** @param  array<string, mixed>  $override */
    private function refused(array $override): ?string
    {
        try {
            app(RentalContractService::class)->open([
                'counterparty' => 'Account check',
                'subject' => 'Shop',
                'deposit_amount' => '240000',
                'monthly_rent' => '30000',
                'monthly_adjustment' => '10000',
                'starts_on' => now()->startOfMonth()->toDateString(),
                'term_months' => 24,
                'money_account_id' => $this->till->id,
                ...$override,
            ]);
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }
}
