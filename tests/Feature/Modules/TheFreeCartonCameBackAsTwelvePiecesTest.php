<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "১ কার্টন ফ্রি" লেখা হলো — কাগজটা ফেরত এল কী নিয়ে?
 *
 * ── মালিকের নকশা (৪ সেপ্টেম্বর ২০২৬) ─────────────────────────────────
 * সরাসরি ক্রয়ের সারিতে চারটা ঘর: `QTY. · UOM · FREE QTY · UOM`। অর্থাৎ
 * ফ্রি পরিমাণের **নিজের একক** আছে — মিল কার্টনে বেচে, ফ্রি দেয় পিসে।
 *
 * ── এখানে যা যাচাই হয়, আর যা হয় না ──────────────────────────────────
 * পর্দাটা খোলে কিনা নয়। এখানে দেখা হয় সংখ্যাটা **সত্যিই নামে** কিনা
 * (কার্টন → পিস), আর **যা লেখা হয়েছিল সেটা ফেরত আসে** কিনা।
 *
 * ⚠️ দ্বিতীয়টাই এই কাজের কারণ। রূপান্তরটা `free_unit_id` ছাড়াও করা
 * যেত, কিন্তু তাতে বিলটা আবার খুললে "১ কার্টন" ফিরত "১২ পিস" হয়ে —
 * ⛔ **আর কেউ বলতে পারত না মালিক কী লিখেছিলেন**।
 *
 * ⓘ দামের পরিমাণে রিপো এই প্রশ্নটার উত্তর আগেই দিয়েছে
 * (`entered_qty` + `entered_unit_id`); এই জোড়াটা তার হুবহু নকল।
 */
