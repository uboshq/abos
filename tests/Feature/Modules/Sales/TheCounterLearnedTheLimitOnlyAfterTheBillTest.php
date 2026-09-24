<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * কাউন্টার সীমাটা জানত কেবল বিল শেষ করার পর।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"অনুপাতের বেশি ফ্রি দিলে বিল প্রডাক্ট এন্টিতেই আটকে যাবে, কার্টে
 * যোগ হবে না আর ওয়ার্নিং দিবে ফ্রি এতটা দেওয়া যাবে"*।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * ⓘ দেয়ালটা ছিল, কিন্তু কথা বলত **সেভ করার সময়** — অর্থাৎ ত্রিশটা সারি
 * তোলার পরে। ⚠️ তখন কোন সারিটা দোষী তা খুঁজতে হত, আর সংখ্যাটা কত হলে
 * চলত সেটা কেউ বলত না।
 *
 * ── ⚠️ এই দরজাটা দেয়াল নয়, দেয়ালটা কোথায় তা আগে বলা ────────────────
 * ⓘ আসল দেয়াল [[DirectSaleService]]-এ, আর সেটা এখানেও অক্ষত।
 * ⛔ শুধু পর্দায় আটকালে অন্য পথে আসা বিল — কাউন্টার, আদেশ, কালকের নতুন
 * পর্দা — প্রতিটাই একটা করে ফাঁক হত।
 */
