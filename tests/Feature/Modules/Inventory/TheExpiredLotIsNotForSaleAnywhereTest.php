<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SellableStock;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মেয়াদ পেরোনো লট "পাওয়া যায়"-তে গোনা হত (পুরো-ERP অডিট, মজুদ M27; fe-র সিদ্ধান্ত (ক), ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ লট-বাছাই মেয়াদ পেরোনো লট কখনো দেয় না, অথচ পর্দা, রিপোর্ট আর বেচার যাচাই সেটাকে বেচার মাল বলত। এখন সবাই একটাই সূত্র
 * ডাকে ([[StockService::availableSql()]])। দাবি দুটো: (১) একই পণ্যের মেয়াদ পেরোনো লট রিপোর্ট, পর্দা, statesFor আর
 * বিক্রয়ের সংখ্যায় — সবখানে বাদ; (২) লট ছাড়া পণ্যে সংখ্যাটা আগের সূত্রের সাথে হুবহু এক (তাকে − ধরা − আটকানো)।
 *
 * পণ্য ক: মেয়াদ পেরোনো লটে তাকে ৫ (তার ১ আটকানো), ভালো লটে ৭, লট ছাড়া ধরা ২ → আগে ৯, এখন ৯ − (৫ − ১) = ৫।
 * পণ্য খ: লট নেই; তাকে ১০, ধরা ৩, আটকানো ২ → আগেও ৫, এখনো ৫।
 */
final class TheExpiredLotIsNotForSaleAnywhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_lot_leaves_available_everywhere_and_nothing_else_moves(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->create(['branch_id' => $company->defaultBranch()->id, 'code' => 'M27-W',
            'name_en' => 'Expiry store', 'is_active' => true]);
        $unit = Unit::query()->where('code', 'PCS')->firstOrFail()->id;
        $lotted = Product::query()->create(['code' => 'M27-L', 'name_en' => 'Lot probe', 'name_bn' => 'লটের নমুনা',
            'unit_id' => $unit, 'is_active' => true, 'track_batch' => true, 'reorder_level' => '6']);
        $plain = Product::query()->create(['code' => 'M27-P', 'name_en' => 'Plain probe', 'name_bn' => 'সাধারণ নমুনা',
            'unit_id' => $unit, 'is_active' => true]);

        $lot = fn (string $no, string $expiry) => Batch::query()->create(['company_id' => $company->id, 'product_id' => $lotted->id,
            'batch_no' => $no, 'expiry_date' => $expiry]);
        $gone = $lot('M27-OLD', now()->subDay()->toDateString());
        $good = $lot('M27-NEW', now()->addDays(30)->toDateString());

        $n = 0;
        $move = function (Product $p, ?Batch $b, array $changes) use ($company, $warehouse, &$n) {
            StockMovement::query()->create(['company_id' => $company->id, 'branch_id' => $warehouse->branch_id, 'product_id' => $p->id,
                'warehouse_id' => $warehouse->id, 'batch_id' => $b?->id, 'trx_date' => now()->toDateString(),
                'source_type' => 'test.m27', 'source_id' => ++$n] + $changes);
        };
        $move($lotted, $gone, ['floor_change' => '5']);
        $move($lotted, $gone, ['hold_change' => '1']);
        $move($lotted, $good, ['floor_change' => '7']);
        $move($lotted, null, ['reserved_change' => '2']);
        $move($plain, null, ['floor_change' => '10']);
        $move($plain, null, ['reserved_change' => '3']);
        $move($plain, null, ['hold_change' => '2']);

        $is = fn (string $want, $got, string $where) => $this->assertSame(0, bccomp((string) $got, $want, 4),
            "⛔ {$where}: চাই {$want}, এল {$got}।");

        // ── বেচার যাচাই: statesFor আর statesForAll ─────────────────────
        $stock = app(StockService::class);
        $is('5', $stock->statesFor($lotted, $warehouse)['available'], 'statesFor, লটের পণ্য');
        $is('5', $stock->statesFor($lotted)['available'], 'statesFor গোটা কোম্পানি, লটের পণ্য');
        $is('5', $stock->statesFor($plain, $warehouse)['available'], 'statesFor, লট ছাড়া পণ্য');
        $all = $stock->statesForAll($warehouse);
        $is('5', $all[$lotted->id]['available'], 'statesForAll, লটের পণ্য');
        $is('5', $all[$plain->id]['available'], 'statesForAll, লট ছাড়া পণ্য');
        $this->assertSame(0, bccomp($stock->statesFor($lotted, $warehouse)['floor'], '12', 4), 'ⓘ তাকের মাল নিজে নড়ে না — মেয়াদি লটও তাকে আছে।');

        // ── ড্যাশবোর্ড আর বিক্রয়ের সংখ্যা ─────────────────────────────
        $is('10', app(StockFacts::class)->states($warehouse->id)['available'], 'StockFacts::states');
        $sellable = app(SellableStock::class)->byProduct($warehouse->id, [$lotted->id, $plain->id]);
        $is('5', $sellable[(string) $lotted->id], 'বিক্রয়ের সংখ্যা, লটের পণ্য');
        $is('5', $sellable[(string) $plain->id], 'বিক্রয়ের সংখ্যা, লট ছাড়া পণ্য');

        // ⓘ পুনঃক্রয়ের স্তর ৬ — নতুন হিসাবে ৫ (নিচে), পুরনোয় ৯ (উপরে); তাই "ফুরিয়ে আসছে"-র গোনাও সূত্রটা দেখে
        $low = app(StockFacts::class)->forWarehouse($warehouse->id)->lowStock(500)->firstWhere('id', $lotted->id);
        $is('5', $low?->available_qty ?? 'নেই', '"পুনঃক্রয়ের নিচে" তালিকা, লটের পণ্য');
        $catalogue = app(\App\Modules\Sales\Services\DirectSaleOptions::class)->catalogue($warehouse, 50, $lotted->id);
        $is('5', $catalogue->first()->available ?? 'নেই', 'কাউন্টারের তালিকা, লটের পণ্য');

        // ── রিপোর্ট ────────────────────────────────────────────────────
        foreach (['inventory.stock_summary', 'inventory.stock_by_warehouse'] as $key) {
            $rows = collect(app(ReportEngine::class)->run($key, ['warehouse_id' => $warehouse->id], perPage: 500)->rows);
            $is('5', $rows->firstWhere('product_id', $lotted->id)['available'] ?? 'নেই', "{$key}, লটের পণ্য");
            $is('5', $rows->firstWhere('product_id', $plain->id)['available'] ?? 'নেই', "{$key}, লট ছাড়া পণ্য");
        }
        $position = collect(app(ReportEngine::class)->run('inventory.stock_position', ['warehouse_id' => $warehouse->id], perPage: 500)->rows);
        $is('5', $position->firstWhere('group_key', $lotted->id)['sellable'] ?? 'নেই', 'অবস্থান রিপোর্ট, লটের পণ্য');
        $is('5', $position->firstWhere('group_key', $plain->id)['sellable'] ?? 'নেই', 'অবস্থান রিপোর্ট, লট ছাড়া পণ্য');
        $alert = collect(app(ReportEngine::class)->run('inventory.stock_alerts', ['warehouse_id' => $warehouse->id], perPage: 500)->rows)
            ->first(fn ($r) => str_starts_with((string) $r['product_name'], 'M27-L'));
        $is('5', $alert['available'] ?? 'নেই', 'সতর্কতা রিপোর্ট, লটের পণ্য');
        $refill = collect(app(ReportEngine::class)->run('inventory.replenishment', [], perPage: 500)->rows)->firstWhere('product_id', $lotted->id);
        $is('5', $refill['available'] ?? 'নেই', 'পুনঃক্রয় রিপোর্ট, লটের পণ্য');

        // ── স্টক পর্দা ─────────────────────────────────────────────────
        $response = $this->get(route('inventory.stock.index', ['warehouse_id' => $warehouse->id]))->assertOk();
        $products = collect($response->viewData('products')->items());
        $is('5', $products->firstWhere('id', $lotted->id)?->available_total ?? 'নেই', 'স্টক পর্দা, লটের পণ্য');
        $is('5', $products->firstWhere('id', $plain->id)?->available_total ?? 'নেই', 'স্টক পর্দা, লট ছাড়া পণ্য');
        $is('10', $response->viewData('grand')['available'] ?? 'নেই', 'স্টক পর্দার যোগফল');
    }
}
