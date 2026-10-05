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
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ১ — স্থায়ী সম্পদ: টাকার উৎসসহ নিবন্ধন আর বিক্রি সই ছাড়া খাতায় বসে না, শেষ সইয়ে ঠিক সই-চাওয়ার তথ্য দিয়ে বসে
 * (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)। ⓘ অবচয়ে সই নয় (নগদ নড়ে না; 63-এর সিদ্ধান্ত)। ছক বন্ধে (UB) আগের মতো।
 */
final class AFixedAssetWaitsForItsSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-G1FA', 'name_en' => 'G1 FA Bank', 'name_bn' => 'গ১ সম্পদ ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_a_funded_registration_waits_takes_no_depreciation_and_posts_on_the_last_signature(): void
    {
        $this->flowOn(AccountsSignature::FIXED_ASSET_REGISTER);

        $asset = $this->register();

        $this->assertTrue($asset->fresh()->isAwaiting(), '⛔ ছক চালু, অথচ সম্পদ সই ছাড়াই চালু হলো।');
        $this->assertSame(0, $this->rows(FixedAsset::drillSourceType(), $asset->id), '⛔ সইয়ের আগেই ব্যাংক থেকে টাকা খাতায় বেরোল।');
        app(FixedAssetService::class)->runFor(now()->endOfMonth());
        $this->assertSame(0, \App\Modules\Accounts\Models\DepreciationEntry::query()->where('fixed_asset_id', $asset->id)->count(),
            '⛔ সইয়ের অপেক্ষায় থাকা সম্পদে মাসের অবচয় বসল।');

        $approval = app(ApprovalEngine::class)->latestFor($asset->fresh(), AccountsSignature::FIXED_ASSET_REGISTER);
        $this->assertSame(Approval::PENDING, $approval?->status);
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertTrue($asset->fresh()->isActive(), '⛔ শেষ সই পড়ল, তবু সম্পদ অপেক্ষায়।');
        $this->assertSame(0, bccomp($this->bankCredit(), '100000', 4), '⛔ সইয়ের পরে টাকার উৎস (ব্যাংক) থেকে দাখিলা বসেনি।');
    }

    public function test_a_sale_waits_and_the_last_signature_sells_at_the_signed_price(): void
    {
        // ⓘ ছক আগে — ইঞ্জিন এক অনুরোধে ছকের তালিকা একবারই পড়ে; নিবন্ধনের ছক নেই, তাই নিবন্ধন সরাসরি চালু
        $this->flowOn(AccountsSignature::FIXED_ASSET_DISPOSE);

        $asset = $this->register();
        $this->assertTrue($asset->fresh()->isActive(), 'প্রস্তুতিটাই ভুল — ছক বন্ধে নিবন্ধন সরাসরি চালু হওয়ার কথা।');

        $after = app(FixedAssetService::class)->dispose($asset->fresh(), '70000', (int) $this->bank->id, now()->toDateString());
        $this->assertTrue($after->isActive(), '⛔ ছক চালু, অথচ সম্পদ সই ছাড়াই বিক্রি হলো।');

        $approval = app(ApprovalEngine::class)->latestFor($asset->fresh(), AccountsSignature::FIXED_ASSET_DISPOSE);
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $sold = $asset->fresh();
        $this->assertSame(FixedAsset::DISPOSED, $sold->status, '⛔ শেষ সই পড়ল, তবু বিক্রি হলো না।');
        $this->assertSame(0, bccomp((string) $sold->disposal_amount, '70000', 4), '⛔ সই হওয়া দামে বিক্রি হয়নি।');
    }

    public function test_with_the_flow_off_both_happen_at_once(): void
    {
        $asset = $this->register();
        $this->assertTrue($asset->fresh()->isActive());
        $this->assertSame(0, bccomp($this->bankCredit(), '100000', 4));

        app(FixedAssetService::class)->dispose($asset->fresh(), '70000', (int) $this->bank->id, now()->toDateString());
        $this->assertSame(FixedAsset::DISPOSED, $asset->fresh()->status, '⛔ ছক বন্ধ, তবু বিক্রি আটকাল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function flowOn(string $action): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE, 'action' => $action, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
    }

    private function register(): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'name' => 'Delivery Van',
            'acquired_on' => now()->subMonth()->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $this->bank->id,
        ]);
    }

    private function rows(string $source, int $id): int
    {
        return LedgerEntry::query()->where('source_type', $source)->where('source_id', $id)->count();
    }

    private function bankCredit(): string
    {
        return (string) LedgerEntry::query()->where('account_id', $this->bank->id)->where('source_type', FixedAsset::drillSourceType())->sum('credit');
    }
}
