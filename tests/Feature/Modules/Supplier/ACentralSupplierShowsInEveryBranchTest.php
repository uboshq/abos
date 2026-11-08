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
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সেবাদাতা আর প্রিন্সিপাল "সব শাখা"-য় — মালিক, ৮ নভেম্বর ২০২৬: "সেন্ট্রালি সবগুলো ব্রাঞ্চ সেন্ট্রালি নিয়োগ করা হয় … অল ব্রাঞ্চ দিয়ে দেওয়া
 * যায় তাহলে সব ব্রাঞ্চই যাতে শো করে"।
 *
 * দাবি:
 *  - ফর্মে শাখার ঘরে স্পষ্ট "সব শাখা (কেন্দ্রীয়)", আর তা বাছলে (খালি) সরবরাহকারী প্রতিটা শাখায় দেখা যায়।
 *  - ⛔ শাখায় বাঁধা কর্মী নতুন সরবরাহকারী "সব শাখা"-য় খুলতে পারেন না — নিজের শাখা বাছতে হয়।
 *  - ⓘ আগে থেকেই সব শাখার কাউকে সেই কর্মী সম্পাদনা করলে তিনি সব শাখারই থাকেন।
 */
final class ACentralSupplierShowsInEveryBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = $this->company->defaultBranch();
        $this->b = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->a->id)->firstOrFail();
        CompanyContext::set($this->company->id, $this->a->id);
    }

    public function test_the_owner_puts_a_service_provider_in_every_branch(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $form = $this->get(route('supplier.create'))->assertOk()->getContent();
        $this->assertStringContainsString(__('supplier::field.branch_all'), $form, '⛔ শাখার ঘরে "সব শাখা (কেন্দ্রীয়)" লেখা নেই — খালি ঘরের মানে কেউ বোঝে না।');

        $this->post(route('supplier.store'), ['name_en' => 'Central Carrier', 'branch_id' => '', 'credit_limit' => 0, 'credit_days' => 0])
            ->assertSessionHasNoErrors();
        $central = Supplier::query()->withoutGlobalScopes()->where('name_en', 'Central Carrier')->firstOrFail();
        $this->assertNull($central->branch_id, '⛔ "সব শাখা" বাছার পরেও একটা শাখায় বাঁধা পড়ল।');

        foreach ([$this->a, $this->b] as $branch) {
            CompanyContext::set($this->company->id, $branch->id);
            $this->assertTrue(Supplier::query()->inViewedBranch()->whereKey($central->id)->exists(),
                "⛔ সব শাখার সরবরাহকারী {$branch->id} শাখায় দেখা যায় না।");
        }
    }

    public function test_a_branch_clerk_must_pick_a_branch_but_keeps_a_central_one_central(): void
    {
        $central = app(SupplierService::class)->create(['name_en' => 'Head Office Courier', 'branch_id' => null, 'credit_limit' => 0, 'credit_days' => 0]);
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->a->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->a->id,
        ]);
        CompanyContext::forCompany($this->company->id, function () use ($clerk): void {
            foreach (['supplier.view', 'supplier.create', 'supplier.update'] as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh());

        $this->post(route('supplier.store'), ['name_en' => 'Clerk Everywhere', 'branch_id' => '', 'credit_limit' => 0, 'credit_days' => 0])
            ->assertSessionHasErrors('branch_id');
        $this->assertFalse(Supplier::query()->withoutGlobalScopes()->where('name_en', 'Clerk Everywhere')->exists(),
            '⛔ শাখায় বাঁধা কর্মী সরবরাহকারীকে সব শাখায় ছড়িয়ে দিলেন।');

        $this->put(route('supplier.update', $central), ['name_en' => 'Head Office Courier', 'branch_id' => '', 'credit_limit' => 0, 'credit_days' => 0])
            ->assertSessionHasNoErrors();
        $this->assertNull($central->fresh()->branch_id, '⛔ কেন্দ্রীয় সরবরাহকারী শাখার কর্মীর সম্পাদনায় একটা শাখায় আটকে গেলেন।');
    }
}
