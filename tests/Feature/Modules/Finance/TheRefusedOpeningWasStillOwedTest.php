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
 * ⛔ সইয়ের "না" পাওয়া বা অপেক্ষার খোলা বকেয়া বকেয়া দেখাত আর তার উপর সুদ জমত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[BankFacilityService::signedPath()]])।
 *
 * ⭐ দাবি: সই বাকি বা "না" — তালিকায় শূন্য, দিনের দেনা শূন্য, মাসের সুদ জমা বসে না।
 */
final class TheRefusedOpeningWasStillOwedTest extends TestCase
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

    public function test_a_refused_opening_is_not_owed_and_earns_no_interest(): void
    {
        $this->flow();
        $service = app(BankFacilityService::class);
        $loan = $service->open([
            'kind' => BankFacility::TERM, 'bank' => 'Janata Bank', 'sanctioned_on' => '2026-07-01', 'limit_amount' => '3000000',
            'interest_rate' => '12', 'instalments' => 36, 'opening_drawn' => '2500000', 'already_running' => true,
            'liability_account_id' => $this->liability()->id,
        ]);
        $this->assertTrue($service->openingFor($loan, '2500000'), 'দৃশ্যটাই বানানো যায়নি — খোলা বকেয়া সইয়ে আটকায়নি');

        $this->assertSame('0', bcadd($service->standing(collect([$loan]))[$loan->id]['used'], '0', 0),
            '⛔ সই বাকি — অথচ ২৫ লাখ বকেয়া দেখাল');

        app(ApprovalEngine::class)->reject(
            Approval::query()->where('status', Approval::PENDING)->where('action', 'bank_facility')->latest('id')->firstOrFail(),
            $this->signer, 'এখন নয়');

        $this->assertSame('0', bcadd($service->standing(collect([$loan->fresh()]))[$loan->id]['used'], '0', 0),
            '⛔ সইকারী "না" বললেন, অথচ ২৫ লাখ বকেয়া দেখাল');
        $this->assertSame('0', bcadd($service->owedOn($loan->fresh(), '2026-10-31'), '0', 0));

        app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));
        $this->assertSame(0, InterestAccrual::query()->where('bank_facility_id', $loan->id)->count(), '⛔ না-নেওয়া ঋণে সুদ খরচ বসল');
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
