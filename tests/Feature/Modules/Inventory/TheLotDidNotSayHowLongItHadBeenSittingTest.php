<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Reports\StockReports;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * লটটা বলত কোন লট আর মেয়াদ কবে, কিন্তু কত দিন ধরে পড়ে আছে তা নয়।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"মজুদের বয়স আর লট দেখা যাবে: কোন লট, কত দিন ধরে আছে, মেয়াদ কবে।"*
 *
 * ── ⓘ তিনটার দুইটা আগেই ছিল ───────────────────────────────────────────
 * [[StockReports::stockByBatch()]] লট নম্বর আর মেয়াদ দুইটাই দিত। ⭐ তাই
 * এই কাজে নতুন কোনো পাতা, কলাম-মাইগ্রেশন বা টেবিল লাগেনি — কেবল
 * **কত দিন ধরে** সংখ্যাটা যোগ হয়েছে।
 *
 * ⓘ পাশের [[StockReports::expiring()]] উল্টো প্রশ্নের উত্তর দেয় —
 * *"কত দিন বাকি"*। ⚠️ দুইটা একই সংখ্যা নয়, আর একটা দিয়ে অন্যটা বের করা
 * যায় না: মেয়াদ না থাকা লটেরও বয়স থাকে।
 *
 * ── ⛔ আসল ফাঁদটা, আর সেটাই এই ফাইলের প্রধান দাবি ────────────────────
 * বয়স গোনা হয় লটটা **কখন এসেছিল** তা থেকে। ⚠️ সহজ পথটা ছিল
 * `MIN(m.trx_date)` — কিন্তু একই লটে বেরোনোর সারিও থাকে, আর একটা লট
 * থেকে বহুবার মাল বেরোয়।
 *
 * ⛔ ফল হত উল্টো: যে লট ছয় মাস পড়ে আছে আর গতকাল তার কিছু বিক্রি
 * হয়েছে, সেটা দেখাত **১ দিন পুরনো** — অর্থাৎ সংখ্যাটা ঠিক সেই লটগুলোকেই
 * নতুন দেখাত যেগুলো নিয়ে এখনই কিছু করা দরকার।
 *
 * ⭐ তাই গোনায় ধরা হয় কেবল সেই সারি যেটা মাল **এনেছে** — তাকে, ফ্রি,
 * বা বসার অপেক্ষায়।
 */
