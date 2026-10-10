<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক বিলের ফেরত অন্য বিলের জায়গা খেয়ে ফেলত।
 *
 * ── ⓘ এই ফাইলটা কেন আলাদা ───────────────────────────────────────────
 * [[Tests\Feature\Modules\CostLayerTest]] পাহারা দেয় **একটা** বিলের ফেরত।
 * ⓘ এখানকার প্রতিটা দাবি সেই ঘরটার, যেখানে **একই স্তর** থেকে একাধিক নথি
 * মাল নিয়েছে — আর সেখানেই তিনটা আলাদা ভুল লুকিয়ে ছিল।
 *
 * ── ⛔ ভুল ১: গোনাটা ছিল স্তর ধরে, বিল ধরে নয় ───────────────────────
 * [[CostLayerService::returnToLayers()]] প্রশ্ন করত *"এই স্তরে আগে কতটা
 * ফিরেছে"*, আর গুনত ঐ ধরনের **সব** ফেরত। ⚠️ হাতে গোনা:
 *
 *     স্তর P1-এ ১০টা @ ১০০
 *     S1 নিল ৪টা · S2 নিল ৪টা
 *     S1-এর ৪টা ফেরত এল   → স্তরে আগে-ফেরা = ৪
 *     S2-এর ২টা ফেরত এল   → "৪ নিয়েছিলে, ৪ ফিরিয়েছ" ⛔ আটকে গেল
 *
 * ⛔ অথচ S2 একটাও ফেরত দেয়নি। ক্রেতার সৎ ফেরত কাগজেই ঠেকে যেত, আর
 * বার্তাটা বলত *"যতটা বেরিয়েছিল তার বেশি"* — যা মিথ্যা।
 *
 * ── ⛔ ভুল ২: একই নথির দুইটা টান দুইবার কাটত ─────────────────────────
 * ⓘ একই পণ্য বিলে দুই সারিতে থাকলে এক স্তরেই দুইটা টান বসে। গোনাটা
 * হত টান ধরে ধরে, আর প্রতিটা টান থেকে **পুরো** আগে-ফেরা বাদ যেত —
 * ⚠️ তাই জায়গাটা দুইবার কমত আর পুরো ফেরতটাই আটকে যেত।
 *
 * ── ⛔ ভুল ৩: ফেরত বাতিলে স্তর ফিরত না ──────────────────────────────
 * তাক নামত, খাতার দাখিলা উল্টাত, কিন্তু স্তরে ফেরা মাল বসেই থাকত।
 * ⓘ ১০ বেচা, ৪ ফেরত, ফেরত বাতিল → তাকে ১০, অথচ স্তরে ১৪ একক।
 * ⚠️ মজুদ রিপোর্ট স্তর থেকে পড়ে, তাই ব্যালান্স শিট আর রিপোর্ট আলাদা
 * হত — আর ঐ বাড়তি ৪টা পরের বিক্রয়ে খরচ হয়ে বেরোত, যা গুদামে কখনো
 * ছিল না।
 *
 * ── ⓘ যা এখানে মাপা হয় না ───────────────────────────────────────────
 * ⛔ বিলের চেয়ে বেশি ফেরত ঠেকানো — সেটা [[SalesReturnService]]-এর কাজ,
 * সেখানে সারি ধরে ধরে পরিমাণ মেলানো হয়। এখানে কেবল স্তরের হিসাব।
 */
