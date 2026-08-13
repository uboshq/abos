<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchAllocator;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুই কাউন্টার, একটাই শেষ পাতা।
 *
 * ── প্ল্যানের শেষ শর্তগুলোর একটা ─────────────────────────────────────
 * "দুই কাউন্টার একসাথে শেষ পাতাটা বেচার চেষ্টা করলে একজন পান, একজন
 * পান না — মজুদ ঋণাত্মক হয় না।"
 *
 * ── কেন RefreshDatabase নয় ───────────────────────────────────────────
 * ওটা পুরো টেস্টকে একটা লেনদেনে মুড়ে রাখে, তাই দ্বিতীয় সংযোগ টেস্টে
 * বানানো সারিগুলো দেখতেই পায় না — আর তখন "দৌড়" বলে কিছু ঘটে না।
 * এখানে সত্যিকারের দুইটা সংযোগ লাগে, তাই মাইগ্রেশন ধরে চালানো হয়।
 *
 * ── কেন থ্রেড নয় ────────────────────────────────────────────────────
 * PHP এক সুতোয় চলে। বদলে দুইটা সংযোগে হাতে হাতে সাজানো হয়: প্রথমটা
 * তালা নেয় আর ধরে রাখে, দ্বিতীয়টা একই সারিতে হাত দিতে গিয়ে অপেক্ষায়
 * আটকে যায় — আর সেটাই প্রমাণ। তালা না থাকলে দ্বিতীয়টা সাথে সাথেই
 * পড়ে ফেলত, দুইজনেই "আছে" দেখত, আর দুইটা বিল ছাপা হয়ে যেত।
 */
class TwoCountersRaceTest extends TestCase
{
    use DatabaseMigrations;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * দ্বিতীয় কাউন্টার — আলাদা সংযোগ, একই ডাটাবেজ।
         *
         * অপেক্ষার সময় এক সেকেন্ড: তালাটা আছে কি না সেটা জানতে এক
         * সেকেন্ডই যথেষ্ট, আর না থাকলে টেস্টটা পঞ্চাশ সেকেন্ড ঝুলে
         * থেকে তারপর ব্যর্থ হত।
         */
        config(['database.connections.counter_two' => config('database.connections.mysql')]);
        DB::purge('counter_two');
        DB::connection('counter_two')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /**
     * তাকের শেষ মালটা — প্রথম কাউন্টার তালা দিলে দ্বিতীয়টা ঢুকতে পারে না।
     *
     * এটাই মূল দাবি। তালা ছাড়া দুইজনেই যোগফলটা পড়ত, দুইজনেই "আছে"
     * দেখত, আর মজুদ ঋণাত্মকে নামত — খাতা বলত এমন মাল বেরিয়েছে যা
     * কোনোদিন ছিল না।
     */
    public function test_the_second_counter_waits_for_the_first(): void
    {
        DB::beginTransaction();

        try {
            // প্রথম কাউন্টার: মাল বের করে, কিন্তু এখনো commit করেনি
            app(StockService::class)->move(
                product: $this->product,
                warehouse: $this->warehouse,
                sourceType: 'test_race',
                sourceId: 1,
                floor: '-1',
            );

            $this->expectException(QueryException::class);

            // দ্বিতীয় কাউন্টার: একই সারিগুলোয় হাত দিতে গিয়ে আটকে যায়
            DB::connection('counter_two')->transaction(function () {
                DB::connection('counter_two')
                    ->table('inv_stock_movements')
                    ->where('product_id', $this->product->id)
                    ->where('warehouse_id', $this->warehouse->id)
                    ->lockForUpdate()
                    ->sum('floor_change');
            });
        } finally {
            DB::rollBack();
        }
    }

    /**
     * লট ধরা পণ্যেও একই পাহারা।
     *
     * FEFO যে লটটা বেছেছে সেটার উপরেই তালা পড়ে। না পড়লে দুই কাউন্টার
     * একই লট থেকে বেচত, লট ঋণাত্মকে যেত, আর রিকলের খাতা বলত এমন
     * বাক্স থেকে মাল বেরিয়েছে যা আগেই খালি ছিল।
     */
    public function test_a_lot_is_locked_while_it_is_being_allocated(): void
    {
        $this->product->forceFill(['track_batch' => true])->save();

        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'RACE1',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $batch->id,
            floor: '1',
            batch: $batch,
        );

        DB::beginTransaction();

        try {
            // প্রথম কাউন্টার শেষ পিসটা বরাদ্দ নেয় — লটে তালা পড়ে
            app(BatchAllocator::class)->allocate($this->product, $this->warehouse, '1');

            $this->expectException(QueryException::class);

            DB::connection('counter_two')->transaction(function () use ($batch) {
                DB::connection('counter_two')
                    ->table('inv_batches')
                    ->where('id', $batch->id)
                    ->lockForUpdate()
                    ->first();
            });
        } finally {
            DB::rollBack();
        }
    }

    /**
     * অতিরিক্ত বিক্রির চেষ্টা ফিরিয়ে দেওয়া হয়, আর মজুদ ঋণাত্মক হয় না।
     *
     * তালার পরের প্রশ্নটা: অপেক্ষা শেষে দ্বিতীয়জন কী দেখেন। উত্তর —
     * শূন্য, আর তাই তিনি ফিরে যান।
     */
    public function test_the_shelf_never_goes_negative(): void
    {
        $onFloor = (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');

        // পুরোটা বের করে নেওয়া — এটুকু চলে
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_race',
            sourceId: 2,
            floor: bcmul($onFloor, '-1', 4),
        );

        // তারপর আর একটাও নয়
        try {
            app(StockService::class)->move(
                product: $this->product,
                warehouse: $this->warehouse,
                sourceType: 'test_race',
                sourceId: 3,
                floor: '-1',
            );

            $this->fail('তাকে যা নেই তা বেরিয়ে গেল');
        } catch (ValidationException) {
            // আশা করাই হচ্ছিল
        }

        $after = (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');

        $this->assertSame(0, bccomp('0', $after, 4), "মজুদ ঋণাত্মক: {$after}");
    }
}
