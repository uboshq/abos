<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

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
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ১ — চেক পাশ আর ফেরত সই ছাড়া খাতায় বসে না, আর শেষ সই পড়লে নিজেই বসে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে "পাশ" চাপলেই ব্যাংকে টাকা বসত আর ডিলারের বকেয়া কমত; "ফেরত" চাপলেই বকেয়া ফিরত — কোনো সই ছাড়া।
 * ⓘ ছক বন্ধ থাকলে (UB-তে সব বন্ধ) আগের মতোই সাথে সাথে।
 */
final class AChequeClearsOrBouncesOnlyAfterItsSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $writer;

    private User $signer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->signer = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->writer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->signer);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-G1C',
            'name_en' => 'G1 Cheque Bank',
            'name_bn' => 'গ১ চেক ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_with_the_flow_on_clearing_waits_and_the_last_signature_clears_it(): void
    {
        $this->flowOn(AccountsSignature::CHEQUE_CLEAR);
        $cheque = $this->receive();

        $this->actingAs($this->writer);
        $held = app(ChequeService::class)->clear($cheque);

        $this->assertNotSame(Cheque::CLEARED, $held->status, '⛔ ছক চালু, অথচ চেক সই ছাড়াই পাশ হলো।');
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 4), '⛔ সইয়ের আগেই ব্যাংকে টাকা বসল।');

        $approval = app(ApprovalEngine::class)->latestFor($cheque->fresh(), AccountsSignature::CHEQUE_CLEAR);
        $this->assertSame(Approval::PENDING, $approval?->status, 'সই চাওয়াই হয়নি।');

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $this->assertSame(Cheque::CLEARED, $cheque->fresh()->status, '⛔ শেষ সই পড়ল, তবু চেক পাশ হলো না।');
        $this->assertSame(0, bccomp($this->bankBalance(), '5000', 4), '⛔ সইয়ের পরেও ব্যাংকে টাকা বসেনি।');
    }

    public function test_with_the_flow_on_a_bounce_waits_and_keeps_its_reason(): void
    {
        $this->flowOn(AccountsSignature::CHEQUE_BOUNCE);
        $cheque = $this->receive();

        $this->actingAs($this->writer);
        $held = app(ChequeService::class)->bounce($cheque, 'তহবিল নেই');

        $this->assertNotSame(Cheque::BOUNCED, $held->status, '⛔ ছক চালু, অথচ চেক সই ছাড়াই ফেরত হলো।');

        $approval = app(ApprovalEngine::class)->latestFor($cheque->fresh(), AccountsSignature::CHEQUE_BOUNCE);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $fresh = $cheque->fresh();
        $this->assertSame(Cheque::BOUNCED, $fresh->status, '⛔ শেষ সই পড়ল, তবু চেক ফেরত হলো না।');
        $this->assertStringContainsString('তহবিল নেই', (string) $fresh->bounce_reason, '⛔ ফেরতের কারণ হারিয়ে গেছে।');
    }

    public function test_with_the_flow_off_the_cheque_clears_at_once_as_before(): void
    {
        $this->actingAs($this->writer);

        $cleared = app(ChequeService::class)->clear($this->receive());

        $this->assertSame(Cheque::CLEARED, $cleared->status, '⛔ ছক বন্ধ, তবু চেক আটকে গেল — UB-র রোজকার কাজ থামত।');
        $this->assertSame(0, bccomp($this->bankBalance(), '5000', 4));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function flowOn(string $action): void
    {
        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => AccountsSignature::MODULE,
            'action' => $action,
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->signer->id,
        ]);
    }

    private function receive(): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'G1C'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '5000',
            'party_type' => 'customer',
            'party_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function bankBalance(): string
    {
        return (string) (LedgerEntry::query()->where('account_id', $this->bank->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal') ?? '0');
    }
}
