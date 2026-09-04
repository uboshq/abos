<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বসানো ছাড়া বিক্রি হয় না — Stock Placement-এর একমাত্র পাহারা।
 *
 * ── কেন এই ফাইলটা আলাদা ─────────────────────────────────────────────
 * ক্রয়-বিক্রয়ের আটটা পুরনো টেস্ট নতুন নিয়মটা **মেনে চলে** — তারা মাল
 * এনে, বসিয়ে, তারপর বেচে। এই ফাইলটা নিয়মটা **পাহারা দেয়**: কেউ ধাপটা
 * তুলে দিলে এটা লাল হবে। দুইটা আলাদা কাজ, আর প্রথমটা দ্বিতীয়টার বদলি নয়।
 *
 * ── মালিকের কথা, ৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * *"স্টক প্লেসমেন্ট করার আগ পর্যন্ত কোনো বিল করা যাবে না, মানে সেল করা
 * যাবে না।"*
 *
 * ── প্রতিটা assertion-এর আগে শর্তটা প্রমাণ করা হয় ───────────────────
 * ⚠️ `unplaced` শূন্যের উপর চালালে নিচের প্রতিটা দাবি **আপনা থেকেই পাস**
 * করত — মাল না এলে বিক্রিও আটকাবে, মোটও নড়বে না। তাই প্রতিটা পরীক্ষায়
 * আগে দেখা হয় সত্যিই কিছু অপেক্ষায় আছে, তারপর মূল দাবিটা।
 *
 * ⓘ **প্রতিটা assertion ইচ্ছে করে ভেঙে লাল হতে দেখা হয়েছে** — বিশেষ করে
 * প্রথমটা: `PurchaseBillService`-এ `unplaced:`-এর বদলে `floor:` ফিরিয়ে
 * দিলে সেটা সাথে সাথে লাল হয়।
 */
