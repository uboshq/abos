<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গণনার চলাচলে কাগজের নম্বর ছিল না — খাতার সারি থেকে গণনায় ফেরা যেত না (পুরো-ERP অডিট, মজুদ ছ২; মালিকের "সব খোলা ভুল",
 * ১০ অক্টোবর ২০২৬)।
 *
 * পাঁচ পথেই নম্বর: ঘাটতি (মেয়াদের ক্রমে), বাড়তি, গোনা লটের ঘাটতি, ফ্রি মালের সমন্বয় আর মাল বের করার কাগজ — চলাচলের সারিতে, আর টাকা গেলে খাতার
 * সারিতেও।
 */
final class ACountsMovementsCarryItsNumberTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_every_path_of_an_approved_count_writes_the_papers_number(): void
    {
        $short = $this->stocked('C2-SHORT');
        $over = $this->stocked('C2-OVER');
        $lotted = $this->stocked('C2-LOT', lotNo: 'C2-L1');
        $free = $this->stocked('C2-FREE', free: true);
        $given = $this->stocked('C2-GIVEN');

        $papers = [
            'shortage' => $this->settled([['product_id' => $short->id, 'counted_qty' => '7']]),
            'surplus' => $this->settled([['product_id' => $over->id, 'counted_qty' => '12', 'unit_cost' => '10']]),
            'lot shortage' => $this->settled([['product_id' => $lotted->id, 'counted_qty' => '6', 'batch_no' => 'C2-L1']]),
            'free' => $this->settled([['product_id' => $free->id, 'counted_qty' => '3']], StockCount::KIND_FREE),
            // ⓘ বিনা বিক্রয়ে মাল বের করার কাগজ — ছক বন্ধে সাথে সাথে শেষ হয়, একই সমন্বয়ের পথে
            'issue' => app(StockCountService::class)->issue($given, $this->warehouse, '2',
                ReasonCode::query()->inContext(ReasonCode::STOCK_ISSUE)->active()->whereNotNull('account_id')->orderBy('id')->firstOrFail())[0],
        ];
        $products = ['shortage' => $short, 'surplus' => $over, 'lot shortage' => $lotted, 'free' => $free, 'issue' => $given];

        foreach ($papers as $path => $paper) {
            $moves = StockMovement::query()->where('source_type', StockService::ADJUSTMENT)->where('product_id', $products[$path]->id)->get();
            $this->assertNotEmpty($moves, "প্রস্তুতিটাই ভুল — {$path}-এ কোনো চলাচল নেই।");
            $this->assertSame([$paper->document_no], $moves->pluck('document_no')->unique()->values()->all(),
                "⛔ {$path}: চলাচলের সারিতে গণনার নম্বর নেই।");
        }

        // ⓘ টাকা গেলে খাতার সারিও নম্বর জানে — ফ্রি মালে খাতায় কিছু যায় না
        foreach (['shortage', 'surplus', 'lot shortage'] as $path) {
            $this->assertGreaterThan(0, LedgerEntry::query()->where('document_no', $papers[$path]->document_no)->count(),
                "⛔ {$path}: খাতার সারি থেকে গণনায় ফেরা যায় না।");
        }
    }

    /** @param list<array<string, string|int>> $lines */
    private function settled(array $lines, string $kind = StockCount::KIND_COUNT): StockCount
    {
        $counts = app(StockCountService::class);
        $paper = $counts->record(['warehouse_id' => $this->warehouse->id, 'kind' => $kind], $lines);

        return $counts->approve($paper, ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail());
    }

    private function stocked(string $code, ?string $lotNo = null, bool $free = false): Product
    {
        $product = Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true, 'track_batch' => $lotNo !== null]);
        $lot = $lotNo === null ? null : Batch::query()->create(['product_id' => $product->id, 'batch_no' => $lotNo,
            'expiry_date' => now()->addYear()->toDateString()]);

        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.in', sourceId: $product->id,
            floor: $free ? '0' : '10', free: $free ? '5' : '0', batch: $lot);

        if (! $free) {
            app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: '10', sourceType: 'test.in',
                sourceId: $product->id, batch: $lot);
        }

        return $product;
    }
}
