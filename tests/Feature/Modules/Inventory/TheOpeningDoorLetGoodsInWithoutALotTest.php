<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Imports\OpeningStockImporter;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\OpeningStockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নিয়মটা বসানো হয়েছিল দুইটা দরজায়, সব দরজায় নয়।
 *
 * ── ⭐ মালিকের নিয়ম, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"লট ছাড়া মাল ঢুকবেও না বেরোবেও না।"*
 *
 * ── ⓘ যা আগে থেকেই ঠিক ছিল ──────────────────────────────────────────
 * ক্রয়ের দুইটা পথ — চালান আর বিল — লট ধরা পণ্যে লট নম্বর ছাড়া মাল
 * নিত না ([[BringsInLots]])। ⭐ ওখানে নিয়মটা সত্যিই বসানো।
 *
 * ── ⛔ আর যেখানে বসানো ছিল না ────────────────────────────────────────
 * **শুরুর মজুদের দরজাটা লটের কথা জানতই না।** ⚠️ অথচ একটা নতুন কোম্পানির
 * গোটা মজুদ ঠিক ঐ দরজা দিয়েই ঢোকে — চারশো পণ্য, এক ফাইলে।
 *
 * ⓘ ফল: ব্যবস্থা চালু করার **প্রথম দিনেই** সব মাল লট ছাড়া ঢুকত, আর
 * দ্বিতীয় দিনে তার একটাও বেচা যেত না। ⛔ কোনো ত্রুটি নয়, কোনো লাল নয় —
 * কেবল একটা গুদাম ভর্তি মাল যা বিক্রয়ের বাছাইয়ে আসে না।
 *
 * ── ⚠️ শিক্ষাটা ─────────────────────────────────────────────────────
 * পাহারা একটা **অবস্থা** আগলায়, একটা দরজা নয়। ⓘ নিয়মটা লেখা হয়েছিল
 * যে পথ দিয়ে মাল আসে বলে জানা ছিল সেই পথে; বাকি পথগুলো ততদিনে ছিল,
 * কেবল কেউ তাকায়নি।
 */
