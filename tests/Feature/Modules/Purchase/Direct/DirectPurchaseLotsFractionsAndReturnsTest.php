<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সরাসরি ক্রয় — লট, ভগ্নাংশ আর ফেরত (চেকলিস্ট §২, ৮–১০)।
 *
 * ── ⭐ কী মাপা হয় ─────────────────────────────────────────────────────
 *   ৮. লট ও মেয়াদওয়ালা পণ্য লটসহ ঢোকে — লটের পরিমাণ ও দাম ঠিক।
 *   ৯. ০.৭ কেজি ৳১০০-তে — দামের স্তর যা এসেছে তার চেয়ে বড় হতে পারে না।
 *  ১০. সরাসরি ক্রয়ের পর ফেরত — মাল ও দেনা হুবহু কমে; আর তাকে না-তোলা
 *      (অপেক্ষার ঘরের) মালও ফেরত দেওয়া যায়।
 *
 * ── ⛔ প্রতিটা পরীক্ষায় §১-এর "পাঁচ মিল", হাতে গোনা অঙ্কে ─────────────
 *   ১. খাতা — ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া (অন্য কোনো খাত নড়েনি)
 *   ২. মাল — পরিমাণ ও মূল্য (FIFO স্তর); মজুদ খাতের নড়াচড়া = স্তরের মূল্যের নড়াচড়া
 *   ৩. সরবরাহকারীর খাতা = তার দেনার অংশ
 *   ৪. নগদ/ব্যাংক — হাতে গোনা
 *   ৫. লাভ-ক্ষতি — না নড়ার কথা হলে নড়েনি
 *
 * ⓘ প্রত্যাশিত অঙ্ক কোডের নিজের হিসাব থেকে নেওয়া হয় না — নিলে দাবিটা
 * কখনো লাল হত না। প্রতিটা সংখ্যার পাশে হাতের হিসাবটা লেখা।
 *
 * ⓘ [[assertFiveMatches()]] এখন এই ক্লাসের নিজের; ভাগের trait এলে ডাকের
 * আকার (স্ন্যাপশট + প্রত্যাশা) বদলাতে হবে না, কেবল পদ্ধতিটা সরবে।
 *
 * ⚠️ প্রতিটা পরীক্ষা **নতুন পণ্য** বানায়। ডেমোর পণ্যে আগে থেকেই তাকে মাল
 * থাকলে "অপেক্ষার ঘরের মাল ফেরত" দাবিটা পুরনো মাল দেখে ভুল কারণে সবুজ হত।
 */
final class DirectPurchaseLotsFractionsAndReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    /** প্রধান নগদ তহবিলের খাত — কাউন্টারের টাকা এখান থেকে যায় */
    private string $tillCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->tillCode = (string) Account::query()->whereKey($till->account_id)->valueOrFail('code');

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    // ── ৮. লট ও মেয়াদ ─────────────────────────────────────────────────────

    /**
     * ⭐ একই পণ্যের দুইটা লট এক বিলে — প্রতিটা লট নিজের পরিমাণ, মেয়াদ আর
     * দাম নিয়ে ঢোকে, আর পাঁচটা মিলই হুবহু।
     *
     * ```
     * লট NAPA-A   ৬০ × ৳১২   = ৳৭২০.০০০০   মেয়াদ ২০২৭-০৬-৩০
     * লট NAPA-B   ৪০ × ৳১৫   = ৳৬০০.০০০০   মেয়াদ ২০২৭-১২-৩১
     * বিল মোট                = ৳১,৩২০.০০০০   Dr ১১২০ / Cr ২১১১
     * কাউন্টারে নগদ          = ৳৫০০.০০০০     Dr ২১১১ / Cr নগদ
     * সরবরাহকারীর দেনা        = ১,৩২০ − ৫০০ = ৳৮২০.০০০০
     * ```
     */
    public function test_lots_arrive_with_their_own_quantity_expiry_and_value(): void
    {
        $medicine = $this->freshProduct('LOT', 'Napa 500 (lot)', trackBatch: true);

        $before = $this->snapshot($medicine);

        $result = app(DirectPurchaseService::class)->complete(
            $this->header(['paid_now' => '500']),
            [
                [
                    'product_id' => $medicine->id, 'qty' => '60', 'rate' => '12',
                    'batch_no' => 'NAPA-A', 'expiry_date' => '2027-06-30', 'mrp' => '18.50',
                ],
                [
                    'product_id' => $medicine->id, 'qty' => '40', 'rate' => '15',
                    'batch_no' => 'NAPA-B', 'expiry_date' => '2027-12-31', 'mrp' => '19.00',
                ],
            ],
        );

        $bill = $result['bill']->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status, '⛔ সরাসরি ক্রয়ের বিলটা নিশ্চিত হয়নি।');
        $this->assertSame('1320.0000', $this->money($bill->total),
            '⛔ বিলের মোট ৬০×১২ + ৪০×১৫ = ৳১,৩২০ নয়।');

        // ── লট দুইটা জন্মেছে, নিজের মেয়াদ নিয়ে ─────────────────────────
        $lotA = Batch::query()->where('product_id', $medicine->id)->where('batch_no', 'NAPA-A')->first();
        $lotB = Batch::query()->where('product_id', $medicine->id)->where('batch_no', 'NAPA-B')->first();

        $this->assertNotNull($lotA, '⛔ লট NAPA-A জন্মায়নি — সারিতে নম্বর আছে, ভাণ্ডারে লট নেই।');
        $this->assertNotNull($lotB, '⛔ লট NAPA-B জন্মায়নি।');
        $this->assertSame('2027-06-30', $lotA->expiry_date?->toDateString(),
            '⛔ NAPA-A-র মেয়াদ ২০২৭-০৬-৩০ বসেনি — FEFO আর মেয়াদ আটকানো ভুল লট ধরবে।');
        $this->assertSame('2027-12-31', $lotB->expiry_date?->toDateString(),
            '⛔ NAPA-B-র মেয়াদ ২০২৭-১২-৩১ বসেনি।');

        // ── লটের পরিমাণ — অপেক্ষার ঘরে, লট ধরে ────────────────────────────
        $this->assertSame(['floor' => '0.0000', 'unplaced' => '60.0000'], $this->lotQty($lotA),
            '⛔ NAPA-A-র ৬০টা লট ধরে অপেক্ষার ঘরে বসেনি (তাকে ০, অপেক্ষায় ৬০ হওয়ার কথা)।');
        $this->assertSame(['floor' => '0.0000', 'unplaced' => '40.0000'], $this->lotQty($lotB),
            '⛔ NAPA-B-র ৪০টা লট ধরে অপেক্ষার ঘরে বসেনি।');
        $this->assertSame('0.0000', $this->money(StockMovement::query()
            ->where('product_id', $medicine->id)->whereNull('batch_id')
            ->selectRaw('COALESCE(SUM(unplaced_change) + SUM(floor_change), 0) as q')->value('q')),
            '⛔ লট ধরা পণ্যের কিছু মাল লট ছাড়া ঢুকেছে — রিকলে ওই মাল তালিকায় উঠবে না।');

        // ── লটের দাম — প্রতিটা লাইন নিজের দরের স্তর ───────────────────────
        $this->assertSame(
            ['40.0000 @ 15.0000', '60.0000 @ 12.0000'],
            $this->layersOf($medicine, PurchaseBill::STOCK_SOURCE, $bill->id),
            '⛔ দুই লটের দামের স্তর ৬০ @ ৳১২ আর ৪০ @ ৳১৫ নয় — লটের মূল্য (৳৭২০ ও ৳৬০০) ভুল বসেছে।',
        );

        $this->assertFiveMatches($before, 'লট ধরা সরাসরি ক্রয়', [
            'ledger' => [
                StandardChart::INVENTORY => '1320.0000',          // Dr ১১২০ ১,৩২০
                StandardChart::PAYABLE => '-820.0000',            // Cr ১,৩২০ − Dr ৫০০
                $this->tillCode => '-500.0000',                   // Cr নগদ ৫০০
            ],
            'stock' => [[$medicine, '100.0000', '1320.0000']],    // ৬০ + ৪০, ৭২০ + ৬০০
            'payable' => '820.0000',
            'cash' => '-500.0000',
            'pl' => '0.0000',
        ]);

        // ── তাকে তোলা — লট ধরে, আর খাতা নড়ে না ─────────────────────────────
        $beforePlacing = $this->snapshot($medicine);

        $stock = app(StockService::class);
        $stock->place($medicine, $this->warehouse, '60', PurchaseBill::STOCK_SOURCE, $bill->id, batch: $lotA);
        $stock->place($medicine, $this->warehouse, '40', PurchaseBill::STOCK_SOURCE, $bill->id, batch: $lotB);

        $this->assertSame(['floor' => '60.0000', 'unplaced' => '0.0000'], $this->lotQty($lotA),
            '⛔ তোলার পর NAPA-A তাকে ৬০ নয়।');
        $this->assertSame(['floor' => '40.0000', 'unplaced' => '0.0000'], $this->lotQty($lotB),
            '⛔ তোলার পর NAPA-B তাকে ৪০ নয়।');

        $this->assertFiveMatches($beforePlacing, 'লট তাকে তোলা', [
            'ledger' => [],                                       // তোলা মানে জায়গা বদল, টাকা নয়
            'stock' => [[$medicine, '0.0000', '0.0000']],
            'payable' => '0.0000',
            'cash' => '0.0000',
            'pl' => '0.0000',
        ], expectNewRows: false);
    }

    // ── ৯. ভগ্নাংশ ─────────────────────────────────────────────────────────

    /**
     * ⛔ ০.৭ কেজি ৳১০০-তে — স্তর ০.৭ কেজির, ৳১০০-র; এক কেজির নয়।
     *
     * ```
     * দর ৳১৪২.৮৫৭২ / কেজি (১০০ ÷ ০.৭, চার ঘরে)
     * ০.৭ × ১৪২.৮৫৭২ = ১০০.০০০০৪ → বিল ৳১০০.০০০০
     * স্তর: ০.৭ কেজি, মোট ৳১০০.০০০০
     * ```
     *
     * ⚠️ সন্দেহ: [[CostLayerService::receiveWorth()]] ভাগশেষ ৳০.০০০১-কে
     * "১০,০০০ ভাগের এক" ধরে **এককের সংখ্যা** বানায় — `bcmul(০.০০০১, ১০০০০)`
     * = ১ — আর ০.৭ কেজির মালে ১.০ কেজির একটা স্তর বসে, মোট ৳১৪২.৮৫৭২।
     * ⓘ তখন মজুদ খাতে ৳১০০, স্তরে ৳১৪২.৮৫৭২ — পাঁচ মিলের দ্বিতীয়টা ভাঙে।
     */
    public function test_a_fraction_of_a_kilo_makes_a_layer_no_bigger_than_what_came(): void
    {
        $sugar = $this->freshProduct('FRC', 'Loose sugar (kg)');

        $before = $this->snapshot($sugar);

        $result = app(DirectPurchaseService::class)->complete(
            $this->header(),
            [['product_id' => $sugar->id, 'qty' => '0.7', 'rate' => '142.8572']],
        );

        $bill = $result['bill']->fresh();

        $this->assertSame('100.0000', $this->money($bill->total),
            'ⓘ পরীক্ষার ভিত্তি: ০.৭ × ১৪২.৮৫৭২ = ১০০.০০০০৪ → ৳১০০.০০০০ হওয়ার কথা।');

        $layers = CostLayer::query()
            ->where('product_id', $sugar->id)
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->get();

        $layerQty = $layers->reduce(fn (string $s, CostLayer $l) => bcadd($s, (string) $l->qty_in, 4), '0');
        $layerValue = $layers->reduce(
            fn (string $s, CostLayer $l) => bcadd($s, bcmul((string) $l->qty_in, (string) $l->unit_cost, 4), 4),
            '0',
        );

        $this->assertSame('0.7000', $this->money($layerQty),
            "⛔ ০.৭ কেজি এসেছে, অথচ দামের স্তরে {$layerQty} কেজি বসেছে — ভাগশেষটা এককের সংখ্যা হয়ে গেছে "
            .'(CostLayerService::receiveWorth)। পরে এই "বাড়তি" মাল বেচলে খরচ বসবে এমন মালের যা কখনো আসেনি।');
        $this->assertSame('100.0000', $this->money($layerValue),
            "⛔ ৳১০০-র মাল, অথচ স্তরের মোট ৳{$layerValue} — মজুদ খাতে ৳১০০, গুদামের মূল্যে অন্য সংখ্যা।");

        $this->assertFiveMatches($before, 'ভগ্নাংশের সরাসরি ক্রয়', [
            'ledger' => [
                StandardChart::INVENTORY => '100.0000',           // Dr ১১২০ ১০০
                StandardChart::PAYABLE => '-100.0000',            // Cr ২১১১ ১০০
            ],
            'stock' => [[$sugar, '0.7000', '100.0000']],
            'payable' => '100.0000',
            'cash' => '0.0000',
            'pl' => '0.0000',
        ]);
    }

    // ── ১০. ফেরত ───────────────────────────────────────────────────────────

    /**
     * ⭐ তাকে তোলা মাল ফেরত — মাল ও দেনা দুইটাই হুবহু কমে।
     *
     * ```
     * ক্রয়   ১০ × ৳৬০ = ৳৬০০.০০০০   Dr ১১২০ / Cr ২১১১
     * নগদ              = ৳২০০.০০০০   Dr ২১১১ / Cr নগদ   → দেনা ৳৪০০
     * ফেরত   ৩ × ৳৬০  = ৳১৮০.০০০০   Dr ২১১১ / Cr ১১২০   → দেনা ৳২২০
     * বাকি মাল ৭, স্তরে ৭ × ৳৬০ = ৳৪২০
     * ```
     */
    public function test_a_placed_direct_purchase_returns_and_stock_and_payable_fall_exactly(): void
    {
        $soap = $this->freshProduct('RTP', 'Bar soap (placed)');

        $beforeBuying = $this->snapshot($soap);

        $bill = app(DirectPurchaseService::class)->complete(
            $this->header(['paid_now' => '200']),
            [['product_id' => $soap->id, 'qty' => '10', 'rate' => '60']],
        )['bill']->fresh(['lines']);

        $this->assertFiveMatches($beforeBuying, 'ক্রয় (ফেরতের আগে)', [
            'ledger' => [
                StandardChart::INVENTORY => '600.0000',
                StandardChart::PAYABLE => '-400.0000',            // Cr ৬০০ − Dr ২০০
                $this->tillCode => '-200.0000',
            ],
            'stock' => [[$soap, '10.0000', '600.0000']],
            'payable' => '400.0000',
            'cash' => '-200.0000',
            'pl' => '0.0000',
        ]);

        app(StockService::class)->place($soap, $this->warehouse, '10', PurchaseBill::STOCK_SOURCE, $bill->id);

        $this->assertSame('10.0000', $this->money(app(StockService::class)->floorQty($soap, $this->warehouse)),
            'ⓘ পরীক্ষার ভিত্তি: দশটাই তাকে ওঠার কথা।');

        $beforeReturn = $this->snapshot($soap);

        $return = $this->returnOf($bill, $soap, '3');

        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, '⛔ ফেরতটা নিশ্চিত হয়নি।');
        $this->assertSame('180.0000', $this->money($return->total), '⛔ ফেরতের মোট ৩ × ৳৬০ = ৳১৮০ নয়।');
        $this->assertSame('180.0000', $this->money($return->cost_of_goods),
            '⛔ ফেরত মালের দাম ঐ বিলের স্তর (৳৬০) থেকে আসেনি।');
        $this->assertSame(['7.0000 @ 60.0000'], $this->layersOf($soap, PurchaseBill::STOCK_SOURCE, $bill->id, remaining: true),
            '⛔ বিলের স্তরে ১০ − ৩ = ৭টা থাকার কথা।');
        $this->assertSame('7.0000', $this->money(app(StockService::class)->floorQty($soap, $this->warehouse)),
            '⛔ তোলা মাল ফেরত গেছে, অথচ তাক থেকে ঠিক ৩টা কমেনি।');

        $this->assertFiveMatches($beforeReturn, 'তোলা মালের ফেরত', [
            'ledger' => [
                StandardChart::INVENTORY => '-180.0000',          // Cr ১১২০ ১৮০
                StandardChart::PAYABLE => '180.0000',             // Dr ২১১১ ১৮০
            ],
            'stock' => [[$soap, '-3.0000', '-180.0000']],
            'payable' => '-180.0000',
            'cash' => '0.0000',
            'pl' => '0.0000',
        ]);

        // ⓘ গোটা পথের শেষ: দেনা ৬০০ − ২০০ − ১৮০ = ৪২০ − ২০০ = ২২০
        $this->assertSame('220.0000', bcsub($this->payable(), $beforeBuying['payable'], 4),
            '⛔ ক্রয় − নগদ − ফেরতের পর সরবরাহকারীর দেনা ৳২২০ নয়।');
    }

    /**
     * ⛔ গাড়ি থেকে নামা, তাকে না-তোলা মাল ফেরত দেওয়া যায়।
     *
     * ```
     * ক্রয়   ১০ × ৳৬০ = ৳৬০০ — সব অপেক্ষার ঘরে, তাকে ০
     * ফেরত   ৩ × ৳৬০ = ৳১৮০ — অপেক্ষার ঘর থেকে; তাক ছোঁয় না
     * বাকি: অপেক্ষায় ৭, তাকে ০, স্তরে ৭ × ৳৬০ = ৳৪২০
     * ```
     *
     * ⚠️ সন্দেহ: [[PurchaseReturnService::confirm()]] নিজেই মালটা আগে
     * অপেক্ষার ঘর থেকে নেয় — কিন্তু তার আগের পাহারা `assertEnoughInStock()`
     * দেখে `availableQty()`, যা **কেবল তাকের** মাল গোনে। ফলে দশটা হাতের
     * সামনে থাকতেও বলে "গুদামে আছে ০"।
     */
    public function test_goods_still_waiting_to_be_placed_can_be_returned(): void
    {
        $soap = $this->freshProduct('RTU', 'Bar soap (unplaced)');

        $bill = app(DirectPurchaseService::class)->complete(
            $this->header(),
            [['product_id' => $soap->id, 'qty' => '10', 'rate' => '60']],
        )['bill']->fresh(['lines']);

        $states = app(StockService::class)->statesFor($soap, $this->warehouse);

        $this->assertSame(['0.0000', '10.0000'], [$this->money($states['floor']), $this->money($states['unplaced'])],
            'ⓘ পরীক্ষার ভিত্তি: দশটাই অপেক্ষার ঘরে, তাকে একটাও নয়।');

        $beforeReturn = $this->snapshot($soap);

        try {
            $return = $this->returnOf($bill, $soap, '3');
        } catch (ValidationException $refused) {
            $this->fail('⛔ তাকে না-তোলা ১০টার ৩টা ফেরত দিতে দিল না: "'
                .implode(' | ', array_merge(...array_values($refused->errors())))
                .'" — PurchaseReturnService::assertEnoughInStock() গোনে availableQty() (কেবল তাক), '
                .'অথচ confirm() নিজেই আগে অপেক্ষার ঘর থেকে নেয়।');
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, '⛔ ফেরতটা নিশ্চিত হয়নি।');
        $this->assertSame('180.0000', $this->money($return->total), '⛔ ফেরতের মোট ৩ × ৳৬০ = ৳১৮০ নয়।');

        $after = app(StockService::class)->statesFor($soap, $this->warehouse);

        $this->assertSame(['0.0000', '7.0000'], [$this->money($after['floor']), $this->money($after['unplaced'])],
            '⛔ ফেরত অপেক্ষার ঘর থেকে যায়নি — তাক ০ আর অপেক্ষায় ৭ থাকার কথা।');

        $this->assertFiveMatches($beforeReturn, 'না-তোলা মালের ফেরত', [
            'ledger' => [
                StandardChart::INVENTORY => '-180.0000',
                StandardChart::PAYABLE => '180.0000',
            ],
            'stock' => [[$soap, '-3.0000', '-180.0000']],
            'payable' => '-180.0000',
            'cash' => '0.0000',
            'pl' => '0.0000',
        ]);
    }

    /**
     * ⛔ তাক + অপেক্ষার ঘর মিলিয়েও যা নেই, তা ফেরত যায় না — আর কিছুই বসে না।
     *
     * ```
     * ক্রয়   ১০ × ৳৬০ — সব অপেক্ষার ঘরে
     * তোলা   ৪ তাকে   → তাকে ৪, অপেক্ষায় ৬
     * বের    ২ তাক থেকে (বিক্রির মতো) → তাকে ২, অপেক্ষায় ৬ = হাতে ৮
     * ফেরত   ৯ চাওয়া — বিলের সীমা (১০) পেরোয় না, কিন্তু হাতে ৮
     * ```
     *
     * ⓘ ৯ বেছে নেওয়া ইচ্ছাকৃত: বিলের পাহারা ([[assertWithinBilled()]])
     * একে পাস করায়, তাই থামাতে হবে **মালের** পাহারাকেই। আর বার্তায়
     * সংখ্যাটা ৮ — তাক + অপেক্ষা; পুরনো হিসাব (কেবল তাক) বলত ২।
     */
    public function test_more_than_shelf_and_waiting_together_is_refused_and_posts_nothing(): void
    {
        $soap = $this->freshProduct('RTX', 'Bar soap (over)');

        $bill = app(DirectPurchaseService::class)->complete(
            $this->header(),
            [['product_id' => $soap->id, 'qty' => '10', 'rate' => '60']],
        )['bill']->fresh(['lines']);

        $stock = app(StockService::class);
        $stock->place($soap, $this->warehouse, '4', PurchaseBill::STOCK_SOURCE, $bill->id);
        $stock->move($soap, $this->warehouse, 'test_issue', 1, floor: '-2');

        $states = $stock->statesFor($soap, $this->warehouse);

        $this->assertSame(['2.0000', '6.0000'], [$this->money($states['floor']), $this->money($states['unplaced'])],
            'ⓘ পরীক্ষার ভিত্তি: তাকে ২, অপেক্ষায় ৬ — হাতে মোট ৮।');

        $beforeReturn = $this->snapshot($soap);
        $movementsBefore = StockMovement::query()->where('product_id', $soap->id)->count();

        $service = app(PurchaseReturnService::class);

        $return = $service->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_bill_id' => $bill->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $soap->id,
            'qty' => '9',
            'purchase_bill_line_id' => $bill->lines->firstWhere('product_id', $soap->id)->id,
        ]]);

        try {
            $service->confirm($return);
            $this->fail('⛔ হাতে ৮ (তাকে ২ + অপেক্ষায় ৬), অথচ ৯টা ফেরত নিশ্চিত হয়ে গেল — স্টক ঋণাত্মক হল।');
        } catch (ValidationException $refused) {
            $this->assertSame(
                [__('purchase::validation.not_enough_to_return', ['product' => $soap->name(), 'available' => '8'])],
                $refused->errors()['lines'] ?? [],
                '⛔ থামল, কিন্তু মালের পাহারার বার্তায় নয়, বা সংখ্যাটা ৮ নয় (তাক ২ + অপেক্ষা ৬) — '
                .'বার্তা ভুল সংখ্যা বললে লোকে ভুল পরিমাণে আবার চেষ্টা করবে।',
            );
        }

        $this->assertSame(DocumentStatus::DRAFT, $return->fresh()->status,
            '⛔ থামানো ফেরতটা খসড়া থেকে সরে গেছে।');
        $this->assertSame($movementsBefore, StockMovement::query()->where('product_id', $soap->id)->count(),
            '⛔ থামানো ফেরত মালের খতিয়ানে সারি রেখে গেছে।');

        $after = $stock->statesFor($soap, $this->warehouse);

        $this->assertSame(['2.0000', '6.0000'], [$this->money($after['floor']), $this->money($after['unplaced'])],
            '⛔ থামানো ফেরতের পরেও তাক বা অপেক্ষার ঘর নড়েছে।');

        $this->assertFiveMatches($beforeReturn, 'থামানো ফেরত', [
            'ledger' => [],
            'stock' => [[$soap, '0.0000', '0.0000']],
            'payable' => '0.0000',
            'cash' => '0.0000',
            'pl' => '0.0000',
        ], expectNewRows: false);
    }

    // ── পাঁচ মিল ────────────────────────────────────────────────────────────

    /**
     * একটা মুহূর্তের ছবি — পরে যা নড়ল তা এর সাথে তুলনা করে মাপা হয়।
     *
     * @return array<string, mixed>
     */
    private function snapshot(Product ...$products): array
    {
        $stock = app(StockService::class);
        $layers = app(CostLayerService::class);

        $perProduct = [];

        foreach ($products as $product) {
            $perProduct[$product->id] = [
                'qty' => $this->money($stock->statesFor($product, $this->warehouse)['on_hand']),
                'layer_qty' => $this->money($layers->qtyOnHand($product)),
                'layer_value' => $this->money($layers->valueOnHand($product)),
            ];
        }

        return [
            'last_ledger_id' => (int) (LedgerEntry::query()->max('id') ?? 0),
            'accounts' => $this->accountNets(),
            'layer_value' => $this->allLayersValue(),
            'products' => $perProduct,
            'payable' => $this->payable(),
        ];
    }

    /**
     * ⭐ চেকলিস্ট §১-এর পাঁচ মিল — স্ন্যাপশটের পর যা নড়েছে তা হাতে গোনা
     * অঙ্কের সাথে।
     *
     * @param  array<string, mixed>  $before  [[snapshot()]]
     * @param  array{ledger: array<string, string>, stock: list<array{0: Product, 1: string, 2: string}>, payable: string, cash: string, pl: string}  $expect
     */
    private function assertFiveMatches(array $before, string $label, array $expect, bool $expectNewRows = true): void
    {
        // ── ১. খাতা ─────────────────────────────────────────────────────
        $newRows = LedgerEntry::query()->where('id', '>', $before['last_ledger_id'])->get();

        if ($expectNewRows) {
            $this->assertNotEmpty($newRows, "⛔ {$label}: খাতায় একটা সারিও বসেনি — মেলানোর কিছুই নেই।");
        } else {
            $this->assertCount(0, $newRows, "⛔ {$label}: টাকার কোনো ঘটনা নয়, অথচ খাতায় সারি বসেছে।");
        }

        foreach ($newRows->groupBy(fn ($r) => $r->source_type.'#'.$r->source_id) as $document => $rows) {
            $debit = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->debit, 4), '0');
            $credit = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->credit, 4), '0');

            $this->assertSame($this->money($debit), $this->money($credit),
                "⛔ {$label}: কাগজ {$document}-এ ডেবিট {$debit} আর ক্রেডিট {$credit} মেলে না।");
        }

        $totals = LedgerEntry::query()->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $this->assertSame($this->money($totals->d), $this->money($totals->c),
            "⛔ {$label}: গোটা কোম্পানির খাতায় ডেবিট আর ক্রেডিট মেলে না — রেওয়ামিল ভাঙা।");

        $moved = $this->movedAccounts($before['accounts']);
        $expected = array_map(fn ($v) => $this->money($v), $expect['ledger']);
        ksort($expected);

        $this->assertSame($expected, $moved,
            "⛔ {$label}: খাতগুলোর নড়াচড়া হাতের হিসাবের সাথে মেলে না (খাতের কোড => ডেবিট − ক্রেডিট)।");

        // ── ২. মাল — পরিমাণ, স্তরের পরিমাণ ও মূল্য; মজুদ খাত = স্তরের মূল্য ──
        $stock = app(StockService::class);
        $layers = app(CostLayerService::class);

        foreach ($expect['stock'] as [$product, $qty, $value]) {
            $was = $before['products'][$product->id];
            $name = $product->code;

            $this->assertSame($this->money($qty),
                bcsub($this->money($stock->statesFor($product, $this->warehouse)['on_hand']), $was['qty'], 4),
                "⛔ {$label}: {$name}-এর গুদামের মোট মাল (তাক + অপেক্ষা) হাতের হিসাবে নড়েনি।");
            $this->assertSame($this->money($qty),
                bcsub($this->money($layers->qtyOnHand($product)), $was['layer_qty'], 4),
                "⛔ {$label}: {$name}-এর দামের স্তরে মালের পরিমাণ গুদামের সাথে মেলে না।");
            $this->assertSame($this->money($value),
                bcsub($this->money($layers->valueOnHand($product)), $was['layer_value'], 4),
                "⛔ {$label}: {$name}-এর স্তরের মূল্য (FIFO) হাতের হিসাবে নড়েনি।");
        }

        $inventoryMoved = bcsub(
            $this->accountNets()[StandardChart::INVENTORY] ?? '0.0000',
            $before['accounts'][StandardChart::INVENTORY] ?? '0.0000',
            4,
        );

        $this->assertSame($this->money($inventoryMoved), bcsub($this->allLayersValue(), $before['layer_value'], 4),
            "⛔ {$label}: উদ্বৃত্তপত্রের মজুদ খাত (১১২০) যতটা নড়েছে, গুদামের মূল্য (সব স্তর) ততটা নড়েনি।");

        // ── ৩. সরবরাহকারীর খাতা ─────────────────────────────────────────────
        $this->assertSame($this->money($expect['payable']), bcsub($this->payable(), $before['payable'], 4),
            "⛔ {$label}: সরবরাহকারীর খাতায় দেনা হাতের হিসাবে নড়েনি।");

        // ── ৪. নগদ/ব্যাংক ───────────────────────────────────────────────────
        $moneyCodes = Account::query()->money()->pluck('code')->map(fn ($c) => (string) $c)->all();

        $this->assertSame($this->money($expect['cash']), $this->sumOf($moved, $moneyCodes),
            "⛔ {$label}: নগদ/ব্যাংক খাতগুলো হাতে গোনা অঙ্কে নড়েনি।");

        // ── ৫. লাভ-ক্ষতি ────────────────────────────────────────────────────
        $plCodes = Account::query()->ofType([Account::INCOME, Account::EXPENSE])
            ->pluck('code')->map(fn ($c) => (string) $c)->all();

        $this->assertSame($this->money($expect['pl']), $this->sumOf($moved, $plCodes),
            "⛔ {$label}: লাভ-ক্ষতির খাত নড়েছে, অথচ এই ঘটনায় কোনো আয়-ব্যয় নেই।");
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function header(array $extra = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'DP-'.fake()->unique()->numberBetween(10000, 99999),
            ...$extra,
        ];
    }

    private function returnOf(PurchaseBill $bill, Product $product, string $qty): PurchaseReturn
    {
        $service = app(PurchaseReturnService::class);

        $return = $service->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_bill_id' => $bill->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $product->id,
            'qty' => $qty,
            'purchase_bill_line_id' => $bill->lines->firstWhere('product_id', $product->id)->id,
        ]]);

        return $service->confirm($return)->fresh();
    }

    /**
     * একেবারে নতুন পণ্য — তাকে কিছু নেই, স্তর নেই, ভ্যাট নেই।
     */
    private function freshProduct(string $prefix, string $name, bool $trackBatch = false): Product
    {
        $product = Product::query()->create([
            'code' => $prefix.'-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $product->forceFill(['track_batch' => $trackBatch, 'tax_id' => null])->save();

        return $product->fresh();
    }

    /** @return array{floor: string, unplaced: string} */
    private function lotQty(Batch $lot): array
    {
        $row = StockMovement::query()
            ->where('batch_id', $lot->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->selectRaw('COALESCE(SUM(floor_change), 0) as f, COALESCE(SUM(unplaced_change), 0) as u')
            ->first();

        return ['floor' => $this->money($row->f), 'unplaced' => $this->money($row->u)];
    }

    /** @return list<string> "পরিমাণ @ একক দর", সাজানো */
    private function layersOf(Product $product, string $sourceType, int $sourceId, bool $remaining = false): array
    {
        $rows = CostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->get()
            ->map(fn (CostLayer $l) => $this->money($remaining ? $l->qty_remaining : $l->qty_in)
                .' @ '.$this->money($l->unit_cost))
            ->sort()
            ->values()
            ->all();

        return $rows;
    }

    /** @return array<string, string> খাতের কোড => ডেবিট − ক্রেডিট, গোটা কোম্পানিতে */
    private function accountNets(): array
    {
        $codes = Account::query()->pluck('code', 'id');

        $nets = [];

        foreach (LedgerEntry::query()
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->get() as $row) {
            $code = (string) ($codes[$row->account_id] ?? 'id:'.$row->account_id);
            $nets[$code] = bcadd($nets[$code] ?? '0', $this->money($row->net), 4);
        }

        return $nets;
    }

    /**
     * @param  array<string, string>  $before
     * @return array<string, string> যে খাতগুলো নড়েছে, কোড ধরে সাজানো
     */
    private function movedAccounts(array $before): array
    {
        $now = $this->accountNets();
        $moved = [];

        foreach (array_unique([...array_keys($before), ...array_keys($now)]) as $code) {
            $delta = bcsub($now[$code] ?? '0', $before[$code] ?? '0', 4);

            if (bccomp($delta, '0', 4) !== 0) {
                $moved[(string) $code] = $delta;
            }
        }

        ksort($moved);

        return $moved;
    }

    /**
     * @param  array<string, string>  $moved
     * @param  list<string>  $codes
     */
    private function sumOf(array $moved, array $codes): string
    {
        $sum = '0';

        foreach ($moved as $code => $delta) {
            if (in_array((string) $code, $codes, true)) {
                $sum = bcadd($sum, $delta, 4);
            }
        }

        return $this->money($sum);
    }

    /** সব পণ্যের সব স্তরে যা পড়ে আছে তার মোট মূল্য। */
    private function allLayersValue(): string
    {
        return CostLayer::query()
            ->where('qty_remaining', '>', 0)
            ->get(['qty_remaining', 'unit_cost'])
            ->reduce(fn (string $s, CostLayer $l) => bcadd($s, bcmul((string) $l->qty_remaining, (string) $l->unit_cost, 4), 4), '0.0000');
    }

    private function payable(): string
    {
        return $this->money($this->supplier->fresh()->payable());
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }
}
