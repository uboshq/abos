<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\InsurancePrepaymentService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * মাসশেষের উল্টো দাখিলা বন্ধ মাসে পড়লে গোটা চাল থামত — জমার মুনাফা আর অগ্রিম বীমায়ও (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * সুদের একই সারাই [[TheReversalWasStuckInAClosedMonthTest]])।
 *
 * ⭐ দাবি: আগস্ট বসানো, সেপ্টেম্বর বন্ধ, অক্টোবর চালালে — আগস্টের উল্টোটা ১ অক্টোবরে, অক্টোবরের জমা বসে; জমার মুনাফার
 * ভাউচার জমার নিজের শাখায়।
 */
final class TheMonthEndReversalSkippedAClosedMonthTooTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_deposit_profit_reversal_moves_out_of_a_closed_month_and_lands_in_the_deposits_branch(): void
    {
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', (int) $this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();
        $this->fdr($other);

        // ⓘ দেখা "সব শাখা", কাজের শাখা প্রধান — জমাটা আগাম দেখায় আসে, অথচ হেডারের শাখা জমার শাখা নয়
        $this->post(route('branch.switch'), ['branch_id' => 'all'])->assertRedirect();
        app(\App\Core\Services\DataScope::class)->forget();
        $this->app->forgetScopedInstances();

        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $august = DepositAccrual::query()->sole();
        $this->assertSame([(int) $other->id], $this->branchesOf((int) $august->voucher_id), '⛔ জমার মুনাফা হেডারের শাখায় বসল, জমার শাখায় নয়');

        $this->closeMonth(9);
        $done = app(DepositAccrualService::class)->run(Carbon::parse('2026-10-01'));

        $august = $august->fresh();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ বন্ধ মাসের জন্য আগস্টের মুনাফা উল্টাল না');
        $reversal = Voucher::query()->withoutGlobalScopes()->findOrFail($august->reversal_voucher_id);
        $this->assertSame('2026-10-01', $reversal->trx_date->toDateString(), '⛔ উল্টো দাখিলা বন্ধ মাসের বাইরে প্রথম খোলা দিনে নয়');
        $this->assertSame([(int) $other->id], $this->branchesOf((int) $reversal->id), '⛔ উল্টো দাখিলা জমার শাখায় নয়');
        $this->assertSame(1, $done['accrued'], '⛔ অক্টোবরের মুনাফা বসেনি');
    }

    public function test_the_prepaid_insurance_reversal_moves_out_of_a_closed_month(): void
    {
        $this->paidPremium('C-OCT', '12000', '2026-07-01', '2027-06-30', '2026-07-05');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-08-01'));
        $august = InsurancePrepayment::query()->sole();

        $this->closeMonth(9);
        $done = app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-10-01'));

        $august = $august->fresh();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ বন্ধ মাসের জন্য আগস্টের অগ্রিম উল্টাল না — গোটা চাল থামল');
        $reversal = Voucher::query()->withoutGlobalScopes()->findOrFail($august->reversal_voucher_id);
        $this->assertSame('2026-10-01', $reversal->trx_date->toDateString(), '⛔ উল্টো দাখিলা বন্ধ মাসের বাইরে প্রথম খোলা দিনে নয়');
        $this->assertSame(1, $done['prepaid'], '⛔ অক্টোবরের অগ্রিম বসেনি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function fdr(Branch $branch): Deposit
    {
        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'document_no' => 'FDR-LOCK', 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '73000',
            'profit_rate' => '10', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => '2026-07-01', 'matures_on' => '2027-07-01',
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);
        DepositMovement::query()->create(['company_id' => $this->company->id, 'deposit_id' => $deposit->id,
            'kind' => DepositMovement::OPENED, 'amount' => '73000', 'moved_on' => '2026-07-01']);

        return $deposit;
    }

    private function paidPremium(string $no, string $amount, string $from, string $to, string $paidOn): void
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$no]);
        $premium = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => $amount, 'starts_on' => $from, 'ends_on' => $to,
        ])->premiums()->firstOrFail();
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $this->putMoneyIn($cash, $amount, $paidOn);

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::PAYMENT, 'trx_date' => $paidOn, 'narration' => 'premium '.$no,
            'against_type' => 'insurance_premium', 'against_id' => $premium->id,
        ], [
            ['account_id' => Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->value('id'), 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => $amount],
        ]));
    }

    private function closeMonth(int $month): void
    {
        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => 2026, 'month' => $month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now()]);
    }

    /** @return list<int> */
    private function branchesOf(int $voucherId): array
    {
        return \App\Models\LedgerEntry::query()->whereIn('source_type', array_values(Voucher::SOURCE_TYPES))->where('source_id', $voucherId)
            ->distinct()->pluck('branch_id')->map(fn ($b) => (int) $b)->all();
    }
}
