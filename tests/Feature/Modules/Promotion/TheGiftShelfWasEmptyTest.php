<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Services\GiftIssuer;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * উপহারের তাক খালি ছিল — স্পেক §২০ *"Gift Stock Unavailable → Block"*।
 *
 * ── ⚠️ তিনটা ভুল, আর তিনটাই নীরব ─────────────────────────────────────
 *   ⓵ তাকে তিন কার্টন, উপহার পাঁচ — বার্তাটা মজুদের ভাষায় আসত, উপহারের
 *     নয়; গুদামের মানুষ বুঝতেন না কোন অফারের কোন পাওনা আটকেছে।
 *   ⓶ তাকে দশ, অথচ আটটা একটা অর্ডারের জন্য ধরা — [[StockService]] কেবল
 *     তাক দেখে, তাই পাঁচটা উপহার হয়ে বেরোত, আর অর্ডারের ক্রেতা মাল
 *     নিতে এসে পেতেন না। ⓘ বিক্রয় এটা আগেই ঠেকায়
 *     ([[SalesInvoiceService::assertEnoughToSell()]], `availableQty`)।
 *   ⓷ বাছা লটে মাল কম — ভিতরে থামলে উপহারের কাগজটা যেন পড়ে না থাকে।
 *
 * ── ⭐ কেন মজুদ আর কাগজ দুইটাই গোনা হয় ──────────────────────────────
 * ⓘ থামানো যথেষ্ট নয়। ⛔ কাগজ লেখা হয়ে তারপর থামলে গুদামের খাতায় একটা
 * উপহার থাকত যা কোনোদিন বেরোয়নি — আর *"এখনো পাওনা"* হিসাবটা ঐ কাগজ
 * গুনে কম দেখাত।
 *
 * ── ⚠️ আজ লাল, আর সেটা ইচ্ছাকৃত ──────────────────────────────────────
 * ⓘ ⓵ ও ⓶ লাল থাকবে যতক্ষণ `GiftIssuer`-এ তাকের আগাম যাচাই আর
 * `gift_shelf_short` ভাষার সারি না বসে। ⭐ ⓷-এর দুইটা দাবি পাহারা দেয় যে
 * বাছা লটটা [[StockService::issue()]]-এ পৌঁছায় (২৭ সেপ্টেম্বরের সংশোধন) —
 * ঐ `batch:` ঘরটা সরালে FEFO অন্য লট থেকে কাটত আর দুইটাই লাল হত।
 */
