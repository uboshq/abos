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
 * ⓶ হেডারে এক শাখা বাছা থাকলে অন্য শাখার একই কোড যাচাইয়ে দেখা যেত না, আর ডাটাবেজের অনন্য-সূচকে ধাক্কা খেয়ে ৫০০।
 */
final class AWarehouseCodeFromAnotherBranchGaveAFiveHundredTest extends TestCase
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

    public function test_a_code_taken_in_another_branch_is_refused_while_one_branch_is_viewed(): void
    {
        $this->warehouse('WF-TAKEN', $this->other);
        $mine = $this->warehouse('WF-MINE', $this->home);

        // ⓘ হেডারে কেবল নিজের শাখা — "সব শাখা" বন্ধ
        auth()->user()->forceFill(['view_all_branches' => false])->save();

        try {
            app(WarehouseService::class)->update($mine, ['code' => 'WF-TAKEN', 'name_en' => 'Mine']);
            $this->fail('অন্য শাখার কোড যাচাই পার হলো — ডাটাবেজে গিয়ে ৫০০ হত।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertSame('WF-MINE', $mine->fresh()->code);
    }
}
