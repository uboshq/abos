<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchAllocator;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * আগে-মেয়াদ নিয়ম লটের মাল গুনত তালা ছাড়া — অডিট গ১৩, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * [[BatchAllocator::candidates()]] লটের সারিতে তালা দিত, কিন্তু প্রতিটা লটের মাল গুনত সাধারণ `SUM`-এ
 * ([[Batch::floorBalance()]])। ⚠️ InnoDB-তে সাধারণ পড়া লেনদেনের প্রথম snapshot দেখতে পারে — দ্বিতীয় বিক্রি
 * তালার অপেক্ষা শেষে পুরনো সংখ্যাই পড়ত, আর দুজনেই একই লটের পুরোটা নিত: লট ঋণাত্মক, রিকলে ভুয়া মাল।
 *
 * ── ⓘ কেন কোয়েরি মাপা, দুই সংযোগের দৌড় নয় ────────────────────────────
 * [[TwoCountersRaceTest]]-এর সেই একই কারণ: চলাচলের সারি লেখার সময় MySQL বিদেশি চাবির জন্য লটের সারিতে নিজেই
 * তালা বসায়, তাই দৌড়ের পরীক্ষা সারাই ছাড়াই সবুজ হত। দাবিটা সরাসরি কথাটা মাপে: লটের প্রতিটা গোনা `FOR UPDATE`।
 */
final class FefoReadTheLotWithoutALockTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'G13-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Two counters, one lot',
            'name_bn' => 'দুই কাউন্টার, এক লট',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'track_batch' => true,
            'is_active' => true,
        ]);

        foreach (['A' => 2, 'B' => 6] as $no => $months) {
            $batch = Batch::query()->create([
                'product_id' => $this->product->id,
                'batch_no' => $no,
                'expiry_date' => now()->addMonths($months)->toDateString(),
            ]);

            app(StockService::class)->move(
                product: $this->product,
                warehouse: $this->warehouse,
                sourceType: 'test_opening',
                sourceId: $batch->id,
                floor: '5',
                free: '5',
                batch: $batch,
            );
        }
    }

    public function test_every_lot_is_counted_under_a_lock_when_selling(): void
    {
        $sums = $this->lotSumsWhile(fn () => DB::transaction(
            fn () => app(BatchAllocator::class)->allocate($this->product, $this->warehouse, '8'),
        ), 'floor_change');

        $this->assertNotEmpty($sums, 'লটের মাল গোনার কোয়েরিই পাওয়া গেল না — দাবিটা কিছু মাপছে না।');

        foreach ($sums as $sql) {
            $this->assertStringContainsString('for update', $sql,
                'লটের মাল গোনা হয়েছে তালা ছাড়া — দুই বিক্রি একই লট শূন্যের নিচে নিতে পারে।');
        }
    }

    public function test_every_lot_is_counted_under_a_lock_when_giving_free_goods(): void
    {
        $sums = $this->lotSumsWhile(fn () => DB::transaction(
            fn () => app(BatchAllocator::class)->allocateFree($this->product, $this->warehouse, '8'),
        ), 'free_change');

        $this->assertNotEmpty($sums, 'লটের ফ্রি মাল গোনার কোয়েরিই পাওয়া গেল না।');

        foreach ($sums as $sql) {
            $this->assertStringContainsString('for update', $sql,
                'লটের ফ্রি মাল গোনা হয়েছে তালা ছাড়া — দুই কাউন্টার একই শেষ ফ্রি কার্টন দিত।');
        }
    }

    public function test_the_counters_chosen_free_lot_is_counted_under_a_lock(): void
    {
        $lot = Batch::query()->where('product_id', $this->product->id)->where('batch_no', 'A')->firstOrFail();

        $have = null;
        $sums = $this->lotSumsWhile(function () use ($lot, &$have) {
            $have = DB::transaction(fn () => app(BatchAllocator::class)->lockedFreeBalance($lot, $this->warehouse));
        }, 'free_change');

        $this->assertSame(0, bccomp((string) $have, '5', 4));
        $this->assertNotEmpty($sums);

        foreach ($sums as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
    }

    /**
     * @return list<string> লট ধরে যোগফলের কোয়েরিগুলো
     */
    private function lotSumsWhile(callable $work, string $column): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $work();

        return array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'sum(')
            && str_contains($sql, $column)
            && str_contains($sql, 'batch_id')));
    }
}
