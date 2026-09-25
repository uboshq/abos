<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeRatio;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফ্রি কার্টন এক অনুপাতে এসেছিল, আর বেরোত অন্য অনুপাতে।
 *
 * ── ⭐ মালিকের নিয়ম, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"যে স্লট যেভাবে কেনা, সেইভাবে যাবে… ফ্রি কম দিতে পারবে কিন্তু কোন
 * ভাবেই বেশি দিতে পারবে না।"*
 *
 * ⓘ আজ ব্যবস্থা ফ্রি মালের **পরিমাণ** জানে, কিন্তু **অনুপাত** জানে না।
 * ⛔ ফলে দশ কার্টনে এক ফ্রি পাওয়া মালে কেউ চাইলে দশ কার্টনেই দশ ফ্রি
 * দিয়ে দিতে পারতেন — ভাণ্ডারে থাকা পর্যন্ত কিছুই আটকাত না।
 *
 * ── ⓘ কেন হিসাবটা লট ধরে ────────────────────────────────────────────
 * একই পণ্য বহুবার কেনা হয়, প্রতিবার অনুপাত আলাদা। ⭐ লট ধরে হিসাব
 * করলে *"আজ কোন অনুপাত"* প্রশ্নটাই উবে যায়।
 */
final class TheFreeCartonsCameInARatioAndLeftInAnotherTest extends TestCase
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

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ লটে যা এসেছিল, হিসাব ঠিক তাই বলে।
     */
    public function test_a_lot_remembers_what_came_with_it(): void
    {
        $lot = $this->goodsArrived(paid: '100', free: '10');

        $this->assertSame(
            ['paid' => '100.0000', 'free' => '10.0000'],
            app(FreeRatio::class)->arrivedIn($lot),
            'লটটা মনে রাখেনি কত মাল আর কত ফ্রি এসেছিল।',
        );
    }

    /**
     * ⭐ দশে এক অনুপাতে বিশ কার্টনে দুই ফ্রি।
     */
    public function test_the_ratio_the_supplier_gave_is_the_ratio_that_leaves(): void
    {
        $lot = $this->goodsArrived(paid: '100', free: '10');

        $this->assertSame(
            '2',
            app(FreeRatio::class)->allowedOn($lot, '20'),
            'অনুপাতটা মানা হয়নি।',
        );
    }

    /**
     * ⛔ ভাঙা সংখ্যা হলে নিচের দিকে — এক কার্টনও বেশি নয়।
     *
     * ── ⚠️ কেন ─────────────────────────────────────────────────────
     * প্রাপ্য ১.৫ মানে দেড় কার্টন, আর **দেড় কার্টন ফ্রি দেওয়ার কোনো
     * বাস্তব রূপ নেই** — কার্টন কেটে অর্ধেক দিলে সেটা আর ফ্রি কার্টন
     * থাকে না। ⓘ মালিকের মূল নীতির সাথেও মেলে: *"কোনোভাবেই বেশি নয়।"*
     */
    public function test_a_half_carton_is_never_given(): void
    {
        $lot = $this->goodsArrived(paid: '100', free: '10');

        $this->assertSame('1', app(FreeRatio::class)->allowedOn($lot, '15'), '১.৫ কে উপরে গোল করা হয়েছে।');
        $this->assertSame('0', app(FreeRatio::class)->allowedOn($lot, '9'), 'এক কার্টনও পূর্ণ হয়নি, তবু ফ্রি দেওয়া হচ্ছে।');
    }

    /**
     * ⛔ যে লটে ফ্রি আসেনি, তাতে ফ্রি নেই।
     *
     * ⓘ মালিকের কথা: *"যা সব প্রোডাক্ট ফ্রি আসে নাই সেইগুলাতে ফ্রি দিতে
     * পারবে না।"*
     */
    public function test_a_lot_that_came_without_free_gives_none(): void
    {
        $lot = $this->goodsArrived(paid: '50', free: '0');

        $this->assertSame('0', app(FreeRatio::class)->allowedOn($lot, '50'), 'ফ্রি না আসা লট থেকেও ফ্রি দেওয়া যাচ্ছে।');
    }

    /**
     * ⭐ দুইটা লট, দুই অনুপাত — আর একটা অন্যটার হিসাবে ঢোকে না।
     *
     * ── ⚠️ কেন এই দাবিটাই সবচেয়ে জরুরি ─────────────────────────────
     * ⓘ পুরো নকশাটা এই একটা কথার উপর দাঁড়িয়ে: **অনুপাত লটের, পণ্যের
     * নয়**। ⛔ হিসাবটা যদি ভুল করে গোটা পণ্যের সব আগমন যোগ করত, তবু
     * উপরের প্রতিটা দাবি সবুজ থাকত — কারণ ওখানে লট একটাই।
     */
    public function test_two_lots_keep_their_own_ratios(): void
    {
        $generous = $this->goodsArrived(paid: '100', free: '10', lot: 'LOT-A');
        $dry = $this->goodsArrived(paid: '100', free: '0', lot: 'LOT-C');

        $ratio = app(FreeRatio::class);

        $this->assertSame('2', $ratio->allowedOn($generous, '20'), 'উদার লটটা নিজের অনুপাত হারিয়েছে।');
        $this->assertSame('0', $ratio->allowedOn($dry, '20'), 'ফ্রি-হীন লটে অন্য লটের অনুপাত এসে বসেছে।');
    }

    /**
     * ⓘ আর ফ্রি মাল বেরিয়ে গেলেও অনুপাত বদলায় না।
     *
     * ── ⚠️ কেন ─────────────────────────────────────────────────────
     * অনুপাতটা **কত এসেছিল** তার হিসাব, **আজ কত পড়ে আছে** তার নয়।
     * ⛔ ভাণ্ডারের যোগফল ধরে হিসাব করলে প্রথম খদ্দের ফ্রি নিয়ে গেলেই
     * পরের জনের প্রাপ্য কমে যেত — অথচ তিনি একই মাল একই দামে কিনছেন।
     *
     * ⓘ ভাণ্ডারে ফ্রি ফুরালে সেটা **আলাদা সীমা**, আর দুইটার মধ্যে যেটা
     * ছোট সেটাই শেষ কথা।
     */
    public function test_the_ratio_does_not_shrink_as_free_goods_leave(): void
    {
        $lot = $this->goodsArrived(paid: '100', free: '10');

        /*
         * ⓘ আগে বসানো, তারপর বেরোনো — বাস্তবের ধাপটাই।
         *
         * ⚠️ প্রথমে এই ধাপটা বাদ দিয়েছিলাম, আর পরীক্ষা লাল হয়ে মনে
         * করিয়ে দিল: ক্রয়ের ফ্রি মাল `unplaced_free`-এ বসে, আর
         * **বসানোর আগে সে বিক্রয়যোগ্য নয়** — মালিকের নিয়ম।
         */
        $stock = app(StockService::class);

        $stock->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '100',
            sourceType: 'purchase_bill',
            sourceId: 7001,
            batch: $lot,
            freeQty: '10',
        );

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'sales_invoice',
            sourceId: 9001,
            free: '-8',
            batch: $lot,
        );

        $this->assertSame(
            '2',
            app(FreeRatio::class)->allowedOn($lot, '20'),
            'ফ্রি মাল বেরোনোর পর অনুপাতটা কমে গেছে।',
        );
    }

    /**
     * একটা লট, আর তার সাথে আসা টাকার ও ফ্রি মাল — ক্রয় যেভাবে লেখে।
     */
    /**
     * ⭐ আর কতটা নিলে পরের ফ্রি — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ তাঁর উদাহরণ, হুবহু ───────────────────────────────────────
     * *"২৪ ctn e ১ ctn hole kew zodi ২০ purches kore take warning
     * masses dibe but atkabe na"*।
     *
     * ⚠️ সংখ্যাটাই বলা হয় (*"আর ৪"*), *"২৪ লাগে"* নয় — ⛔ দ্বিতীয়টা
     * বললে বিক্রেতাকে গ্রাহকের সামনে দাঁড়িয়ে বিয়োগ করতে হত।
     */
    public function test_it_says_how_much_more_earns_the_next_free(): void
    {
        $lot = $this->goodsArrived(paid: '24', free: '1');

        $ratio = app(FreeRatio::class);

        $this->assertSame('4', $ratio->shortOfNextFree($lot, '20'),
            '⛔ ২৪-এ ১ অনুপাতে ২০ কার্টনের পর আর ৪ লাগে।');

        $this->assertSame('0', $ratio->allowedOn($lot, '20'),
            '⛔ ২৪ পূর্ণ হয়নি, তবু ফ্রি পাওনা দেখাচ্ছে।');
    }

    /**
     * ⭐ ঠিক অনুপাতে পৌঁছালে পরেরটা পুরো এক ধাপ দূরে।
     *
     * ⚠️ এটাই `upTo()`-র আসল পরীক্ষা: ২৪-এ দাঁড়িয়ে উত্তরটা ০ হলে
     * বার্তাটা দেখাতই না, আর ২৪ হলে বিক্রেতা ভাবতেন আরও ২৪ লাগবে
     * দ্বিতীয়টার জন্য — ⓘ যা সত্যি।
     */
    public function test_standing_exactly_on_the_ratio_the_next_one_is_a_full_step_away(): void
    {
        $lot = $this->goodsArrived(paid: '24', free: '1');

        $this->assertSame('1', app(FreeRatio::class)->allowedOn($lot, '24'),
            '⛔ ২৪ কার্টনে ১ ফ্রি পাওনা।');

        $this->assertSame('24', app(FreeRatio::class)->shortOfNextFree($lot, '24'),
            '⛔ দ্বিতীয় ফ্রি-র জন্য আরও ২৪ লাগে।');
    }

    /**
     * ⛔ অনুপাত না থাকা লটে কোনো বার্তা নয় — মালিকের সম্মতিতে।
     *
     * ⚠️ শূন্য ফেরালে পর্দা চুপ থাকে। ⓘ নাহলে ডিপোর চাল-ডাল-সাবানের
     * প্রতিটা সারিতে একটা অর্থহীন *"আর কত নিলে ফ্রি"* বসত — অথচ ঐ
     * লটে ফ্রি বলে কিছু আসেইনি।
     */
    public function test_a_lot_that_came_without_free_says_nothing(): void
    {
        $dry = $this->goodsArrived(paid: '100', free: '0', lot: 'LOT-DRY');

        $this->assertSame('0', app(FreeRatio::class)->shortOfNextFree($dry, '20'),
            '⛔ ফ্রি না আসা লটেও "আর কত নিলে ফ্রি" বলা হচ্ছে।');
    }

    /**
     * ⭐ দুইটা লট দুই অনুপাতে — উত্তরও দুই রকম।
     *
     * ⛔ এটাই সেই কারণ যে কাউন্টার এখন **বাছা লটের** অনুপাত জিজ্ঞেস
     * করে, FEFO-র নয়। ⚠️ একটা লটের উত্তর অন্যটায় বসালে ফ্রি ভুল
     * বসত, নীরবে — কারণ সংখ্যাটা দেখতে নিখুঁতই লাগত।
     */
    public function test_two_lots_two_answers(): void
    {
        $tight = $this->goodsArrived(paid: '24', free: '1', lot: 'LOT-TIGHT');
        $loose = $this->goodsArrived(paid: '10', free: '1', lot: 'LOT-LOOSE');

        $ratio = app(FreeRatio::class);

        /*
         * ⚠️ এই দাবিটা একবার ভুল লেখা হয়েছিল, আর ভুলটা শিক্ষণীয়।
         *
         * ⛔ প্রথমে লেখা ছিল `'0'` — যুক্তি ছিল *"২০ কার্টনে দুইটা ফ্রি
         * পূর্ণ, তাই আর কিছু লাগে না"*। ⓘ কিন্তু পদ্ধতিটা ঐ প্রশ্নের
         * উত্তর দেয় না; সে বলে **পরেরটার জন্য আর কত** — আর অনুপাত
         * থাকলে ঐ সংখ্যাটা কখনো শূন্য হয় না।
         *
         * ⭐ অর্থাৎ লালটা পড়েছিল কোডের উপর, অথচ ভুলটা ছিল দাবিতে।
         * ⚠️ দাবিটা শিথিল না করে **প্রশ্নটা ঠিক করা হলো**।
         */
        $this->assertSame('4', $ratio->shortOfNextFree($tight, '20'),
            '⛔ ২৪-এ ১ অনুপাতে ২০ কার্টনের পর তৃতীয় ধাপ আরও ৪ দূরে।');

        $this->assertSame('2', $ratio->allowedOn($loose, '20'),
            '⛔ ১০-এ ১ অনুপাতে ২০ কার্টনে দুইটা ফ্রি পাওনা।');

        $this->assertSame('10', $ratio->shortOfNextFree($loose, '20'),
            '⛔ দুইটা পূর্ণ হয়েছে, আর তৃতীয়টার জন্য আরও ১০ লাগে।');
    }

    private function goodsArrived(string $paid, string $free, string $lot = 'LOT-1'): Batch
    {
        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => $lot,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $stock = app(StockService::class);

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill',
            sourceId: 7001,
            unplaced: $paid,
            batch: $batch,
        );

        if (bccomp($free, '0', 4) > 0) {
            /* ⓘ ফ্রি মাল নিজের উৎস-নামে — ক্রয় ঠিক এভাবেই লেখে */
            $stock->move(
                product: $this->product,
                warehouse: $this->warehouse,
                sourceType: 'purchase_bill:free',
                sourceId: 7001,
                unplacedFree: $free,
                batch: $batch,
            );
        }

        return $batch->fresh();
    }
}
