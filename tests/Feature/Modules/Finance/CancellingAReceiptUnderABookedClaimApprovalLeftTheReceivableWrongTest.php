<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ অনুমোদন খাতায় বসার পরে আগের রসিদ বাতিল করলে ১১৫২ ভুল হত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⛔২)।
 *
 * ⓘ অনুমোদন বসে "অনুমোদিত − তখন পর্যন্ত পাওয়া": ৩,০০০ এসে ১০,০০০ অনুমোদন হলে Dr 1152 ৭,০০০। আগে সেই ৩,০০০-এর রসিদ (Cr 4370)
 * বাতিল করা যেত — দাবি তখন পুরো ১০,০০০ চাইত, আর পরের রসিদ Cr 1152 ১০,০০০ বসিয়ে 1152 −৩,০০০ করত। বন্ধ দাবির 1152-এর রসিদ
 * বাতিলেও 1152-এ টাকা ঝুলে থাকত, পেছনে কোনো খোলা দাবি নেই।
 *
 * ⭐ দাবি:
 *   · অনুমোদনের আগের রসিদ, অনুমোদন খাতায় থাকলে — বাতিল থামে, রসিদ পোস্টেড থাকে, 1152 ৭,০০০-ই
 *   · বন্ধ দাবির রসিদ — বাতিল থামে
 *   · খোলা অনুমোদিত দাবির 1152-এর রসিদ — আগের মতোই বাতিল হয়, বাকি ফেরে
 */
final class CancellingAReceiptUnderABookedClaimApprovalLeftTheReceivableWrongTest extends TestCase
{
    use RefreshDatabase;

    private InsuranceClaimService $claims;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->claims = app(InsuranceClaimService::class);
        $this->bank = $this->bankLeaf();
    }

    public function test_a_receipt_from_before_the_approval_cannot_be_cancelled_while_the_approval_stands(): void
    {
        $claim = $this->lodge('10000');
        $early = $this->receive($claim, '3000');
        $this->claims->approve($claim->fresh(), ['approved_amount' => '10000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-1']);
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '7000', 4), 'দৃশ্যটাই বানানো যায়নি — অনুমোদন ৭,০০০ নয়');

        try {
            app(VoucherService::class)->cancel($early->fresh(), 'ভুল রসিদ');
            $this->fail('⛔ অনুমোদন খাতায় থাকতেই অনুমোদনের আগের রসিদ বাতিল হলো — দাবি আবার পুরো ১০,০০০ চাইবে, 1152 −৩,০০০ হবে');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancel_reason', $e->errors(), '⛔ থামল, কিন্তু কারণটা বাতিলের ঘরে নেই');
        }

        $this->assertSame(DocumentStatus::CONFIRMED, Voucher::acrossBranches()->findOrFail($early->id)->status, '⛔ থামার পরেও রসিদ বাতিল দেখায়');
        $this->assertSame(0, bccomp($claim->fresh()->outstanding(), '7000', 4), '⛔ দাবির বাকি ৭,০০০ নয়');
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '7000', 4), '⛔ 1152 নড়ে গেল');
    }

    public function test_a_receipt_on_a_closed_claim_cannot_be_cancelled(): void
    {
        $claim = $this->lodge('10000');
        $this->claims->approve($claim, ['approved_amount' => '8000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-2']);
        $paid = $this->receive($claim->fresh(), '5000');
        $this->claims->close($claim->fresh(), 'বাকিটা আসবে না', now()->toDateString());
        $this->assertSame('0', $this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), 'দৃশ্যটাই বানানো যায়নি — বন্ধের পরে 1152 শূন্য নয়');

        try {
            app(VoucherService::class)->cancel($paid->fresh(), 'ভুল রসিদ');
            $this->fail('⛔ বন্ধ দাবির রসিদ বাতিল হলো — 1152-এ ৫,০০০ ঝুলে থাকবে, পেছনে কোনো খোলা দাবি নেই');
        } catch (ValidationException) {
        }

        $this->assertSame('0', $this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ বন্ধ দাবির 1152-এ টাকা ঝুলে রইল');
        $this->assertSame(0, bccomp((string) $claim->fresh()->received_amount, '5000', 4));
    }

    public function test_a_receivable_receipt_on_an_open_approved_claim_still_cancels(): void
    {
        $claim = $this->lodge('10000');
        $this->claims->approve($claim, ['approved_amount' => '8000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-3']);
        $paid = $this->receive($claim->fresh(), '5000');

        app(VoucherService::class)->cancel($paid->fresh(), 'ভুল রসিদ');

        $fresh = $claim->fresh();
        $this->assertSame(InsuranceClaim::APPROVED, $fresh->status, '⛔ খোলা দাবির 1152-এর রসিদ বাতিল আটকে গেল বা অবস্থা ফেরেনি');
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '8000', 4), '⛔ রসিদ বাতিলে প্রাপ্য ফেরেনি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function receive(InsuranceClaim $claim, string $amount): Voucher
    {
        $done = $this->claims->receive($claim, [
            'money_account_id' => $this->bank->id, 'amount' => $amount, 'received_on' => now()->toDateString(),
            'instrument_no' => 'TST-'.$claim->id.'-'.$amount.'-'.random_int(1, 999999),
        ]);
        $this->assertFalse($done['held'], 'দাবির ভিত্তি নেই — রসিদ সইয়ে আটকাল।');

        return $done['voucher'];
    }

    private function net(string $code): string
    {
        $row = LedgerEntry::query()->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $net = bcsub((string) $row->d, (string) $row->c, 4);

        return bccomp($net, '0', 4) === 0 ? '0' : $net;
    }

    private function lodge(string $amount): InsuranceClaim
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Cancel Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'CX-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);

        return $this->claims->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Godown water damage', 'claimed_amount' => $amount,
        ]);
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-CXB', 'name_en' => 'Cancel bank', 'name_bn' => 'Cancel bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