final class TheOpeningDoorLetGoodsInWithoutALotTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $tracked;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /* ⓘ নিজের গুদাম — ডেমোর গুদামে ঐ পণ্যের চলাচল আছে, আর তখন
           শুরুর মজুদের দরজাটা এমনিতেই বন্ধ (`stillOpen()`) */
        $this->warehouse = Warehouse::query()->create([
            'code' => 'OPNWH',
            'name_en' => 'Opening test store',
            'name_bn' => 'শুরুর পরীক্ষার গুদাম',
            'is_active' => true,
        ]);

        $products = Product::query()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $products, 'ডেমোতে দুইটা পণ্যও নেই — পরীক্ষাটা কিছুই দেখছে না।');

        $this->tracked = $products[0];
        $this->tracked->track_batch = true;
        $this->tracked->save();

        $this->plain = $products[1];

        $this->assertFalse((bool) $this->plain->track_batch,
            'দ্বিতীয় পণ্যটারও লট চালু — তাহলে "ছাড় দেওয়া" দাবিটা কিছুই মাপছে না।');
    }

    /**
     * ⛔ লট ধরা পণ্যে লট ছাড়া শুরুর মজুদ নয়।
     */
    public function test_opening_stock_without_a_lot_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->bringIn($this->tracked);
    }

    /**
     * ⭐ আর লট দিলে ঢুকে যায়।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা একা থাকলে **সব শুরুর মজুদ আটকে দিলেও** সবুজ থাকত — আর
     * তখন নতুন কোনো কোম্পানি ABOS চালুই করতে পারত না। ⛔ দেয়ালটা লট
     * ছাড়া মালের জন্য, শুরুর মজুদের জন্য নয়।
     */
    public function test_with_a_lot_it_goes_in(): void
    {
        $movement = $this->bringIn($this->tracked, lot: 'LOT-START');

        $this->assertNotNull($movement->batch_id, 'মালটা ঢুকল ঠিকই, কিন্তু লট ছাড়াই।');
    }

    /**
     * ⓘ আর যে পণ্যে লট ধরা হয় না, তার কিছুই বদলায়নি।
     *
     * ── ⚠️ কেন এটা মাপা হয় ──────────────────────────────────────────
     * ⛔ ডিপোর চাল-ডাল-সাবানে লট নেই আর কোনোদিন হবেও না। নিয়মটা সবার
     * উপর বসালে ওদের প্রতিটা সারিতে একটা **বানানো** লট নম্বর বসত, আর
     * বানানো লট রিকলের খাতায় একটা মিথ্যা সারি।
     */
    public function test_a_product_without_lots_still_walks_in_freely(): void
    {
        $movement = $this->bringIn($this->plain);

        $this->assertNull($movement->batch_id, 'লট ধরা হয় না এমন পণ্যেও একটা লট বসে গেছে।');
    }

    /**
     * ⛔ আর ফাইল আমদানিও একই কথা বলে — সারিটা বসার **আগে**।
     *
     * ── ⚠️ কেন ফাইলের পথটা আলাদা করে মাপা ───────────────────────────
     * ⓘ শুরুর মজুদ বাস্তবে ফর্ম ধরে আসে না, ফাইল ধরে আসে — চারশো পণ্য
     * একসাথে। ⛔ দেয়ালটা কেবল সেবায় থাকলে ফাইলটা মাঝপথে একটা ব্যতিক্রমে
     * থামত, আর কোন সারিতে থেমেছে তা বলার উপায় থাকত না — অর্ধেক বসা,
     * অর্ধেক বাকি।
     */
    public function test_the_file_says_it_too_before_anything_is_written(): void
    {
        $importer = app(OpeningStockImporter::class);

        $errors = $importer->check($this->rowFor($this->tracked, lot: ''));

        $this->assertNotSame([], $errors, 'ফাইলে লট ছাড়া সারিটা নীরবে পার হয়ে যাচ্ছে।');
    }

    /**
     * ⭐ আর লট লেখা থাকলে ফাইলের সারিটা পার হয়।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⓘ [[OpeningStockImporter]]-এর যাচাই অন্য কোনো কারণেও ত্রুটি দিতে
     * পারে — অচেনা কোড, খালি গুদাম, ভুল সংখ্যা। ⛔ উপরের দাবিটা কেবল
     * "কিছু একটা ত্রুটি এসেছে" দেখে, আর সেটা লটের ত্রুটি কি না জানে না।
     *
     * ⭐ এখানে একই সারি, কেবল লট নম্বরটা বসানো — ত্রুটি চলে গেলে বোঝা
     * যায় ত্রুটিটা সত্যিই লটেরই ছিল।
     */
    public function test_the_same_row_passes_once_the_lot_is_written(): void
    {
        $importer = app(OpeningStockImporter::class);

        $this->assertSame(
            [],
            $importer->check($this->rowFor($this->tracked, lot: 'LOT-FILE')),
            'লট লেখার পরেও ফাইলের সারিটা আটকে আছে — ত্রুটিটা তাহলে অন্য কিছুর।',
        );
    }

    /** শুরুর মজুদ বসানো — লট দিলে লট ধরে। */
    private function bringIn(Product $product, string $lot = ''): \App\Modules\Inventory\Models\StockMovement
    {
        $batch = $lot === '' ? null : Batch::query()->create([
            'product_id' => $product->id,
            'batch_no' => $lot,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        return app(OpeningStockService::class)->bringIn(
            product: $product,
            warehouse: $this->warehouse,
            qty: '50',
            unitCost: '100',
            batch: $batch,
        );
    }

    /**
     * ফাইলের একটা সারি — যেভাবে ইমপোর্ট ওটা পায়।
     *
     * @return array<string, string>
     */
    private function rowFor(Product $product, string $lot): array
    {
        return [
            'product_code' => $product->code,
            'warehouse' => $this->warehouse->code,
            'qty' => '50',
            'unit_cost' => '100',
            'trx_date' => '',
            'batch_no' => $lot,
            'expiry_date' => '',
        ];
    }
}
