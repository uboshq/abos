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
use Tests\TestCase;

/**
 * মাসিক চলাচল মাসের মাঝ থেকে শুরু করলে প্রথম মাস ভুল, আর "শুরু থেকে" ১,৫২০ মাস — Inventory অডিট ম২৬, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ প্রথম মাসের শুরুর মজুদ ধরা হত মাসের ১ তারিখে, অথচ আসা-যাওয়া পরিসরের শুরু থেকে: ১৫ তারিখে শুরু করলে ১–১৪-এর
 * মাল হারাত আর শেষের মজুদ ঋণাত্মক দেখাত; ১৯০০ সাল থেকে চাইলে প্রতিটা খালি মাসের সারি জন্মাত।
 * ⭐ এখন ভাঙা মাসের সীমা পরিসরের তারিখে, আর ক্যালেন্ডার শুরু হয় কোম্পানির প্রথম চলাচলের মাসে।
 */
final class TheMonthlyMovementStartedOnTheFirstTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->create(['code' => 'M26', 'name_en' => 'Month probe', 'name_bn' => 'মাস-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        foreach ([['2026-09-03', '10'], ['2026-09-20', '-4']] as $i => [$day, $qty]) {
            StockMovement::query()->create(['company_id' => $company->id, 'branch_id' => $warehouse->branch_id,
                'product_id' => $this->product->id, 'warehouse_id' => $warehouse->id, 'trx_date' => $day,
                'floor_change' => $qty, 'source_type' => 'test.m26', 'source_id' => $i + 1]);
        }
    }

    public function test_a_month_cut_in_half_opens_on_the_day_the_range_starts(): void
    {
        $rows = $this->report('2026-09-15', '2026-09-30');

        $this->assertCount(1, $rows);
        $this->assertSame(0, bccomp((string) $rows[0]['opening_qty'], '10', 4), '⛔ ১৫ তারিখের আগে আসা ১০টা শুরুর মজুদে নেই।');
        $this->assertSame(0, bccomp((string) $rows[0]['closing_qty'], '6', 4), '⛔ শেষের মজুদ ভুল (১০ − ৪ = ৬ হওয়ার কথা)।');
    }

    public function test_from_the_beginning_starts_at_the_first_movement_not_in_1900(): void
    {
        $first = StockMovement::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->min('trx_date');
        $months = (int) floor(\Illuminate\Support\Carbon::parse($first)->startOfMonth()->diffInMonths(\Illuminate\Support\Carbon::parse('2026-09-01'), true)) + 1;

        $rows = $this->report('1900-01-01', '2026-09-30');

        $this->assertLessThanOrEqual(max($months, 1), count($rows), '⛔ "শুরু থেকে" ১৯০০ সাল থেকে মাস বানাল: '.count($rows).'টা সারি।');
        $this->assertSame('2026-09', (string) end($rows)['ym']);
    }

    /** @return list<array<string, mixed>> */
    private function report(string $from, string $to): array
    {
        return app(ReportEngine::class)->run('inventory.monthly_movement', [
            'from' => $from, 'to' => $to, 'product_id' => $this->product->id,
        ], perPage: 5000)->rows;
    }
}
