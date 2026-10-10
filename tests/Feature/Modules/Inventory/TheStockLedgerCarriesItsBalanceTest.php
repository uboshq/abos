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
 * ⛔ মজুদের খাতায় শুরুর জের আর চলমান জের ছিল না (পুরো-ERP অডিট, মজুদ ছ১৯; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * পণ্য ক: পরিসরের আগে গুদাম ১-এ +১০ −৩, গুদাম ২-এ +২; পরিসরে গুদাম ১-এ +৫ −৪, গুদাম ২-এ +১। পণ্য খ: পরিসরে +৭।
 * সব গুদামে পণ্য ক-এর জের: ৯ থেকে ১৪ → ১০ → ১১; গুদাম ১ বাছলে ৭ থেকে ১২ → ৮। পণ্য খ নিজের জের (৭), ক-এর সাথে মেশে না।
 */
final class TheStockLedgerCarriesItsBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_row_carries_the_balance_from_before_the_range_product_by_product(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $branch = $company->defaultBranch()->id;
        $one = Warehouse::query()->create(['branch_id' => $branch, 'code' => 'SL-1', 'name_en' => 'Ledger one', 'is_active' => true]);
        $two = Warehouse::query()->create(['branch_id' => $branch, 'code' => 'SL-2', 'name_en' => 'Ledger two', 'is_active' => true]);
        $unit = Unit::query()->where('code', 'PCS')->firstOrFail()->id;
        $ka = Product::query()->create(['code' => 'SL-KA', 'name_en' => 'Ledger ka', 'name_bn' => 'খাতা ক', 'unit_id' => $unit, 'is_active' => true]);
        $kha = Product::query()->create(['code' => 'SL-KHA', 'name_en' => 'Ledger kha', 'name_bn' => 'খাতা খ', 'unit_id' => $unit, 'is_active' => true]);

        $n = 0;
        $move = function (Product $p, Warehouse $w, int $daysAgo, string $qty) use ($company, &$n) {
            StockMovement::query()->create(['company_id' => $company->id, 'branch_id' => $w->branch_id, 'product_id' => $p->id,
                'warehouse_id' => $w->id, 'trx_date' => now()->subDays($daysAgo)->toDateString(), 'floor_change' => $qty,
                'source_type' => 'test.ledger', 'source_id' => ++$n, 'document_no' => 'SL-'.$n]);
        };
        $move($ka, $one, 10, '10');
        $move($ka, $one, 8, '-3');
        $move($ka, $two, 9, '2');
        $move($ka, $one, 2, '5');
        $move($ka, $one, 1, '-4');
        $move($ka, $two, 1, '1');
        $move($kha, $one, 2, '7');

        $range = ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()];
        $balances = fn (array $extra, Product $p) => collect(app(ReportEngine::class)->run('inventory.stock_ledger', $range + $extra, perPage: 500)->rows)
            ->filter(fn ($r) => str_starts_with((string) $r['product_name'], $p->code.' '))
            ->map(fn ($r) => rtrim(rtrim((string) $r['balance'], '0'), '.'))
            ->values()->all();

        $this->assertSame(['14', '10', '11'], $balances([], $ka), '⛔ জের পরিসরের আগের মাল থেকে শুরু হয়নি, বা পথে ভুল।');
        $this->assertSame(['7'], $balances([], $kha), '⛔ এক পণ্যের জের অন্য পণ্যের সাথে মিশল।');
        $this->assertSame(['12', '8'], $balances(['warehouse_id' => $one->id], $ka), '⛔ গুদাম বাছলেও জের অন্য গুদামের মাল গুনল।');
        $this->assertSame(['14', '10', '11'], $balances(['product_id' => $ka->id], $ka), '⛔ পণ্য বাছলে জের বদলাল।');
        $this->assertSame([], $balances(['product_id' => $ka->id], $kha), '⛔ পণ্য বাছলেও অন্য পণ্য এল।');
    }
}
