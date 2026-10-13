<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখার কর্মী শাখা মুছে সরবরাহকারীকে গোটা কোম্পানির করে দিতেন (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierService::update()]])।
 *
 * ⚠️ শাখার নিয়ম খালি মানে চলে না, আর শাখাহীন সারি সবার নাগালে — "—" বেছে সংরক্ষণ করলেই সরবরাহকারী সব শাখায় দেখা দিত।
 */
final class ABranchClerkMadeASupplierCompanyWideTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_clerk_cannot_clear_the_branch_of_a_supplier(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['supplier.view', 'supplier.update']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $supplier->forceFill(['branch_id' => $mine->id])->save();

        $this->actingAs($clerk)->put(route('supplier.update', $supplier), ['name_en' => $supplier->name_en, 'branch_id' => ''])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame($mine->id, (int) $supplier->fresh()->branch_id, '⛔ শাখার কর্মী সরবরাহকারীকে কোম্পানি-স্তরে তুলে দিলেন');
    }
}
