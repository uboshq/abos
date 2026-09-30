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
     * নিজের আবর্জনা নিজে তুলে নেওয়া — ৬ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কী ভাঙা ছিল, আর কেন ধরতে তিন ঘণ্টা লাগল ──────────────────
     * এই ফাইলটা `DatabaseMigrations` ব্যবহার করে, `RefreshDatabase` নয় —
     * আর সেটা **ইচ্ছাকৃত ও সঠিক**: দৌড়ের পরীক্ষা এক লেনদেনের ভিতরে
     * প্রমাণ হয় না, দুইটা সংযোগকে সত্যিই একই সারিতে ধাক্কা খেতে হয়।
     *
     * ⚠️ কিন্তু তার মানে এখানকার লেখাগুলো **কমিট হয়ে যায়**, আর
     * `DemoSeeder` ঠিক দুইটা কোম্পানি বানায় (TDEPOT ও FMART)। ⓘ সেগুলো
     * ডাটাবেজে পড়ে থাকত, আর তারপর যে টেস্টই `DemoSeeder` সিড করত সে
     * `companies_code_unique`-এ গিয়ে ধাক্কা খেত।
     *
     * ⛔ ফল: দ্বিতীয় পূর্ণ রানে **২৮১টা ত্রুটি, যার ২৪৭টাই এই এক লাইন**।
     * আর তার নিচের ঢেউ আরও বিভ্রান্তিকর — *"account 1101 does not exist
     * in this company"* — কারণ কোম্পানির প্রসঙ্গটাই ভুল হয়ে যেত।
     *
     * ⚠️ **সবচেয়ে খারাপ দিকটা এলোমেলোপনা**: প্রথম রানে ত্রুটি ছিল শূন্য,
     * দ্বিতীয়তে ২৮১ — পার্থক্যটা কেবল **এই ফাইলটা কোন ক্রমে চলেছে**।
     * ⓘ শেষে চললে কিছুই নষ্ট হয় না; শুরুতে চললে বাকি সব নষ্ট। ⛔ তাই
     * একই কোড একবার সবুজ, একবার লাল — আর কেউ কোডে কারণ খুঁজে পেত না।
     *
     * ⭐ সারাইটা ছোট: যা কমিট করেছি, তা তুলে নেওয়া। ⓘ `migrate:fresh`
     * পরের ক্লাসে আবার চলে, কিন্তু **এই ক্লাসের নিজের সারিগুলো**
     * তার আগেই পরের টেস্টের সিডে ধাক্কা দিত।
     */
    protected function tearDown(): void
    {
        /*
         * ⚠️ বিদেশি চাবির ছাঁকনি বন্ধ করে, তারপর টেবিলগুলো খালি।
         *
         * ⓘ কেবল `companies` মুছলে হত না — তার সন্তানরা (শাখা, ব্যবহারকারী,
         * পণ্য, মজুদের চলাচল) পড়ে থাকত, আর পরের সিড ওখানেই আটকাত।
         * ⭐ তাই পুরো সিডের ছাপটাই তোলা হয়, একই ক্রমে যেভাবে সে বসেছিল।
         */
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table === 'migrations') {
                continue;
            }

            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::purge('counter_two');

        parent::tearDown();
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
     * ⭐ কাউন্টারে **নিজে বাছা** লটেও তালা, আর লটের মাল গোনা হয় তালার **পরে** — চূড়ান্ত অডিট ⛔৫,
     * ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────
     * ওপরের দাবিটা কেবল FEFO-র পথ দেখে ([[BatchAllocator::candidates()]])। অথচ মালিকের সিদ্ধান্তে (২৫
     * সেপ্টেম্বর) কাউন্টারে লট বাছা **বাধ্যতামূলক** — বিক্রির আসল পথ `issue(batch: …)`, আর সেখানে লটের
     * মাল গোনা হত তালা ছাড়া। ⚠️ দুই কাউন্টার একই লটে ১০ দেখে দুজনেই ৮ বেচতেন; পণ্যের মোটের পাহারা
     * ([[StockService::assertEnoughOnFloor()]]) দুজনকে পালা করে চালাত, কিন্তু পণ্যের অন্য লটে মাল থাকলে
     * দুজনকেই ছেড়ে দিত — লট −৬-এ যেত, আর রিকলের খাতা বলত খালি বাক্স থেকে মাল বেরিয়েছে।
     *
     * ── ⚠️ কেন কোয়েরির ক্রম মাপা, দুই সংযোগের ধাক্কা নয় ─────────────────
     * প্রথম খসড়ায় দ্বিতীয় সংযোগ দিয়ে লটের সারি ছুঁয়ে দেখা হয়েছিল — আর সেটা **সারাই ছাড়াই সবুজ** ছিল:
     * চলাচলের সারি `batch_id` দিয়ে লেখার সময় MySQL বিদেশি চাবির জন্য লটের সারিতে নিজেই ভাগের তালা
     * বসায়, তাই দ্বিতীয়জন আটকাত — অথচ গোনাটা তখনো তালা ছাড়াই হচ্ছিল। ⓘ তাই দাবিটা সরাসরি কথাটা
     * মাপে: লটের সারিতে `FOR UPDATE` আগে, আর লটের তাকের মাল গোনা তার পরে, সেটাও তালাসহ (নইলে
     * আগের snapshot পড়ত)।
     */
    public function test_a_chosen_lot_is_counted_only_after_it_is_locked(): void
    {
        $this->product->forceFill(['track_batch' => true])->save();

        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'CHOSEN1',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $batch->id,
            floor: '10',
            batch: $batch,
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        app(StockService::class)->issue(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_sale',
            sourceId: $batch->id,
            qty: '8',
            batch: $batch,
        );

        $lockAt = null;
        $countAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'inv_batches') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($countAt === null && str_contains($sql, 'floor_change') && str_contains($sql, 'batch_id') && str_contains($sql, 'sum(')) {
                $countAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'বাছা লটের সারিতে কোনো তালা পড়েনি — দুই কাউন্টার একই লট ঋণাত্মকে নিতে পারে।');
        $this->assertNotNull($countAt, 'লটের তাকের মাল গোনার কোয়েরিই পাওয়া গেল না — দাবিটা কিছু মাপছে না।');
        $this->assertLessThan($countAt, $lockAt, 'লটের মাল গোনা হয়েছে তালার আগে — দ্বিতীয় কাউন্টার পুরনো সংখ্যা দেখবে।');
        $this->assertStringContainsString('for update', $queries[$countAt], 'লটের মাল গোনা তালা ছাড়া — আগের snapshot পড়ে ঋণাত্মক মাল ছাড়তে পারে।');

        // ⓘ আর কাজের ফল: ৮ বেরিয়েছে, লটে ২ আছে — ঋণাত্মক নয়
        $this->assertSame(0, bccomp($batch->floorBalance($this->warehouse), '2', 4));
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
