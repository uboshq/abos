<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * শাখায় আটকানো কর্মী অন্য শাখার সরবরাহকারী খুলতে আর বদলাতে পারতেন — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⛔ [[SupplierPolicy]] কেবল চাবি দেখত, শাখা নয়; আর [[SupplierRequest]] যেকোনো শাখায় সরবরাহকারী বসাতে দিত।
 * ⓘ বিপজ্জনক মানুষটা এখানে: কেবল শাখা A-র কর্মী, সব সরবরাহকারী-চাবিসহ। দ্বিতীয় শাখা B।
 */
final class ABranchClerkOpenedAnotherBranchsSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_clerk_cannot_open_change_or_move_another_branchs_supplier(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = $company->defaultBranch();
        $b = Branch::query()->where('company_id', $company->id)->whereKeyNot($a->id)->firstOrFail();
        CompanyContext::set($company->id, $a->id);

        $theirs = app(SupplierService::class)->create(['name_en' => 'B Mill', 'branch_id' => $b->id, 'credit_limit' => 0, 'credit_days' => 0]);
        $ours = app(SupplierService::class)->create(['name_en' => 'A Mill', 'branch_id' => $a->id, 'credit_limit' => 0, 'credit_days' => 0]);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $a->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        CompanyContext::forCompany($company->id, function () use ($clerk): void {
            foreach (['supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete'] as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh());

        $this->get(route('supplier.show', $ours))->assertOk();
        $this->get(route('supplier.show', $theirs))->assertForbidden();
        $this->get(route('supplier.edit', $theirs))->assertForbidden();
        $this->put(route('supplier.update', $theirs), ['name_en' => 'Taken', 'branch_id' => $a->id])->assertForbidden();
        $this->assertSame('B Mill', $theirs->fresh()->name_en, '⛔ অন্য শাখার সরবরাহকারী বদলে গেল।');

        // ⓘ নিজের সরবরাহকারীকে অন্য শাখায় সরানোও নয়
        $this->put(route('supplier.update', $ours), ['name_en' => 'A Mill', 'branch_id' => $b->id, 'credit_limit' => 0, 'credit_days' => 0])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame($a->id, (int) $ours->fresh()->branch_id, '⛔ সরবরাহকারী নাগালের বাইরের শাখায় সরে গেল।');
    }
}
