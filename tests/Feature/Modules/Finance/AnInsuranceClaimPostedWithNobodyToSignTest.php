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
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
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
 * ⛔ বীমা দাবির অনুমোদন, টাকা আসা আর বন্ধ সই ছাড়াই খাতায় বসত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৮)।
 *
 * ⓘ অনুমোদন আর বন্ধের দাখিলা সরাসরি পোস্ট হত; টাকা আসার রসিদ হিসাবের রসিদের নিয়মে চলত, আর নগদে এলে সেখানে ছাড় — বীমার টাকা
 * কারও সই ছাড়াই আয় হত। এখন তিনটাই অর্থের সইয়ের ছকে ([[FinanceSignature::INSURANCE_CLAIM]]), অন্য অর্থের কাগজের মতো।
 *
 * ⭐ দাবি:
 *   · ছক থাকলে অনুমোদন খসড়া — ১১৫২ নড়ে না; শেষ সই পড়লে খাতায়
 *   · অপেক্ষার অনুমোদনের পাশে টাকা নেওয়া যায় না; টাকা আসার রসিদও সইয়ের অপেক্ষায়, সই পড়লে দাবি "আংশিক"
 *   · অনুমোদন "না" হলে খসড়া বাতিল, দাবি আবার জমা দেওয়া অবস্থায় — অনুমোদনের ঘর খালি
 *   · বন্ধ "না" হলে দাবি আবার খোলা
 *   · কোম্পানির সব ছক বন্ধ থাকলে বাকি অ্যাপের মতো সাথে সাথে খাতায়
 */
final class AnInsuranceClaimPostedWithNobodyToSignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
        $this->bank = $this->bankLeaf();
    }

    public function test_the_approval_and_the_money_wait_for_their_signature(): void
    {
        $this->flow(active: true);
        $claim = $this->lodge();

        $claim = $this->claims()->approve($claim, ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-9']);
        $this->assertSame('0', $this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ সই ছাড়াই অনুমোদন খাতায় বসল');

        try {
            $this->receive($claim->fresh(), '30000');
            $this->fail('⛔ অনুমোদন সইয়ের অপেক্ষায়, অথচ টাকা নেওয়া গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->sign();
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '80000', 4), '⛔ শেষ সই পড়ল, অনুমোদন খাতায় বসেনি');

        $done = $this->receive($claim->fresh(), '30000');
        $this->assertTrue($done['held'], '⛔ টাকা আসার রসিদ সই ছাড়াই পোস্ট হলো');
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '80000', 4));

        $this->sign();
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '50000', 4), '⛔ সইয়ের পর টাকা খাতায় বসেনি');
        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status);
    }

    public function test_a_refused_approval_or_closing_puts_the_claim_back(): void
    {
        $this->flow(active: true);
        $claim = $this->claims()->approve($this->lodge(), ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-9']);
        $voucherId = (int) $claim->approval_voucher_id;

        $this->refuse();

        $claim = $claim->fresh();
        $this->assertSame(InsuranceClaim::LODGED, $claim->status, '⛔ "না" পাওয়া অনুমোদনের পরেও দাবি অনুমোদিত');
        $this->assertNull($claim->approved_amount);
        $this->assertNull($claim->approval_voucher_id);
        $this->assertSame(DocumentStatus::CANCELLED, Voucher::query()->withoutGlobalScopes()->findOrFail($voucherId)->status);

        // ⓘ আবার অনুমোদন, সই; তারপর বন্ধ — বন্ধের উল্টো দাখিলা "না" হলে দাবি আবার খোলা
        $claim = $this->claims()->approve($claim, ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-10']);
        $this->sign();
        $claim = $this->claims()->close($claim->fresh(), 'insurer will pay nothing more', now()->toDateString());
        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '80000', 4), '⛔ সই ছাড়াই বন্ধের উল্টো দাখিলা বসল');

        $this->refuse();

        $claim = $claim->fresh();
        $this->assertTrue($claim->isOpen(), '⛔ "না" পাওয়া বন্ধের পরেও দাবি বন্ধ');
        $this->assertNull($claim->closed_on);
        $this->assertSame(InsuranceClaim::APPROVED, $claim->status);
    }

    public function test_with_every_flow_switched_off_it_posts_at_once_like_the_rest_of_the_app(): void
    {
        $this->flow(active: false);
        $claim = $this->claims()->approve($this->lodge(), ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-9']);

        $this->assertSame(0, bccomp($this->net(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '80000', 4));
        $this->assertFalse($this->receive($claim->fresh(), '30000')['held']);
        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function flow(bool $active): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => FinanceSignature::INSURANCE_CLAIM, 'is_active' => $active]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);

        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function pending(): Approval
    {
        return Approval::query()->where('status', Approval::PENDING)->where('action', FinanceSignature::INSURANCE_CLAIM)->latest('id')->firstOrFail();
    }

    private function sign(): void
    {
        app(ApprovalEngine::class)->approve($this->pending(), $this->signer);
    }

    private function refuse(): void
    {
        app(ApprovalEngine::class)->reject($this->pending(), $this->signer, 'এখন নয়');
    }

    private function claims(): InsuranceClaimService
    {
        return app(InsuranceClaimService::class);
    }

    /** @return array{voucher: Voucher, held: bool} */
    private function receive(InsuranceClaim $claim, string $amount): array
    {
        return $this->claims()->receive($claim, [
            'money_account_id' => $this->bank->id, 'amount' => $amount, 'received_on' => now()->toDateString(),
            'instrument_no' => 'TST-'.$claim->id.'-'.random_int(1, 999999),
        ]);
    }

    private function lodge(): InsuranceClaim
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Sign Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'SG-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);

        return $this->claims()->lodge($policy, [
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
        $bank->forceFill(['code' => 'TST-SGB', 'name_en' => 'Sign bank', 'name_bn' => 'Sign bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
