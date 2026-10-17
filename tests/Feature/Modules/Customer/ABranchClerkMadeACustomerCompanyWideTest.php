<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখার কর্মী শাখা মুছে গ্রাহককে গোটা কোম্পানির করে দিতেন (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CustomerService::assertStaysInReach()]])।
 *
 * ⚠️ শাখার নিয়ম খালি মানে চলে না, আর শাখাহীন সারি সবার নাগালে — তাই "—" বেছে সংরক্ষণ করলেই অন্য শাখার লোকেরা গ্রাহকটা
 * দেখতেন, বদলাতেন, বিল করতেন। ⭐ সীমিত মানুষের জন্য এখন কারণসহ ফেরত।
 */
final class ABranchClerkMadeACustomerCompanyWideTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_clerk_cannot_clear_the_branch_of_a_customer(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['customer.view', 'customer.update']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $mine->id, 'code' => 'WALL-X', 'name_en' => 'Our Shop',
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);

        $this->actingAs($clerk)->put(route('customer.update', $customer), ['name_en' => 'Our Shop', 'branch_id' => ''])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame($mine->id, (int) $customer->fresh()->branch_id, '⛔ শাখার কর্মী গ্রাহককে কোম্পানি-স্তরে তুলে দিলেন');
    }
}