final class OneBillsReturnAteAnotherBillsHeadroomTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⓘ সিডারের খোলা মজুদের স্তরটা সরিয়ে নেওয়া — [[CostLayerTest]]-এর
         * একই নিয়ম। ⚠️ না সরালে FIFO পুরনো সস্তা স্তর থেকে টানত আর
         * "কোন স্তরে কে কতটা নিল" প্রশ্নটার উত্তরই জানা থাকত না।
         * ⭐ মোছা হয় না, খরচ করা হয় — ইতিহাস থাকে।
         */
        $onHand = $this->costs()->qtyOnHand($this->product);

        if (bccomp($onHand, '0', 4) > 0) {
            $this->costs()->issue($this->product, $onHand, 'test_opening_cleared', 1);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ ভুল ১ ──────────────────────────────────────────────────────

    public function test_one_bills_return_does_not_eat_another_bills_headroom(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');

        // একই স্তর থেকে দুইটা আলাদা বিল
        $this->costs()->issue($this->product, '4', 'test_out', 1, 'S1', '2026-08-05');
        $this->costs()->issue($this->product, '4', 'test_out', 2, 'S2', '2026-08-06');

        $this->returnFor(invoice: 1, qty: '4', returnId: 1, siblings: [1]);

        /*
         * ⛔ এই ডাকটাই আগে ValidationException ছুঁড়ত। ⓘ S2 একটাও ফেরত
         * দেয়নি, তাই তার ৪টার পুরোটাই তখনো ফেরতযোগ্য।
         */
        $value = $this->returnFor(invoice: 2, qty: '2', returnId: 2, siblings: [2]);

        $this->assertSame('200.0000', $value,
            'S2-এর ফেরত নিজের দামে ফেরেনি — অথচ মালটা ১০০ টাকার স্তরেরই।');

        /*
         * ⓘ তাকের হিসাব: ১০ এল, S1 নিল ৪, S2 নিল ৪ → বাকি ২; তারপর
         * ফিরল ৪ + ২ → ৮। ⚠️ প্রথমে ৬ লিখেছিলাম, আর লালটা আমারই গোনার
         * ভুল ছিল — কোডের নয়।
         */
        $this->assertSame('8.0000', $this->costs()->qtyOnHand($this->product));
        $this->assertSame('800.0000', $this->costs()->valueOnHand($this->product));
    }

    public function test_the_same_bills_goods_still_cannot_come_back_twice(): void
    {
        /*
         * ⚠️ পাল্টা-দাবি: উপরের ছাড়টা যেন দরজা খুলে না দেয়। ⓘ ডাকার পক্ষ
         * এই বিলের **আগের** ফেরতগুলোও তালিকায় দেয় ([[SalesReturnService]]
         * `$siblings` — পোস্ট হওয়া সব, এটাসহ), তাই দুইবার গোনা বন্ধই থাকে।
         */
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '5', 'test_out', 1, 'S1', '2026-08-05');

        $this->returnFor(invoice: 1, qty: '5', returnId: 1, siblings: [1]);

        $this->expectException(ValidationException::class);

        $this->returnFor(invoice: 1, qty: '1', returnId: 2, siblings: [1, 2]);
    }

    // ── ⭐ ভুল ২ ──────────────────────────────────────────────────────

    public function test_one_bill_drawing_twice_from_a_layer_can_return_it_all(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');

        /*
         * ⓘ একই বিল (`test_out`/১), একই স্তর, দুইটা টান — বিলে পণ্যটা দুই
         * সারিতে থাকলে বাস্তবে ঠিক এটাই হয়।
         */
        $this->costs()->issue($this->product, '3', 'test_out', 1, 'S1', '2026-08-05');
        $this->costs()->issue($this->product, '3', 'test_out', 1, 'S1', '2026-08-05');

        $value = $this->returnFor(invoice: 1, qty: '6', returnId: 1, siblings: [1]);

        $this->assertSame('600.0000', $value,
            'পুরো ৬টা ফেরত নেয়নি — এক স্তরে দুইটা টান থাকায় জায়গা দুইবার কমেছে।');

        // ⓘ ১০ এল, ৩ + ৩ বেরোল → ৪; পুরো ৬ ফিরল → ১০, অর্থাৎ তাক আবার ভরা
        $this->assertSame('10.0000', $this->costs()->qtyOnHand($this->product));
    }

    // ── ⭐ ভুল ৩ ──────────────────────────────────────────────────────

    public function test_cancelling_a_return_leaves_nothing_behind_in_the_layers(): void
    {
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '10', 'test_out', 1, 'S1', '2026-08-05');

        $this->returnFor(invoice: 1, qty: '4', returnId: 1, siblings: [1]);
        $this->assertSame('4.0000', $this->costs()->qtyOnHand($this->product));

        $lifted = $this->costs()->undoReturn('test_return', 1);

        $this->assertSame('400.0000', $lifted,
            'যত টাকার মাল তোলা হলো বলে জানানো হলো, তা ফেরা মালের দামের সাথে মেলে না।');

        /*
         * ⚠️ তুলনাটা সংখ্যায়, হুবহু লেখায় নয় — আর কারণটা মেপে বের করা।
         *
         * ⓘ [[CostLayerService::valueOnHand()]] সারি বাছে
         * `where('qty_remaining', '>', 0)` দিয়ে, আর যোগ করে `reduce(..., '0')`।
         * খালি তাকে একটাও সারি মেলে না, তাই শুরুর মানটাই ফেরে: `'0'`, স্কেল-৪
         * নয়। ⭐ একটা সারি থাকলেই bcadd স্কেল ৪ বানায়, তাই ফারাকটা কেবল
         * শূন্য তাকের ঘরে।
         *
         * ⛔ প্রথমে দায়ী করেছিলাম `qtyOnHand()`-এর `->sum()`-কে, "decimal
         * কলামকে float করে" বলে। ⚠️ ঘরের ঐ নিয়মটা সত্যি, কিন্তু এখানে কারণ
         * নয় — `Query\Builder::sum()` হলো `return $result ?: 0;`, সে
         * `numericAggregate()` ডাকেই না; আর `'0.0000'` PHP-তে সত্য।
         * ⓘ প্রমাণ ঘরেই ছিল: [[CostLayerTest]]:১০৫ `'6.0000'` দাবি করে, সবুজ।
         * ⭐ শিক্ষা: পরিচিত নিয়ম আগে থেকেই বিশ্বাস করা থাকে, তাই ভুল কারণকেও
         * প্রমাণিত দেখায় — কোন লাইনটা মানটা বানাল, সেটা আগে বের করতে হয়।
         *
         * ⓘ দাবিটা যা বলতে চায় তা হলো **কিছুই পড়ে নেই** — `'0'` না `'0.0000'`
         * লেখা হলো, সেটা নয়। তাই bccomp, আর তাই ঐ ফারাক সারানোর দিনেও
         * এই দাবি সবুজই থাকবে।
         */
        $this->assertSame(0, bccomp($this->costs()->qtyOnHand($this->product), '0', 4),
            'ফেরত বাতিলের পরেও স্তরে মাল পড়ে আছে — পরের বিক্রয় ওটা খরচ করে ফেলবে।');
        $this->assertSame(0, bccomp($this->costs()->valueOnHand($this->product), '0', 4),
            'ফেরত বাতিলের পরেও স্তরে মূল্য পড়ে আছে।');

        /*
         * ⭐ বাতিল ফেরত আর "আগে ফিরেছে" নয় — পুরো ১০টাই আবার ফেরত নেওয়া যায়, তালিকায় বাতিলটার নাম থাকলেও।
         * ⓘ আগে এখানে দাবি ছিল "সারিগুলো মোছা হয়"। পুরো-ERP অডিট ⚠️৫ (৬ অক্টোবর ২০২৬): মুছলে ফেরতের দিনের মজুদ-মূল্য
         * পেছনে বদলাত, তাই এখন উল্টো সারি (`test_return:cancel`) থাকে, আর গোনা তাকে কাটাকাটি করে — দাবিটা আকার নয়,
         * উদ্দেশ্য মাপে ([[ACancelNeverRewritesAClosedMonthsStockTest]])।
         */
        $this->assertSame('1000.0000', $this->returnFor(invoice: 1, qty: '10', returnId: 2, siblings: [1, 2]),
            'বাতিল ফেরতটা এখনো "আগে ফিরেছে" গোনা হল — পরের ফেরত আটকে যেত।');
    }

    public function test_a_return_whose_goods_have_left_again_cannot_be_cancelled(): void
    {
        /*
         * ⚠️ পাল্টা-দাবি: বাতিলটা যেন নীরবে স্তর ঋণাত্মক করে না ফেলে।
         * ⓘ ফেরা ৪টা আবার বেরিয়ে গেছে — ঐ বিক্রয়গুলোর খরচ ঐ দামেই বসে
         * আছে, তাই সৎ পথ বাতিল নয়, নতুন নথি।
         */
        $this->costs()->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $this->costs()->issue($this->product, '10', 'test_out', 1, 'S1', '2026-08-05');

        $this->returnFor(invoice: 1, qty: '4', returnId: 1, siblings: [1]);
        $this->costs()->issue($this->product, '4', 'test_out', 2, 'S2', '2026-08-07');

        $this->expectException(ValidationException::class);

        $this->costs()->undoReturn('test_return', 1);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  list<int>  $siblings */
    private function returnFor(int $invoice, string $qty, int $returnId, array $siblings): string
    {
        return $this->costs()->returnToLayers(
            product: $this->product,
            qty: $qty,
            issuedSourceType: 'test_out',
            issuedSourceId: $invoice,
            sourceType: 'test_return',
            sourceId: $returnId,
            documentNo: 'RET-'.$returnId,
            date: '2026-08-08',
            returnedBy: $siblings,
        );
    }

    private function costs(): CostLayerService
    {
        return app(CostLayerService::class);
    }
}
