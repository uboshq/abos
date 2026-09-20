<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ কার্টনের মজুদ পিসে নামানো — মূল্য এক চুলও না নড়িয়ে।
 * ২০ সেপ্টেম্বর ২০২৬, মালিকের সিদ্ধান্ত: Demo-র দুই পণ্যে ১ CTN = ২৪ PCS।
 *
 * ── ⚠️ কেন এটা নিয়মের ব্যতিক্রম ──────────────────────────────────────
 * নিয়ম বলে মজুদ-চলাচল হয়ে গেলে base বদলায় না, আর সেটা ঠিকই
 * ([[PacksAreSavedTheWayPeopleSayThemTest]])। ⓘ কিন্তু পুরনো দুইটা পণ্য
 * ভুল এককে বসে গেছে, আর ওদের নামানোর একমাত্র সৎ উপায় হলো **সব সংখ্যা
 * একসাথে** বদলানো: পরিমাণ ২৪ গুণ, দর ২৪ ভাগ।
 *
 * ⭐ তাই এই পরীক্ষার কেন্দ্রীয় দাবি একটাই — **মোট মূল্য আগে যা, পরেও তা**।
 * পরিমাণ বদলায়, মানে বদলায় না।
 */
final class BringingTheStockDownToPiecesKeepsItsValueTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $carton;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->carton = Unit::query()->where('code', 'CTN')->firstOrFail();

        // কার্টনে গোনা একটা পণ্য, লাইভের Cosmos-এর মতো
        $this->product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TCOSMOS', 'name_en' => 'Cosmos 40gm',
            'unit_id' => $this->carton->id, 'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        // ২৩ কার্টন ঢুকল, ৩ কার্টন গেল — বাকি ২০ কার্টন @ ৪৮০ টাকা
        StockMovement::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $company->defaultBranch()?->id,
            'product_id' => $this->product->id, 'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(), 'floor_change' => '23',
            'source_type' => 'opening', 'source_id' => $this->product->id,
        ]);

        StockMovement::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $company->defaultBranch()?->id,
            'product_id' => $this->product->id, 'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(), 'floor_change' => '-3',
            'source_type' => 'opening', 'source_id' => $this->product->id,
        ]);

        CostLayer::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $this->product->id,
            'source_type' => 'opening', 'source_id' => $this->product->id,
            'trx_date' => now()->toDateString(), 'qty_in' => '23', 'qty_remaining' => '20',
            'unit_cost' => '480',
        ]);
    }

    private function rebase(bool $apply): array
    {
        return app(PackRebase::class)->run($this->product, $this->piece, '24', $apply);
    }

    private function stock(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->product->id)
            ->sum('floor_change');
    }

    private function value(): string
    {
        return (string) DB::table('inv_cost_layers')
            ->where('product_id', $this->product->id)
            ->selectRaw('SUM(qty_remaining * unit_cost) as v')
            ->value('v');
    }

    /**
     * ⭐ মূল কথা: ২০ কার্টন × ৪৮০ = ৪৮০ পিস × ২০ — মোট ৯,৬০০ টাকা, দুইবারই।
     */
    public function test_the_value_is_the_same_and_only_the_unit_changes(): void
    {
        $valueBefore = $this->value();
        $stockBefore = $this->stock();

        $report = $this->rebase(apply: true);

        $this->assertSame(0, bccomp($valueBefore, $this->value(), 2),
            "মূল্য বদলে গেছে: {$valueBefore} → ".$this->value());

        $this->assertSame(0, bccomp(bcmul($stockBefore, '24', 4), $this->stock(), 4),
            'পরিমাণ ২৪ গুণ হয়নি।');

        $this->assertSame($this->piece->id, $this->product->fresh()->unit_id);
        $this->assertSame(0, bccomp($report['value_before'], $report['value_after'], 2));
    }

    /**
     * ⭐ দর ভাগ হয় — ১ কার্টন ৪৮০ মানে ১ পিস ২০।
     */
    public function test_the_cost_per_piece_is_the_carton_cost_divided(): void
    {
        $this->rebase(apply: true);

        $this->assertSame(0, bccomp('20', (string) CostLayer::query()
            ->where('product_id', $this->product->id)->value('unit_cost'), 4));
    }

    /**
     * ⛔ ডিফল্টে কিছুই লেখা হয় না — রিপোর্টটা আসল চালানোর মতোই পূর্ণ।
     */
    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $report = $this->rebase(apply: false);

        $this->assertSame($this->carton->id, $this->product->fresh()->unit_id, 'dry-run একক বদলে ফেলেছে।');
        $this->assertSame(0, bccomp('20', $this->stock(), 4), 'dry-run মজুদ বদলে ফেলেছে।');
        $this->assertSame(0, bccomp(bcmul('20', '24', 4), $report['qty_after'], 4),
            'রিপোর্ট বলেনি পরিমাণ কত হত।');
    }

    /**
     * ⭐ নামানোর পরে পুরনো একক একটা প্যাক হয়ে থাকে — "১ কার্টন = ২৪ পিস",
     * আর রূপান্তরও সেটাই বলে।
     */
    public function test_the_old_unit_becomes_a_pack_of_the_new_one(): void
    {
        $this->rebase(apply: true);

        $pack = ProductUnit::query()->where('product_id', $this->product->id)
            ->where('unit_id', $this->carton->id)->sole();

        $this->assertSame('24.000000', $pack->factor);
        $this->assertSame('24.000000', $pack->per_qty);
        $this->assertSame($this->piece->id, $pack->per_unit_id);

        $this->assertSame('24.000000',
            app(PackConversion::class)->factorFor($this->product->fresh(), $this->carton->id));
    }

    /**
     * ⛔ যে পণ্য আগে থেকেই ঐ এককে, তাকে নামানোর কিছু নেই — আর বার্তাটা
     * সেটাই বলে, "একক বসানো নেই" নয়।
     */
    public function test_a_product_already_in_that_unit_is_refused_clearly(): void
    {
        $this->rebase(apply: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('inventory::validation.already_that_unit', [
            'product' => $this->product->fresh()->name(), 'unit' => $this->piece->name(),
        ]));

        app(PackRebase::class)->run($this->product->fresh(), $this->piece, '24', apply: false);
    }
}
