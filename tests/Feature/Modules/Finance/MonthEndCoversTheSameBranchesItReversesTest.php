<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InsurancePrepaymentService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\InterestAccrualService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ মাসশেষের সুদ আর অগ্রিম বীমা হেডারের শাখা দেখে বসাত, অথচ উল্টাত সব শাখার — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৪)।
 *
 * ⓘ হেডারে এক শাখা বাছা থাকলে নতুন মাসের জমা বসত কেবল সেই শাখায়, আর আগের মাসের উল্টো দাখিলা হত সবার — বাকি শাখার সুদ বা
 * বীমার খরচ সে মাসে খাতা থেকে উধাও। এখন আগাম দেখা আর চালানো একই সারি ধরে: মানুষটার নাগালের সব শাখা ([[ActingBranches]])।
 *
 * ⭐ দাবি: "সব শাখা"-তে আগস্ট চালিয়ে, হেডারে এক শাখা রেখে সেপ্টেম্বর চালালে —
 *   · দুই শাখার ঋণেই সেপ্টেম্বরের সুদ জমা বসে, আর প্রতি ঋণে ঠিক একটা জমা না-উল্টানো থাকে
 *   · দুই শাখার পলিসিতেই সেপ্টেম্বরের অগ্রিম বসে, আর প্রতি কিস্তিতে ঠিক একটা অগ্রিম না-উল্টানো থাকে
 */
final class MonthEndCoversTheSameBranchesItReversesTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_interest_accrues_for_every_branch_it_reverses(): void
    {
        $this->choose('MMS');
        $here = $this->loan('Sonali Bank');
        $this->choose('NTK');
        $there = $this->loan('Janata Bank');

        $this->choose('all');
        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $this->assertSame(2, InterestAccrual::query()->count(), 'দৃশ্যটাই বানানো যায়নি — আগস্টে দুই ঋণে জমা বসেনি');

        $this->choose('MMS');
        app(InterestAccrualService::class)->run(Carbon::parse('2026-09-01'));

        foreach ([$here, $there] as $loan) {
            $this->assertSame(1, InterestAccrual::query()->where('bank_facility_id', $loan->id)->where('for_month', '2026-09-01')->count(),
                "⛔ {$loan->bank}-এর সেপ্টেম্বরের সুদ বসল না, অথচ আগস্টেরটা উল্টে গেল");
            $this->assertSame(1, InterestAccrual::query()->where('bank_facility_id', $loan->id)->whereNull('reversal_voucher_id')->count(),
                "⛔ {$loan->bank}-এর খাতায় ঠিক একটা চলতি জমা নেই");
        }
    }

    public function test_prepaid_insurance_is_set_aside_for_every_branch_it_reverses(): void
    {
        $this->choose('MMS');
        $this->paidPremium('MMS-1');
        $this->choose('NTK');
        $this->paidPremium('NTK-1');

        $this->choose('all');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-08-01'));
        $this->assertSame(2, InsurancePrepayment::query()->count(), 'দৃশ্যটাই বানানো যায়নি — আগস্টে দুই পলিসিতে অগ্রিম বসেনি');

        $this->choose('MMS');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-09-01'));

        foreach (InsurancePolicy::query()->get() as $policy) {
            $this->assertSame(1, InsurancePrepayment::query()->where('policy_id', $policy->id)->where('for_month', '2026-09-01')->count(),
                "⛔ {$policy->policy_no}-এর সেপ্টেম্বরের অগ্রিম বসল না, অথচ আগস্টেরটা উল্টে গেল");
            $this->assertSame(1, InsurancePrepayment::query()->where('policy_id', $policy->id)->whereNull('reversal_voucher_id')->count(),
                "⛔ {$policy->policy_no}-এর খাতায় ঠিক একটা চলতি অগ্রিম নেই");
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function loan(string $bank): BankFacility
    {
        $loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => $bank, 'sanctioned_on' => '2026-07-08', 'first_instalment_on' => '2026-08-07',
            'limit_amount' => '1200000', 'interest_rate' => '12', 'instalments' => 12,
            'instalment_amount' => LoanSchedule::instalment('1200000', '12', 12),
            'liability_account_id' => Account::query()->where('code', '2211')->firstOrFail()->id,
        ]);

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => '2026-07-08', 'narration' => 'loan '.$bank,
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $loan->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '1200000', 'credit' => '0'],
            ['account_id' => Account::query()->where('code', '2211')->firstOrFail()->id, 'debit' => '0', 'credit' => '1200000'],
        ]));

        return $loan;
    }

    private function paidPremium(string $no): void
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$no]);
        $premium = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => '12000', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30',
        ])->premiums()->firstOrFail();

        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $this->putMoneyIn($cash, '12000', '2026-07-05');

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::PAYMENT, 'trx_date' => '2026-07-05', 'narration' => 'premium '.$no,
            'against_type' => 'insurance_premium', 'against_id' => $premium->id,
        ], [
            ['account_id' => Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->firstOrFail()->id, 'debit' => '12000', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '12000'],
        ]));
    }

    private function choose(string $code): void
    {
        $id = $code === 'all' ? 'all' : (string) $this->branch($code)->id;
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => $id])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id ?? $this->branch('MMS')->id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
