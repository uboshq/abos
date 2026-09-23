<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StrandedStock;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * তাকে মাল ছিল, খাতায় ছিল, বেরোনোর কোনো পথ ছিল না।
 *
 * ── ⭐ মালিকের নিয়ম, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"লট ছাড়া মাল ঢুকবেও না বেরোবেও না।"*
 *
 * ── ⛔ নিয়মটা বসাতে গেলে যে ফাঁদটা খোলে ─────────────────────────────
 * একটা পণ্যে লট ধরা চালু করার **মুহূর্তে** তার আগেকার মজুদ বিক্রয়ের
 * বাছাই থেকে হারিয়ে যায় — [[BatchAllocator]] কেবল লট-ওয়ালা সারি দেখে,
 * আর পুরনো সারিতে লট নেই। ⚠️ মজুদের সংখ্যায় মালটা থাকে, তাই পর্দায়
 * ১২০ পিস দেখা যায় অথচ বিল হয় না।
 *
 * ⓘ [[Product]]-এর সুইচটা এতদিন কোনো পর্দা থেকে ছোঁয়াই যেত না, তাই
 * ফাঁদটা কখনো খোলেনি। ⛔ সুইচটা বসানোর সাথে সাথেই এটা সত্যিকারের বাগ
 * হয়ে ওঠে — ঠিক যেমন ফ্রি ভাণ্ডারের উল্টো-সারির বাগটা হয়েছিল।
 *
 * ── ⚠️ আর বার্তাটা যে দরজার নাম বলত, সেটা খুলত না ───────────────────
 * ত্রুটিবার্তাটা পাঠাত খোলা মজুদের পর্দায়। ⛔ ঐ দরজা দুইবার বন্ধ:
 * ওখানে লটের কোনো ঘরই নেই, আর `stillOpen()` কোনো চলাচল থাকলেই ফিরিয়ে
 * দেয় — আটকে থাকা মালের তো চলাচল আছেই।
 */
