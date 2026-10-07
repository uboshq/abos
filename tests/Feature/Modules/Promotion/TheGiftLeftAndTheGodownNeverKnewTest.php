<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Services\GiftIssuer;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * উপহারটা বেরিয়ে যেত, আর গুদাম কোনোদিন জানত না।
 *
 * ── ⭐ স্পেক §৮ ──────────────────────────────────────────────────────
 * *"Gift issue হলে Inventory Stock কমবে।"*
 *
 * ── ⚠️ কেন মজুদটা আসল দরজা দিয়ে গোনা হয় ─────────────────────────────
 * ⓘ দাবিগুলো মজুদের **মোট** পড়ে [[StockService]] থেকে — উপহারের
 * কাগজ থেকে নয়। ⛔ কাগজ থেকে পড়লে দাবিটা মাপত *"কাগজ লেখা হয়েছে"*,
 * আর মজুদ না কমলেও সবুজ হত — ঠিক যে ভুলটা এই ফাইল ঠেকাতে লেখা।
 */
final class TheGiftLeftAndTheGodownNeverKnewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $gift;

    private PromotionApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        /* ⓘ নিজের গুদাম — ডেমোরটায় ঐ পণ্যের অন্য মালও আছে, আর তখন গোনাটা ঘোলা হত */
        $this->warehouse = Warehouse::query()->create([
            'code' => 'GIFTWH',
            'name_en' => 'Gift store',
            'name_bn' => 'উপহারের গুদাম',
            'is_active' => true,
        ]);

        $this->gift = Product::query()->orderBy('id')->firstOrFail();

        $offer = new Promotion([
            'name_en' => 'Eid gift',
            'name_bn' => 'ঈদের উপহার',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-G-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $this->application = PromotionApplication::query()->create([
            'promotion_id' => $offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => 7001,
            'benefit_kind' => BenefitKind::GOODS,
            'benefit_amount' => '5',
            'worth' => '0',
        ]);
    }

    /** ১০০ কার্টন মেঝেতে, একটা লটে। */
    private function stocked(bool $trackBatch): ?Batch
    {
        $this->gift->track_batch = $trackBatch;
        $this->gift->save();

        $batch = $trackBatch ? Batch::query()->create([
            'product_id' => $this->gift->id,
            'batch_no' => 'LOT-GIFT',
            'expiry_date' => Carbon::today()->addYear()->toDateString(),
        ]) : null;

        $stock = app(StockService::class);

        $stock->move(product: $this->gift, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 5001, unplaced: '100', batch: $batch);

        $stock->place(product: $this->gift, warehouse: $this->warehouse,
            qty: '100', sourceType: 'purchase_bill', sourceId: 5001, batch: $batch);

        return $batch;
    }

    /* ⚠️ `floorQty()` — নামটা StockService থেকে মেপে নেওয়া, ধরে নেওয়া নয় */
    private function onFloor(): string
    {
        return app(StockService::class)->floorQty($this->gift, $this->warehouse);
    }

    /**
     * ⭐ উপহার দিলে মজুদ সত্যিই কমে।
     *
     * ⛔ না কমলে খাতা বলত পাঁচ কার্টন আছে, গুদামে থাকত না — আর পরের
     * বিক্রয় *"আছে"* দেখে চালান কাটত।
     */
    public function test_giving_a_gift_really_takes_it_off_the_floor(): void
    {
        $this->stocked(trackBatch: false);
        $before = $this->onFloor();

        app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '5');

        $this->assertSame(0, bccomp(bcsub($before, $this->onFloor(), 4), '5', 4),
            'উপহার বেরোল, অথচ মেঝের মজুদ পাঁচ কার্টন কমেনি।');
    }

    /**
     * ⛔ লট-রাখা পণ্যের উপহারে লট ছাড়া বেরোনো যায় না।
     *
     * ⚠️ লট ছাড়া বেরোলে মেঝে কমত, লটের হিসাব অক্ষত থাকত — লট বলত
     * পঞ্চাশ, মেঝে বলত পঁয়তাল্লিশ। ⓘ আর ডাটাবেজ এটা পাহারা দিতে পারে
     * না, কারণ সে জানে না কোন পণ্য লট রাখে।
     */
    public function test_a_lot_tracked_gift_cannot_leave_without_its_lot(): void
    {
        $this->stocked(trackBatch: true);
        $before = $this->onFloor();

        try {
            app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '5');
            $this->fail('লট-রাখা পণ্যের উপহার লট ছাড়াই বেরিয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_id', $e->errors());
        }

        $this->assertSame(0, bccomp($before, $this->onFloor(), 4),
            'বাধা দিল, অথচ মজুদ ততক্ষণে কমে গেছে — বাধাটা বসেছে মজুদ ছোঁয়ার পরে।');
    }

    /** ⭐ পাল্টা-দাবি: লট দিলে ঠিকই বেরোয় — নাহলে "সবসময় থামাও" লিখেও উপরেরটা সবুজ হত। */
    public function test_with_its_lot_the_gift_leaves_normally(): void
    {
        $batch = $this->stocked(trackBatch: true);
        $before = $this->onFloor();

        $issue = app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '5', $batch);

        $this->assertSame($batch->id, (int) $issue->batch_id);
        $this->assertSame(0, bccomp(bcsub($before, $this->onFloor(), 4), '5', 4));
    }

    /**
     * ⭐ উপহারের খরচ জমে যায় — ক্রয়মূল্যে, বিক্রয়মূল্যে নয়।
     *
     * ⓘ §১৭-এর *"Promotion Cost"* এই সংখ্যা ধরে গোনা হয়। ⚠️ বিক্রয়মূল্য
     * ধরলে খরচটা ফুলে দেখাত, আর একটা ভালো অফারও কাগজে খারাপ মনে হত।
     */
    public function test_the_gift_cost_is_frozen_at_the_purchase_price(): void
    {
        $this->stocked(trackBatch: false);

        $issue = app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '5');

        $this->assertSame(0, bccomp((string) $issue->unit_cost, (string) ($this->gift->purchase_price ?? '0'), 4));
    }
}
