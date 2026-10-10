<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ শাখা-পেরোনো বদলি মজুদের টাকা সরাত কোম্পানির গড়ে, FIFO-তে নয় (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️৮)।
 *
 * ⓘ [[StockTransferService]] গ্রহণে পাঠানো শাখায় ক্রেডিট, পাওয়া শাখায় ডেবিট বসাত গড় খরচে। পরের বিক্রি টানে লটের বা FIFO-র
 * আসল খরচ — দামি লট গেলে গন্তব্যের মজুদ খাত বিক্রির পরে ঋণাত্মক, উৎসে বাড়তি। এখন গন্তব্যে যে লট যতটা নামল তার খরচ,
 * বিক্রির একই ক্রমে ([[CostLayerService::costOf()]])।
 */
final class ABranchTransferCarriesWhatTheGoodsCostTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mms;

    private Branch $ntk;

    private Warehouse $atNtk;

    private Warehouse $atMms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $this->ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $this->mms->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $house = fn (string $code, int $branch) => Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $branch, 'code' => $code, 'name_en' => $code, 'is_active' => true,
        ]);
        $this->atNtk = $house('W8N', $this->ntk->id);
        $this->atMms = $house('W8M', $this->mms->id);
    }

    public function test_the_lot_that_left_carries_its_own_cost_not_the_average(): void
    {
        $product = $this->product(true);
        // ⓘ দামি লট আগে মেয়াদোত্তীর্ণ — FEFO তাকেই পাঠায়; গড় ৭৫, আসল ১০০
        $this->lot($product, 'W8-CHEAP', '50', Carbon::today()->addMonths(9));
        $this->lot($product, 'W8-DEAR', '100', Carbon::today()->addMonths(2));

        $companyBooks = fn () => StandardChart::find(StandardChart::INVENTORY)->balanceOn();
        $layerTotal = fn () => (string) \Illuminate\Support\Facades\DB::table('inv_cost_layers')->where('company_id', CompanyContext::id())
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')->value('v');
        [$booksBefore, $layersBefore] = [$companyBooks(), $layerTotal()];

        $transfer = $this->send($product, '4');

        // ⓘ টাকা কেবল শাখা বদলায় — কোম্পানির মজুদ খাত (১১২০) আর স্তরের মোট এক পয়সাও নড়ে না
        $this->assertSame(0, bccomp($companyBooks(), $booksBefore, 4), '⛔ বদলিতে কোম্পানির মজুদ খাত নড়ল।');
        $this->assertSame(0, bccomp($layerTotal(), $layersBefore, 4), '⛔ বদলিতে খরচ-স্তরের মোট নড়ল।');

        $this->assertSame('W8-DEAR', Batch::query()->withoutGlobalScopes()->whereKey(
            \App\Modules\Inventory\Models\StockMovement::query()->withoutGlobalScopes()->where('source_id', $transfer->id)
                ->where('warehouse_id', $this->atMms->id)->value('batch_id'))->value('batch_no'), 'প্রস্তুতিটাই ভুল — দামি লট যায়নি।');
        $this->assertSame('400', $this->moved($transfer), '⛔ ৪টা দামি লট গেল, অথচ মজুদের টাকা সরল কোম্পানির গড়ে।');
    }

    public function test_goods_without_a_lot_carry_the_oldest_cost_first(): void
    {
        $product = $this->product(false);
        $stock = app(StockService::class);
        $stock->move(product: $product, warehouse: $this->atNtk, sourceType: 'test.in', sourceId: 1, floor: '20');
        app(CostLayerService::class)->receive($product, '10', '50', 'test.in', 1, 'IN-OLD', Carbon::today()->subDays(20)->toDateString());
        app(CostLayerService::class)->receive($product, '10', '100', 'test.in', 2, 'IN-NEW', Carbon::today()->subDays(5)->toDateString());

        $this->assertSame('200', $this->moved($this->send($product, '4')), '⛔ ৪টা গেল পুরনো দামে নয় — বিক্রি FIFO-তে ৫০ টানবে, খাতা সরাল গড়ে।');
    }

    /** ⓘ তাকে আছে, স্তরে কম (স্তর-যুগের আগের মাল) — যা স্তরে নেই তা গড়ে, আগের নিয়মে; বদলি থামে না, শূন্যেও যায় না */
    public function test_what_the_layers_cannot_cover_is_priced_at_the_average_as_before(): void
    {
        $product = $this->product(false);
        app(StockService::class)->move(product: $product, warehouse: $this->atNtk, sourceType: 'test.in', sourceId: 3, floor: '4');
        app(CostLayerService::class)->receive($product, '2', '50', 'test.in', 3, 'IN-PART');

        $this->assertSame('200', $this->moved($this->send($product, '4')), '⛔ স্তরে না থাকা ২টার দাম বাদ পড়ল।');
    }

    private function send(Product $product, string $qty): StockTransfer
    {
        $transfers = app(StockTransferService::class);
        $transfer = $transfers->dispatch($transfers->create(
            ['from_warehouse_id' => $this->atNtk->id, 'to_warehouse_id' => $this->atMms->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => $qty]],
        ));
        $transfers->receive($transfer->fresh());

        return $transfer->fresh();
    }

    /** গ্রহণে ময়মনসিংহের মজুদ খাতে কত টাকা ঢুকল — বদলির নিজের দাখিলা */
    private function moved(StockTransfer $transfer): string
    {
        $sum = (string) LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', StockTransfer::drillSourceType())->where('source_id', $transfer->id)
            ->where('account_id', StandardChart::find(StandardChart::INVENTORY)->id)->where('branch_id', $this->mms->id)
            ->sum('debit');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }

    private function product(bool $lots): Product
    {
        return Product::query()->create([
            'code' => 'W8-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Transfer cost probe', 'name_bn' => 'বদলির খরচের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => $lots,
        ]);
    }

    private function lot(Product $product, string $no, string $cost, Carbon $expiry): void
    {
        $batch = app(BatchService::class)->receive($product, $no, $expiry->toDateString());
        app(StockService::class)->move(product: $product, warehouse: $this->atNtk, sourceType: 'test.in', sourceId: $batch->id, floor: '10', batch: $batch);
        app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: $cost, sourceType: 'test.in', sourceId: $batch->id, batch: $batch);
    }
}
