<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StrandedStock;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ দুটো লট-বসানো একসাথে চললে লটহীন মজুদ ঋণাত্মক হত (পুরো-ERP অডিট, মজুদ ছ১২; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ যাচাই চলত লেনদেনের বাইরে, তালা ছাড়া — দুজনেই "১০ আছে" দেখতেন। একসাথে চলা দুই অনুরোধ পরীক্ষায় বানানো যায় না, তাই
 * দাবিটা দুই ভাগে ([[PlacementTookAnotherPapersGoodsTest]]-এর রীতি): (১) গোনাটা লেনদেনের ভিতরে, পণ্যের সারিতে তালার পরে,
 * নিজেও `FOR UPDATE`; (২) পরপর দুজনে — দ্বিতীয়জন যা বাকি তার বেশি পান না।
 */
final class TwoLotAssignmentsCannotShareTheSameCartonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lotless_goods_are_counted_inside_the_transaction_under_a_lock(): void
    {
        [$product, $warehouse, $lotA, $lotB] = $this->stage();

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            $seen[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });

        app(StrandedStock::class)->giveItALot($product, $warehouse, $lotA, '6');

        $productLock = collect($seen)->search(fn ($q) => str_contains($q['sql'], 'from `inv_products`') && str_contains($q['sql'], 'for update'));
        $sums = collect($seen)->filter(fn ($q) => str_contains($q['sql'], 'sum(') && str_contains($q['sql'], 'batch_id` is null'));

        $this->assertNotFalse($productLock, '⛔ পণ্যের সারিতে তালা নেই — দুই লট-বসানো একসাথে চলে।');
        $this->assertNotEmpty($sums, 'লটহীন মাল গোনার কোয়েরিই পাওয়া গেল না।');

        foreach ($sums as $index => $q) {
            $this->assertGreaterThan(0, $q['level'], '⛔ লটহীন মাল গোনা হলো লেনদেনের বাইরে।');
            $this->assertStringContainsString('for update', $q['sql'], '⛔ লটহীন মাল গোনা হলো তালা ছাড়া।');
            $this->assertGreaterThan($productLock, $index, '⛔ গোনা হলো পণ্যের তালার আগে।');
        }

        // (২) পরপর — বাকি ৪-এর বেশি নয়
        try {
            app(StrandedStock::class)->giveItALot($product, $warehouse, $lotB, '6');
            $this->fail('⛔ একই লটহীন কার্টন দ্বিতীয় লটেও বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }

        $lotless = (string) \App\Modules\Inventory\Models\StockMovement::query()->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)->whereNull('batch_id')->sum('floor_change');
        $this->assertSame(0, bccomp($lotless, '4', 4), '⛔ লটহীন মজুদ ঠিক নেই।');
    }

    /** @return array{0: Product, 1: Warehouse, 2: Batch, 3: Batch} */
    private function stage(): array
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create(['code' => 'ST-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Stranded probe',
            'name_bn' => 'লটহীন নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.lotless', sourceId: 1, floor: '10');
        $product->forceFill(['track_batch' => true])->save();

        $lot = fn (string $no) => Batch::query()->create(['company_id' => $company->id, 'product_id' => $product->id, 'batch_no' => $no,
            'expiry_date' => now()->addYear()->toDateString()]);

        return [$product->fresh(), $warehouse, $lot('ST-A'), $lot('ST-B')];
    }
}