class TheFreeCartonCameBackAsTwelvePiecesTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $carton;

    private Product $product;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();

        /*
         * ১ কার্টন = ১২ পিস — ছোট সংখ্যা, তাই ভুল হলে চোখেই ধরা পড়ে।
         *
         * ⚠️ `create()` নয়, `firstOrCreate()`। ⛔ DemoSeeder ঐ কোম্পানিতে
         * `CTN` আগেই বসিয়ে রাখে, আর `mdm_units`-এ (company_id, code)
         * ইউনিক — তাই `create()` দশটা টেস্টেই একই ৫০০ দিত:
         *
         *     SQLSTATE[23000]: Duplicate entry '17-CTN'
         *
         * ⓘ সংখ্যাটা কোম্পানির আইডি, তাই প্রতিটা টেস্টেই নতুন কোম্পানি
         * আর প্রতিটাতেই একই সংঘর্ষ। ⚠️ বার্তাটা টেবিলের নামও বলে না,
         * তাই কারণ খুঁজতে সময় যায়।
         */
        $this->carton = Unit::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'code' => 'CTN'],
            [
                'name_en' => 'Carton', 'name_bn' => 'কার্টন',
                'base_unit_id' => $this->piece->id, 'factor' => '12', 'is_active' => true,
            ],
        );

        /*
         * ⚠️ সিডারের সারিটা এলে তার রূপান্তরও আমাদের ধরে নেওয়া মানটাই
         * কি না — সেটা যাচাই না করলে পরীক্ষাগুলো **অন্য সংখ্যা** মাপত।
         * ⓘ না মিললে এখানেই বসিয়ে দেওয়া হয়, কারণ ১২-এর উপরেই পুরো
         * ফাইলের প্রতিটা প্রত্যাশা দাঁড়িয়ে।
         */
        $this->carton->forceFill([
            'base_unit_id' => $this->piece->id,
            'factor' => '12',
        ])->save();

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['unit_id' => $this->piece->id])->save();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function buy(array $line): \App\Modules\Purchase\Models\PurchaseBillLine
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [$line + ['product_id' => $this->product->id]],
        );

        return $result['bill']->lines->firstOrFail();
    }

    /**
     * ১৫% ভ্যাটের সারিটা — দুইটা পরীক্ষায় একই।
     *
     * ⚠️ `create()` নয় — `mdm_taxes`-এও (company_id, code) ইউনিক, আর
     * সিডার `VAT15` আগেই বসিয়ে রাখতে পারে। ⓘ হুবহু `CTN`-এর ফাঁদ।
     *
     * ⛔ আর হারটা জোর করে বসানো: সিডারের সারিটা এলে তার হার অন্য হলে
     * পরীক্ষাগুলো **সবুজ হয়েও অন্য সংখ্যা মাপত** — ১৫০ দাঁড়িয়ে আছে
     * ১৫%-এর উপরে, ১০০০ × ১৫÷১০০।
     */
    private function fifteenPercentVat(): Tax
    {
        $tax = Tax::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'code' => 'VAT15'],
            [
                'name_en' => 'VAT 15%', 'name_bn' => 'ভ্যাট ১৫%',
                'rate' => '15', 'kind' => 'vat', 'is_inclusive' => false, 'is_active' => true,
            ],
        );

        $tax->forceFill(['rate' => '15', 'is_inclusive' => false, 'is_active' => true])->save();

        return $tax;
    }

    private function assertQty(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$actual} ≠ {$expected}");
    }

    /**
     * ⭐ এই পরীক্ষাটাই কাজটার কারণ।
     *
     * ১০ কার্টন কেনা হলো, আর ফ্রি এল **১ কার্টন** — কেনা পরিমাণের
     * এককেই, কিন্তু নিজের ঘরে লেখা।
     */
    public function test_a_free_carton_is_remembered_as_a_carton(): void
    {
        $line = $this->buy([
            'qty' => '10',
            'unit_id' => $this->carton->id,
            'free_qty' => '1',
            'free_unit_id' => $this->carton->id,
            'rate' => '1200',
        ]);

        // মজুদের ঘরগুলো মূল এককে — ওখানেই প্রতিটা হিসাব চলে
        $this->assertQty('120', $line->qty);
        $this->assertQty('12', $line->free_qty);

        // ⭐ আর যা লেখা হয়েছিল সেটাও রইল
        $this->assertQty('1', $line->entered_free_qty);
        $this->assertSame($this->carton->id, $line->free_unit_id);
    }

    /**
     * ⚠️ আসল ঘটনাটা — দুইটা একক এক সারিতে।
     *
     * "১০ কার্টন কিনলাম, সাথে ১ পিস ফ্রি"। ⛔ আগের কোডে এটা লেখাই যেত
     * না: ফ্রি পরিমাণ **লাইনের** একক ধরে নামত, তাই ১ পিস হয়ে যেত ১২
     * পিস — বারো গুণ বেশি মাল, নীরবে।
     */
    public function test_free_goods_may_come_in_a_different_unit(): void
    {
        $line = $this->buy([
            'qty' => '10',
            'unit_id' => $this->carton->id,
            'free_qty' => '1',
            'free_unit_id' => $this->piece->id,
            'rate' => '1200',
        ]);

        $this->assertQty('120', $line->qty);

        // ⛔ ১২ নয় — এক পিসই এক পিস
        $this->assertQty('1', $line->free_qty);

        /*
         * ⓘ পণ্যের নিজের একক এলে জোড়াটা খালিই থাকে — "NULL মানে যেভাবে
         * সবসময় হত"। বসিয়ে দিলে কাগজে "১ পিস (১ পিস)" ছাপা হত।
         */
        $this->assertNull($line->free_unit_id);
    }

    /**
     * ⚠️ পুরনো ডাকগুলো ভাঙেনি।
     *
     * API, ইমপোর্ট আর সিডার `free_unit_id` পাঠায় না। ⓘ না এলে আগের
     * নিয়মই — লাইনের একক। ⛔ এই পরীক্ষাটা না থাকলে একদিন কেউ
     * fallback-টা সরিয়ে দিতেন, আর ওই ডাকগুলোর ফ্রি পরিমাণ নীরবে
     * বারো ভাগের এক ভাগ হয়ে যেত।
     */
    public function test_without_a_free_unit_the_line_unit_still_rules(): void
    {
        $line = $this->buy([
            'qty' => '10',
            'unit_id' => $this->carton->id,
            'free_qty' => '1',
            'rate' => '1200',
        ]);

        $this->assertQty('12', $line->free_qty);
        $this->assertQty('1', $line->entered_free_qty);
        $this->assertSame($this->carton->id, $line->free_unit_id);
    }

    /**
     * ⭐ ভ্যাটের তিনটা ধরন — পর্দার ড্রপডাউনের তিনটা মান।
     *
     * ⚠️ ঘরটা **ফাঁকা রাখা** আর **০ লেখা** দুইটা সম্পূর্ণ আলাদা কাজ, আর
     * এতদিন পর্দা সেটা কোথাও বলত না। ⓘ ড্রপডাউনটা ওই নীরব পার্থক্যটাকে
     * দৃশ্যমান করে, আর এই পরীক্ষাটা দেখায় পার্থক্যটা সত্যিই আছে।
     */
    public function test_an_empty_vat_box_takes_the_products_own_rate(): void
    {
        $this->product->forceFill(['tax_id' => $this->fifteenPercentVat()->id])->save();

        // ঘরটা পাঠানোই হলো না — "পণ্য অনুযায়ী"
        $byProduct = $this->buy(['qty' => '10', 'rate' => '100']);
        $this->assertQty('150', $byProduct->tax);

        // আর ০ পাঠানো হলো — "ভ্যাট নেই"
        $none = $this->buy(['qty' => '10', 'rate' => '100', 'tax' => '0']);
        $this->assertQty('0', $none->tax);

        /*
         * ⭐ পার্থক্যটা নীরব নয়, লেখা থাকে: হাতে দেওয়া অঙ্ক পণ্যের হার
         * থেকে কতটা সরে আছে সেটাও সারিতে বসে ([[CalculatesLineTotals]])।
         */
        $this->assertQty('-150', $none->tax_variance);
    }

    /**
     * ⛔ বাক্সে কিনলে পণ্যের গায়ের দাম বাক্সের দামই হয়ে বসত।
     *
     * ── কী ভাঙা ছিল (৫ সেপ্টেম্বর ২০২৬-এ ধরা) ───────────────────────
     * `DirectPurchaseService::stampSalesPrices()` **পর্দা থেকে আসা কাঁচা
     * সংখ্যা** পড়ত, বিলের সারির নামানো সংখ্যা নয়। ⚠️ *"১ কার্টন @
     * ১২০০"* লিখলে পর্দার `rate` মানে **কার্টনের দাম**, অথচ পণ্যের গায়ের
     * দাম **পিসের**।
     *
     * ```
     * purchase_price   ১২০০ বসত, ১০০-র বদলে
     * sale_price       ১৫০ বসত, ১২.৫০-র বদলে
     * ```
     *
     * ⛔ দ্বিতীয়টার দাম সবচেয়ে বেশি: **পরদিন কাউন্টারে প্রতিটা পিস
     * কার্টনের দামে বিক্রি হত**, আর কেউ টের পেত না।
     *
     * ⭐ [[PurchaseBillService::replaceLines()]] দুইটাকেই মূল এককে নামায়,
     * আর সেখানকার মন্তব্যে ঠিক এই বিপদটাই লেখা আছে — ⛔ কিন্তু তার পরেই
     * এই পদ্ধতিটা মাস্টারে কাঁচা সংখ্যাটাই বসিয়ে দিত। **রূপান্তরটা হত,
     * আর পরের লাইনেই মুছে যেত।**
     *
     * ⓘ কেন এতদিন কোনো টেস্ট ধরেনি: এই পর্দাটা `unit_id` **পাঠাতই না**
     * (একক ঘর দুইটা আজ বসেছে)। ⚠️ ভ্যাটের বাগটার হুবহু আকৃতি — **ঘরটা
     * না থাকায় ফাঁকটা ঘুমিয়ে ছিল।**
     */
    public function test_a_carton_price_does_not_become_the_piece_price(): void
    {
        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => '1',
                'unit_id' => $this->carton->id,   // ১ কার্টন = ১২ পিস
                'rate' => '1200',                 // কার্টনপ্রতি
                'sales_price' => '150',           // কার্টনপ্রতি
            ]],
        );

        $product = $this->product->fresh();

        // ⭐ পিসপ্রতি — ১২০০ ÷ ১২ আর ১৫০ ÷ ১২
        $this->assertQty('100', $product->purchase_price);
        $this->assertQty('12.5', $product->sale_price);
    }

    /**
     * ⛔ ভ্যাট দুইবার ডেবিট হচ্ছিল — সরাসরি ক্রয়ে, প্রতিবার।
     *
     * ── কী ভাঙা ছিল (৫ সেপ্টেম্বর ২০২৬-এ ধরা) ───────────────────────
     * চালান ছাড়া বিলে মজুদের ডেবিট বসত `line->amount` ধরে, আর **ওটা
     * ভ্যাটসহ**। ⚠️ ভ্যাটটা তার পরেই আবার আলাদা করে ডেবিট হত (উপকরণ
     * ভ্যাট), তাই:
     *
     *     ডেবিট  = মোট + ভ্যাট
     *     ক্রেডিট = মোট
     *     ফারাক  = −ভ্যাট
     *
     * ⓘ ফারাকটা মূল্য-পার্থক্যের খাতে গিয়ে পড়ত, আর পাহারাটা চালু থাকলে
     * বিলটাই আটকে যেত। ⛔ পাহারা বন্ধ থাকলে আরও খারাপ: গুদামের মালের
     * দাম ভ্যাটের পরিমাণে বেশি বসত, আর বেচার দিন মুনাফা ঠিক ততটাই কম
     * দেখাত — নীরবে, প্রতিটা ভ্যাটওয়ালা ক্রয়ে।
     *
     * ⚠️ কোডের নিজের মন্তব্যই উল্টো কথা বলত: *"ভ্যাট ফেরতযোগ্য, ওটা
     * মালের দাম নয়"*। অর্থাৎ নিয়মটা লেখা ছিল, কেবল অঙ্কটা মানত না।
     *
     * ⭐ এই পরীক্ষাটা তাই অঙ্ক নয়, **খতিয়ান** মাপে: ভ্যাটসহ একটা সরাসরি
     * ক্রয়ের পর ডেবিট আর ক্রেডিট সমান কি না।
     */
    public function test_vat_is_not_debited_twice_on_a_direct_purchase(): void
    {
        $tax = $this->fifteenPercentVat();
        $this->product->forceFill(['tax_id' => $tax->id])->save();

        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        );

        $bill = $result['bill']->fresh();

        // ভ্যাটটা সত্যিই বসেছে — নাহলে পরীক্ষাটা কিছুই মাপত না
        $this->assertQty('150', $bill->tax);

        $entries = \App\Models\LedgerEntry::query()
            ->where('source_type', \App\Modules\Purchase\Models\PurchaseBill::drillSourceType())
            ->where('source_id', $bill->id)
            ->get();

        $this->assertTrue($entries->isNotEmpty(), 'বিলটা খতিয়ানে বসেনি।');

        $debit = $entries->reduce(fn ($sum, $e) => bcadd($sum, (string) $e->debit, 4), '0');
        $credit = $entries->reduce(fn ($sum, $e) => bcadd($sum, (string) $e->credit, 4), '0');

        $this->assertSame(0, bccomp($debit, $credit, 4),
            "খতিয়ান মেলেনি — ডেবিট {$debit}, ক্রেডিট {$credit}।");

        /*
         * ⭐ আর মজুদে বসা টাকাটা **ভ্যাট ছাড়া** — ১০০০, ১১৫০ নয়।
         *
         * ⓘ কেবল "মিলেছে" মাপলে যথেষ্ট হত না: ভ্যাটটা মজুদে ঢুকিয়ে
         * ভ্যাটের খাতটা বাদ দিলেও খতিয়ান মিলত, অথচ মালের দাম ভুল হত।
         */
        $inventory = $entries->firstWhere(
            'account_id',
            \App\Modules\Accounts\Models\Account::query()
                ->where('code', \App\Modules\Accounts\Services\StandardChart::INVENTORY)
                ->value('id'),
        );

        $this->assertNotNull($inventory, 'মজুদের সারিটাই খতিয়ানে নেই।');
        $this->assertQty('1000', $inventory->debit);
    }

    /**
     * ⭐ আমদানি চালানের পাঁচটা ঘর।
     *
     * ⛔ আজ পর্যন্ত `pur_bills`-এ ওদের একটারও ঘর ছিল না, তাই নম্বরগুলো
     * হয় `narration`-এ গদ্য হয়ে বসত, নয়তো বসতই না। ⚠️ আর যেদিন ব্যাংক
     * জিজ্ঞেস করে *"কোন LC-র মাল"*, গদ্য থেকে সেটা খোঁজা যায় না।
     */
    public function test_the_import_papers_are_kept_with_the_bill(): void
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'lc_no' => 'LC-2026-4471',
                'be_no' => 'BE-889201',
                'be_date' => '2026-08-30',
                'vessel' => 'MV Banglar Joyjatra',
                'port_of_entry' => 'Chattogram',
            ],
            [['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100']],
        );

        $bill = $result['bill']->fresh();

        $this->assertSame('LC-2026-4471', $bill->lc_no);
        $this->assertSame('BE-889201', $bill->be_no);
        $this->assertSame('2026-08-30', $bill->be_date?->toDateString());
        $this->assertSame('MV Banglar Joyjatra', $bill->vessel);
        $this->assertSame('Chattogram', $bill->port_of_entry);
    }

    /**
     * ⭐ বিল ২ তারিখের, গাড়ি এল ৫ তারিখে।
     *
     * ── এই পরীক্ষাটা কী পাহারা দেয় ──────────────────────────────────
     * **খতিয়ান বিলের তারিখে, মজুদ গাড়ির তারিখে** — মালিকের আটটা Global
     * Feature-এর একটা (*Transaction vs Entry Date*)।
     *
     * ⛔ দুইটা এক করে ফেললে যেকোনো একটা মিথ্যা হত: হয় সরবরাহকারীর খাতা
     * আমাদের খাতার সাথে মিলত না, নয় জুলাইয়ের বিলের মাল জুলাইয়ের মজুদে
     * বসত যদিও আগস্টে এসেছে। ⚠️ আর দ্বিতীয়টা ধরা পড়ত মাস-শেষে, যখন আর
     * বলা যায় না কোন চালানটা ভুল ছিল।
     */
    public function test_the_ledger_takes_the_bill_date_and_the_stock_takes_the_lorry(): void
    {
        $billedOn = now()->subDays(3)->toDateString();
        $arrivedOn = now()->subDay()->toDateString();

        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => $billedOn,
                'received_on' => $arrivedOn,
            ],
            [['product_id' => $this->product->id, 'qty' => '4', 'rate' => '100']],
        );

        $bill = $result['bill']->fresh();

        $this->assertSame($billedOn, $bill->trx_date->toDateString());
        $this->assertSame($arrivedOn, $bill->received_on?->toDateString());

        /*
         * ⭐ আসল যাচাইটা এখানে — সারিটা কোন দিনে গুদামে বসল।
         *
         * ⚠️ কেবল কলামটা সেভ হয়েছে দেখলে কিছুই প্রমাণ হত না: ঘরটা ভরা
         * থাকত আর চলাচল আগের তারিখেই বসত, আর কেউ টের পেত না।
         */
        $movement = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('source_type', \App\Modules\Purchase\Models\PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->firstOrFail();

        $this->assertSame($arrivedOn, $movement->trx_date->toDateString(),
            'মালটা গাড়ির দিনে বসেনি — বিলের দিনে বসেছে।');
    }

    /**
     * ⓘ ঘরটা খালি থাকলে আগের নিয়মই — বিলের তারিখেই মাল বসে।
     *
     * ⛔ এই পরীক্ষাটা না থাকলে একদিন কেউ fallback-টা সরিয়ে দিতেন, আর
     * `received_on` না-পাঠানো প্রতিটা পুরনো ডাকে মজুদের তারিখ **খালি**
     * হয়ে যেত।
     */
    public function test_without_an_arrival_date_the_bill_date_still_rules(): void
    {
        $billedOn = now()->subDays(2)->toDateString();

        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => $billedOn,
            ],
            [['product_id' => $this->product->id, 'qty' => '4', 'rate' => '100']],
        );

        $movement = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('source_id', $result['bill']->id)
            ->firstOrFail();

        $this->assertSame($billedOn, $movement->trx_date->toDateString());
    }

    /**
     * ⭐ পর্দার ভরে রাখা নম্বরে সিরিজ **এগোয়**।
     *
     * ── কেন এই পরীক্ষাটা লেখা হলো ───────────────────────────────────
     * বিক্রয়ে ৩ সেপ্টেম্বর ২০২৬-এ ঠিক এই জায়গায় একটা বাগ ছিল: পর্দা
     * `preview()` দিয়ে নম্বরটা ভরে রাখত, ব্যবহারকারী কিছু না বদলে সেভ
     * করতেন, আর সার্ভার ওটাকে **হাতে লেখা** ধরে সিরিজ এক ধাপও এগোত না।
     * ⛔ ফল দিনের **দ্বিতীয়** বিলেই — একই নম্বর, আর ডাটাবেসের ইউনিক
     * ইনডেক্সে ৫০০।
     *
     * ⚠️ তাই দুইটা বিল করে দেখা হয়, একটা নয় — এক বিলে ভুলটা ধরাই পড়ত না।
     */
    public function test_the_number_the_screen_showed_still_moves_the_series(): void
    {
        $first = $this->post(route('purchase.direct.store'), $this->counterForm());
        $first->assertSessionHasNoErrors();

        $second = $this->post(route('purchase.direct.store'), $this->counterForm());
        $second->assertSessionHasNoErrors();

        $numbers = \App\Modules\Purchase\Models\PurchaseBill::query()
            ->orderBy('id')->pluck('document_no')->all();

        $this->assertCount(2, $numbers);
        $this->assertNotSame($numbers[0], $numbers[1],
            'দুইটা বিলে একই নম্বর — সিরিজ এগোয়নি।');
    }

    /**
     * ⛔ একই নম্বর দুইবার দিলে পড়ার মতো বার্তা, ৫০০ নয়।
     */
    public function test_a_number_already_used_says_so_in_words(): void
    {
        $this->post(route('purchase.direct.store'),
            $this->counterForm() + ['bill_no' => 'PBL-HAND-001'])
            ->assertSessionHasNoErrors();

        $this->post(route('purchase.direct.store'),
            $this->counterForm() + ['bill_no' => 'PBL-HAND-001'])
            ->assertSessionHasErrors('bill_no');
    }

    /**
     * কাউন্টারের একটা ন্যূনতম ফর্ম — প্রতিটা পরীক্ষায় একই।
     *
     * @return array<string, mixed>
     */
    private function counterForm(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '1',
                'rate' => '50',
            ]],
        ];
    }

    /**
     * ⭐ পরিশোধের শর্তটা খাতায় বসে — কেবল তারিখটা নয়।
     *
     * ── কেন দুইটাই লাগে ─────────────────────────────────────────────
     * সাতটা বিকল্পই শেষে একটা তারিখ দেয়, আর তারিখটা `due_on`-এ বসে।
     * ⚠️ কিন্তু **তারিখটা ধরনটা বলে না**: ৩০ দিনের বাকি আর মাস-শেষ — দুইটার
     * তারিখ একই দিন হতে পারে, ব্যবসায়িক অর্থ আলাদা।
     *
     * ⛔ ধরনটা না রাখলে *"এই মাসে কত মাস-শেষের শর্তে কিনলাম"* প্রশ্নের উত্তর
     * কোথাও থাকত না, যদিও ব্যবহারকারী প্রতিটা বিলে ঘরটা ভরেছেন।
     */
    public function test_the_paper_remembers_its_terms_not_just_its_date(): void
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => '2026-09-05',
                'payment_term' => 'month_end',
                'due_on' => '2026-09-30',
            ],
            [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],
        );

        $bill = $result['bill']->fresh();

        $this->assertSame('month_end', $bill->payment_term);
        $this->assertSame('2026-09-30', $bill->due_on?->toDateString());
    }

    /**
     * ⛔ অচেনা কোনো ধরন খাতায় ঢুকতে পারে না।
     *
     * ── কেন এই পাহারাটা লেখা হলো ────────────────────────────────────
     * কলামটা ১৬ অক্ষরের, আর যেকোনো লেখা ঢুকতে দিলে একদিন রিপোর্টে
     * `"3 days Cr"` আর `"credit"` **দুইটাই** বসে থাকত, আর *"COD-তে কত
     * কিনলাম"* প্রশ্নের উত্তর গোনাই যেত না। ⓘ পাহারাটা দরজায়, তাই
     * পর্দা বদলালেও থাকে।
     */
    public function test_a_term_nobody_defined_is_refused_at_the_door(): void
    {
        $this->post(route('purchase.direct.store'),
            $this->counterForm() + ['payment_term' => '30 days Cr'])
            ->assertSessionHasErrors('payment_term');

        /* ⓘ আর চেনা মানগুলো ঠিকই ঢোকে — নাহলে পাহারাটা সবকিছু আটকাত। */
        $this->post(route('purchase.direct.store'),
            $this->counterForm() + ['payment_term' => 'month_end'])
            ->assertSessionHasNoErrors();
    }

    /**
     * ⭐ খালি রাখলে আগের মতোই — পুরনো ডাক ভাঙে না।
     *
     * ⓘ API, ইমপোর্ট আর সিডার এই ঘরটা পাঠায় না, আর পাঠানোর কথাও নয়।
     * ⚠️ `Rule::in()` তখন যেন আটকে না দেয় — `nullable` ছাড়া প্রতিটা
     * পুরনো ডাক লাল হত।
     */
    public function test_a_bill_without_terms_still_goes_through(): void
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']],
        );

        $this->assertNull($result['bill']->fresh()->payment_term);
    }

    /**
     * ⚠️ দরজাটাও ঘরগুলো চেনে — নাহলে পর্দা পাঠাত আর যাচাই ফেলে দিত।
     *
     * ⓘ সার্ভিস ঠিক থাকা সত্ত্বেও `validate()`-এ নাম না থাকলে ঘরগুলো
     * `$data`-তেই পৌঁছাত না, আর কোথাও কোনো ত্রুটি হত না।
     */
    public function test_the_screen_can_actually_send_all_of_it(): void
    {
        $response = $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lc_no' => 'LC-2026-9001',
            'vessel' => 'MV Test',
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '2',
                'unit_id' => $this->carton->id,
                'free_qty' => '3',
                'free_unit_id' => $this->piece->id,
                'rate' => '1200',
            ]],
        ]);

        $response->assertSessionHasNoErrors();

        $bill = \App\Modules\Purchase\Models\PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertSame('LC-2026-9001', $bill->lc_no);

        $line = $bill->lines()->firstOrFail();
        $this->assertQty('24', $line->qty);

        // ⭐ ৩ পিস ফ্রি — ৩৬ নয়, যদিও লাইনটা কার্টনে লেখা
        $this->assertQty('3', $line->free_qty);

        /*
         * ⛔ এখানে আমি প্রথমে `piece->id` আশা করেছিলাম, আর সেটাই ছিল
         * পরীক্ষার ভুল — কোডের নয়।
         *
         * ⓘ নিয়মটা [[ReadsPackedQuantities::packed()]]-এ লেখা:
         * **পণ্যের নিজের একক এলে জোড়াটা খালিই থাকে**, কারণ "NULL মানে
         * যেভাবে সবসময় হত"। ⚠️ বসিয়ে দিলে কাগজে "৩ পিস (৩ পিস)" ছাপা
         * হত, আর ছাপা ও পর্দার প্রতিটা জায়গায় শর্ত মেলাতে হত।
         *
         * ⭐ উপরের `test_free_goods_may_come_in_a_different_unit`-এ ঠিক
         * এই কথাটাই লেখা আছে — অর্থাৎ দুইটা পরীক্ষা একই নিয়ম মাপে, আর
         * এতক্ষণ তারা একে অন্যের বিরুদ্ধে দাঁড়িয়ে ছিল।
         */
        $this->assertNull($line->free_unit_id);
    }
}