final class TheGiftShelfWasEmptyTest extends TestCase
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
            'code' => 'EMPTYWH',
            'name_en' => 'Nearly empty store',
            'name_bn' => 'প্রায় খালি গুদাম',
            'is_active' => true,
        ]);

        $this->gift = Product::query()->orderBy('id')->firstOrFail();

        /* ⚠️ সিরিয়াল বন্ধ — এই ফাইল তাক মাপে; সিরিয়ালের নিয়ম বসলে এখানে অন্য কারণে থামত */
        $this->gift->track_batch = false;
        $this->gift->track_serial = false;
        $this->gift->save();

        $offer = new Promotion([
            'name_en' => 'Shelf gift',
            'name_bn' => 'তাকের উপহার',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-E-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $this->application = PromotionApplication::query()->create([
            'promotion_id' => $offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => 7101,
            'benefit_kind' => BenefitKind::GOODS,
            'benefit_amount' => '5',
            'worth' => '0',
        ]);
    }

    /** তাকে এতটা মাল — এলো, তারপর বুঝে নেওয়া হলো; লট দিলে ঐ লটে। */
    private function shelve(string $qty, ?Batch $batch = null, int $bill = 5101): void
    {
        $stock = app(StockService::class);

        $stock->move(product: $this->gift, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: $bill, unplaced: $qty, batch: $batch);

        $stock->place(product: $this->gift, warehouse: $this->warehouse,
            qty: $qty, sourceType: 'purchase_bill', sourceId: $bill, batch: $batch);
    }

    private function lot(string $no, Carbon $expires): Batch
    {
        return Batch::query()->create([
            'product_id' => $this->gift->id,
            'batch_no' => $no,
            'expiry_date' => $expires->toDateString(),
        ]);
    }

    /* ⚠️ `floorQty()` — নামটা StockService থেকে মেপে নেওয়া, ধরে নেওয়া নয় */
    private function onFloor(): string
    {
        return app(StockService::class)->floorQty($this->gift, $this->warehouse);
    }

    /** ⛔ উপহারের কোনো কাগজ বা মজুদের সারি পড়ে নেই। */
    private function assertNothingLeftBehind(): void
    {
        $this->assertSame(0, PromotionGiftIssue::query()
            ->where('promotion_application_id', $this->application->id)->count(),
            'থামল, অথচ উপহারের কাগজটা পড়ে আছে — লেনদেনটা পুরো ফেরেনি।');

        $this->assertSame(0, StockMovement::query()
            ->where('source_type', 'promotion:gift')->count(),
            'থামল, অথচ মজুদ থেকে উপহারের সারি কাটা হয়ে গেছে।');
    }

    /** @return string প্রথম `qty` বার্তা */
    private function refusal(callable $attempt): string
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());

            return $e->errors()['qty'][0];
        }

        $this->fail('তাকে যা নেই সেই উপহার বেরিয়ে গেল।');
    }

    /** ⓘ বার্তার সারিটা সত্যিই আছে কি না — নাহলে `__()` চাবিটাই ফেরত দিত, আর দুই দিকে একই চাবি মিলে সবুজ হত। */
    private function shelfShort(string $available): string
    {
        $this->assertTrue(Lang::has('promotion::validation.gift_shelf_short'),
            'promotion::validation.gift_shelf_short সারিটা ভাষার ফাইলে নেই।');

        return __('promotion::validation.gift_shelf_short', [
            'product' => $this->gift->name(),
            'warehouse' => $this->warehouse->name(),
            'available' => $available,
        ]);
    }

    /**
     * ⭐ ⓵ তাকে তিন, উপহার পাঁচ — থামে, আর উপহারের ভাষায় বলে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পাঁচটাই **পাওনা** — তাই পাওনার পাহারা
     * (`gift_over_owed`) এটা ধরে না; ধরতে হবে তাকের পাহারাকেই।
     */
    public function test_a_gift_bigger_than_the_shelf_is_refused_in_the_gifts_own_words(): void
    {
        $this->shelve('3');

        $message = $this->refusal(fn () => app(GiftIssuer::class)
            ->issue($this->application, $this->gift, $this->warehouse, '5'));

        $this->assertSame($this->shelfShort('3'), $message,
            'থামল, কিন্তু বার্তাটা উপহারের নয় — সম্ভবত মজুদের কাঁচা বার্তা।');
        $this->assertSame(0, bccomp($this->onFloor(), '3', 4), 'থামল, অথচ তাক থেকে মাল কমে গেছে।');
        $this->assertNothingLeftBehind();
    }

    /**
     * ⛔ ⓶ অর্ডারের জন্য ধরা মাল উপহার হয়ে বেরোয় না।
     *
     * ⓘ তাকে দশ, আটটা একটা বিক্রয় আদেশে ধরা — দেওয়া যায় দুইটা। ⚠️ মজুদের
     * নিজের পাহারা কেবল তাক দেখে (দশ ≥ পাঁচ), তাই এটা ছাড়া পাঁচটাই বেরোত।
     */
    public function test_stock_promised_to_an_order_does_not_leave_as_a_gift(): void
    {
        $this->shelve('10');

        app(StockService::class)->move(product: $this->gift, warehouse: $this->warehouse,
            sourceType: 'sales_order', sourceId: 9101, reserved: '8');

        $message = $this->refusal(fn () => app(GiftIssuer::class)
            ->issue($this->application, $this->gift, $this->warehouse, '5'));

        $this->assertSame($this->shelfShort('2'), $message);
        $this->assertSame(0, bccomp($this->onFloor(), '10', 4));
        $this->assertNothingLeftBehind();
    }

    /**
     * ⭐ পাল্টা-দাবি: তাকে ঠিক যতটা আছে, ততটা যায়।
     *
     * ⚠️ তুলনাটা `>`, `>=` নয় — ভুল দিকে লিখলে শেষ কার্টনটা কোনোদিন
     * উপহার হতে পারত না, আর *"সবসময় থামাও"* লিখেও উপরের দুইটা সবুজ হত।
     */
    public function test_exactly_what_is_on_the_shelf_can_go(): void
    {
        $this->shelve('3');

        app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '3');

        $this->assertSame(0, bccomp($this->onFloor(), '0', 4));
    }

    /**
     * ⛔ ⓷ বাছা লটে কম — ভিতরে থামে, আর কাগজটা পড়ে থাকে না।
     *
     * ⓘ পণ্যের মোট বারো, তাই তাকের আগাম যাচাই পেরোয়; থামে
     * [[StockService]]-এর *"এই লটে নেই"* পাহারায় — কাগজ লেখা হয়ে যাওয়ার
     * **পরে**। ⭐ এটাই লেনদেনের আসল মাপ: কাগজ লেখা হয়েছিল, আর ফিরতে হবে।
     *
     * ⚠️ লট B আগে ফুরায় (FEFO-র প্রথম পছন্দ) আর তাতে দশটা আছে — বাছা লট
     * মজুদে না পৌঁছালে FEFO চুপচাপ B থেকে কেটে উপহারটা দিয়ে দিত।
     */
    public function test_a_short_lot_leaves_no_paper_behind(): void
    {
        $this->gift->track_batch = true;
        $this->gift->save();

        $chosen = $this->lot('LOT-A', Carbon::today()->addYear());
        $sooner = $this->lot('LOT-B', Carbon::today()->addMonth());
        $this->shelve('2', $chosen, 5201);
        $this->shelve('10', $sooner, 5202);

        $this->refusal(fn () => app(GiftIssuer::class)
            ->issue($this->application, $this->gift, $this->warehouse, '5', $chosen));

        $this->assertSame(0, bccomp($chosen->balance($this->warehouse), '2', 4));
        $this->assertSame(0, bccomp($sooner->balance($this->warehouse), '10', 4),
            'বাছা লটে মাল ছিল না, তাই FEFO অন্য লট থেকে কেটে নিয়েছে।');
        $this->assertNothingLeftBehind();
    }

    /**
     * ⭐ বাছা লট থেকেই কাটে — কাগজে যা লেখা, গুদামেও তাই।
     *
     * ⛔ নাহলে কাগজ বলত A, মাল যেত B থেকে; বাতিলের দিন
     * [[PromotionReversal]] মাল ফেরাত A-তে — আর দুইটা লটের হিসাবই ভুল
     * থাকত, কোনো লাল ছাড়াই।
     */
    public function test_the_chosen_lot_is_the_one_that_empties(): void
    {
        $this->gift->track_batch = true;
        $this->gift->save();

        $chosen = $this->lot('LOT-A', Carbon::today()->addYear());
        $sooner = $this->lot('LOT-B', Carbon::today()->addMonth());
        $this->shelve('2', $chosen, 5301);
        $this->shelve('10', $sooner, 5302);

        $issue = app(GiftIssuer::class)->issue($this->application, $this->gift, $this->warehouse, '2', $chosen);

        $this->assertSame($chosen->id, (int) $issue->batch_id);
        $this->assertSame(0, bccomp($chosen->balance($this->warehouse), '0', 4),
            'কাগজে লট A, অথচ লট A থেকে কিছু কমেনি।');
        $this->assertSame(0, bccomp($sooner->balance($this->warehouse), '10', 4),
            'কাগজে লট A, অথচ মাল গেছে লট B থেকে।');
    }
}
