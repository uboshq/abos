<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ভাড়ার জামানতের ভাউচার আর বীমা দাবির জার্নাল Accounts থেকে একা বাতিল হত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬; ভাড়া ⛔২,
 * বীমা ⛔৩; [[UndoThePaperOfACancelledVoucher]])।
 *
 * ⭐ দাবিগুলো:
 *   · টপ-আপের ভাউচার বাতিল থামে — নইলে চুক্তির জামানত বেশি থাকত আর বন্ধে ফেরত বেশি, ১১৪০ ঋণাত্মক।
 *   · দাবির মঞ্জুরির জার্নাল বাতিল থামে — নইলে দাবি "মঞ্জুর" থাকত অথচ ১১৫২-এ ডেবিট নেই।
 */
final class ARentOrClaimVoucherIsNotCancelledAloneTest extends TestCase
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
        $this->putMoneyIn($this->cash(), '1000000', now()->subDays(5)->toDateString());
    }

    public function test_a_deposit_top_up_voucher_is_not_cancelled_alone(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Godown Owner', 'deposit_amount' => '100000', 'monthly_rent' => '10000', 'monthly_adjustment' => '0',
            'term_months' => 12, 'starts_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
        ]);
        app(RentalContractService::class)->addToDeposit($contract->fresh(), ['amount' => '50000', 'money_account_id' => $this->cash()->id]);
        $this->assertSame(0, bccomp((string) $contract->fresh()->deposit_amount, '150000', 4), 'দৃশ্যটাই বানানো যায়নি');

        $topUp = Voucher::acrossBranches()->where('against_type', \App\Modules\Finance\Models\RentalContract::drillSourceType())
            ->where('against_id', $contract->id)->latest('id')->firstOrFail();

        try {
            app(VoucherService::class)->cancel($topUp, 'ভুল');
            $this->fail('⛔ জামানতের টপ-আপ Accounts থেকে একা বাতিল — চুক্তিতে ১,৫০,০০০, খাতায় ১,০০,০০০');
        } catch (ValidationException $e) {
            $this->assertSame(__('finance::validation.rental_voucher_cancel_not_alone'), $e->errors()['status'][0] ?? null);
        }

        $this->assertNotSame(DocumentStatus::CANCELLED, $topUp->fresh()->status);
        $this->assertSame(0, bccomp((string) $contract->fresh()->deposit_amount, '150000', 4));
    }

    public function test_a_claims_approval_journal_is_not_cancelled_alone(): void
    {
        $claim = app(InsuranceClaimService::class)->lodge($this->policy(), [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Fire', 'claimed_amount' => '100000',
        ]);
        $claim = app(InsuranceClaimService::class)->approve($claim->fresh(), [
            'approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-1',
        ]);
        $this->assertNotNull($claim->fresh()->approval_voucher_id, 'দৃশ্যটাই বানানো যায়নি');

        try {
            app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($claim->fresh()->approval_voucher_id), 'ভুল');
            $this->fail('⛔ দাবির মঞ্জুরির জার্নাল একা বাতিল — দাবি "মঞ্জুর", অথচ ১১৫২-এ কিছু নেই');
        } catch (ValidationException $e) {
            $this->assertSame(__('finance::validation.claim_journal_cancel_not_alone'), $e->errors()['status'][0] ?? null);
        }

        $this->assertSame(0, bccomp((string) $claim->fresh()->approved_amount, '80000', 4));
    }

    private function policy(): InsurancePolicy
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Claim Insurer',
        ]);

        return app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'CL-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
