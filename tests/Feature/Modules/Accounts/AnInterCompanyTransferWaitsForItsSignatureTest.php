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
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\InterCompanyService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * গ১ — আন্তঃকোম্পানি লেনদেন সই ছাড়া কোনো খাতায় বসে না; শেষ সই পড়লে দুই কোম্পানির খাতায় একসাথে বসে
 * (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)। ⓘ ছক বন্ধ থাকলে (UB) আগের মতোই এখনই।
 */
final class AnInterCompanyTransferWaitsForItsSignatureTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany((int) $this->alpha->id,
            fn () => $this->putMoneyIn($this->money($this->alpha), '20000', now()->toDateString()));

        CompanyContext::set((int) $this->alpha->id);
        $this->actingAs($this->owner);
    }

    public function test_with_the_flow_on_neither_book_moves_until_the_last_signature(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->alpha->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::INTER_COMPANY, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $transfer = $this->record();

        $this->assertSame(DocumentStatus::DRAFT, $transfer->fresh()->status, '⛔ ছক চালু, অথচ লেনদেন সই ছাড়াই পাকা হলো।');
        $this->assertFalse($this->posted($this->alpha, (int) $transfer->out_voucher_id), '⛔ সইয়ের আগেই দেওয়ার খাতায় বসল।');
        $this->assertFalse($this->posted($this->beta, (int) $transfer->in_voucher_id), '⛔ সইয়ের আগেই অন্য কোম্পানির খাতায় বসল।');

        $approval = app(ApprovalEngine::class)->latestFor($transfer->fresh(), AccountsSignature::INTER_COMPANY);
        $this->assertSame(Approval::PENDING, $approval?->status, 'সই চাওয়াই হয়নি।');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(DocumentStatus::CONFIRMED, $transfer->fresh()->status, '⛔ শেষ সই পড়ল, তবু লেনদেন খসড়া।');
        $this->assertTrue($this->posted($this->alpha, (int) $transfer->out_voucher_id), '⛔ সইয়ের পরেও দেওয়ার খাতায় বসেনি।');
        $this->assertTrue($this->posted($this->beta, (int) $transfer->in_voucher_id), '⛔ সইয়ের পরেও অন্য কোম্পানির খাতায় বসেনি।');
        $this->assertSame((int) $this->alpha->id, (int) CompanyContext::id(), '⛔ শেষ সইয়ের পরে কোম্পানির প্রসঙ্গ ফেরেনি।');
    }

    public function test_with_the_flow_off_both_books_get_it_at_once_as_before(): void
    {
        $transfer = $this->record();

        $this->assertSame(DocumentStatus::CONFIRMED, $transfer->fresh()->status, '⛔ ছক বন্ধ, তবু আটকে গেল — UB-র রোজকার কাজ থামত।');
        $this->assertTrue($this->posted($this->alpha, (int) $transfer->out_voucher_id));
        $this->assertTrue($this->posted($this->beta, (int) $transfer->in_voucher_id));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function record(): InterCompanyTransfer
    {
        return app(InterCompanyService::class)->record($this->owner, [
            'counter_company_id' => $this->beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '5000.0000',
            'purpose' => 'ভাড়ার টাকা',
            'from_account_id' => $this->money($this->alpha)->id,
            'to_account_id' => $this->money($this->beta)->id,
        ]);
    }

    private function posted(Company $company, int $voucherId): bool
    {
        return CompanyContext::forCompany((int) $company->id, fn () => (bool) Voucher::query()->findOrFail($voucherId)->isPosted());
    }

    private function money(Company $company): Account
    {
        return CompanyContext::forCompany((int) $company->id, fn () => Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail());
    }
}
