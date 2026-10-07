<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * অলস/মৃত মজুদের দিন গোনা হত ডেটাবেসের ঘড়িতে — Inventory অডিট ম২৯, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ ডেটাবেস UTC-তে, অ্যাপ ঢাকায়: রাত ১২টা থেকে ভোর ৬টা পর্যন্ত "আজ" এক দিন পিছিয়ে, আর ৯০/১৮০ দিনের সীমার পণ্য ভুল ঘরে।
 * ⭐ এখন "আজ" অ্যাপের ([[InventoryControlReports::slowAndDead()]])। ⓘ দাবিটা অ্যাপের ঘড়ি সরিয়ে দেখে: ডেটাবেসের ঘড়ি পড়লে
 * দিনের সংখ্যা অ্যাপের "আজ"-এর সাথে মিলত না।
 */
final class TheIdleDaysCountedByTheDatabaseClockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_idle_days_and_the_state_follow_the_apps_today(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create(['code' => 'M29', 'name_en' => 'Idle probe', 'name_bn' => 'অলস-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        foreach ([['2029-01-01', '50'], ['2029-10-01', '-5']] as $i => [$day, $qty]) {
            StockMovement::query()->create(['company_id' => $company->id, 'branch_id' => $warehouse->branch_id,
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'trx_date' => $day,
                'floor_change' => $qty, 'source_type' => 'test.m29', 'source_id' => $i + 1]);
        }

        // ⓘ শেষ বেরোনো ১ অক্টোবর ২০২৯; অ্যাপের "আজ" ৩০ ডিসেম্বর ২০২৯ → ৯০ দিন → ধীর
        Carbon::setTestNow('2029-12-30 03:00:00');

        $row = collect(app(ReportEngine::class)->run('inventory.slow_dead', [], perPage: 5000)->rows)
            ->firstWhere('product_name', 'M29 - '.(app()->getLocale() === 'bn' ? 'অলস-নমুনা' : 'Idle probe'));

        $this->assertNotNull($row, '⛔ ৯০ দিন অলস পণ্যটা তালিকাতেই নেই — "আজ" অন্য ঘড়ি থেকে এল।');
        $this->assertSame(90, (int) $row['idle_days'], '⛔ অলস দিন অ্যাপের "আজ" ধরে গোনা হয়নি।');
    }
}