final class TheLotDidNotSayHowLongItHadBeenSittingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⓘ "আজ" জমিয়ে রাখা হয়, আর সেটা এই ফাইলে **কাজ করে** কেবল
         * কারণ রিপোর্টটা তারিখ অ্যাপ থেকে নেয়। ⚠️ `CURDATE()` হলে
         * জমানোটা ডাটাবেস মানত না আর দাবিগুলো দিন বদলালেই লাল হত।
         */
        Carbon::setTestNow('2026-09-28');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি ───────────────────────────────────────────────────

    public function test_the_lot_says_how_many_days_it_has_been_sitting(): void
    {
        $lot = $this->aLotThatArrived('L-1', on: '2026-09-08', qty: '10');

        $row = $this->rowFor('L-1');

        $this->assertSame(20, (int) $row->held_days,
            '৮ তারিখে এসেছে আর আজ ২৮ — ২০ দিন হওয়ার কথা।');

        // ⓘ লট আর মেয়াদ আগেই ছিল; দাবিটা নিশ্চিত করে ওরা যায়নি
        $this->assertSame('L-1', $row->batch_no);
        $this->assertSame($lot->expiry_date->toDateString(), (string) $row->expiry_date);
    }

    public function test_a_later_sale_does_not_change_how_old_the_lot_is(): void
    {
        /*
         * ⓘ পরের বিক্রয়ে বয়স বদলায় না, আর কারণটা সরল: `MIN` সবচেয়ে
         * পুরনো তারিখটাই নেয়।
         *
         * ⚠️ এই দাবিটা প্রথমে লেখা হয়েছিল *"ফাইলের সবচেয়ে গুরুত্বপূর্ণ
         * দাবি"* হিসেবে, ধরে নিয়ে যে সব সারি গুনলে বিক্রয়ের তারিখটা
         * জিতবে। ⛔ ধারণাটা ভুল ছিল, আর সেটা ধরা পড়েছে একটা মিউটেন্ট
         * **বেঁচে যাওয়ায়** — `MIN(m.trx_date)` বসিয়েও দাবিটা সবুজ ছিল।
         *
         * ⓘ তাই দাবিটা এখানে রইল, কিন্তু সৎ নামে: এটা একটা সাধারণ
         * পাল্টা-দাবি, পাহারা নয়। ⭐ আসল পাহারাটা নিচের দাবিটা।
         */
        $this->aLotThatArrived('L-2', on: '2026-09-08', qty: '10');

        app(StockService::class)->issue(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '3',
            sourceType: 'test:lot',
            sourceId: 2,
            date: '2026-09-27',
            batch: Batch::query()->where('batch_no', 'L-2')->firstOrFail(),
        );

        $this->assertSame(20, (int) $this->rowFor('L-2')->held_days);
    }

    /**
     * ⭐ পিছনের তারিখে বসানো একটা সংশোধন লটটাকে পুরনো বানায় না।
     *
     * ── ⛔ কী ঘটত ─────────────────────────────────────────────────────
     * বয়স গোনা হয় `MIN` দিয়ে, অর্থাৎ সবচেয়ে পুরনো তারিখ। ⚠️ তাই
     * বিপদটা পরের সারিতে নয়, **আগের** সারিতে: লটটা আসার আগের কোনো
     * তারিখে যদি একটা সারি বসে, সে-ই জিতে যায়।
     *
     * ⓘ এটা বাস্তবে ঘটে পিছনের তারিখে দেওয়া সংশোধনে — কেউ গত মাসের
     * একটা ভুল ঠিক করলেন, আর সারিটা ঐ পুরনো তারিখেই বসল।
     *
     * ⛔ ফল: আজ আসা একটা তাজা লট দেখাত ২৭ দিন পুরনো, আর পুরনো মালের
     * তালিকায় উঠে আসত — যেখানে তাকে নিয়ে কিছুই করার নেই।
     *
     * ⚠️ সারিটা [[StockService::move()]] দিয়ে বসানো, উঁচু স্তরের কোনো
     * সেবা দিয়ে নয় — আর সেটা ইচ্ছাকৃত: ওরা মাল আসার আগে বেরোতে দেয়
     * না, তাই এই অবস্থাটা ওদের দিয়ে বানানোই যায় না। ⓘ কিন্তু অবস্থাটা
     * ডাটাবেসে থাকতে পারে, আর রিপোর্ট ডাটাবেসই পড়ে।
     */
    public function test_a_back_dated_correction_does_not_age_the_lot(): void
    {
        $this->aLotThatArrived('L-7', on: '2026-09-08', qty: '10');

        // ⓘ আসার আগের তারিখে একটা ঋণাত্মক সংশোধন
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test:lot',
            sourceId: 7,
            floor: '-1',
            date: '2026-09-01',
            batch: Batch::query()->where('batch_no', 'L-7')->firstOrFail(),
        );

        $this->assertSame(20, (int) $this->rowFor('L-7')->held_days,
            'আসার আগের তারিখের সারিটা বয়সের গোনায় ঢুকেছে — তাজা লট পুরনো দেখাচ্ছে।');
    }

    public function test_the_oldest_arrival_wins_when_a_lot_came_twice(): void
    {
        /*
         * ⓘ একই লট দুইবার আসতে পারে (দুইটা চালান, একই লট নম্বর)।
         * ⭐ তখন প্রশ্নটার উত্তর হলো **সবচেয়ে পুরনো** আসাটা — কারণ
         * তাকে পড়ে থাকা মালের মধ্যে ঐ পুরনো মালটাও আছে।
         */
        $this->aLotThatArrived('L-3', on: '2026-09-08', qty: '5');
        $this->aLotThatArrived('L-3', on: '2026-09-25', qty: '5', sourceId: 33);

        $this->assertSame(20, (int) $this->rowFor('L-3')->held_days);
    }

    public function test_free_goods_and_goods_not_yet_put_away_also_count_as_arrivals(): void
    {
        /*
         * ⚠️ তিনটা খোপ তিনটা আলাদা পথে ভরে, আর কেবল `floor` গুনলে
         * ফ্রি মাল বা *"এসেছে কিন্তু বসেনি"* মালের লট বয়সহীন থাকত —
         * `held_days` আসত `null`, আর পর্দায় ফাঁকা ঘর।
         */
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test:lot',
            sourceId: 4,
            date: '2026-09-18',
            batch: $this->aBatch('L-4'),
            free: '6',
        );

        $this->assertSame(10, (int) $this->rowFor('L-4')->held_days,
            'ফ্রি মালে আসা লটটার বয়স গোনা হয়নি।');

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test:lot',
            sourceId: 5,
            date: '2026-09-13',
            batch: $this->aBatch('L-5'),
            unplaced: '4',
        );

        $this->assertSame(15, (int) $this->rowFor('L-5')->held_days,
            'বসার অপেক্ষায় থাকা মালের লটটার বয়স গোনা হয়নি।');
    }

    /**
     * ⭐ যে লট এসেছে অথচ কেউ গুদামে তোলেনি, সেটাও রিপোর্টে থাকে।
     *
     * ── ⛔ কী ছিল ─────────────────────────────────────────────────────
     * সারি বাছার শর্ত ছিল `floor + free > 0`। ⚠️ ক্রয়ের মাল প্রথমে
     * `unplaced` খোপে বসে, তাই আজ আসা লটটা রিপোর্টে **ছিলই না**।
     *
     * ⓘ আর পাতাটায় `unplaced` কলামটা আগে থেকেই ছিল — শর্তটা ওটাকে না
     * গোনায় সংখ্যাটা কেবল তখনই দেখা যেত যখন একই লটে তাকেও মাল আছে।
     * ⛔ অর্থাৎ কলামটা একা কোনোদিন দাঁড়াতে পারত না।
     *
     * ⚠️ উপরের দাবিটা (`free_goods_and_goods_not_yet_put_away…`) এই
     * সারাইয়ের উপরেই দাঁড়িয়ে, কিন্তু সে মাপে **বয়স**। ⓘ এই দাবিটা
     * আলাদা করে মাপে **সারিটা আছে কি নেই**, যাতে শর্তটা কেউ সংকুচিত
     * করলে কারণটা পড়া যায়।
     */
    public function test_a_lot_that_arrived_but_was_never_put_away_is_still_listed(): void
    {
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test:lot',
            sourceId: 6,
            date: '2026-09-26',
            batch: $this->aBatch('L-6'),
            unplaced: '9',
        );

        $row = $this->rowFor('L-6');

        $this->assertSame('9.0000', (string) $row->unplaced,
            'সারিটা এল, কিন্তু বসার অপেক্ষায় থাকা সংখ্যাটা নয়।');

        $this->assertSame('0.0000', (string) $row->on_hand,
            'তাকে কিছু ওঠেনি — সংখ্যাটা শূন্যই থাকার কথা।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * রিপোর্টের সেই সারিটা, যেটা এই লটের।
     *
     * ⛔ রিপোর্টের সংজ্ঞা থেকেই চালানো হয়, হাতে লেখা কোনো কোয়েরি নয় —
     * নাহলে দাবিটা আমার লেখা SQL-কে পরীক্ষা করত, পর্দা যা দেখায় তাকে নয়।
     */
    private function rowFor(string $batchNo): object
    {
        $definition = StockReports::stockByBatch();

        $rows = ($definition->query)([
            'company_id' => CompanyContext::id(),
            'branch_id' => null,
        ])->get();

        $row = $rows->firstWhere('batch_no', $batchNo);

        $this->assertNotNull($row, "লট {$batchNo} রিপোর্টে নেই।");

        return $row;
    }

    private function aBatch(string $batchNo): Batch
    {
        return Batch::query()->firstOrCreate(
            [
                'company_id' => CompanyContext::id(),
                'product_id' => $this->product->id,
                'batch_no' => $batchNo,
            ],
            ['expiry_date' => '2027-03-31'],
        );
    }

    private function aLotThatArrived(string $batchNo, string $on, string $qty, int $sourceId = 1): Batch
    {
        $batch = $this->aBatch($batchNo);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test:lot',
            sourceId: $sourceId,
            floor: $qty,
            date: $on,
            batch: $batch,
        );

        return $batch->refresh();
    }
}
