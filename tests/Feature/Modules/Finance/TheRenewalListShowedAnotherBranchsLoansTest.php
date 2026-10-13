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
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ নবায়নের তালিকা শাখার দেয়াল মানত না — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৪)।
 *
 * ⓘ [[BankFacilityService::dueForRenewal()]]-এ `inViewedBranch()` ছিল না, আর [[BankFacility]]-তে শাখার গ্লোবাল স্কোপ নেই।
 * হেডারে MMS বাছা থাকলেও ঋণের পাতার উপরের নবায়নের সারিতে NTK-এর ব্যাংক, সীমা আর দিন দেখাত।
 *
 * ⭐ দাবি: হেডারে MMS → নবায়নের তালিকায় কেবল MMS-এর ঋণ, পাতাতেও NTK-এর ব্যাংকের নাম নেই; "সব শাখা" → দুইটাই।
 */
final class TheRenewalListShowedAnotherBranchsLoansTest extends TestCase
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

    public function test_the_renewal_strip_keeps_to_the_picked_branch(): void
    {
        $here = $this->facilityIn('MMS', 'Mymensingh Renewal Bank');
        $there = $this->facilityIn('NTK', 'Netrokona Renewal Bank');

        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->branch('MMS')->id])->save();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        $due = app(BankFacilityService::class)->dueForRenewal();
        $this->assertTrue($due->contains('id', $here->id), 'প্রস্তুতিটাই ভুল — নিজের শাখার নবায়ন তালিকায় নেই।');
        $this->assertFalse($due->contains('id', $there->id), '⛔ হেডারে MMS, অথচ নবায়নের তালিকায় NTK-এর ঋণ।');

        $this->get(route('finance.bank_facility.index'))->assertOk()
            ->assertViewHas('renewals', fn ($renewals) => ! $renewals->contains('id', $there->id))
            ->assertDontSee('Netrokona Renewal Bank');

        $this->owner->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        $this->assertTrue(app(BankFacilityService::class)->dueForRenewal()->contains('id', $there->id), '"সব শাখা"-তে NTK-এর নবায়ন হারাল।');
    }

    private function facilityIn(string $code, string $bank): BankFacility
    {
        $facility = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => $bank, 'sanctioned_on' => now()->subYear()->toDateString(),
            'limit_amount' => '500000', 'interest_rate' => '10', 'instalments' => 12,
            'liability_account_id' => Account::query()->where('code', '2211')->value('id'),
            'renews_on' => now()->addDays(10)->toDateString(),
        ]);
        $facility->forceFill(['branch_id' => $this->branch($code)->id])->save();

        return $facility;
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
