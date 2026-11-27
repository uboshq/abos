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
 * ⭐ হাতধারের চেনা নম্বর — যাঁর ধার, তাঁর কোড; খতিয়ানের সারি আর কাগজের তালিকা থেকে ঐ নম্বরেই চেনা যায়।
 *
 * ⛔ আগে হিসাব আর তার নড়াচড়া দুটোই `document_no` পড়ত — `fin_hand_loan_accounts`-এ ঐ কলামই নেই, তাই সবসময়
 *    কেবল সারির নম্বর ("হাতধার — 12") দেখাত। ধরা পড়েছে PHPStan-এ (২৮ নভেম্বর ২০২৬), চোখে নয়।
 */
final class AHandLoanIsKnownByItsPersonsCodeTest extends TestCase
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

    public function test_the_loan_and_each_of_its_movements_carry_the_persons_code(): void
    {
        $person = Person::query()->create(['code' => 'HL-K7', 'name_en' => 'Karim', 'name_bn' => 'করিম', 'is_active' => true]);
        $account = app(HandLoanService::class)->open(['person_id' => $person->id]);

        app(HandLoanService::class)->move($account->fresh(), [
            'direction' => HandLoanMovement::OUT, 'amount' => '2500', 'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);
        $movement = HandLoanMovement::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame('HL-K7', $account->fresh()->drillDocumentNo(), '⛔ ধারের হিসাব মানুষের কোডে চেনা যায় না');
        $this->assertSame('HL-K7', $movement->drillDocumentNo(), '⛔ ধারের নড়াচড়া তার হিসাবের নম্বর বলে না');
        $this->assertStringEndsWith('— HL-K7', $movement->drillLabel());
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
