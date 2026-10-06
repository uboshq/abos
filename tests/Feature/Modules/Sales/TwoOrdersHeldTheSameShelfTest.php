<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * দুটো আদেশ একই তাকের মাল একসাথে ধরল — অডিট ম১৮, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ "কতটা খালি" তালা ছাড়া পড়া হত, তারপর ধরা লেখা হত। ⓘ দৃশ্য: আদেশ খ খালি মাল পড়ার ঠিক পরে, ধরা লেখার আগে, আরেক সংযোগে
 * আদেশ ক পুরো খালি মালটা ধরে কমিট করে। তালা না থাকলে দুটোই পুরোটা ধরত — তাকের দ্বিগুণ।
 * ⭐ এখন খ পড়ার আগে পণ্যের সারিতে তালা নেয় ([[StockLock]]); ক সেই তালায় দাঁড়ায় আর সময় পেরিয়ে ফেরে — ধরা কখনো তাকের বেশি নয়।
 *
 * ⚠️ দুই সংযোগ, কমিটসহ — তাই RefreshDatabase নয়; শেষে টেবিল খালি করা ([[TwoClicksReservedTheOrderTwiceTest]]-এর মতো)।
 */
final class TwoOrdersHeldTheSameShelfTest extends TestCase
{
    private const OTHER = 'other_desk';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['database.connections.'.self::OTHER => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER);
        DB::connection(self::OTHER)->statement('SET SESSION innodb_lock_wait_timeout = 3');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge(self::OTHER);

        parent::tearDown();
    }

    public function test_two_orders_confirmed_at_once_never_hold_more_than_the_shelf(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $shelf = bcadd(app(StockService::class)->availableQty($biscuit, $warehouse), '0', 4);
        $this->assertSame(1, bccomp($shelf, '0', 4), 'প্রস্তুতিটাই ভুল — তাকে খালি মাল নেই।');

        // ⓘ একই ক্রেতা, একই মানুষ — দুটো আদেশ, প্রতিটা পুরো খালি মালটা চায়
        $first = $this->draft($biscuit, $warehouse, $shelf);
        $second = $this->draft($biscuit, $warehouse, $shelf);

        $main = DB::getDefaultConnection();
        $armed = true;
        $otherRefused = false;

        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$armed, &$otherRefused, $main, $first): void {
            if (! $armed || $q->connectionName !== $main || ! str_contains(strtolower($q->sql), 'sum(floor_change)')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::OTHER);

            try {
                app(SalesOrderService::class)->confirm(SalesOrder::query()->findOrFail($first->id));
            } catch (\Throwable) {
                $otherRefused = true; // ⓘ তালায় দাঁড়িয়ে সময় পেরোল — এটাই চাওয়া
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        app(SalesOrderService::class)->confirm($second->fresh(['lines']));

        $held = bcadd((string) StockMovement::query()->where('source_type', SalesOrder::STOCK_SOURCE)
            ->whereIn('document_no', [$first->document_no, $second->document_no])->where('product_id', $biscuit->id)->sum('reserved_change'), '0', 4);

        $this->assertFalse($armed, 'প্রস্তুতিটাই ভুল — খালি মাল পড়ার মুহূর্তটা ধরা পড়েনি।');
        $this->assertSame(0, bccomp($held, $shelf, 4),
            "⛔ দুটো আদেশ মিলে {$held} ধরল, তাকে খালি ছিল {$shelf} — একই মাল দুবার ধরা হয়েছে।");
        $this->assertTrue($otherRefused, '⛔ অন্য আদেশ তালায় দাঁড়াল না — দুটো একসাথে খালি মাল পড়েছে।');
    }

    private function draft(Product $product, Warehouse $warehouse, string $qty): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'ordered_qty' => $qty, 'rate' => '10']]);
    }
}