final class TheCounterLearnedTheLimitOnlyAfterTheBillTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /* ⓘ নিজের গুদাম — ডেমোরটায় ঐ পণ্যের অন্য মালও আছে */
        $this->warehouse = Warehouse::query()->create([
            'code' => 'FREEWH',
            'name_en' => 'Free ratio store',
            'name_bn' => 'ফ্রি অনুপাতের গুদাম',
            'is_active' => true,
        ]);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->track_batch = true;
        $this->product->save();

        /* ⓘ ১০০ কার্টনে ১০ ফ্রি — অর্থাৎ ২০ কার্টনে প্রাপ্য ২ */
        $this->stocked(paid: '100', free: '10');
    }

    /**
     * ⭐ দরজাটা সংখ্যাটাই বলে।
     *
     * ── ⚠️ কেন সংখ্যাটা, "বেশি হয়েছে" নয় ───────────────────────────
     * ⓘ সীমাটা না বললে মানুষ কমাতে কমাতে চেষ্টা করতেন, আর প্রতিবার
     * একটা করে অনুরোধ যেত। ⛔ পর্দার বার্তাটাও এই সংখ্যাটাই ছাপে।
     */
    /*
     * ⚠️ তুলনাটা সংখ্যায়, লেখায় নয় — আর এটা মেপে শেখা।
     *
     * ⓘ প্রথমে `'2.00000000'` লেখা হয়েছিল, আর চারটা দাবিই লাল হলো:
     * উত্তরটা এল `'2.0000'`। ⛔ সংখ্যা দুইটাই দুই, কেবল লেখা আলাদা।
     *
     * ⚠️ লেখা মিলালে দাবিটা `bcdiv`-এর দশমিক সংখ্যার উপর দাঁড়াত —
     * ⓘ যেটা ব্যবসার কোনো নিয়ম নয়, কেবল একটা অ্যার্গুমেন্ট।
     */
    public function test_the_door_says_how_much_free_may_go(): void
    {
        $this->getJson(route('sales.direct.free_allowed', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '20',
        ]))
            ->assertOk()
            ->assertJsonPath('data.known', true)
            ->assertJsonPath('data.allowed',
                fn ($got) => bccomp((string) $got, '2', 4) === 0);
    }

    /**
     * ⭐ আর বেশি মাল নিলে প্রাপ্যও বাড়ে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা একা থাকলে **একটা স্থির সংখ্যা** ফেরত দেওয়া দরজাও সবুজ
     * পেত। ⛔ আর তখন দশ কার্টনে আর একশো কার্টনে একই ফ্রি দেখাত।
     */
    public function test_a_bigger_line_earns_more(): void
    {
        $this->getJson(route('sales.direct.free_allowed', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '50',
        ]))
            ->assertOk()
            ->assertJsonPath('data.allowed',
                fn ($got) => bccomp((string) $got, '5', 4) === 0);
    }

    /**
     * ⛔ যে লটে ফ্রি আসেনি, সেখানে শূন্য।
     *
     * ⓘ মালিকের কথা: *"যা সব প্রোডাক্ট ফ্রি আসে নাই সেইগুলাতে ফ্রি দিতে
     * পারবে না"*।
     */
    public function test_a_product_that_came_without_free_earns_none(): void
    {
        $bare = Product::query()->whereKeyNot($this->product->id)->firstOrFail();
        $bare->track_batch = true;
        $bare->save();

        $this->stocked(paid: '100', free: '0', product: $bare, lot: 'LOT-DRY');

        $this->getJson(route('sales.direct.free_allowed', [
            'product_id' => $bare->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '50',
        ]))
            ->assertOk()
            ->assertJsonPath('data.allowed',
                fn ($got) => bccomp((string) $got, '0', 4) === 0);
    }

    /**
     * ⛔ আর অন্য কোম্পানির পণ্যের কথা এই দরজা বলে না।
     *
     * ── ⚠️ কেন এই দাবিটা লাগে ───────────────────────────────────────
     * ⓘ দরজাটা একটা সংখ্যা ফেরত দেয়, আর সংখ্যাটা ব্যবসার খবর: কার কাছে
     * কত ফ্রি এসেছিল। ⛔ ছাঁকনি না থাকলে ঠিকানা ধরে ধরে অন্য কোম্পানির
     * অনুপাত গোনা যেত।
     */
    public function test_another_companys_product_is_refused(): void
    {
        $theirs = Company::query()->where('id', '!=', CompanyContext::id())->first();

        $this->assertNotNull($theirs, 'ডেমোতে দ্বিতীয় কোম্পানিই নেই — দাবিটা কিছু মাপছে না।');

        /*
         * ⚠️ পণ্যটা সরাসরি সারি হিসেবে বসানো হয়, Eloquent দিয়ে নয়।
         *
         * ── ⛔ প্রথম দুইটা চেষ্টা কীভাবে ভেঙেছে ─────────────────────────
         * ⓘ প্রথমে ডেমোর দ্বিতীয় কোম্পানির প্রথম পণ্যটা নেওয়া হয়েছিল:
         * ডেমোতে ওদের কোনো পণ্যই নেই।
         *
         * ⓘ তারপর `CompanyContext::forCompany()`-র ভিতরে বানানো হয়েছিল,
         * আর উত্তরটা এল **302 — হোমে রিডাইরেক্ট**, ৪২২ নয়। ⛔ অর্থাৎ
         * অনুরোধটা যাচাই পর্যন্ত পৌঁছায়ইনি; কোম্পানি বদলে যাওয়ায় মাঝপথেই
         * ফিরে গেছে। ⚠️ দাবিটা তখন ছাঁকনির কথা মাপত না, প্রসঙ্গ বদলানোর
         * পার্শ্বপ্রতিক্রিয়া মাপত — আর সবুজ হলেও কিছুই প্রমাণ হত না।
         *
         * ⭐ `DB::table()` বৈশ্বিক স্কোপ মানে না, তাই এখানে কোম্পানি না
         * বদলেই অন্য কোম্পানির একটা সারি বসানো যায় — ঠিক যেমনটা দরকার।
         */
        $strangerId = DB::table('inv_products')->insertGetId([
            'company_id' => $theirs->id,
            'public_id' => (string) Str::uuid7(),
            'code' => 'FOREIGN-1',
            'name_en' => 'Their product',
            'name_bn' => 'ওদের পণ্য',
            'unit_id' => $this->product->unit_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson(route('sales.direct.free_allowed', [
            'product_id' => $strangerId,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '10',
        ]))->assertStatus(422);
    }

    /** একটা লট, তার টাকার ও ফ্রি মাল, আর দুইটাই বসানো। */
    private function stocked(string $paid, string $free, ?Product $product = null, string $lot = 'LOT-1'): void
    {
        $product ??= $this->product;
        $stock = app(StockService::class);

        $batch = Batch::query()->create([
            'product_id' => $product->id,
            'batch_no' => $lot,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $stock->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 4001,
            unplaced: $paid, batch: $batch,
        );

        if (bccomp($free, '0', 4) > 0) {
            $stock->move(
                product: $product, warehouse: $this->warehouse,
                sourceType: 'purchase_bill:free', sourceId: 4001,
                unplacedFree: $free, batch: $batch,
            );
        }

        $stock->place(
            product: $product, warehouse: $this->warehouse,
            qty: $paid, sourceType: 'purchase_bill', sourceId: 4001,
            batch: $batch, freeQty: $free,
        );
    }
}
