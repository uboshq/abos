<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের সারাংশের বকেয়া — হেডারে বাছা শাখায় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, গ্রাহক ১৩; [[CustomerSummaryController]])।
 *
 * ⓘ ময়মনসিংহে ১১,১১১ আর নেত্রকোনায় ৭৭,৭৭৭ বাকি। মালিক হেডারে "ময়মনসিংহ" বেছে সারাংশ খুললে আগে ৮৮,৮৮৮ দেখাত — গ্রাহকের পাতা
 * আর খাতা দেখাত ১১,১১১।
 */
final class TheSummaryFollowsTheBranchInTheHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_due_on_the_summary_is_the_viewed_branchs_due(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $customer = Customer::query()->create(['company_id' => $company->id, 'code' => 'SUM-1', 'name_en' => 'Two Branch Shop', 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');

        foreach ([[$mms, '11111'], [$ntk, '77777']] as [$branch, $amount]) {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $receivable, 'debit' => $amount, 'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id],
                ['account_id' => $income, 'credit' => $amount],
            ], branchId: $branch->id);
        }

        // ⓘ হেডারে একটা শাখা — ময়মনসিংহ
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $mms->id])->save();
        $this->actingAs($owner->fresh());

        $this->get(route('customer.summary', $customer))->assertOk()
            ->assertSee(Money::format('11111'))
            ->assertDontSee(Money::format('88888'));

        // ⓘ "সব শাখা" — দুই শাখা মিলিয়ে, আগের মতোই
        $owner->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($owner->fresh());
        $this->get(route('customer.summary', $customer))->assertOk()->assertSee(Money::format('88888'));
    }
}
