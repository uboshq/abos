<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ ভাউচারের "পোস্ট করুন"-এর আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"* ([[VoucherOverview]])।
 *
 * দাবি:
 *   সারাংশ ভাউচারের নিজের সারি (খাত, ডেবিট/ক্রেডিট) আর দুই পাশের মোট দেখায়; খাতায় কিছু ওঠে না, অবস্থা খসড়াই;
 *   সই-ছক না থাকলে সইয়ের কথা নেই; একই ভাউচারে ছক বসালে "সই লাগবে" — আর ⛔ সারাংশ দেখতে গিয়ে কোনো সইয়ের অনুরোধ লেখা
 *   হয় না ([[VoucherApproval::waitFor()]], `stopping()` নয়);
 *   ভাউচারের পাতা সারাংশের ঠিকানা, পপ-আপের খোলস আর "পোস্ট" বোতামের চিহ্ন বহন করে।
 */
final class TheVoucherIsShownBeforeItIsPostedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($company->id, $this->owner->current_branch_id ?? $company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    public function test_the_overview_reads_the_voucher_and_writes_nothing(): void
    {
        $voucher = $this->draftExpense();
        $ledger = LedgerEntry::query()->count();

        $this->post(route('accounts.voucher.overview', $voucher))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee($voucher->document_no)
            ->assertSee(__('accounts::overview_confirm.debit_total'))
            ->assertSee('750.00');

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই ভাউচার পোস্ট হয়ে গেল।');
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ সারাংশ দেখতে গিয়েই খাতায় কিছু উঠল।');
    }

    public function test_a_signature_is_mentioned_only_with_a_flow_and_asking_never_writes_a_request(): void
    {
        // ⓘ ডেমোর নিজের ছক থাকলে "ছক নেই" অবস্থাটা দেখাই যেত না
        DB::table('approval_flows')->where('module', 'accounts')->where('action', 'expense')->delete();
        $voucher = $this->draftExpense();

        $this->post(route('accounts.voucher.overview', $voucher))->assertOk()
            ->assertDontSee(__('accounts::overview_confirm.awaiting'));

        $approver = User::query()->where('id', '!=', $voucher->created_by)->firstOrFail();
        $this->flowRequiring((int) $voucher->company_id, $approver->id);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(\App\Modules\Accounts\Services\VoucherApproval::class);

        $this->post(route('accounts.voucher.overview', $voucher))->assertOk()
            ->assertSee(__('accounts::overview_confirm.awaiting'));

        $this->assertSame(0, Approval::query()->where('approvable_id', $voucher->id)->count(),
            '⛔ সারাংশ দেখতে গিয়েই সইয়ের অনুরোধ লেখা হয়ে গেল — ভাউচারটা ইনবক্সে চলে গেছে।');
    }

    public function test_the_voucher_page_carries_the_popup_and_its_address(): void
    {
        $voucher = $this->draftExpense();

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()
            ->assertSee('data-confirm-overview="'.route('accounts.voucher.overview', $voucher).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }

    private function draftExpense(): Voucher
    {
        $this->post(route('accounts.voucher.store', Voucher::EXPENSE), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'from_account_id' => (int) Account::query()->money()->postable()->active()->orderBy('code')->value('id'),
            'to_account_id' => (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->value('id'),
            'amount' => '750',
            'narration' => 'OVERVIEW-GUARD',
            'save_as_draft' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'হেল্পারটা খসড়া দেয়নি।');

        return $voucher;
    }

    private function flowRequiring(int $companyId, int $approverId): void
    {
        $flowId = DB::table('approval_flows')->insertGetId([
            'company_id' => $companyId, 'module' => 'accounts', 'action' => 'expense', 'document_type' => '',
            'threshold_amount' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'public_id' => (string) Str::uuid(),
        ]);

        DB::table('approval_flow_steps')->insert([
            'approval_flow_id' => $flowId, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $approverId,
            'requires_all' => false, 'created_at' => now(), 'updated_at' => now(), 'public_id' => (string) Str::uuid(),
        ]);
    }
}
