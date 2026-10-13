<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ এক শাখায় সীমিত মানুষ মাসের প্রদেয় ভাড়া আর ভাড়াটের দাবি চালালে সব শাখার চুক্তিতে খাতা বসত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর
 * ২০২৬ ([[RentalAccrualService::run()]], [[TenancyService::charge()]]; সুদ জমার নিয়ম [[ActingBranches]])।
 *
 * ⭐ দাবি: সীমিত মানুষের চালে কেবল তাঁর শাখার চুক্তি; অন্য শাখার মাস পরে সেই শাখার মানুষ (বা ঘণ্টার কাজ) বসায়।
 */
final class AClerkOfOneBranchBookedEveryBranchsRentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_the_months_rent_runs_only_in_the_clerks_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $contracts = [];
        $tenancies = [];

        foreach (['MMS', 'NTK'] as $code) {
            CompanyContext::set($this->company->id, $this->branch($code)->id);
            $contracts[$code] = app(RentalContractService::class)->open([
                'counterparty' => 'Landlord '.$code, 'subject' => 'Godown '.$code, 'deposit_amount' => '0', 'monthly_rent' => '10000',
                'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->toDateString(),
            ]);
            $person = Person::query()->create(['code' => 'TEN-'.$code, 'name_en' => 'Tenant '.$code, 'name_bn' => 'Tenant '.$code, 'is_active' => true]);
            $tenancies[$code] = app(TenancyService::class)->open([
                'party_type' => 'person', 'party_id' => $person->id, 'tenant' => 'Tenant '.$code, 'monthly_rent' => '8000',
                'deposit_amount' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->toDateString(), 'money_account_id' => null,
            ]);
        }

        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->clerkOf('MMS'));

        app(RentalAccrualService::class)->run(now()->startOfMonth());
        app(TenancyService::class)->charge(now()->startOfMonth());

        $this->assertSame(1, RentalAccrual::query()->where('rental_contract_id', $contracts['MMS']->id)->count(), 'দৃশ্যটাই বানানো যায়নি — নিজের শাখার মাস বসেনি');
        $this->assertSame(0, RentalAccrual::query()->where('rental_contract_id', $contracts['NTK']->id)->count(), '⛔ অন্য শাখার প্রদেয় ভাড়া বসাল');
        $this->assertSame(1, TenancyCharge::query()->where('tenancy_id', $tenancies['MMS']->id)->count());
        $this->assertSame(0, TenancyCharge::query()->where('tenancy_id', $tenancies['NTK']->id)->count(), '⛔ অন্য শাখার ভাড়াটের দাবি বসাল');
    }

    private function clerkOf(string $code): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->branch($code)->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        // ⓘ মাসের প্রথম দিনের দাখিলা — সাত দিনের পেছনের জানালার বাইরে পড়তে পারে, তাই ছাড়
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.backdate.override', 'web')));
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