final class TheStrandedStockHadNowhereToGoTest extends TestCase
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

        /*
         * ⚠️ নিজের একটা গুদাম, ডেমোরটা নয় — আর এটা মেপে শেখা।
         *
         * ⛔ প্রথমে ডিফল্ট গুদামে লেখা হয়েছিল, আর দুইটা দাবি ভুল কারণে
         * লাল হলো: ডেমোতে ঐ পণ্যের মাল **আগে থেকেই** আছে, আর সেগুলোও
         * লট ছাড়া। ⓘ তাই "১০০-র বেশি বসানো যায় না" দাবিটা মাপছিল
         * ১০০ + ডেমোর মাল, আর "তালিকা খালি হয়" দাবিটা কোনোদিন খালি
         * হত না।
         *
         * ⚠️ সংখ্যাটা [[StrandedStock]]-কে জিজ্ঞেস করে নেওয়া যেত, কিন্তু
         * তাহলে পাহারা আর দাবি একসাথে নড়ত — ⛔ `onFloor()` ভুল হলে
         * দাবিটাও ঠিক ততটাই ভুল হয়ে সবুজ থাকত। ⓘ তাই পুকুরটাই আলাদা,
         * আর ভিতরের প্রতিটা সংখ্যা এই ফাইলের নিজের রাখা।
         */
        $this->warehouse = Warehouse::query()->create([
            'code' => 'LOTWH',
            'name_en' => 'Lot test store',
            'name_bn' => 'লট পরীক্ষার গুদাম',
            'is_active' => true,
        ]);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /* ⓘ লট ধরা শুরুর **আগে** ঢোকা মাল — সারিতে কোনো লট নেই */
        $this->arrivedWithoutALot('100');

        $this->product->track_batch = true;
        $this->product->save();
    }

    /**
     * ⛔ প্রথমে ফাঁদটাই — লট চালু করার পর পুরনো মাল বেচা যায় না।
     *
     * ── ⚠️ কেন এই দাবিটা এখানে, যদিও এটা "কাজ করছে" ─────────────────
     * ⓘ এটাই বাকি সব দাবির ভিত্তি। এই দেয়ালটা যদি কোনো কারণে না থাকত —
     * ধরা যাক লট-ছাড়া মালও বাছাইয়ে এসে গেল — তবে নিচের "লট বসানোর পর
     * বেচা যায়" দাবিটা **সবুজ থাকত কিছু না করেই**, আর গোটা পর্দাটা
     * অপ্রয়োজনীয় হয়েও সবুজ দেখাত।
     */
    public function test_stock_that_came_before_lots_cannot_be_sold(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell('10');
    }

    /**
     * ⛔ আর বার্তাটা যে দরজার নাম বলত সেটা খুলত না।
     *
     * ⓘ খোলা মজুদের পর্দা এই পণ্যটাকে নেয় না — চলাচল আছে বলে। ⚠️ এটা
     * খোলা মজুদের বাগ নয়, ওর নিয়মটা ঠিকই আছে (FIFO-র স্তর ক্রম ভাঙত)।
     * ⛔ বাগটা ছিল বার্তাটার — সে এমন এক দরজায় পাঠাত যেটা এই অবস্থায়
     * কোনোদিন খোলে না।
     */
    public function test_the_opening_stock_door_really_is_shut(): void
    {
        $this->assertFalse(
            app(OpeningStockService::class)->stillOpen($this->product, $this->warehouse),
            'খোলা মজুদ এখানে নিয়ে নিচ্ছে — তাহলে আলাদা পর্দার দরকারই ছিল না।',
        );
    }

    /**
     * ⭐ লট বসানোর পর ঠিক ঐ মালটাই বেরিয়ে যায়।
     */
    public function test_once_it_has_a_lot_it_sells(): void
    {
        $this->giveItALot('100');

        $sale = $this->sell('10');

        $this->assertNotNull($sale['invoice'] ?? null, 'লট বসানোর পরেও মালটা বেরোচ্ছে না।');
    }

    /**
     * ⛔ আর মজুদের সংখ্যা এক চুলও নড়ে না।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা, আর কেন এটাই সবচেয়ে জরুরি ────────────
     * ⓘ উপরেরটা সবুজ করার সবচেয়ে সহজ ভুল পথ হলো লটসহ একটা নতুন সারি
     * **ঢুকিয়ে দেওয়া**। ⛔ তখন মাল বেচা যেত ঠিকই, কিন্তু গুদামে মাল
     * দ্বিগুণ হয়ে যেত — ১০০ ছিল, ২০০ দেখাত।
     *
     * ⚠️ ভুলটা নীরব: কোনো ত্রুটি নেই, বিক্রয় চলে, আর সংখ্যাটা ভুল।
     * ⓘ ধরা পড়ত গুদাম গুনতে গিয়ে, মাস দুয়েক পরে।
     */
    public function test_the_stock_figure_does_not_move(): void
    {
        $stock = app(StockService::class);

        $before = $stock->floorQty($this->product, $this->warehouse);

        $this->giveItALot('100');

        $this->assertSame(
            0,
            bccomp($before, $stock->floorQty($this->product, $this->warehouse), 4),
            'লট বসাতে গিয়ে মজুদের সংখ্যাটাই বদলে গেছে।',
        );
    }

    /**
     * ⛔ যতটা লট ছাড়া আছে, তার বেশি বসানো যায় না।
     *
     * ⓘ বেশি বসালে যে মালের লট **আগেই জানা**, সেটাও নতুন লটে চলে যেত —
     * একই কার্টন দুই লটে, আর রিকলের ফোন দুইবার।
     *
     * ── ⛔ আর কেন তাকে আগে থেকে লট-ওয়ালা মাল বসাতে হয় ───────────────
     * ⚠️ এটা মেপে শেখা। প্রথমে কেবল ১০০-র জায়গায় ১০১ চাওয়া হয়েছিল, আর
     * মিউট্যান্ট চালিয়ে দেখা গেল **পাহারাটা তুলে নিলেও দাবিটা সবুজ**:
     * মোট ফ্লোরই তখন ১০০, তাই [[StockService]]-র নিজের সাধারণ পাহারাটা
     * ফিরিয়ে দিত। ⛔ দাবিটা সত্যি কথা বলত, কিন্তু অন্য কারও কথা।
     *
     * ⓘ বিপজ্জনক অবস্থাটা এটাই: তাকে অনেক মাল আছে, তার বেশিরভাগের লট
     * **জানা**, আর লট ছাড়া পড়ে আছে সামান্য। তখন সাধারণ পাহারাটা কিছুই
     * বলে না, অথচ বেশি বসালে জানা-লটের কার্টনগুলো নতুন লটে চলে যায়।
     */
    public function test_more_than_what_is_lot_less_is_refused(): void
    {
        /* ⓘ ২০০ এল লট ধরে, তাই মোট ফ্লোর ৩০০ — লট ছাড়া কেবল ১০০ */
        $this->arrivedWithALot('200');

        $this->expectException(ValidationException::class);

        $this->giveItALot('150');
    }

    /**
     * ⛔ আর অন্য পণ্যের লট বসানো যায় না।
     *
     * ⓘ বসালে রিকলের খাতা **উল্টো দিকে** মিথ্যা বলত: যে লটের ফোন
     * করার কথা নয় তার ক্রেতাদের ফোন যেত, আর যাঁদের যাওয়ার কথা তাঁরা
     * বাদ পড়তেন।
     */
    public function test_a_lot_belonging_to_another_product_is_refused(): void
    {
        $other = Product::query()->whereKeyNot($this->product->id)->firstOrFail();

        $batch = Batch::query()->create([
            'product_id' => $other->id,
            'batch_no' => 'LOT-OTHER',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->expectException(ValidationException::class);

        app(StrandedStock::class)->giveItALot(
            product: $this->product, warehouse: $this->warehouse,
            batch: $batch, qty: '10',
        );
    }

    /**
     * ⭐ আর পর্দাটা সত্যিই খোলে, আর আটকে থাকা মালটা ওখানে দেখা যায়।
     *
     * ── ⚠️ কেন পর্দাটাও মাপা হয় ─────────────────────────────────────
     * ⓘ উপরের সব দাবি সেবা-স্তরের। ⛔ রুট, অনুমতি বা ভিউয়ের নামে একটা
     * ভুল থাকলে সেবাটা নিখুঁত থেকেও **কেউ পৌঁছাতে পারত না** — আর ঠিক
     * সেটাই এই খাতার সবচেয়ে সাধারণ বাগ: কাজটা হয়ে আছে, জোড়াটা নেই।
     */
    public function test_the_screen_opens_and_shows_what_is_waiting(): void
    {
        $this->assertStringContainsString($this->warehouse->name(), $this->waitingPanel(),
            'আটকে থাকা মালটা পর্দার তালিকায় নেই।');
    }

    /**
     * ⭐ আর কাজ শেষ হলে তালিকাটা খালি হয়।
     *
     * ── ⚠️ কেন এটা আলাদা দাবি ───────────────────────────────────────
     * ⓘ উপরেরটা কেবল দেখায় সারিটা **আসে**। ⛔ ছাঁকনিটা যদি কিছুই না
     * ছাঁকত — সব পণ্যের সব সারি দেখাত — তবু ওটা সবুজ থাকত, আর তালিকাটা
     * কোনোদিন খালি হত না। ⓘ খালি হওয়াটাই এখানে "কাজ শেষ"-এর একমাত্র
     * চিহ্ন, তাই ওটাও মেপে দেখা হয়।
     */
    public function test_the_list_empties_once_the_lot_is_on(): void
    {
        $this->giveItALot('100');

        $this->assertStringNotContainsString($this->warehouse->name(), $this->waitingPanel(),
            'লট বসানোর পরেও মালটা "বাকি" তালিকায় বসে আছে।');
    }

    /**
     * পর্দার কেবল ডান দিকের তালিকাটুকু — ফর্মের ড্রপডাউন বাদ দিয়ে।
     *
     * ── ⚠️ কেন কেটে নেওয়া, গোটা পাতা নয় ─────────────────────────────
     * ⛔ প্রথমে গোটা পাতায় পণ্যের কোড খোঁজা হয়েছিল, আর "তালিকা খালি
     * হয়" দাবিটা **কখনোই সবুজ হত না**: কোডটা বাছাইয়ের ড্রপডাউনেও
     * থাকে, সবসময়। ⓘ অর্থাৎ দাবিটা ঠিক কথা বলছিল, কিন্তু ভুল ঘরে
     * তাকিয়ে — আর ঐ ঘরটার উত্তর কোনোদিন বদলাত না।
     */
    private function waitingPanel(): string
    {
        $html = (string) $this->get(route('inventory.stock.lot'))->assertOk()->getContent();

        $at = strpos($html, __('inventory::message.lot_waiting_title'));

        $this->assertNotFalse($at, 'তালিকার শিরোনামটাই পাতায় নেই — পর্দাটা অন্য কিছু আঁকছে।');

        return substr($html, $at);
    }

    /**
     * ⛔ আর যে পণ্যে লট ধরাই হয় না, সে এই তালিকায় আসে না।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরের দুইটা ছাঁকনিটা মাপত না ──────────
     * ⓘ এটাও মেপে শেখা। ছাঁকনিটা তুলে নিয়ে দেখা গেল উপরের দুইটা দাবি
     * **তবুও সবুজ** — আমার গুদামে লট-ছাড়া মাল কেবল একটাই পণ্যের, আর
     * তার সুইচ চালু। ⛔ অর্থাৎ ছাঁকনিটা কোনোদিন কিছু ছাঁকেনি, অথচ
     * দাবিগুলো তা টেরও পায়নি।
     *
     * ⚠️ চাল-ডাল-সাবানের সারিও লট ছাড়া, আর চিরকাল থাকবে। ⓘ ওগুলো
     * তালিকায় এলে সেটা কোনোদিন খালি হত না — আর খালি হওয়াটাই এখানে
     * "কাজ শেষ"-এর একমাত্র চিহ্ন।
     */
    public function test_a_product_that_keeps_no_lots_never_waits_here(): void
    {
        $soap = Product::query()->whereKeyNot($this->product->id)->firstOrFail();

        $this->assertFalse((bool) $soap->track_batch, 'নমুনা পণ্যটারই লট চালু — দাবিটা কিছু মাপছে না।');

        $stock = app(StockService::class);

        $stock->move(
            product: $soap, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 6002, unplaced: '80',
        );

        $stock->place(
            product: $soap, warehouse: $this->warehouse,
            qty: '80', sourceType: 'purchase_bill', sourceId: 6002,
        );

        $this->assertStringNotContainsString($soap->code, $this->waitingPanel(),
            'লট ধরা হয় না এমন পণ্যও "বাকি" তালিকায় বসে আছে।');
    }

    /** লট ধরে আসা মাল — যার লট আগে থেকেই জানা। */
    private function arrivedWithALot(string $qty): void
    {
        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'LOT-KNOWN',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $stock = app(StockService::class);

        $stock->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 6003, unplaced: $qty, batch: $batch,
        );

        $stock->place(
            product: $this->product, warehouse: $this->warehouse,
            qty: $qty, sourceType: 'purchase_bill', sourceId: 6003, batch: $batch,
        );
    }

    /** লট ধরা শুরুর আগে ঢোকা মাল — বসানো পর্যন্ত, কোনো লট ছাড়াই। */
    private function arrivedWithoutALot(string $qty): void
    {
        $stock = app(StockService::class);

        $stock->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 6001, unplaced: $qty,
        );

        $stock->place(
            product: $this->product, warehouse: $this->warehouse,
            qty: $qty, sourceType: 'purchase_bill', sourceId: 6001,
        );
    }

    /** @return list<\App\Modules\Inventory\Models\StockMovement> */
    private function giveItALot(string $qty): array
    {
        $batch = Batch::query()->firstOrCreate(
            ['product_id' => $this->product->id, 'batch_no' => 'LOT-OPENING'],
            ['expiry_date' => now()->addYear()->toDateString()],
        );

        return app(StrandedStock::class)->giveItALot(
            product: $this->product, warehouse: $this->warehouse,
            batch: $batch, qty: $qty,
        );
    }

    /** @return array{challan: mixed, invoice: mixed, change: string} */
    private function sell(string $qty): array
    {
        return app(DirectSaleService::class)->complete(
            [
                'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100', 'free_qty' => '0']],
        );
    }
}
