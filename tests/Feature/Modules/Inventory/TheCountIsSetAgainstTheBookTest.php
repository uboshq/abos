<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গণনা বনাম খাতা — রিপোর্ট সেন্টার ধাপ ৬ ([[InventoryControlReports::countVsBook()]])।
 *
 * খাতায় ১০, গুনে ৮ → কম-বেশি −২, টাকায় −২০ (স্তরের ১০ দরে)। ⓘ গুদাম বাছলে কেবল সেই গুদামের গণনা; এক শাখা বাছলে
 * অন্য শাখার গণনা নেই।
 */
final class TheCountIsSetAgainstTheBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_counted_line_shows_book_counted_and_the_gap_inside_the_branch_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'CNT-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Count probe',
            'name_bn' => 'গণনার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => false,
        ]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '10');
        app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: '10.00', sourceType: 'test.opening', sourceId: $product->id);

        $count = app(StockCountService::class)->record(
            ['warehouse_id' => $warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '8']],
        );

        $row = collect($this->rows([]))->firstWhere('document_no', $count->document_no);
        $this->assertNotNull($row, 'গণনাটা রিপোর্টে নেই।');
        $this->assertSame(['10', '8', '-2', '-20'], array_map(
            fn ($k) => rtrim(rtrim((string) $row[$k], '0'), '.'),
            ['book_qty', 'counted_qty', 'difference', 'difference_value'],
        ), 'খাতা/গোনা/কম-বেশি/টাকা ভুল।');

        /*
         * ⓘ অন্য গুদাম বাছলে গণনাটা নেই — সত্যিকারের দ্বিতীয় গুদাম দিয়ে। আগে বানানো নম্বর (`+ 999`) দেওয়া হত, কিন্তু
         * রিপোর্ট ৩ অক্টোবর থেকে তালিকার বাইরের নম্বর ফিরিয়ে দেয় ([[ReportFilters::resolve()]], b5b4b823), তাই দাবিটা
         * কিছু না দেখেই লাল হত।
         */
        $other = Warehouse::query()->create(['branch_id' => $warehouse->branch_id, 'code' => 'CNT-2', 'name_en' => 'Second store', 'is_active' => true]);
        $this->assertNull(collect($this->rows(['warehouse_id' => $other->id]))->firstWhere('document_no', $count->document_no),
            '⛔ অন্য গুদাম বাছলেও গণনাটা এসেছে।');

        // ⛔ তালিকার বাইরের নম্বর — রিপোর্ট চলে না, ছাঁকনি ফেলে গোটা তালিকাও দেয় না
        try {
            $this->rows(['warehouse_id' => $other->id + 999]);
            $this->fail('⛔ অজানা গুদাম-নম্বরে রিপোর্ট চলল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('warehouse_id', $e->errors());
        }

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        StockCount::query()->whereKey($count->id)->update(['branch_id' => $other->id]);

        $this->assertNull(collect($this->rows(['branch_id' => $company->defaultBranch()->id]))->firstWhere('document_no', $count->document_no),
            '⛔ অন্য শাখার গণনা এই শাখার রিপোর্টে।');
    }

    private function rows(array $extra): array
    {
        return app(ReportEngine::class)->run('inventory.count_vs_book', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), ...$extra,
        ], perPage: 500)->rows;
    }
}
