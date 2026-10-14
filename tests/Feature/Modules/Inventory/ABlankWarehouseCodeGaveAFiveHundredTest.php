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
use Tests\TestCase;

/**
 * গুদামের সেবা — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓵ সম্পাদনায় কোড খালি রাখলে NULL বসাতে গিয়ে ৫০০ — এখন পুরনো কোডই থাকে (পণ্যের ছ১৫-এর একই নিয়ম)।
 */
final class ABlankWarehouseCodeGaveAFiveHundredTest extends TestCase
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

    public function test_a_blank_code_on_edit_keeps_the_old_code(): void
    {
        $store = $this->warehouse('WF-KEEP', $this->home);

        app(WarehouseService::class)->update($store, ['code' => null, 'name_en' => 'Renamed']);

        $this->assertSame('WF-KEEP', $store->fresh()->code, 'খালি কোডে পুরনো কোড থাকেনি।');
        $this->assertSame('Renamed', $store->fresh()->name_en);

        app(WarehouseService::class)->update($store, ['name_en' => 'No code field']);
        $this->assertSame('WF-KEEP', $store->fresh()->code, 'কোডের ঘর না এলে পুরনো কোড থাকেনি।');
    }
}
