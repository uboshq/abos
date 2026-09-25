<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রেতা একটা লট বাছলেন, আর বেরোল অন্যটা।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * লট ধরা পণ্যে লট বাছা **বাধ্যতামূলক**। ⓘ তাঁকে দুইটা বিকল্প দেওয়া
 * হয়েছিল — না বাছলে FEFO চলবে, নাকি বাছা বাধ্যতামূলক — আর তিনি
 * দ্বিতীয়টা বেছেছেন।
 *
 * ── ⛔ এই ফাইলটা কেন আছে ────────────────────────────────────────────
 * বাছাইটা চারটা হাত বদলায়: পর্দা → যাচাই → সেবা → চালানের সারি →
 * মজুদের কোর। ⚠️ যেকোনো একটা জোড়া ছুটে গেলে ফলটা **নীরব**: সেবা লট
 * চাইত, পেত, আর তারপর মাল বেরোত FEFO ধরে — অর্থাৎ সম্ভবত অন্য লট
 * থেকে। ⓘ কাগজে এক লট, গুদামে আরেকটা, আর ধরা পড়ত রিকলের দিন।
 *
 * ⭐ তাই দাবিগুলো **চলাচলের সারি** মাপে, কাগজের ঘর নয় — কেবল ওখানেই
 * লেখা থাকে কোন কার্টনটা সত্যিই গেল।
 */
final class TheSellerChoseALotAndAnotherOneLeftTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $older;

    private Batch $newer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);

        /*
         * ⚠️ দুইটা লট, আর মেয়াদ আলাদা — ⛔ একটা লট দিয়ে এই ফাইলের
         * কোনো দাবিই কিছু প্রমাণ করত না: FEFO আর "বাছাই" দুইটাই তখন
         * একই লট বেছে নিত, আর জোড়টা ছুটে গেলেও সবুজ থাকত।
         */
        $this->older = $this->lot('LOT-OLD', '2027-01-01');
        $this->newer = $this->lot('LOT-NEW', '2028-01-01');
    }

    private function lot(string $no, string $expiry): Batch
    {
        $batch = Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $batch->id,
            floor: '100',
            date: now()->toDateString(),
            documentNo: 'TEST-'.$no,
            batch: $batch,
        );

        return $batch;
    }

    /** @param  array<string, mixed>  $line */
    private function sell(array $line): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', ...$line]],
        ]);
    }

    /** কোন লট থেকে মাল বেরোল — চলাচলের সারি ধরে। */
    private function lotThatLeft(): ?int
    {
        return StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('floor_change', '<', 0)
            ->latest('id')
            ->first()?->batch_id;
    }

    /**
     * ⭐ এটাই আসল দাবি: বাছা লটটাই বেরোয়, FEFO-র পছন্দটা নয়।
     *
     * ⚠️ মেয়াদের ক্রমে `LOT-OLD` আগে, তাই FEFO **সবসময়** ওটাই নিত।
     * ⛔ নতুনটা চেয়ে নতুনটাই বেরোলে বোঝা যায় বাছাইটা সত্যিই কোর
     * পর্যন্ত পৌঁছেছে — জোড়ার কোনোটাই ছুটে যায়নি।
     */
    public function test_the_chosen_lot_is_the_one_that_leaves(): void
    {
        $this->sell(['batch_id' => $this->newer->id])->assertSessionHasNoErrors();

        $this->assertSame($this->newer->id, $this->lotThatLeft(),
            '⛔ বিক্রেতা নতুন লট বেছেছেন, অথচ গুদাম থেকে বেরিয়েছে অন্যটা — '
            .'অর্থাৎ বাছাইটা মজুদের কোর পর্যন্ত পৌঁছায়নি।');
    }

    /**
     * ⛔ লট ধরা পণ্যে লট ছাড়া সারি ঢোকে না।
     *
     * ⚠️ দেয়ালটা সেবায়, পর্দায় নয় — ⓘ API বা কালকের নতুন পর্দা
     * পর্দার পাহারা দেখে না।
     */
    public function test_a_tracked_line_without_a_lot_is_refused(): void
    {
        $this->sell([])->assertSessionHasErrors('lines');

        $this->assertNull($this->lotThatLeft(),
            '⛔ লট ছাড়া মাল বেরিয়ে গেছে।');
    }

    /**
     * ⛔ একই পণ্যের একই লট দুইটা সারিতে নয় — মালিকের নিয়ম।
     */
    public function test_the_same_lot_cannot_appear_twice(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100', 'batch_id' => $this->older->id],
                ['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100', 'batch_id' => $this->older->id],
            ],
        ])->assertSessionHasErrors('lines');
    }

    /**
     * ⭐ পাল্টা-দাবি: **আলাদা** লট হলে দুইটা সারি ঠিকই চলে।
     *
     * ⚠️ এটা ছাড়া "একই পণ্য দুইবার নয়" লিখেও উপরেরটা সবুজ থাকত, আর
     * তখন মালিকের "প্রতি লটে এক সারি" নিয়মটাই ভাঙত।
     */
    public function test_two_different_lots_may_share_one_bill(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100', 'batch_id' => $this->older->id],
                ['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100', 'batch_id' => $this->newer->id],
            ],
        ])->assertSessionHasNoErrors();

        $lots = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('floor_change', '<', 0)
            ->pluck('batch_id')
            ->unique();

        $this->assertCount(2, $lots,
            '⛔ দুইটা আলাদা লট চাওয়া হয়েছিল, অথচ বেরিয়েছে '.$lots->count().'টা লট থেকে।');
    }

    /**
     * ⛔ বলা লটে যথেষ্ট না থাকলে আটকায়, আর বার্তাটা ঐ লটের কথাই বলে।
     *
     * ⚠️ পর্দা লটটা দেখায় বলে ধরে নেওয়া যায় না যে মাল আছে — ⓘ পর্দাটা
     * আঁকা হয়েছিল কয়েক মিনিট আগে, আর মাঝখানে অন্য কাউন্টার পুরোটা
     * নিয়ে যেতে পারে। ⛔ না দেখলে `floor` ঋণাত্মক হত।
     */
    public function test_a_lot_that_is_short_stops_the_sale(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '500',
                'rate' => '100',
                'batch_id' => $this->newer->id,
            ]],
        ])->assertSessionHasErrors();

        $this->assertNull($this->lotThatLeft(),
            '⛔ লটে যথেষ্ট ছিল না, তবু মাল বেরিয়ে গেছে।');
    }

    /**
     * ⭐ লট ধরা **নয়** এমন পণ্যের আচরণ এক চুলও বদলায়নি।
     *
     * ⚠️ ডিপোর চাল-ডাল-সাবান কিছু টের পায় না — ⛔ এটা না মাপলে
     * "সব সারিতে লট চাও" লিখেও উপরের দাবিগুলো সবুজ থাকত, আর
     * প্রতিটা সাধারণ বিক্রি আটকে যেত।
     */
    public function test_an_untracked_product_still_sells_without_a_lot(): void
    {
        $plain = Product::query()->where('id', '<>', $this->product->id)
            ->where('track_batch', false)->firstOrFail();

        app(StockService::class)->move(
            product: $plain,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $plain->id,
            floor: '100',
            date: now()->toDateString(),
            documentNo: 'TEST-PLAIN',
        );

        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $plain->id, 'qty' => '10', 'rate' => '100']],
        ])->assertSessionHasNoErrors();
    }
}