class GoodsNobodyTookInCannotBeSoldTest extends TestCase
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

        $this->product = Product::query()->where('track_batch', false)->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    /** গাড়ি থেকে নামল, কিন্তু কেউ বুঝে নেয়নি। */
    private function goodsArrive(string $qty = '10', string $free = '0'): void
    {
        $this->stock()->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill',
            sourceId: 4242,
            documentNo: 'PB-GUARD',
            unplaced: $qty,
            unplacedFree: $free,
        );
    }

    // ── ১ · সবচেয়ে জরুরি দাবি ──────────────────────────────────────────

    public function test_goods_that_arrived_but_were_not_taken_in_cannot_be_sold(): void
    {
        $before = $this->stock()->availableQty($this->product, $this->warehouse);

        $this->goodsArrive('10');

        // ⚠️ আগে প্রমাণ: সত্যিই দশ কার্টন অপেক্ষায় আছে
        $this->assertSame(0, bccomp(
            $this->stock()->unplacedQty($this->product, $this->warehouse), '10', 4,
        ), 'মালই আসেনি — তাহলে নিচের দাবিটা এমনিতেই পাস করত।');

        // ⭐ তবু বিক্রয়যোগ্য এক কার্টনও বাড়েনি
        $this->assertSame(
            0,
            bccomp($this->stock()->availableQty($this->product, $this->warehouse), $before, 4),
            'বসানো হয়নি এমন মাল বিক্রয়যোগ্য হয়ে গেছে।',
        );

        // ⭐ আর তাক থেকে ওই দশটা বের করতে গেলে থামে
        $this->expectException(ValidationException::class);

        $this->stock()->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'sales_invoice',
            sourceId: 1,
            floor: bcmul(bcadd($before, '1', 4), '-1', 4),
        );
    }

    // ── ২ · মাল উধাও হয় না ─────────────────────────────────────────────

    public function test_the_goods_are_still_counted_as_being_in_the_warehouse(): void
    {
        $before = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->goodsArrive('10');

        $after = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($after['floor'], $before['floor'], 4),
            'তাকের সংখ্যা বেড়েছে — মালটা বসানোর আগেই তাকে উঠে গেছে।');

        $this->assertSame(0, bccomp($after['on_hand'], bcadd($before['on_hand'], '10', 4), 4),
            'গুদামে মোট কত — সেখানে মালটা গোনা হয়নি, অর্থাৎ উধাও দেখাচ্ছে।');
    }

    // ── ৩ · বসানোর আগে-পরে মোট এক ──────────────────────────────────────

    public function test_placing_moves_the_goods_without_changing_the_total(): void
    {
        $this->goodsArrive('10');

        $before = $this->stock()->statesFor($this->product, $this->warehouse);
        $this->assertSame(0, bccomp($before['unplaced'], '10', 4));

        $this->stock()->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '10',
            sourceType: 'purchase_bill',
            sourceId: 4242,
        );

        $after = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($after['unplaced'], '0', 4), 'অপেক্ষার ঘর খালি হয়নি।');
        $this->assertSame(0, bccomp($after['floor'], bcadd($before['floor'], '10', 4), 4),
            'তাকে ওঠেনি।');

        // ⭐ সবচেয়ে জরুরি — মাল কোথাও হারায়নি, কেবল ঘর বদলেছে
        $this->assertSame(0, bccomp($after['on_hand'], $before['on_hand'], 4),
            'বসানোর আগে-পরে গুদামের মোট বদলে গেছে।');
    }

    // ── ৪ · আংশিক বসানো ────────────────────────────────────────────────

    public function test_part_of_a_paper_can_be_taken_in_and_the_rest_keeps_waiting(): void
    {
        $this->goodsArrive('10');

        $this->stock()->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '6',
            sourceType: 'purchase_bill',
            sourceId: 4242,
        );

        $state = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($state['unplaced'], '4', 4),
            'বাকি চারটা অপেক্ষায় থাকেনি — আংশিক বসানো ভাঙা।');
    }

    /** যা অপেক্ষায় নেই তার বেশি বসানো যায় না — নাহলে শূন্য থেকে মাল তৈরি হত। */
    public function test_more_than_what_is_waiting_cannot_be_taken_in(): void
    {
        $this->goodsArrive('10');

        $this->expectException(ValidationException::class);

        $this->stock()->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '11',
            sourceType: 'purchase_bill',
            sourceId: 4242,
        );
    }

    // ── ৫ · বাতিল করলে অপেক্ষার ঘরও খালি হয় ────────────────────────────

    public function test_cancelling_takes_back_goods_that_were_never_taken_in(): void
    {
        $this->goodsArrive('10', '2');

        $this->assertSame(0, bccomp(
            $this->stock()->unplacedQty($this->product, $this->warehouse), '10', 4,
        ), 'মালই আসেনি — নিচের দাবিটা তখন অর্থহীন।');

        $this->stock()->reverse(
            sourceType: 'purchase_bill',
            sourceId: 4242,
            reversedType: 'purchase_bill:cancel',
            date: now(),
            narration: 'বাতিল',
        );

        $state = $this->stock()->statesFor($this->product, $this->warehouse);

        /*
         * ⚠️ এটাই সবচেয়ে বিপজ্জনক ছিল: বিল বাতিল, খতিয়ান উল্টে গেছে,
         * অথচ কাগজটা Placement-এর পর্দায় রয়ে গেছে — আর কেউ বসিয়ে দিলে
         * বাতিল করা মাল বিক্রয়যোগ্য হয়ে যেত।
         */
        $this->assertSame(0, bccomp($state['unplaced'], '0', 4),
            'বাতিল করা মাল এখনো বসার অপেক্ষায় ঝুলে আছে।');

        $this->assertSame(0, bccomp($state['unplaced_free'], '0', 4),
            'বাতিল করা ফ্রি মাল এখনো ঝুলে আছে।');
    }
}
