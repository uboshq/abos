<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বীমা দাবির ফর্ম — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[InsuranceClaimController]])।
 *
 * ⭐ দাবি:
 *   · অঙ্কে পয়সার দুই ঘরের বেশি নয় — সেবা দুই ঘরে কাটে, তাই ১০০.৫৫৫ অনুমোদনে দাবি চিরকাল ০.০০৫ বাকি থাকত
 *   · দাবি জমার আগের দিনে অনুমোদন নয়
 *   · দাবির পাতার রসিদের তালিকা হেডারে অন্য শাখা থাকলেও রসিদ দেখায় ("পাওয়া" দেখাত, তালিকা খালি)
 */
final class TheClaimFormTookThreeDecimalsAndAnEarlierDayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private InsuranceClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'CL-FORM', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500', 'starts_on' => now()->subMonths(2)->toDateString(),
            'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
        $this->claim = app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Water', 'claimed_amount' => '100000',
        ]);
    }

    public function test_amounts_take_two_decimals_and_the_approval_not_before_the_claim(): void
    {
        $this->post(route('finance.insurance.claim.approve', $this->claim), [
            'approved_amount' => '100.555', 'approved_on' => now()->toDateString(), 'approval_ref' => 'GD/1',
        ])->assertSessionHasErrors('approved_amount');

        $this->post(route('finance.insurance.claim.approve', $this->claim), [
            'approved_amount' => '80000', 'approved_on' => now()->subDays(10)->toDateString(), 'approval_ref' => 'GD/1',
        ])->assertSessionHasErrors('approved_on');

        $this->assertNull($this->claim->fresh()->approval_voucher_id, '⛔ ভুল অনুমোদন খাতায় বসল');
    }

    public function test_the_claim_page_lists_its_receipts_whatever_the_header_branch(): void
    {
        $bank = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $receipt = app(InsuranceClaimService::class)->receive($this->claim, [
            'money_account_id' => $bank->id, 'amount' => '30000', 'received_on' => now()->toDateString(), 'instrument_no' => 'CHQ-77',
        ])['voucher'];

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot((int) $this->claim->branch_id)->orderBy('id')->firstOrFail();
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $other->id])->save();
        app(DataScope::class)->forget();

        $this->actingAs($this->owner->fresh())->get(route('finance.insurance.claim.show', $this->claim))
            ->assertOk()->assertSee($receipt->document_no, false);
    }
}
