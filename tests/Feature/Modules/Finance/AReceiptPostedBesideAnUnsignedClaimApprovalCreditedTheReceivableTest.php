<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\FinanceSignature;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ দাবির অনুমোদন সইয়ের অপেক্ষায়, অথচ ভাউচারের পর্দার রসিদ Cr 1152 বসে যেত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⚠️৪)।
 *
 * ⓘ দাবির পর্দার বোতাম তখন থামত, কিন্তু ভাউচারের পর্দা থেকে দাবির বিপরীতে লেখা রসিদ পোস্ট হত। সইকারী অনুমোদনে "না" বললে 1152
 * ঋণাত্মক থাকত, পেছনে কোনো প্রাপ্য নেই।
 *
 * ⭐ দাবি:
 *   · অনুমোদন খসড়া থাকতে দাবির বিপরীতে রসিদ পোস্ট হয় না — "দাবি খোলা নেই"; রসিদ খসড়াই, 1152 শূন্য
 *   · শেষ সই পড়লে একই রসিদ পোস্ট হয়, দাবি আংশিক
 */
final class AReceiptPostedBesideAnUnsignedClaimApprovalCreditedTheReceivableTest extends TestCase
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

    public function test_a_voucher_screen_receipt_waits_for_the_approval_signature(): void
    {
        $this->flow();
        $claim = app(InsuranceClaimService::class)->approve($this->lodge(),
            ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-4']);
        $this->assertNotNull($claim->approval_voucher_id, 'দৃশ্যটাই বানানো যায়নি — অনুমোদনের দাখিলা নেই');

        $vouchers = app(VoucherService::class);
        $receipt = $vouchers->create([
            'type' => Voucher::RECEIPT, 'branch_id' => $claim->branch_id, 'trx_date' => now()->toDateString(),
            'narration' => 'claim money by hand', 'instrument_no' => 'TST-HAND-1',
            'against_type' => InsuranceClaim::drillSourceType(), 'against_id' => $claim->id,
        ], [
            ['account_id' => $this->bank->id, 'debit' => '30000', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INSURANCE_CLAIM_RECEIVABLE)->id, 'debit' => '0', 'credit' => '30000'],
        ]);

        try {
            $vouchers->post($receipt);
            $this->fail('⛔ অনুমোদন সইয়ের অপেক্ষায়, অথচ ভাউচারের পর্দার রসিদ Cr 1152 বসে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('against_id', $e->errors(), '⛔ থামল, কিন্তু "দাবি খোলা নেই" বলে নয়');
        }

        $this->assertTrue($receipt->fresh()->isDraft(), '⛔ থামার পরেও রসিদ খাতায় বসল');
        $this->assertSame('0', $this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ সই ছাড়াই 1152 নড়ল');

        // ⓘ শেষ সই পড়লে একই রসিদ পোস্ট হয়
        app(ApprovalEngine::class)->approve(Approval::query()->where('status', Approval::PENDING)
            ->where('action', FinanceSignature::INSURANCE_CLAIM)->latest('id')->firstOrFail(), $this->signer);
        $vouchers->post($receipt->fresh());

        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status, '⛔ সইয়ের পরে রসিদ দাবিতে বসেনি');
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '50000', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => FinanceSignature::INSURANCE_CLAIM, 'is_active' => true]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);

        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function lodge(): InsuranceClaim
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Hand Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'HD-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);

        return app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Godown water damage', 'claimed_amount' => '100000',
        ]);
    }

    private function net(string $code): string
    {
        $row = LedgerEntry::query()->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $net = bcsub((string) $row->d, (string) $row->c, 4);

        return bccomp($net, '0', 4) === 0 ? '0' : $net;
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-HDB', 'name_en' => 'Hand bank', 'name_bn' => 'Hand bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
