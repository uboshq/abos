<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * যে পর্দার কাজ ভুল সারানো, সে-ই অবিক্রেয় মাল বানাত।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৩ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"bosaw"* — সমন্বয়ের পর্দায় লটের ঘর বসানোর কথা, নতুন বাধ্যতামূলক
 * ঘরটার খরচ জেনেশুনেই।
 *
 * ── ⛔ দুইটা দিক, দুই রকম ক্ষতি ──────────────────────────────────────
 * **বাড়তি** — গুনে পাঁচ বেশি পাওয়া গেলে ওগুলো মেঝেতে বসত **লট ছাড়া**,
 * অর্থাৎ ঢোকার মুহূর্তেই অবিক্রেয়। ⓘ মজুদের ভুল সারাতে গিয়ে একটা নতুন
 * ভুল তৈরি।
 *
 * **ঘাটতি** — আর এটাই নীরব দিকটা। ⚠️ পাঁচ কম পাওয়া গেলে মালটা **মোট**
 * মেঝে থেকে কমত, অথচ প্রতিটা লটের হিসাব অক্ষত থাকত।
 *
 * ⛔ দুইটা যোগফল তখন আলাদা: লট বলে ৫০, মেঝে বলে ৪৫। এর পরের বিক্রয়টা
 * লট দেখে বরাদ্দ করত আর *"আছে"* পেত — চালান ছাপা হত, মাল দিতে গিয়ে
 * পাওয়া যেত না। ⓘ কোনো ত্রুটি নেই, কোথাও লাল নেই; ভুলটা ধরা পড়ত গুদামে
 * মাল খুঁজতে গিয়ে।
 */
final class TheCountingScreenMadeUnsellableStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    private ReasonCode $reason;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /* ⓘ নিজের গুদাম — ডেমোর গুদামে ঐ পণ্যের লট-ছাড়া মালও আছে */
        $this->warehouse = Warehouse::query()->create([
            'code' => 'CNTWH',
            'name_en' => 'Count test store',
            'name_bn' => 'গণনার পরীক্ষার গুদাম',
            'is_active' => true,
        ]);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->track_batch = true;
        $this->product->save();

        $this->reason = ReasonCode::query()
            ->inContext(ReasonCode::STOCK_ADJUSTMENT)
            ->active()->orderBy('id')->firstOrFail();

        /* ⓘ ৫০ ঢুকল, লট ধরেই — তাই শুরুতে দুইটা যোগফল মেলে */
        $this->lot = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'LOT-COUNT',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $stock = app(StockService::class);

        $stock->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 5001, unplaced: '50', batch: $this->lot,
        );

        $stock->place(
            product: $this->product, warehouse: $this->warehouse,
            qty: '50', sourceType: 'purchase_bill', sourceId: 5001, batch: $this->lot,
        );
    }

    /**
     * ⓘ আগে নিয়ন্ত্রণ সারিটা — শুরুতে দুইটা যোগফল সত্যিই মেলে।
     *
     * ── ⚠️ কেন এটা ছাড়া নিচের দাবিগুলো কিছুই মাপত না ────────────────
     * ⛔ যদি সাজানোটাই ভুল হত — ধরা যাক মালটা লট ছাড়া ঢুকল — তবে
     * ঘাটতির দাবিটা **শুরু থেকেই** লাল থাকত, আর সবুজ করার একমাত্র
     * উপায় হত দাবিটা আলগা করা।
     */
    public function test_the_two_totals_agree_before_anything_is_counted(): void
    {
        $this->assertSame(0, bccomp($this->onFloor(), $this->inLots(), 4),
            'সাজানোর পরেই দুইটা যোগফল আলাদা — পরীক্ষাটা ভুল জায়গা থেকে শুরু করছে।');
    }

    /**
     * ⛔ লট ধরা পণ্যে বাড়তি মাল লট ছাড়া বসানো যায় না।
     */
    public function test_a_surplus_without_a_lot_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->counted('55', unitCost: '100');
    }

    /**
     * ⭐ আর লট দিলে বাড়তিটা বসে যায়, লট ধরেই।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা একা থাকলে **সব বাড়তি আটকে দিলেও** সবুজ থাকত, আর তখন
     * গণনার পর্দাটা অকেজো। ⛔ দেয়ালটা লট ছাড়া মালের জন্য, বাড়তি মালের
     * জন্য নয়।
     */
    public function test_a_surplus_with_a_lot_goes_onto_the_shelf(): void
    {
        $this->counted('55', unitCost: '100', batch: $this->lot);

        $this->assertSame(0, bccomp($this->onFloor(), '55', 4), 'বাড়তি মালটা মেঝেতে বসেনি।');

        $this->assertSame(0, bccomp($this->inLots(), '55', 4), 'বাড়তি মালটা বসল, কিন্তু লটের বাইরে।');
    }

    /**
     * ⛔ আর ঘাটতি লট থেকেই কাটে — নীরব দিকটা।
     *
     * ── ⚠️ কেন যোগফল দুইটা মিলিয়ে দেখা, কেবল মেঝে নয় ────────────────
     * ⓘ `move()` দিয়ে লিখলেও মেঝের সংখ্যাটা **ঠিকই** হত — ৫০ থেকে ৪৫।
     * ⛔ ভুলটা দেখা যায় কেবল পাশের যোগফলে: লট তখনও বলত ৫০।
     *
     * ⚠️ অর্থাৎ একটা সংখ্যা মাপলে দাবিটা সবুজ থাকত, আর বাগটা অক্ষত।
     */
    public function test_a_shortage_comes_out_of_the_lot(): void
    {
        $this->counted('45');

        $this->assertSame(0, bccomp($this->onFloor(), '45', 4), 'মেঝের সংখ্যাটাই ভুল।');

        $this->assertSame(
            0,
            bccomp($this->inLots(), '45', 4),
            'মাল মেঝে থেকে কমল অথচ লটের হিসাব অক্ষত — পরের বিক্রয় খালি লট থেকে বরাদ্দ করবে।',
        );
    }

    /**
     * ⛔ আর অন্য পণ্যের লট বসানো যায় না।
     */
    public function test_a_lot_from_another_product_is_refused(): void
    {
        $other = Product::query()->whereKeyNot($this->product->id)->firstOrFail();

        $stray = Batch::query()->create([
            'product_id' => $other->id,
            'batch_no' => 'LOT-STRAY',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->expectException(ValidationException::class);

        $this->counted('55', unitCost: '100', batch: $stray);
    }

    /**
     * ⭐ আর যে পণ্যে লট ধরা হয় না, তার বাড়তি আগের মতোই বসে।
     *
     * ⓘ চালে-সাবানে লট চাপালে প্রতিটা সারিতে একটা বানানো নম্বর বসত,
     * আর বানানো লট রিকলের খাতায় একটা মিথ্যা সারি।
     */
    public function test_a_product_without_lots_still_takes_a_surplus(): void
    {
        $soap = Product::query()->whereKeyNot($this->product->id)->firstOrFail();

        $this->assertFalse((bool) $soap->track_batch, 'নমুনা পণ্যটারই লট চালু — দাবিটা কিছু মাপছে না।');

        $movement = app(StockAdjustmentService::class)->adjust(
            product: $soap,
            warehouse: $this->warehouse,
            countedQty: '10',
            reason: $this->reason,
            unitCost: '100',
        );

        $this->assertNotNull($movement, 'লট ধরা হয় না এমন পণ্যের বাড়তিও আটকে গেছে।');
    }

    /** গুনে যা পাওয়া গেল — সমন্বয়ের আসল পথ ধরে। */
    private function counted(string $qty, ?string $unitCost = null, ?Batch $batch = null): void
    {
        app(StockAdjustmentService::class)->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: $qty,
            reason: $this->reason,
            unitCost: $unitCost,
            batch: $batch,
        );
    }

    /** গুদামে মোট কত — লট ধরুক বা না ধরুক। */
    private function onFloor(): string
    {
        return app(StockService::class)->floorQty($this->product, $this->warehouse);
    }

    /**
     * আর লটগুলোতে মোট কত।
     *
     * ⚠️ যোগটা এখানে নিজের হাতে করা, [[Batch]]-এর `balance()` ডেকে নয়।
     * ⓘ দাবিটা বলছে *"দুইটা হিসাব মেলে"*, আর দুইটার একটা যদি পাহারার
     * নিজের কোড হত তবে দুইটাই একসাথে ভুল হয়ে সবুজ থাকত।
     */
    private function inLots(): string
    {
        return bcadd((string) StockMovement::query()
            ->forProduct($this->product->id)
            ->inWarehouse($this->warehouse->id)
            ->whereNotNull('batch_id')
            ->sum('floor_change'), '0', 4);
    }
}
