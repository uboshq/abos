<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গুদামের সেবা — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓷ প্রধান গুদাম অন্য শাখায় সরালে নতুন শাখায় দুইটা প্রধান, পুরনোটায় একটাও না — ৬ অক্টোবরের লাইভের ত্রুটি আবার।
 */
final class TheMainWarehouseMovedBranchTest extends TestCase
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
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'WFB', 'name_en' => 'Other branch', 'is_active' => true]);
    }

    private function warehouse(string $code, Branch $branch): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes(['user-warehouse', Warehouse::VIEWED_BRANCH])
            ->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'branch_id' => $branch->id]);
    }

    public function test_the_main_warehouse_cannot_move_to_another_branch(): void
    {
        $main = $this->warehouse('WF-MAIN', $this->home);
        $main->makeDefault();

        try {
            app(WarehouseService::class)->update($main->fresh(), ['code' => 'WF-MAIN', 'name_en' => 'Main', 'branch_id' => $this->other->id]);
            $this->fail('প্রধান গুদাম অন্য শাখায় চলে গেল — দুই শাখার প্রধান ভাঙত।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $this->assertSame((int) $this->home->id, (int) $main->fresh()->branch_id);

        // ⓘ প্রধান নয় এমন খালি গুদাম আগের মতোই সরে
        $plain = $this->warehouse('WF-PLAIN', $this->home);
        app(WarehouseService::class)->update($plain, ['code' => 'WF-PLAIN', 'name_en' => 'Plain', 'branch_id' => $this->other->id]);
        $this->assertSame((int) $this->other->id, (int) $plain->fresh()->branch_id);
    }
}
