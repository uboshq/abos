<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ কমিশনের দাবি — মাথার শাখার দেয়ালে (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, প্রমোশন ২১; [[CommissionClaim]])।
 *
 * ⓘ দাবির মাথায় শাখা লেখা হয়, অথচ তালিকা আর অপেক্ষমাণের মোট সব শাখার দাবি দেখাত — ময়মনসিংহে সীমিত কর্মী নেত্রকোনার দাবিও দেখতেন
 * আর মেটাতে পারতেন।
 */
final class ACommissionClaimStaysInItsBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_person_sees_only_their_branchs_claims(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        foreach ([['CMC-MMS-1', $mms], ['CMC-NTK-1', $ntk]] as [$no, $branch]) {
            CommissionClaim::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'document_no' => $no,
                'trx_date' => now()->toDateString(), 'customer_id' => Customer::query()->value('id'), 'supplier_id' => Supplier::query()->value('id'),
                'base_amount' => '1000', 'amount' => '50', 'status' => CommissionClaim::PENDING]);
        }

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.commission.view', 'web')));
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())->get(route('sales.commission.index'))->assertOk()
            ->assertSee('CMC-MMS-1')
            ->assertDontSee('CMC-NTK-1');

        $this->assertSame(['CMC-MMS-1'], CommissionClaim::query()->pluck('document_no')->all(), '⛔ অন্য শাখার দাবি নাগালে');
    }
}
