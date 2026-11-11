<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মজুদের ড্যাশবোর্ড, সারি 6–7 — মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * প্রতিটা দাবি নিজের নতুন গুদাম আর নতুন পণ্যে — ডেমোর অন্য মাল অঙ্কে ঢোকে না, তাই সংখ্যাগুলো হুবহু ধরা যায়।
 * ⓘ মজুদের বয়স FIFO ধরে: যা আছে তা সবচেয়ে নতুন ঢোকা থেকে; জায়গা = মাল ÷ ধারণক্ষমতা, না বসালে শতাংশ নয়;
 * ঢোকা-বেরোনো "সব গুদাম"-এ বদলি বাদ; মেয়াদোত্তীর্ণ = বাদ দেওয়ার দিনে লটের মেয়াদ শেষ, বাকি সমন্বয় = ভাঙা/নষ্ট।
 */
final class TheStockBoardsLowerRowsReadTheRealGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $yard;

    private Product $biscuit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->yard = Warehouse::query()->create(['code' => 'BY-1', 'name_en' => 'Board yard', 'is_active' => true,
            'branch_id' => $this->company->defaultBranch()?->id]);
        $this->biscuit = Product::query()->create(['code' => 'BY-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Board biscuit',
            'name_bn' => 'বোর্ডের বিস্কুট', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id,
            'purchase_price' => '10', 'is_active' => true]);
    }

    /** ⭐ 10টা 100 দিন আগে, 10টা আজ ঢুকল, 5টা বেরোল — বাকি 15-র 10টা নতুন (0–30), 5টা পুরনো (90+): 67% আর 33%। */
    public function test_the_age_of_stock_takes_what_is_left_from_the_newest_arrivals(): void
    {
        $this->in('10', now()->subDays(100)->toDateString());
        $this->in('10', now()->toDateString());
        $this->in('-5', now()->toDateString());

        $row = collect($this->board()->ageing())->firstWhere('name', $this->yard->drillLabel());

        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — নতুন গুদামের সারি নেই।');
        $this->assertSame([67, 0, 0, 33], $row['parts'], '⛔ বয়সের ভাগ FIFO মেনে হয়নি — পুরনো মালকেই রেখে দেওয়া হয়েছে, বা বেরোনোও ঢোকা ধরা হয়েছে।');
        $this->assertSame(100, array_sum($row['parts']), '⛔ ভাগের যোগফল 100 নয়।');
    }

    /** ⭐ 45 দিন আগে ঢোকা মাল 31–60-এর ঘরে — সীমার দুই পাশ আলাদা। */
    public function test_goods_that_came_in_45_days_ago_sit_in_the_second_band(): void
    {
        $this->in('4', now()->subDays(45)->toDateString());

        $row = collect($this->board()->ageing())->firstWhere('name', $this->yard->drillLabel());

        $this->assertSame([0, 100, 0, 0], $row['parts'], '⛔ 45 দিনের মাল 31–60-এর ঘরে বসেনি।');
    }

    /** ⭐ ধারণক্ষমতা 200, মাল 180 → 90%; ধারণক্ষমতা মুছলে শতাংশ নেই — পাতায় "বসানো নেই"। */
    public function test_the_space_reads_goods_over_capacity_and_says_when_capacity_is_missing(): void
    {
        $this->in('180', now()->toDateString());
        $this->yard->forceFill(['capacity_qty' => '200'])->save();

        $row = collect($this->board()->space())->firstWhere('name', $this->yard->drillLabel());
        $this->assertSame(90, $row['pct'], '⛔ জায়গা কতটা ভরা — 180 ÷ 200 = 90% আসেনি।');

        $this->yard->forceFill(['capacity_qty' => null])->save();
        $row = collect($this->board()->space())->firstWhere('name', $this->yard->drillLabel());
        $this->assertNull($row['pct'], '⛔ ধারণক্ষমতা নেই, তবু একটা শতাংশ আন্দাজ করা হলো।');

        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('inventory::board.y.space_unset')), $html, '⛔ ধারণক্ষমতা না থাকার কথা পাতায় নেই।');
    }

    /** ⭐ গুদামের ফর্ম ধারণক্ষমতা রাখে, আর শূন্য বা ঋণাত্মক নেয় না। */
    public function test_the_warehouse_form_keeps_its_capacity_and_refuses_zero(): void
    {
        $this->put(route('inventory.warehouse.update', $this->yard), ['code' => 'BY-1', 'name_en' => 'Board yard', 'capacity_qty' => '0',
            'branch_id' => $this->yard->branch_id])->assertSessionHasErrors('capacity_qty');
        $this->assertNull($this->yard->fresh()->capacity_qty, '⛔ শূন্য ধারণক্ষমতা বসে গেল।');

        $this->put(route('inventory.warehouse.update', $this->yard), ['code' => 'BY-1', 'name_en' => 'Board yard', 'capacity_qty' => '1500',
            'branch_id' => $this->yard->branch_id])->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->yard->fresh()->capacity_qty, '1500', 4), '⛔ ফর্ম থেকে ধারণক্ষমতা বসেনি।');
    }

    /** ⭐ আজ 10টা ঢুকল, 4টা বেরোল (দর 10) — ঢোকা ৳100, বেরোনো ৳40; "সব গুদাম"-এ বদলির চলাচল গোনে না। */
    public function test_the_daily_flow_counts_money_in_and_out_and_leaves_transfers_out(): void
    {
        $before = $this->board()->dailyFlow();

        $this->in('10', now()->toDateString());
        $this->in('-4', now()->toDateString());
        $this->in('6', now()->toDateString(), StockTransfer::STOCK_SOURCE);

        $after = $this->board()->dailyFlow();

        $this->assertCount(30, $after['days'], '⛔ 30 দিনের বার নেই।');
        $this->assertSame(0, bccomp(bcsub($after['in'], $before['in'], 4), '100', 4), '⛔ ঢোকা ৳100 বাড়েনি — বা বদলিও ঢোকা ধরা হয়েছে।');
        $this->assertSame(0, bccomp(bcsub($after['out'], $before['out'], 4), '40', 4), '⛔ বেরোনো ৳40 বাড়েনি।');
    }

    /** ⭐ মেয়াদ পেরোনো লট বাদ দিলে "মেয়াদোত্তীর্ণ", মেয়াদ থাকা মাল বাদ দিলে "ভাঙা/নষ্ট" — একই কারণ, আলাদা ঘর। */
    public function test_a_write_off_of_a_lapsed_lot_is_expired_and_of_a_good_lot_is_broken(): void
    {
        $reason = ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail();
        $before = $this->board()->damage();

        $old = Batch::query()->create(['company_id' => $this->company->id, 'product_id' => $this->biscuit->id, 'batch_no' => 'BY-OLD', 'expiry_date' => now()->subDay()->toDateString()]);
        $good = Batch::query()->create(['company_id' => $this->company->id, 'product_id' => $this->biscuit->id, 'batch_no' => 'BY-NEW', 'expiry_date' => now()->addDays(90)->toDateString()]);

        $this->in('-3', now()->toDateString(), 'test', $old, $reason);
        $this->in('-2', now()->toDateString(), 'test', $good, $reason);

        $after = $this->board()->damage();

        $this->assertSame(0, bccomp(bcsub($after['expired'], $before['expired'], 4), '30', 4), '⛔ মেয়াদ পেরোনো লটের ৳30 "মেয়াদোত্তীর্ণ"-তে আসেনি।');
        $this->assertSame(0, bccomp(bcsub($after['broken'], $before['broken'], 4), '20', 4), '⛔ ভালো লটের ৳20 "ভাঙা/নষ্ট"-তে আসেনি।');
    }

    /** ⭐ পাতায় সারি 6 আর 7-এর সাতটা ঘরই আছে, নমুনার ক্রমে। */
    public function test_the_page_carries_the_seven_boxes_of_rows_six_and_seven_in_the_samples_order(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $at = [];
        foreach (['data-inv-ageing', 'data-inv-otif', 'data-inv-daily-flow', 'data-inv-space', 'data-inv-grn', 'data-inv-transit', 'data-inv-damage'] as $mark) {
            $at[$mark] = strpos($html, $mark);
            $this->assertNotFalse($at[$mark], '⛔ পাতায় '.$mark.' ঘর নেই।');
        }

        $this->assertSame(array_keys($at), array_keys(collect($at)->sort()->all()), '⛔ ঘরগুলো নমুনার ক্রমে নেই।');
        $this->assertLessThan($at['data-inv-ageing'], strpos($html, 'data-inv-trend'), '⛔ সারি 6 সারি 5-এর আগে বসেছে।');
    }

    private function board(): InventoryBoard
    {
        return app(InventoryBoard::class);
    }

    private function in(string $qty, string $date, string $source = 'test', ?Batch $batch = null, ?ReasonCode $reason = null): void
    {
        StockMovement::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->biscuit->id, 'warehouse_id' => $this->yard->id,
            'batch_id' => $batch?->id, 'trx_date' => $date, 'floor_change' => $qty, 'reason_code_id' => $reason?->id,
            'source_type' => $source, 'source_id' => 1, 'document_no' => 'BY-'.mb_substr(md5(microtime().$qty), 0, 8),
        ]);
    }
}
