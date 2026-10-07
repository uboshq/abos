<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন গুদাম বাছা শাখাতেই বসে, আর তালিকায় আসে — মালিক, ২ অক্টোবর ২০২৬:
 * *"godawn create korar poreo list e godawn asteche na"*।
 *
 * ⛔ আগে: শাখার ঘর ফাঁকা রেখে বানালে গুদাম কোনো শাখার নয়, আর হেডারে এক শাখা বাছা থাকলে তালিকায় অদৃশ্য।
 * ⭐ এখন: ফাঁকা এলে হেডারের শাখা ([[WarehouseController::withBranch()]]); "সব শাখা"-য় একাধিক শাখা থাকলে জিজ্ঞেস।
 * ⓘ আগে বানানো শাখা-ছাড়া গুদাম "সব শাখা"-তে দেখা যায় — এক শাখা বাছলে নয় ([[EachBranchIsFullySeparateTest]])।
 */
final class ANewWarehouseLandsInThePickedBranchTest extends TestCase
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
    }

    public function test_a_warehouse_made_with_the_branch_left_blank_lands_in_the_picked_branch_and_shows(): void
    {
        $branch = $this->otherBranch();
        $this->choose($branch->id);

        $this->get(route('inventory.warehouse.create'))->assertOk()
            ->assertViewHas('warehouse', fn (Warehouse $w) => (int) $w->branch_id === $branch->id);

        $this->post(route('inventory.warehouse.store'), ['name_en' => 'ZQ Godown', 'branch_id' => ''])
            ->assertSessionHasNoErrors();

        $made = Warehouse::acrossWarehouses()->where('name_en', 'ZQ Godown')->sole();
        $this->assertSame($branch->id, (int) $made->branch_id, '⛔ শাখা ফাঁকা রেখে বানানো গুদাম বাছা শাখায় বসেনি।');

        $this->assertContains((int) $made->id, $this->listed(), '⛔ নতুন গুদাম তালিকায় আসেনি — মালিকের অভিযোগটাই।');
    }

    public function test_all_branches_with_several_branches_asks_which_one_instead_of_guessing(): void
    {
        $this->choose('all');

        $this->post(route('inventory.warehouse.store'), ['name_en' => 'ZQ Unsure', 'branch_id' => ''])
            ->assertSessionHasErrors('branch_id');

        $this->assertFalse(Warehouse::acrossWarehouses()->where('name_en', 'ZQ Unsure')->exists(), '⛔ কোন শাখার না জেনেই গুদাম বানানো হলো।');
    }

    /** @return list<int> */
    private function listed(): array
    {
        return collect($this->actingAs($this->owner)->get(route('inventory.warehouse.index'))->assertOk()->viewData('warehouses')->items())
            ->map(fn ($w) => (int) $w->id)->all();
    }

    private function otherBranch(): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('is_active', true)->orderByDesc('id')->firstOrFail();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }
}
