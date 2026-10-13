<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ হেডারে অন্য শাখা থাকলে দাবির পাতায় রসিদের তালিকা ফাঁকা দেখাত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⓘ৮)।
 *
 * ⓘ রসিদ আর অনুমোদনের দাখিলা দাবির শাখায় বসে; পাতাটা সেগুলো হেডারের শাখা ধরে খুঁজত। মালিক এক শাখা হেডারে রেখে খতিয়ানের লিংক
 * ধরে আরেক শাখার দাবিতে এলে পাতা খুলত, কিন্তু কোন টাকা এসেছে তা দেখাত না।
 *
 * ⭐ দাবি: দাবি ময়মনসিংহে, হেডারে নেত্রকোনা — পাতায় রসিদ আর অনুমোদনের ভাউচার নম্বর দুটোই দেখা যায়।
 */
final class TheClaimPageHidItsReceiptsUnderAnotherHeaderBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_the_claim_page_lists_its_receipts_from_any_header_branch(): void
    {
        $claims = app(InsuranceClaimService::class);
        $insurer = Institution::query()->create(['company_id' => $this->company->id, 'kind' => Institution::INSURANCE, 'name_en' => 'Branch Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'BR-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
        $claim = $claims->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Godown water damage', 'claimed_amount' => '100000',
        ]);
        $claim = $claims->approve($claim, ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'BR-L']);
        $receipt = $claims->receive($claim->fresh(), [
            'money_account_id' => $this->bankLeaf()->id, 'amount' => '30000', 'received_on' => now()->toDateString(), 'instrument_no' => 'TST-BR-1',
        ])['voucher'];
        $approval = Voucher::acrossBranches()->findOrFail($claim->fresh()->approval_voucher_id);
        $this->assertSame($this->branch('MMS')->id, (int) $receipt->branch_id, 'দৃশ্যটাই বানানো যায়নি — রসিদ ময়মনসিংহে নয়');

        // ⓘ হেডারে নেত্রকোনা
        $ntk = $this->branch('NTK');
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $ntk->id])->save();
        CompanyContext::set($this->company->id, $ntk->id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());

        $this->get(route('finance.insurance.claim.show', $claim))->assertOk()
            ->assertSee($receipt->document_no, false)
            ->assertSee($approval->document_no, false);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-BRB', 'name_en' => 'Branch bank', 'name_bn' => 'Branch bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
