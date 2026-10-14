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
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Services\InsurancePrepaymentService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ অগ্রিম বীমা ব্যাংক ঋণের সুদ জমার ৯ অক্টোবরের সারাইগুলো পায়নি — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬
 * ([[InsurancePrepaymentService]]; সুদের জোড়া [[TheReversalWasStuckInAClosedMonthTest]])।
 *
 * ⓘ আগে:
 *   · উল্টানোর দিন বন্ধ মাসে পড়লে গোটা চাল থামত — পরের কোনো মাসের অগ্রিমই আর বসত না
 *   · চালানো মাসটা বন্ধ হলে কারণ ছাড়া খাতার সাধারণ ভুল
 *   · অগ্রিম খরচ সরাত পলিসির শাখা থেকে, অথচ খরচ বসেছিল পরিশোধ ভাউচারের শাখায় — এক শাখার খরচ ঋণাত্মক, অন্যটার ফোলা
 */
final class ThePrepaidInsuranceStoppedAtAClosedMonthTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reversal_due_in_a_closed_month_lands_on_the_first_day_of_the_month_being_run(): void
    {
        $this->paidPremium('MMS', 'MMS');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-08-01'));
        $this->closeMonth(9);

        $done = app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-10-01'));

        $august = InsurancePrepayment::query()->where('for_month', '2026-08-01')->sole();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ আগস্টের অগ্রিম উল্টাল না');
        $this->assertSame('2026-10-01', Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id)->trx_date->toDateString(),
            '⛔ উল্টো দাখিলা বন্ধ মাসের বাইরে প্রথম খোলা দিনে নয়');
        $this->assertSame(1, $done['prepaid'], '⛔ অক্টোবরের অগ্রিম বসেনি');
    }

    public function test_a_closed_month_is_refused_with_its_reason(): void
    {
        $this->paidPremium('MMS', 'MMS');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-08-01'));
        $this->closeMonth(10);
        $vouchers = Voucher::acrossBranches()->count();

        try {
            app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-10-01'));
            $this->fail('⛔ বন্ধ মাসে অগ্রিম বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors(), '⛔ বন্ধ মাসের কারণ "মাস" ঘরে নেই');
        }

        $this->assertSame($vouchers, Voucher::acrossBranches()->count());
    }

    public function test_the_prepayment_moves_the_expense_in_the_branch_that_booked_it(): void
    {
        $paid = $this->paidPremium('NTK', 'MMS');
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-08-01'));
        app(InsurancePrepaymentService::class)->run(Carbon::parse('2026-09-01'));

        $august = InsurancePrepayment::query()->where('for_month', '2026-08-01')->sole();
        $this->assertSame((int) $paid->branch_id, (int) Voucher::acrossBranches()->findOrFail($august->voucher_id)->branch_id,
            '⛔ অগ্রিম পলিসির শাখা থেকে সরল, অথচ খরচ বসেছিল পরিশোধের শাখায়');
        $this->assertSame((int) $paid->branch_id, (int) Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id)->branch_id,
            '⛔ উল্টো দাখিলা পরিশোধের শাখায় নয়');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** পলিসি এক শাখায়, পরিশোধ ভাউচার (হেডারের শাখা) আরেক শাখায় হতে পারে */
    private function paidPremium(string $policyBranch, string $payingBranch): Voucher
    {
        CompanyContext::set($this->company->id, $this->branch($policyBranch)->id);
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer']);
        $premium = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'POL-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => '12000', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30',
        ])->premiums()->firstOrFail();
        $this->assertSame((int) $this->branch($policyBranch)->id, (int) $premium->policy->branch_id, 'দৃশ্যটাই বানানো যায়নি — পলিসির শাখা');

        CompanyContext::set($this->company->id, $this->branch($payingBranch)->id);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $this->putMoneyIn($cash, '12000', '2026-07-05');

        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create([
            'type' => Voucher::PAYMENT, 'trx_date' => '2026-07-05', 'narration' => 'premium',
            'against_type' => 'insurance_premium', 'against_id' => $premium->id,
        ], [
            ['account_id' => Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->firstOrFail()->id, 'debit' => '12000', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '12000'],
        ]);
        $vouchers->post($voucher);
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);

        return $voucher->fresh();
    }

    private function closeMonth(int $month): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => $month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
