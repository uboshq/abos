<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ গণনার শিট খাতাকে পণ্যপ্রতি একবার জিজ্ঞেস করত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️১১)।
 *
 * ⓘ [[StockCountController]]-এর টীকা বলত "একবারে", কোড প্রতিটা পণ্যে `floorQty()` ডাকত — চারশো পণ্যের শিটে চারশো কোয়েরি, আর
 * সংখ্যাগুলো আলাদা মুহূর্তের। এখন [[StockService::statesForAll()]] — এক কোয়েরি, একই মুহূর্তের ছবি; সংখ্যা আগের মতোই।
 */
final class TheCountSheetAsksTheBooksOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_reads_every_products_book_in_one_question_and_the_numbers_stay(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $stocked = [];
        foreach (['12', '7.5', '30'] as $i => $qty) {
            $product = Product::query()->create(['code' => 'CS1-'.$i.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Sheet probe '.$i,
                'name_bn' => 'শিটের নমুনা '.$i, 'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
            app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
            $stocked[$product->id] = $qty;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $this->get(route('inventory.count.create', ['warehouse' => $warehouse->id]))->assertOk();
        $asked = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'inv_stock_movements')
            && str_contains(strtolower($q), 'sum(floor_change)'))->count();
        DB::disableQueryLog();

        $products = collect($page->viewData('products'));
        $this->assertGreaterThan(3, $products->count(), 'প্রস্তুতিটাই ভুল — শিটে কয়েকটা পণ্য থাকার কথা।');
        $this->assertSame(1, $asked, "⛔ শিট খাতাকে {$asked} বার জিজ্ঞেস করল — পণ্যপ্রতি একবার, একবারে নয়।");

        $book = $page->viewData('bookQty');
        $this->assertSame($products->pluck('id')->sort()->values()->all(), collect(array_keys($book))->sort()->values()->all(), '⛔ শিটের কোনো পণ্যের খাতার ঘর নেই।');
        foreach ($stocked as $id => $qty) {
            $this->assertSame(0, bccomp((string) $book[$id], $qty, 4), "⛔ পণ্য {$id}-এর খাতা {$qty} নয়।");
        }
    }
}
