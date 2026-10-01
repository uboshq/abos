<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মাসওয়ারি মাল আসা-যাওয়া — মালিক, ১ অক্টোবর ২০২৬ ([[InventoryAnalysisReports::monthly()]])।
 *
 * জুলাইয়ে ৫, আগস্টে ২০ এল, সেপ্টেম্বরে ১০ এল ও ১২ গেল, অক্টোবরে কিছুই না।
 *   পরিমাণ    আগস্ট ৫→২৫ · সেপ্টেম্বর ২৫→২৩ · অক্টোবর ২৩→২৩ (চলাচল নেই, তবু সারি)
 *   এল টাকা  মাসে জন্মানো স্তর, নিজের দরে: আগস্ট ২০ × ৫০ = ১,০০০ · সেপ্টেম্বর ১০ × ৫০ = ৫০০
 *   গেল টাকা মাসে স্তর থেকে টানা: সেপ্টেম্বর ৬০০
 *   শুরু/শেষ মজুদ খাতের জের — পণ্য বাছলে খালি; না বাছলে মাসের বদল = সেই মাসের খাতার দাখিলা
 * ⓘ গুদামটা অন্য শাখার হলে এই শাখায় পরিমাণ শূন্য। ⓘ দাম পরে বদলালেও আগের মাসের টাকা বদলায় না (আজকের দরে নয়)।
 */
final class TheMonthlyStockInAndOutAddsUpTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    public function test_each_month_opens_where_the_last_closed_at_the_cost_actually_booked(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'ZQ-MON', 'name_en' => 'Monthly probe', 'name_bn' => 'মাসের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);

        $this->move('2026-07-10', '5');
        $this->move('2026-08-05', '20');
        $this->move('2026-09-02', '10');
        $this->move('2026-09-20', '-12');
        $this->layer('2026-08-05', '20', '50');
        $layer = $this->layer('2026-09-02', '10', '50');
        DB::table('inv_cost_layer_uses')->insert([
            'company_id' => CompanyContext::id(), 'cost_layer_id' => $layer, 'product_id' => $this->product->id,
            'source_type' => 'test', 'source_id' => 1, 'trx_date' => '2026-09-20',
            'qty' => '12', 'unit_cost' => '50', 'amount' => '600', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ⓘ পরে নতুন দামে মাল এল (অক্টোবরের পরে) — আগের মাসের টাকা নড়বে না, কারণ টাকা স্তরের নিজের দরে
        $this->layer('2026-11-05', '5', '999');

        $this->assertSame([
            '2026-08' => ['5', '20', '0', '25', '', '1000', '0', ''],
            '2026-09' => ['25', '10', '12', '23', '', '500', '600', ''],
            '2026-10' => ['23', '0', '0', '23', '', '0', '0', ''],
        ], $this->months(['product_id' => $this->product->id]), 'মাসের হিসাব মেলে না — বা চলাচলহীন মাস হারিয়েছে।');

        // ⓘ পণ্য না বাছলে শুরু/শেষ টাকা মজুদ খাতের জের — মাসের বদল = সেই মাসের দাখিলা
        $this->book('2026-08-05', '1000');
        $this->book('2026-09-02', '500');
        $this->book('2026-09-20', '-600');
        $all = $this->months([]);
        $this->assertSame('1000', (string) round((float) $all['2026-08'][7] - (float) $all['2026-08'][4]));
        $this->assertSame('-100', (string) round((float) $all['2026-09'][7] - (float) $all['2026-09'][4]));
        $this->assertSame($all['2026-08'][7], $all['2026-09'][4], 'আগস্টের শেষ আর সেপ্টেম্বরের শুরু আলাদা।');

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $this->warehouse->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertSame(['0', '0', '0', '0'], array_slice(
            $this->months(['product_id' => $this->product->id, 'branch_id' => $company->defaultBranch()->id])['2026-09'], 0, 4),
            '⛔ অন্য শাখার গুদামের মাল এই শাখার হিসাবে।');
    }

    /** @return array<string, list<string>> মাস → শুরু, এল, গেল, শেষ, আর চারটার টাকা (খালি = '') */
    private function months(array $extra): array
    {
        $rows = app(ReportEngine::class)->run('inventory.monthly_movement', [
            'from' => '2026-08-01', 'to' => '2026-10-31', ...$extra,
        ], perPage: 100)->rows;

        $keys = ['opening_qty', 'in_qty', 'out_qty', 'closing_qty', 'opening_value', 'in_value', 'out_value', 'closing_value'];

        return collect($rows)->mapWithKeys(fn ($r) => [(string) $r['ym'] => array_map(
            fn ($k) => $r[$k] === null ? '' : (string) round((float) $r[$k]), $keys,
        )])->all();
    }

    private function move(string $date, string $qty): void
    {
        DB::table('inv_stock_movements')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->warehouse->branch_id,
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => $date, 'floor_change' => $qty,
            'source_type' => 'test.monthly', 'source_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function layer(string $date, string $qty, string $cost): int
    {
        return (int) DB::table('inv_cost_layers')->insertGetId([
            'company_id' => CompanyContext::id(), 'product_id' => $this->product->id, 'source_type' => 'test', 'source_id' => 1,
            'trx_date' => $date, 'qty_in' => $qty, 'qty_remaining' => $qty, 'unit_cost' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** মজুদ খাতে দাখিলা, এই শাখায় — ধনাত্মক মানে মাল এল */
    private function book(string $date, string $amount): void
    {
        $inventory = Account::query()->where('code', StandardChart::INVENTORY)->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $in = ! str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        $branch = $this->warehouse->branch_id;

        app(PostingEngine::class)->post(
            sourceType: 'test:monthly', sourceId: random_int(1, PHP_INT_MAX), trxDate: $date,
            lines: [
                ['account_id' => $inventory->id, $in ? 'debit' : 'credit' => $amount, 'branch_id' => $branch],
                ['account_id' => $capital->id, $in ? 'credit' : 'debit' => $amount, 'branch_id' => $branch],
            ],
        );
    }
}
