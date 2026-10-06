<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রধান গুদাম শাখা-প্রতি না কোম্পানি-প্রতি, তা হেডারের বাছাইয়ে ঝুলত — Inventory অডিট ম২২, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ সাধারণ `makeDefault()` অন্যদের পতাকা নামাত কেবল দেখা যায় এমনগুলোর মধ্যে: "সব শাখা"-য় অন্য শাখার প্রধানও নামত,
 * আর গুদাম-সীমিত মানুষ বাছলে নিজের শাখার আগের প্রধানটা থেকেই যেত — এক শাখায় দুইটা প্রধান।
 * ⭐ এখন নিয়ম একটাই, কে কোথা থেকে বাছলেন তা নির্বিশেষে: প্রতিটা শাখায় একটা প্রধান ([[Warehouse::makeDefault()]])।
 */
final class TheMainWarehouseDependedOnTheHeaderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'M22B', 'name_en' => 'Other branch', 'is_active' => true]);
    }

    public function test_choosing_a_main_store_in_one_branch_leaves_the_other_branchs_main_store_alone(): void
    {
        $homeMain = $this->store('M22H', $this->home)->makeDefault();
        $otherMain = $this->store('M22O', $this->other);

        $otherMain->makeDefault();

        $this->assertTrue((bool) $otherMain->fresh()->is_default);
        $this->assertTrue((bool) $homeMain->fresh()->is_default, '⛔ অন্য শাখায় প্রধান বাছায় এই শাখার প্রধান হারাল।');
    }

    public function test_a_clerk_limited_to_one_store_leaves_one_main_store_in_the_branch(): void
    {
        $oldMain = $this->store('M22A', $this->home)->makeDefault();
        $mine = $this->store('M22M', $this->home);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $mine->makeDefault();

        $mains = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('branch_id', $this->home->id)->where('is_default', true)->pluck('code')->all();
        $this->assertSame(['M22M'], $mains, '⛔ এক শাখায় দুইটা প্রধান গুদাম রইল: '.implode(', ', $mains));
        $this->assertFalse((bool) $oldMain->fresh()->is_default);
    }

    private function store(string $code, Branch $branch): Warehouse
    {
        return Warehouse::query()->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'branch_id' => $branch->id]);
    }
}
