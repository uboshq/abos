<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পেছনের তারিখের কাগজ মেয়াদি লট বাছত, আর লট ছাড়া ঘাটতি ভালো লট থেকে কাটত (পুরো-ERP অডিট, মজুদ ছ১৩; মালিকের
 * "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * লট পুরনো: মেয়াদ গতকাল, তাকে ৫ (ফ্রি ২); লট নতুন: মেয়াদ দুই মাস পরে, তাকে ৫ (ফ্রি ২)।
 *   · তিন দিন আগের তারিখে ৩ বেরোলে — মালটা আজ তাক থেকে যায়, তাই নতুন লট থেকে (আগে: "তখনো ভালো" পুরনো লট);
 *   · লট না বলা ঘাটতি — মেয়াদি লট থেকে আগে (আগে: ভালো লট থেকে, মেয়াদি লট অমর); ফ্রি ঘাটতিও তাই।
 */
final class NoExpiredLotLeavesOnABackdatedPaperTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $old;

    private Batch $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->create(['code' => 'EX-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Expiry probe',
            'name_bn' => 'মেয়াদের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id,
            'is_active' => true, 'track_batch' => true]);

        $n = 0;
        foreach (['old' => -1, 'new' => 60] as $name => $days) {
            $lot = Batch::query()->create(['company_id' => $company->id, 'product_id' => $this->product->id, 'batch_no' => 'EX-'.$name,
                'expiry_date' => now()->addDays($days)->toDateString()]);
            app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.lot', sourceId: ++$n,
                floor: '5', free: '2', batch: $lot, date: now()->subDays(10));
            app(CostLayerService::class)->receive(product: $this->product, qty: '5', unitCost: '10', sourceType: 'test.lot', sourceId: $n,
                batch: $lot, date: now()->subDays(10));
            $this->{$name} = $lot;
        }
    }

    public function test_a_backdated_paper_does_not_send_out_a_lot_that_is_expired_today(): void
    {
        $out = app(StockService::class)->issue(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.sale', sourceId: 1,
            qty: '3', date: now()->subDays(3));

        $this->assertSame([(int) $this->new->id], array_map(fn ($m) => (int) $m->batch_id, $out),
            '⛔ তিন দিন আগের কাগজে আজ মেয়াদ পেরোনো লট বেরোল।');
        $this->assertFloor($this->old, '5');
    }

    public function test_a_shortage_without_a_lot_comes_off_the_expired_lot_first(): void
    {
        app(StockAdjustmentService::class)->settle($this->product, $this->warehouse, '-2', $this->reason());

        $this->assertFloor($this->old, '3', '⛔ লট না বলা ঘাটতি মেয়াদি লট থেকে আগে কাটল না।');
        $this->assertFloor($this->new, '5', '⛔ ঘাটতি ভালো লট থেকে কাটল।');

        app(StockAdjustmentService::class)->settleFree($this->product, $this->warehouse, '-1', $this->reason());
        $this->assertSame(0, bccomp($this->old->freeBalance($this->warehouse), '1', 4), '⛔ ফ্রি ঘাটতিও মেয়াদি লট থেকে আগে নয়।');
        $this->assertSame(0, bccomp($this->new->freeBalance($this->warehouse), '2', 4));
    }

    public function test_a_sale_today_still_never_takes_the_expired_lot(): void
    {
        $out = app(StockService::class)->issue(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.sale', sourceId: 2, qty: '4');

        $this->assertSame([(int) $this->new->id], array_map(fn ($m) => (int) $m->batch_id, $out), '⛔ আজকের বিক্রিতে মেয়াদি লট গেল।');
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function assertFloor(Batch $lot, string $expected, string $why = ''): void
    {
        $this->assertSame(0, bccomp($lot->floorBalance($this->warehouse), $expected, 4), $why ?: "⛔ লট {$lot->batch_no}-এ {$expected} নেই।");
    }
}
