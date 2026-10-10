<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ঠিকানায় আইডি বসিয়ে অন্য শাখার অর্থের কাগজ খোলা যেত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৬)।
 *
 * ⓘ অর্থের মডেলে শাখার দেয়াল কেবল তালিকায় ছিল; ঠিকানা থেকে মডেল বাঁধায় কোনো দেয়াল ছিল না। এক শাখায় আটকানো কর্মী সংখ্যা
 * বদলে অন্য শাখার ভাড়া, হাতধার, বীমা খুলতেন ([[OpensOnlyInReach]])।
 *
 * ⭐ দাবি:
 *   · এক শাখায় আটকানো কর্মী — নিজের শাখার কাগজ খোলে, অন্য শাখার ভাড়া, হাতধার, পলিসি, দাবি ৪০৪
 *   · মালিক (সীমাহীন) হেডারে এক শাখা রেখেও অন্য শাখার কাগজ খোলেন — খতিয়ানের লিংক আগের মতোই কাজ করে
 */
final class AFinancePaperOpenedByIdFromAnotherBranchTest extends TestCase
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
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_branch_limited_clerk_gets_a_404_on_another_branchs_rent_hand_loan_and_insurance(): void
    {
        $mine = $this->papersIn('MMS');
        $theirs = $this->papersIn('NTK');

        $clerk = $this->clerkLimitedTo('MMS');

        foreach ($mine as $what => $url) {
            $this->actingAs($clerk)->get($url)->assertOk();
        }

        foreach ($theirs as $what => $url) {
            $this->actingAs($clerk)->get($url)->assertNotFound();
        }
    }

    public function test_the_owner_still_opens_another_branchs_paper_from_any_header(): void
    {
        $theirs = $this->papersIn('NTK');
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);

        foreach ($theirs as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
        }
    }

    /** @return array<string, string> কাগজ → পাতার ঠিকানা */
    private function papersIn(string $code): array
    {
        CompanyContext::set($this->company->id, $this->branch($code)->id);
        $this->actingAs($this->owner);

        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Landlord '.$code, 'subject' => 'Shop '.$code, 'deposit_amount' => '0', 'monthly_rent' => '1000',
            'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->toDateString(),
        ]);

        $person = Person::query()->create(['code' => 'P-'.$code, 'name_en' => 'Lender '.$code, 'name_bn' => 'Lender '.$code, 'is_active' => true]);
        $loan = app(HandLoanService::class)->open(['person_id' => $person->id]);

        $insurer = Institution::query()->create(['company_id' => $this->company->id, 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$code]);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'POL-'.$code, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => '1000', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString(),
        ]);
        $claim = app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Fire', 'claimed_amount' => '1000',
        ]);

        $this->assertSame([$this->branch($code)->id], array_values(array_unique(array_map('intval',
            [$contract->branch_id, $loan->branch_id, $policy->branch_id, $claim->branch_id]))), "দৃশ্যটাই বানানো যায়নি — কাগজগুলো {$code}-এ নয়");

        return [
            'rent' => route('finance.rental.show', $contract),
            'hand loan' => route('finance.hand_loan.show', $loan),
            'policy' => route('finance.insurance.show', $policy),
            'claim' => route('finance.insurance.claim.show', $claim),
        ];
    }

    private function clerkLimitedTo(string $code): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->branch($code)->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($clerk): void {
            foreach (['finance.rental.view', 'finance.hand_loan.view', 'finance.insurance.view'] as $permission) {
                $clerk->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();

        return $clerk->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
