<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InterestAccrualService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ কিস্তির সূচি নেই এমন ঋণে (LTR) মাসের সুদ জমা মঞ্জুরির দিন থেকে গোনা হত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[InterestAccrualService]])।
 *
 * ⭐ দাবি: LTR-এর মাসের সুদ জমা কেবল সেই মাসের দিনগুলোর দৈনিক জেরে (CC-র নিয়ম)।
 */
final class TheLtrAccruedInterestFromTheSanctionDayTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(StandardChart::class)->install();

        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_ltr_accrues_only_the_days_of_the_month(): void
    {
        $ltr = app(BankFacilityService::class)->open([
            'kind' => BankFacility::LTR, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '2000000', 'interest_rate' => '12', 'margin_percent' => '10',
            'liability_account_id' => $this->liability()->id,
        ]);
        $this->draw($ltr, '1000000', '2026-07-01');

        app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));

        // ⓘ ১০,০০,০০০ × ১২% × ৩১ দিন ÷ ৩৬৫ = ১০,১৯১.৭৮; আগে মঞ্জুরি থেকে ১২২ দিন = ৪০,১০৯.৫৮
        $this->assertSame('10191.78', bcadd((string) InterestAccrual::query()->where('bank_facility_id', $ltr->id)->sole()->amount, '0', 2),
            '⛔ LTR-এর মাসের সুদ মঞ্জুরির দিন থেকে গোনা হল — প্রদেয় সুদ দশ গুণ');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }

    private function draw(BankFacility $facility, string $amount, string $on): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'drawn',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => $amount],
        ]));
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'bank_facility', 'is_active' => true]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }
}
