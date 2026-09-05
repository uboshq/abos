<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * উপহারের কার্টনটা সোজা তাকে উঠে যেত।
 *
 * ── কী ভাঙা ছিল, ৫ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * Stock Placement আসার পর (৪ সেপ্টেম্বর) বাইরে থেকে আসা মাল আর সরাসরি
 * তাকে ওঠে না — সে **অপেক্ষার ঘরে** বসে, আর গুদামের কেউ বুঝে না নেওয়া
 * পর্যন্ত বিক্রয়যোগ্য হয় না। কেনা পরিমাণ আর ফ্রি পরিমাণ দুইটাই সেই
 * নিয়ম মানত।
 *
 * ⛔ **উপহার মানত না।** `DirectPurchaseService::bringInGifts()` সরাসরি
 * `free`-তে বসাত, `unplacedFree`-তে নয় — অর্থাৎ **একই লরির মাল, দুই
 * নিয়ম**। ফল দুইটা:
 *
 *   ⓘ বসানোর পর্দায় উপহারটা কোনোদিন আসত না — গুদামের লোক জানতেনই না
 *     ওটা এসেছে, আর গাড়ি থেকে নামানো কার্টনটা কারো দায়িত্বে পড়ত না
 *
 *   ⚠️ আর সে সাথে সাথেই বিক্রয়যোগ্য — অর্থাৎ মালিকের নিয়ম ("বসানোর
 *     আগে বিক্রি নয়") উপহারের বেলায় খাটত না
 *
 * ── ⭐ কেন কোনো পরীক্ষা এটা ধরেনি ────────────────────────────────────
 * উপহারের পরীক্ষা উপহার মাপত, ফ্রি পরিমাণের পরীক্ষা ফ্রি পরিমাণ — আর
 * দুইটার **তুলনা** কেউ করেনি। ⓘ ধরা পড়েছে সহকর্মীর কোড পড়ায়। এই
 * পরীক্ষাটা সেই তুলনাটাই লিখিত করে রাখে।
 */
class TheGiftCartonWentStraightToTheShelfTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    /** উপহারও অপেক্ষার ঘরে বসে — তাকে নয়। */
    public function test_a_gift_waits_to_be_placed_like_everything_else(): void
    {
        $stock = app(StockService::class);
        $before = $stock->statesFor($this->product, $this->warehouse);

        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'GIFT-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
            [['product_id' => $this->product->id, 'qty' => '3', 'remarks' => 'মিল দিয়েছে']],
        );

        $after = $stock->statesFor($this->product, $this->warehouse);

        /*
         * ⭐ তিনটা উপহার **অপেক্ষার ফ্রি ঘরে** — তাকে নয়।
         */
        $this->assertSame(
            0,
            bccomp(bcadd($before['unplaced_free'], '3', 4), $after['unplaced_free'], 4),
            'উপহারটা অপেক্ষার ঘরে বসেনি — তাহলে বসানোর পর্দায় কোনোদিন আসবে না।',
        );

        $this->assertSame(
            0,
            bccomp($before['free'], $after['free'], 4),
            'উপহারটা সরাসরি তাকে উঠে গেছে — বসানোর আগেই বিক্রয়যোগ্য।',
        );
    }

    /**
     * ⭐ আর কেনা মাল, ফ্রি পরিমাণ ও উপহার — তিনটাই এক নিয়ম মানে।
     *
     * ⚠️ এটাই আসল দাবি। একটা করে মাপলে প্রতিটা সবুজ থাকত, অথচ তিনজন
     * তিন রকম করত — ঠিক যেটা ঘটেছিল।
     */
    public function test_bought_free_and_gifted_all_land_in_the_same_room(): void
    {
        $stock = app(StockService::class);
        $before = $stock->statesFor($this->product, $this->warehouse);

        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'GIFT-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '50',
                'free_qty' => '2',
            ]],
            [['product_id' => $this->product->id, 'qty' => '3']],
        );

        $after = $stock->statesFor($this->product, $this->warehouse);

        /* কেনা দশটা — অপেক্ষার ঘরে */
        $this->assertSame(0, bccomp(bcadd($before['unplaced'], '10', 4), $after['unplaced'], 4),
            'কেনা মালটা অপেক্ষার ঘরে বসেনি।');

        /* ফ্রি দুই + উপহার তিন = পাঁচ, সবই অপেক্ষার ফ্রি ঘরে */
        $this->assertSame(0, bccomp(bcadd($before['unplaced_free'], '5', 4), $after['unplaced_free'], 4),
            'ফ্রি পরিমাণ আর উপহার একই ঘরে বসেনি।');

        /* আর তাক এক চুলও নড়েনি — কেউ এখনো কিছু বুঝে নেয়নি */
        $this->assertSame(0, bccomp($before['floor'], $after['floor'], 4),
            'কেউ বুঝে নেওয়ার আগেই মাল তাকে উঠেছে।');
        $this->assertSame(0, bccomp($before['free'], $after['free'], 4),
            'কেউ বুঝে নেওয়ার আগেই ফ্রি মাল তাকে উঠেছে।');
    }
}
