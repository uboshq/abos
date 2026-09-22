<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফ্রি কার্টন বিলি হত যতটা এসেছিল তার চেয়ে বেশি।
 *
 * ── ⭐ মালিকের নিয়ম, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ফ্রি কম দিতে পারবে কিন্তু কোন ভাবেই বেশি দিতে পারবে না।"*
 *
 * ⛔ আজ পর্যন্ত কিছুই আটকাত না — ভাণ্ডারে ফ্রি মাল থাকা পর্যন্ত যতটা
 * ইচ্ছা দেওয়া যেত। ⓘ দশ কার্টনে এক ফ্রি পাওয়া মালে কেউ দশ কার্টনেই
 * দশ ফ্রি দিয়ে দিতে পারতেন।
 *
 * ── ⚠️ দেয়ালটা সেবা স্তরে, পর্দায় নয় ───────────────────────────────
 * পর্দার ঘরে সীমা বসানো সহজ, কিন্তু বিল আসতে পারে অন্য পথেও —
 * কাউন্টার, আদেশ থেকে, কিংবা কাল যোগ হওয়া কোনো পর্দা। ⛔ দেয়াল পর্দায়
 * থাকলে **প্রতিটা নতুন পথ একটা করে ফাঁক**।
 */
final class TheFreeCartonsWereGivenBeyondWhatCameTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        /*
         * ⚠️ `update()` নয়, সরাসরি — আর এটা মেপে ধরা পড়েছে।
         *
         * ⛔ `track_batch` [[Product]]-এর `fillable`-এ **নেই**, তাই
         * mass assignment-এ বসে না। ⓘ অর্থাৎ আজ লট চালু করার কোনো
         * স্বাভাবিক পথও নেই — ওটা ভিত্তির কাজের অংশ, আর মালিকের নিয়ম
         * (*"লট ছাড়া মাল ঢুকবেও না"*) বসানোর সময় সারাতে হবে।
         */
        $this->product->track_batch = true;
        $this->product->save();

        $this->stocked(paid: '100', free: '10');
    }

    /**
     * ⛔ অনুপাতের বেশি ফ্রি দিলে বিলটাই হয় না।
     */
    public function test_more_free_than_the_lot_gave_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell(qty: '20', free: '5');
    }

    /**
     * ⭐ আর ঠিক প্রাপ্যটা দিলে বিল হয়ে যায়।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা একা থাকলে **সব ফ্রি আটকে দিলেও** সবুজ থাকত — আর তখন
     * নিয়মটা "বেশি দেওয়া যাবে না" নয়, "কিছুই দেওয়া যাবে না" হয়ে যেত।
     * ⓘ মালিক ফ্রি বন্ধ করতে বলেননি, **মেপে দিতে** বলেছেন।
     */
    public function test_exactly_what_the_ratio_allows_goes_through(): void
    {
        $sale = $this->sell(qty: '20', free: '2');

        $this->assertNotNull($sale['invoice'] ?? null, 'প্রাপ্য ফ্রি দিতে গিয়েও বিল আটকে গেছে।');
    }

    /**
     * ⓘ কম দেওয়া সবসময়ই চলে — মালিকের কথা।
     */
    public function test_giving_less_is_always_fine(): void
    {
        $sale = $this->sell(qty: '20', free: '1');

        $this->assertNotNull($sale['invoice'] ?? null, 'কম ফ্রি দিতে গিয়েও বিল আটকে গেছে।');
    }

    /**
     * ⛔ যে লটে ফ্রি আসেনি, সেখানে এক কার্টনও নয়।
     *
     * ⓘ মালিকের কথা: *"যা সব প্রোডাক্ট ফ্রি আসে নাই সেইগুলাতে ফ্রি দিতে
     * পারবে না।"*
     */
    public function test_a_lot_that_came_without_free_gives_none(): void
    {
        $bare = Product::query()->whereKeyNot($this->product->id)->firstOrFail();
        $bare->track_batch = true;
        $bare->save();

        $this->stocked(paid: '100', free: '0', product: $bare, lot: 'LOT-DRY');

        /*
         * ⚠️ তুলনাটা সংখ্যায়, লেখায় নয় — `'0'` আর `'0.0000'` একই
         * সংখ্যা কিন্তু আলাদা লেখা, আর দাবিটা সংখ্যার কথা বলছে।
         */
        $this->assertSame(
            0,
            bccomp(app(FreeAllowance::class)->on($bare, $this->warehouse, '50'), '0', 4),
            'ফ্রি না আসা লট থেকেও ফ্রি দেওয়া যাচ্ছে।',
        );
    }

    /**
     * ⭐ আর একটা বিক্রি দুই লট জুড়ে গেলে প্রাপ্যটা যোগফল।
     *
     * ── ⚠️ কেন এটা আলাদা করে মাপা ───────────────────────────────────
     * ⓘ একটা বিক্রি প্রায়ই কয়েকটা লট জুড়ে যায়, আর লটগুলোর অনুপাত
     * আলাদা হতে পারে। ⛔ হিসাবটা যদি কেবল **প্রথম** লট দেখত, তবু
     * উপরের সব দাবি সবুজ থাকত — ওখানে লট একটাই।
     *
     * ⓘ এখানে প্রথম লটে ১০-এ ১ (১০০ মাল, ১০ ফ্রি), দ্বিতীয়টায় ফ্রি
     * নেই। ১২০ কার্টন নিলে প্রথমটা থেকে ১০০ (প্রাপ্য ১০), দ্বিতীয়টা
     * থেকে ২০ (প্রাপ্য ০) — মোট ১০।
     */
    public function test_a_sale_across_two_lots_adds_their_allowances(): void
    {
        $this->stocked(paid: '100', free: '0', lot: 'LOT-DRY');

        $this->assertSame(
            0,
            bccomp(app(FreeAllowance::class)->on($this->product, $this->warehouse, '120'), '10', 4),
            'দুই লটের প্রাপ্য যোগ হয়নি।',
        );
    }

    /** @return array{challan: mixed, invoice: mixed, change: string} */
    private function sell(string $qty, string $free): array
    {
        return app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100', 'free_qty' => $free]],
        );
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
            sourceType: 'purchase_bill', sourceId: 7001,
            unplaced: $paid, batch: $batch,
        );

        if (bccomp($free, '0', 4) > 0) {
            $stock->move(
                product: $product, warehouse: $this->warehouse,
                sourceType: 'purchase_bill:free', sourceId: 7001,
                unplacedFree: $free, batch: $batch,
            );
        }

        // ⓘ বসানোর আগে মাল বিক্রয়যোগ্য নয় — মালিকের নিয়ম
        $stock->place(
            product: $product, warehouse: $this->warehouse,
            qty: $paid, sourceType: 'purchase_bill', sourceId: 7001,
            batch: $batch, freeQty: $free,
        );
    }
}
