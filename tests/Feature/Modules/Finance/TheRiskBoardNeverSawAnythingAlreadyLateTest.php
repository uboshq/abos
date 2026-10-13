<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\RiskBoard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ঝুঁকির বোর্ড কেবল সামনের দিন দেখত, পেরিয়ে যাওয়াটা নয় — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৪)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[RiskBoard]] ঋণের কিস্তি আর জমার মেয়াদ ছাঁকত `আজ থেকে আজ+৩০`-এ। দিন পেরোনো না-দেওয়া কিস্তি ছাঁকনিতেই বাদ পড়ত,
 * তাই `isOverdue()` কখনো সত্যি হত না আর 'bad' স্তরে পৌঁছানোই যেত না; গতকাল মেয়াদ শেষ হওয়া খোলা FDR-ও বোর্ডে আসত না।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * দিন পেরোনো কিস্তি বোর্ডে 'bad' হয়ে ওঠে, তার বাকি অঙ্কসহ; মেয়াদ পেরোনো খোলা জমা জমার গোনায় ঢোকে।
 */
final class TheRiskBoardNeverSawAnythingAlreadyLateTest extends TestCase
{
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
    }

    public function test_an_overdue_loan_instalment_is_a_bad_risk(): void
    {
        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-RISK', 'name_en' => 'Risk Bank', 'name_bn' => 'ঝুঁকির ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_group' => false,
        ]);

        // ⓘ প্রথম কিস্তি পাঁচ দিন আগে, দেওয়া হয়নি
        $loan = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank', 'kind' => Loan::TERM, 'interest_method' => LoanSchedule::REDUCING,
                'sanctioned' => '120000', 'interest_rate' => '12', 'tenure_months' => 12,
                'start_date' => Carbon::today()->subDays(35)->toDateString(),
                'first_instalment_on' => Carbon::today()->subDays(5)->toDateString(),
                'principal_account_id' => Account::query()->where('code', '2210')->value('id'),
                'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
            ],
            intoAccountId: $bank->id,
        );

        $late = $loan->instalments()->orderBy('no')->firstOrFail();
        $this->assertTrue($late->isOverdue(), 'প্রস্তুতিটাই ভুল — প্রথম কিস্তি দিন পেরোনো নয়।');

        $risk = collect(app(RiskBoard::class)->risks())->firstWhere('key', 'loan_instalments');

        $this->assertNotNull($risk, '⛔ ঋণের কিস্তির ঝুঁকিটাই বোর্ডে নেই।');
        $this->assertSame('bad', $risk['level'], '⛔ দিন পেরোনো কিস্তি থাকা সত্ত্বেও ঝুঁকিটা "bad" নয় — পেরোনো কিস্তি ছাঁকনিতে বাদ পড়ছে।');
        $this->assertGreaterThanOrEqual(0, bccomp($risk['value'], bcsub($late->total(), (string) ($late->paid_amount ?? 0), 4), 4),
            '⛔ ঝুঁকির অঙ্কে দিন পেরোনো কিস্তির বাকিটা নেই।');
    }

    public function test_a_deposit_that_matured_yesterday_and_is_still_open_is_counted(): void
    {
        app(DepositKindInstaller::class)->install();

        $before = (int) (collect(app(RiskBoard::class)->risks())->firstWhere('key', 'deposits_maturing')['value'] ?? 0);

        Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'FDR-RISK-PAST', 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'profit_rate' => '8', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => Carbon::today()->subYear()->toDateString(),
            'matures_on' => Carbon::yesterday()->toDateString(),
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id,
            'status' => Deposit::ACTIVE,
        ]);

        $after = (int) (collect(app(RiskBoard::class)->risks())->firstWhere('key', 'deposits_maturing')['value'] ?? 0);

        $this->assertSame($before + 1, $after, '⛔ গতকাল মেয়াদ শেষ হওয়া খোলা FDR ঝুঁকির বোর্ডে গোনা হয়নি।');
    }
}
