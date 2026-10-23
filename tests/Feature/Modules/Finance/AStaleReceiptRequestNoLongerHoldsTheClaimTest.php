<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ দাবির বিপরীতে রসিদের সই চাওয়া থাকলে দাবিতে আর টাকা বসে না — কিন্তু কেবল টাটকা অনুরোধে ([[InsuranceClaimService::assertNothingWaiting()]];
 * fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী বদলাল ────────────────────────────────────────────────────────────
 * আগে যেকোনো PENDING অনুরোধ আটকাত — অনুরোধের পরে রসিদটা বদলালেও। সেই বাসি অনুরোধ পরের পোস্টে তুলে নতুন চাওয়া হয়
 * ([[VoucherApproval::stopping()]]), আর ভাউচারের পাতা, ফোন, তালিকার মোট সেটাকে খসড়া বলে ([[VoucherApproval::decide()]])। এই
 * পাহারার নিজের নিয়মে সাধারণ খসড়া আটকায় না — তাই বাসিটাও আর আটকায় না।
 *
 * ⭐ দাবি: টাটকা অনুরোধের রসিদ থাকতে দাবিতে টাকা বসে না ("আগে সই"); একই রসিদ বদলালে (অনুরোধ বাসি) আর সেই কারণে আটকায় না।
 */
final class AStaleReceiptRequestNoLongerHoldsTheClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
        $this->bank = $this->bankLeaf();
    }

    public function test_a_fresh_request_holds_the_claim_and_a_stale_one_does_not(): void
    {
        // ⓘ দাবির অনুমোদনে সই নেই (ছক নেই) — অনুমোদনের দাখিলা নিজেই পাকা, তাই আটকায় কেবল রসিদ
        $claim = app(InsuranceClaimService::class)->approve($this->lodge(),
            ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-ST']);
        $this->assertNotNull($claim->approval_voucher_id, 'দৃশ্যটাই বানানো যায়নি — অনুমোদনের দাখিলা নেই');

        $this->receiptsNeedASignature();
        $receipt = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT, 'branch_id' => $claim->branch_id, 'trx_date' => now()->toDateString(),
            'narration' => 'claim money asked to sign', 'instrument_no' => 'TST-ST-1',
            'against_type' => InsuranceClaim::drillSourceType(), 'against_id' => $claim->id,
        ], [
            ['account_id' => $this->bank->id, 'debit' => '20000', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INSURANCE_CLAIM_RECEIVABLE)->id, 'debit' => '0', 'credit' => '20000'],
        ]);
        $approval = app(VoucherApproval::class);
        $this->assertNotNull($approval->stopping($receipt->fresh()), 'দৃশ্যটাই বানানো যায়নি — রসিদের সই চাওয়া হয়নি');
        $this->assertSame(VoucherApproval::AWAITING, $approval->waitFor($receipt->fresh()), 'দৃশ্যটাই বানানো যায়নি — অনুরোধ টাটকা নয়');

        $this->assertSame(__('finance::validation.awaits_signature_first'), $this->receiveRefusal($claim),
            '⛔ রসিদের টাটকা অনুরোধ থাকতে দাবিতে আরও টাকা বসল — "আগে সই" বলল না');

        $receipt->fresh()->forceFill(['narration' => 'অনুরোধের পরে বদলানো'])->save();
        $this->assertSame(VoucherApproval::STALE, $approval->waitFor($receipt->fresh()), 'দৃশ্যটাই বানানো যায়নি — বদলানোর পরেও অনুরোধ বাসি নয়');

        $this->assertNotSame(__('finance::validation.awaits_signature_first'), $this->receiveRefusal($claim),
            '⛔ বাসি অনুরোধেও দাবি "আগে সই" বলে আটকাল — বাসিটা বদলানো খসড়া, সইয়ের অপেক্ষা নয়');
    }

    /** দাবিতে টাকা নেওয়ার চেষ্টা — "amount"-এর প্রথম বাধা, না থামলে null */
    private function receiveRefusal(InsuranceClaim $claim): ?string
    {
        try {
            app(InsuranceClaimService::class)->receive($claim->fresh(), [
                'money_account_id' => $this->bank->id, 'amount' => '10000', 'received_on' => now()->toDateString(), 'instrument_no' => 'TST-ST-RCV',
            ]);
        } catch (ValidationException $e) {
            return $e->errors()['amount'][0] ?? null;
        }

        return null;
    }

    private function receiptsNeedASignature(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => VoucherApproval::MODULE, 'action' => Voucher::RECEIPT, 'is_active' => true]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);

        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetInstance(VoucherApproval::class);
    }

    private function lodge(): InsuranceClaim
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Stale Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'ST-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);

        return app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Godown fire', 'claimed_amount' => '100000',
        ]);
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-STB', 'name_en' => 'Stale bank', 'name_bn' => 'Stale bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
