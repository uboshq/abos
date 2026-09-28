<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * সরাসরি ক্রয়ে দাম কোথায় বসে — চেকলিস্ট §২, দফা ৫–৭।
 *
 * ── কী মাপা হয় ─────────────────────────────────────────────────────────
 *   ৫. লাইনের ছাড় আর ভ্যাট — কোনটা মালের দামে ঢোকে, কোনটা খাতে যায়
 *   ৬. লরির ভাড়া — সারিগুলোর মধ্যে টাকার অনুপাতে ভাগ, শেষ সারি বাকিটুকু
 *      নেয়, আর স্তরের যোগফল = মাল + ভাড়া, হুবহু
 *   ৭. ফ্রি মাল ও উপহার — শূন্য দামে ঢোকে, অন্য পণ্যের FIFO দর অক্ষত
 *
 * ── নকশার উদ্দেশ্য, কোডের মন্তব্য থেকে পড়া ──────────────────────────────
 * [[PurchaseBillService::bringInDirectLines()]]: *"দর হিসাব করা হয় ছাড়ের
 * পরে, করের আগে … ভ্যাট ফেরতযোগ্য, ওটা মালের দাম নয়"*। অর্থাৎ:
 *
 *   লাইনের ছাড়  → মালের দাম থেকে কমে (কোনো আয়ের খাতে নয় — ব্যবসায়িক ছাড়)
 *   ভ্যাট       → ২১২০ উপকরণ ভ্যাটে ডেবিট, মালের দামে নয়
 *   ভাড়া       → মালের দামে (১১২০), ক্রেডিট ২১১৬ পরিবহনের প্রদেয়
 *   বিলের ছাড়   → ⚠️ নেই। `direct/partials/totals.blade.php` বলে ঘরটা
 *                 "⏳ nexus-25" — তাই এখানে কোনো দাবি লেখা হয়নি; একটা
 *                 অনুপস্থিত ঘরের আচরণ বেঁধে দিলে যেদিন ঘরটা আসবে সেদিন
 *                 পরীক্ষাটা সঠিক কোডকে লাল দেখাত।
 *
 * ── ⭐ প্রতিটা পরীক্ষা পাঁচটা মিল মাপে, সারির গোনা নয় ──────────────────
 * [[assertFiveMatches()]] — হাতে কষা অঙ্কের সাথে:
 *   (১) খতিয়ান: ডেবিট = ক্রেডিট, আর **প্রতিটা** খাতের নড়াচড়া হুবহু
 *       প্রত্যাশিত — তালিকার বাইরের কোনো খাত এক পয়সাও নড়লে লাল
 *   (২) মজুদ: প্রতিটা পণ্যের স্তরের পরিমাণ ও মূল্য, আর ১১২০ = স্তরের মোট
 *   (৩) সরবরাহকারীর খাতা = প্রদেয়ে তার অংশ
 *   (৪) নগদ/ব্যাংক = হাতে গোনা টাকা
 *   (৫) মুনাফা: আয়-ব্যয়ের কোনো খাত নড়ে না, যেখানে নড়ার কথা সেখানে ছাড়া
 *
 * ⓘ সহায়কটা ইচ্ছে করে এই ক্লাসের ভিতরে আর ছোট — অন্য সেশন থেকে
 * `tests/Concerns/ChecksTheFiveMatches.php` আসছে, এলে এটা বদলে দেওয়া যাবে।
 *
 * ⓘ সব কেনা **পর্দার দরজা দিয়ে** (`purchase.direct.store`), সেবা সরাসরি
 * ডেকে নয় — ভাড়া বা উপহার নিয়ন্ত্রকের ছাঁকনিতে হারালে সেবা-স্তরের পরীক্ষা
 * সবুজই থাকত।
 */
final class DirectPurchaseCostsLandRightTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $a;

    private Product $b;

    private Product $c;

    /** উপহারের পণ্য — আগে কেনা স্তর আছে, উপহার যেন সেটা পাতলা না করে */
    private Product $g;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        /*
         * ⚠️ সুইচ দুইটা স্পষ্ট করে — ডেমোর সেটিং বদলালে পরীক্ষা অন্য পথ
         * মাপত। `receipt_needs_order` চালু থাকলে সরাসরি বিল "ব্যতিক্রম"
         * হত, আর মিলের দাবি ভুল কারণে লাল হত।
         */
        app(SettingsService::class)->set('purchase.receipt_needs_order', false);
        app(SettingsService::class)->set('purchase.block_price_mismatch', true);

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⓘ লট-ছাড়া পণ্য — এই পর্দা লট নম্বর নেয় না, আর উপহারে লট-ধরা
         * পণ্য ইচ্ছে করেই আটকানো ([[DirectPurchaseService::writeGifts()]])।
         *
         * ⚠️ পণ্যের নিজের ভ্যাট হার সরিয়ে রাখা হয়: প্রতিটা অঙ্ক হাতে কষা,
         * আর ডেমোর একটা হার চুপচাপ ভ্যাট বসালে হিসাবটা আর হাতের থাকত না।
         * যে পরীক্ষা পণ্যের হার মাপে সে নিজের হার নিজে বসায়।
         */
        $products = Product::query()->where('track_batch', false)->orderBy('id')->take(4)->get();
        $this->assertCount(4, $products, 'ডেমোতে লট-ছাড়া চারটা পণ্য নেই — পরীক্ষার জমিটাই নেই।');

        foreach ($products as $product) {
            $product->forceFill(['tax_id' => null])->save();
        }

        [$this->a, $this->b, $this->c, $this->g] = $products->all();

        $this->till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
    }

    // ── ৫ · লাইনের ছাড় আর ভ্যাট ─────────────────────────────────────────

    /**
     * ছাড় মালের দাম কমায়, ভ্যাট ২১২০-এ যায়, আর কোনোটাই মুনাফা ছোঁয় না।
     *
     * হাতে কষা:
     * ```
     * A  ১০ × ১০০ = ১০০০ − ছাড় ৫০ = ৯৫০ নেট, ভ্যাট ১৪২.৫ → সারি ১০৯২.৫
     * B   ৪ × ২৫০ = ১০০০, ছাড় ০, ভ্যাট ০                 → সারি ১০০০
     * বিলের মোট ২০৯২.৫ · নগদে দেওয়া ৫০০
     *
     * ১১২০ মজুদ        +১৯৫০     (৯৫০ + ১০০০ — ভ্যাট ছাড়া, ছাড়ের পরে)
     * ২১২০ উপকরণ ভ্যাট  +১৪২.৫   (ডেবিট)
     * ২১১১ প্রদেয়       −২০৯২.৫ + ৫০০ = −১৫৯২.৫
     * নগদ              −৫০০
     * স্তর: A ১০ @ ৯৫ · B ৪ @ ২৫০
     * ```
     *
     * ⛔ ছাড়টা মজুদে না কমলে গুদামের মাল ৫০ টাকা দামি দেখাত, আর বেচার দিন
     * মুনাফা ততটাই কম। ⛔ ভ্যাট মজুদে গেলে উল্টোটা — আর ভ্যাটটা সরকারের
     * কাছে দাবি করাও হত না।
     */
    public function test_the_line_discount_lowers_the_cost_and_the_vat_goes_to_input_vat(): void
    {
        /*
         * ⓘ নগদ খাত শূন্যের নিচে নামে না (1da4285a), আর ডেমো টিলে টাকা রাখে
         * না — তাই ৫০০ টাকা পুঁজি থেকে আগে রাখা, ছবি তোলার **আগে**।
         */
        $this->putMoneyIn($this->till, '500');

        $before = $this->snapshot();

        $bill = $this->buy([
            'paid_now' => '500',
            'paid_from_account_id' => $this->till->id,
            'lines' => [
                ['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100', 'discount' => '50', 'tax' => '142.5'],
                ['product_id' => $this->b->id, 'qty' => '4', 'rate' => '250', 'discount' => '0', 'tax' => '0'],
            ],
        ]);

        $this->assertSame(0, bccomp((string) $bill->total, '2092.5', 4),
            "বিলের মোট {$bill->total}, হাতে কষা ২০৯২.৫ — সারির নিয়ম (পরিমাণ × দর − ছাড় + ভ্যাট) ভেঙেছে।");
        $this->assertSame(0, bccomp((string) $bill->discount, '50', 4),
            "বিলে ছাড়ের যোগফল {$bill->discount}, হাতে কষা ৫০।");

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '1950',
                StandardChart::VAT_PAYABLE => '142.5',
                StandardChart::PAYABLE => '-1592.5',
                $this->till->code => '-500',
            ],
            layers: [
                $this->a->id => ['10', '950'],
                $this->b->id => ['4', '1000'],
            ],
            supplier: '-1592.5',
            money: [$this->till->code => '-500'],
        );

        $this->assertBillLayers($bill, [
            [$this->a->id, '10', '95'],
            [$this->b->id, '4', '250'],
        ]);
    }

    /**
     * দামের **ভিতরের** ভ্যাট, পণ্যের নিজের হার থেকে — ছাড়সহ, আর ভাগ অসমান।
     *
     * হাতে কষা (১৫% ভিতরের):
     * ```
     * ১০ × ১১৫ = ১১৫০ − ছাড় ৫০ = ১১০০ (ভ্যাটসহ)
     * ভ্যাট = ১১০০ − ১১০০ ÷ ১.১৫ = ১১০০ − ৯৫৬.৫২১৭ = ১৪৩.৪৭৮৩
     * মাল  = ৯৫৬.৫২১৭ → ১০ এককে: ৭ @ ৯৫.৬৫২২ + ৩ @ ৯৫.৬৫২১
     *        (৬৬৯.৫৬৫৪ + ২৮৬.৯৫৬৩ = ৯৫৬.৫২১৭, হুবহু)
     * বিলের মোট = ১১০০ (ভিতরের ভ্যাটে মোট বাড়ে না)
     * ```
     *
     * ⛔ ৫ সেপ্টেম্বরের বাগ ঠিক এই পথে ছিল: `amount` ধরা হত, আর ভিতরের
     * ভ্যাটে সেটা ভ্যাটসহ — মাল ১৪৩.৪৭৮৩ দামি বসত, আর ভ্যাটটা দুইবার ডেবিট।
     */
    public function test_vat_inside_the_price_is_taken_out_of_the_cost_to_the_last_paisa(): void
    {
        $tax = Tax::query()->create([
            'company_id' => $this->company->id,
            'code' => 'VAT15-IN',
            'name_en' => 'VAT 15% inclusive',
            'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15',
            'kind' => 'vat',
            'is_inclusive' => true,
            'is_active' => true,
        ]);

        $this->a->forceFill(['tax_id' => $tax->id])->save();

        $before = $this->snapshot();

        // ⓘ `tax` ঘরটা পাঠানোই হয় না — "পণ্য অনুযায়ী" পথ
        $bill = $this->buy(['lines' => [
            ['product_id' => $this->a->id, 'qty' => '10', 'rate' => '115', 'discount' => '50'],
        ]]);

        $this->assertSame(0, bccomp((string) $bill->tax, '143.4783', 4),
            "পণ্যের নিজের ভিতরের হারে ভ্যাট {$bill->tax}, হাতে কষা ১৪৩.৪৭৮৩ — হারটা বসেনি, নাকি বাইরের নিয়মে কষা হয়েছে।");
        $this->assertSame(0, bccomp((string) $bill->total, '1100', 4),
            "বিলের মোট {$bill->total}, হাতে কষা ১১০০ — ভিতরের ভ্যাট মোটের উপরে আবার যোগ হয়েছে।");

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '956.5217',
                StandardChart::VAT_PAYABLE => '143.4783',
                StandardChart::PAYABLE => '-1100',
            ],
            layers: [$this->a->id => ['10', '956.5217']],
            supplier: '-1100',
        );

        $this->assertBillLayers($bill, [
            [$this->a->id, '7', '95.6522'],
            [$this->a->id, '3', '95.6521'],
        ]);
    }

    // ── ৫ক · গোটা বিলের ছাড় (মালিকের সিদ্ধান্ত ক, ২৭ সেপ্টেম্বর ২০২৬) ───────

    /**
     * বিলের ছাড় সারিগুলোর খরচে ভাগ হয় — মালের অনুপাতে, শেষ সারি বাকিটা।
     *
     * হাতে কষা:
     * ```
     * A ৩ × ১০০ · B ৭ × ১০০ · C ১ × ১০০ · D ১ × ১০০ → মাল ১২০০
     * বিলের ছাড় ১০০:
     *   A ১০০ × ৩০০/১২০০ = ২৫            → নেট ২৭৫
     *   B ১০০ × ৭০০/১২০০ = ৫৮.৩৩৩৩ → ৫৮.৩৩ → নেট ৬৪১.৬৭
     *   C ১০০ × ১০০/১২০০ =  ৮.৩৩৩৩ →  ৮.৩৩ → নেট  ৯১.৬৭
     *   D বাকি ১০০ − ২৫ − ৫৮.৩৩ − ৮.৩৩ = ৮.৩৪ → নেট  ৯১.৬৬
     * ১১২০ মজুদ +১১০০ · ২১১১ প্রদেয় −১১০০ · আলাদা কোনো আয়ের খাত নড়ে না
     *
     * ⓘ D ইচ্ছে করে: অনুপাতে গোল করা চারটা ভাগের যোগ ৯৯.৯৯ — বাকি পয়সাটা
     * শেষ সারি না নিলে এখানেই ধরা পড়ে।
     * ```
     *
     * ⛔ ছাড়টা আয়ে গেলে গুদামের মাল ১০০ টাকা দামি বসত, আর লাভটা আসত কেনার
     * দিনে। ⛔ শেষ সারি বাকিটা না নিলে স্তরে ৯৯৯.৯৯ আর খাতায় ১০০০।
     */
    public function test_a_bill_discount_is_spread_into_the_goods_cost_to_the_paisa(): void
    {
        $before = $this->snapshot();

        $bill = $this->buy([
            'payment_term' => 'credit',
            'bill_discount' => '100',
            'lines' => [
                ['product_id' => $this->a->id, 'qty' => '3', 'rate' => '100', 'tax' => '0'],
                ['product_id' => $this->b->id, 'qty' => '7', 'rate' => '100', 'tax' => '0'],
                ['product_id' => $this->c->id, 'qty' => '1', 'rate' => '100', 'tax' => '0'],
                ['product_id' => $this->g->id, 'qty' => '1', 'rate' => '100', 'tax' => '0'],
            ],
        ]);

        $this->assertSame(
            ['25.0000', '58.3300', '8.3300', '8.3400'],
            $bill->lines->sortBy('id')->map(fn ($l) => bcadd((string) $l->discount, '0', 4))->values()->all(),
            'বিলের ছাড় সারিগুলোতে হাতে কষা ভাগে বসেনি — অনুপাত, গোল, নাকি শেষ সারির বাকিটা ভেঙেছে।',
        );
        $this->assertSame(0, bccomp((string) $bill->discount, '100', 4),
            "বিলে ছাড়ের যোগফল {$bill->discount}, লেখা ছিল ১০০ — ভাগে পয়সা হারিয়েছে বা বেড়েছে।");
        $this->assertSame(0, bccomp((string) $bill->total, '1100', 4),
            "বিলের মোট {$bill->total}, হাতে কষা ১১০০।");

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '1100',
                StandardChart::PAYABLE => '-1100',
            ],
            layers: [
                $this->a->id => ['3', '275'],
                $this->b->id => ['7', '641.67'],
                $this->c->id => ['1', '91.67'],
                $this->g->id => ['1', '91.66'],
            ],
            supplier: '-1100',
        );
    }

    /**
     * শতাংশে লেখা ছাড় সার্ভার নিজে কষে, আর "পণ্য অনুযায়ী" ভ্যাট ছাড়ের পরের দামে।
     *
     * হাতে কষা (১৫% বাইরের):
     * ```
     * A ১০ × ১০০ = ১০০০ · বিলের ছাড় ১০% = ১০০ → নেট ৯০০
     * ভ্যাট ৯০০ × ১৫% = ১৩৫ (১৫০ নয়) → মোট ১০৩৫
     * ১১২০ +৯০০ · ২১২০ +১৩৫ · ২১১১ −১০৩৫
     * ```
     */
    public function test_a_percent_bill_discount_is_worked_out_on_the_server_and_lowers_the_vat_base(): void
    {
        $tax = Tax::query()->create([
            'company_id' => $this->company->id,
            'code' => 'VAT15-OUT',
            'name_en' => 'VAT 15%',
            'name_bn' => 'ভ্যাট ১৫%',
            'rate' => '15',
            'kind' => 'vat',
            'is_inclusive' => false,
            'is_active' => true,
        ]);

        $this->a->forceFill(['tax_id' => $tax->id])->save();

        $before = $this->snapshot();

        $bill = $this->buy([
            'payment_term' => 'credit',
            'bill_discount' => '10',
            'bill_discount_mode' => 'percent',
            'lines' => [['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100']],
        ]);

        $this->assertSame(0, bccomp((string) $bill->tax, '135', 4),
            "ভ্যাট {$bill->tax}, হাতে কষা ১৩৫ — ছাড়ের আগের দামে কষা হয়েছে, নাকি শতাংশটা টাকা ধরা হয়েছে।");

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '900',
                StandardChart::VAT_PAYABLE => '135',
                StandardChart::PAYABLE => '-1035',
            ],
            layers: [$this->a->id => ['10', '900']],
            supplier: '-1035',
        );
    }

    /** ⛔ মালের মোটের চেয়ে বড় ছাড় থামে — আর কিছুই বসে না। */
    public function test_a_bill_discount_over_the_goods_total_is_refused_and_posts_nothing(): void
    {
        $bills = PurchaseBill::query()->withTrashed()->count();
        $entries = LedgerEntry::query()->count();

        $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'bill_discount' => '100.01',
            'lines' => [['product_id' => $this->a->id, 'qty' => '1', 'rate' => '100', 'tax' => '0']],
        ])->assertSessionHasErrors('bill_discount');

        $this->assertSame($bills, PurchaseBill::query()->withTrashed()->count(), '⛔ বাড়তি ছাড়ে থেমেও বিল থেকে গেছে।');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ বাড়তি ছাড়ে থেমেও খাতায় সারি বসেছে।');
    }

    // ── ৫খ · ভ্যাট বন্ধ মানে বন্ধ (মালিকের সিদ্ধান্ত, ২৮ সেপ্টেম্বর ২০২৬) ─────────────

    /**
     * ⛔ ভ্যাট বন্ধ থাকলে পাঠানো ভ্যাট থামে — আর কিছুই লেখা হয় না।
     *
     * ⓘ মালিক: *"বন্ধ মানে ভ্যাট টোটাল ফাংশনের বন্ধ"*। পর্দায় ঘর নেই, কিন্তু সরাসরি
     * অনুরোধে (বা পুরনো খোলা পর্দা থেকে) ভ্যাট এলে সার্ভার নিজেই থামায়
     * ([[CalculatesLineTotals::lineFigures()]])। ⚠️ চুপচাপ শূন্য করলে বিলের মোট
     * সরবরাহকারীর কাগজের সাথে মিলত না, আর কেউ জানত না কেন।
     */
    public function test_with_vat_off_a_posted_vat_is_refused_and_nothing_is_written(): void
    {
        app(SettingsService::class)->set('master_data.tax_enabled', false);

        $bills = PurchaseBill::query()->withTrashed()->count();
        $entries = LedgerEntry::query()->count();

        $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'lines' => [['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100', 'tax' => '150']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame($bills, PurchaseBill::query()->withTrashed()->count(), '⛔ ভ্যাট বন্ধ, তবু ভ্যাটওয়ালা বিল বসেছে।');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ ভ্যাট বন্ধ, তবু খাতায় সারি বসেছে।');
    }

    /**
     * ⛔→⭐ একই কোম্পানি: ভ্যাট বন্ধে পণ্যের নিজের হার কিছুই যোগ করে না; চালু করলে ফেরে।
     *
     * হাতে কষা (১৫% বাইরের), ১০ × ১০০:
     * ```
     * বন্ধ:  ১১২০ +১০০০ · ২১২০ ০    · ২১১১ −১০০০ · বিলের ভ্যাট ০
     * চালু:  ১১২০ +১০০০ · ২১২০ +১৫০ · ২১১১ −১১৫০
     * ```
     */
    public function test_with_vat_off_the_products_own_rate_adds_nothing_and_on_brings_it_back(): void
    {
        $tax = Tax::query()->create([
            'company_id' => $this->company->id,
            'code' => 'VAT15-OFF',
            'name_en' => 'VAT 15%',
            'name_bn' => 'ভ্যাট ১৫%',
            'rate' => '15',
            'kind' => 'vat',
            'is_inclusive' => false,
            'is_active' => true,
        ]);
        $this->a->forceFill(['tax_id' => $tax->id])->save();

        app(SettingsService::class)->set('master_data.tax_enabled', false);
        $before = $this->snapshot();

        $bill = $this->buy([
            'payment_term' => 'credit',
            'lines' => [['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100']],
        ]);

        $this->assertSame(0, bccomp((string) $bill->tax, '0', 4), "⛔ ভ্যাট বন্ধ, তবু বিলে ভ্যাট {$bill->tax}।");
        // ⚠️ আর কোনো ভুয়া "ভ্যাটের পার্থক্য"ও নয় — হারটা বন্ধ, তাই মাপার কিছু নেই (মিউট্যান্ট বেঁচেছিল)
        $this->assertNull($bill->lines->first()->tax_variance,
            '⛔ ভ্যাট বন্ধ, অথচ সারিতে পণ্যের হার ধরে "পার্থক্য" লেখা — সরবরাহকারী ভুল ভ্যাট দিয়েছেন বলে দেখাবে।');

        $this->assertFiveMatches(
            before: $before,
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::PAYABLE => '-1000'],
            layers: [$this->a->id => ['10', '1000']],
            supplier: '-1000',
        );

        app(SettingsService::class)->set('master_data.tax_enabled', true);
        $before = $this->snapshot();

        $bill = $this->buy([
            'payment_term' => 'credit',
            'lines' => [['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100']],
        ]);

        $this->assertSame(0, bccomp((string) $bill->tax, '150', 4), "ভ্যাট চালু, অথচ বিলে ভ্যাট {$bill->tax}, হাতে কষা ১৫০।");

        $this->assertFiveMatches(
            before: $before,
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::VAT_PAYABLE => '150', StandardChart::PAYABLE => '-1150'],
            layers: [$this->a->id => ['10', '1000']],
            supplier: '-1150',
        );
    }

    /** ⛔→⭐ একই মানুষ: ভ্যাট বন্ধে বিলের পর্দায় ভ্যাটের ঘর নেই, চালু করলে আছে। */
    public function test_the_bill_screen_offers_no_vat_box_when_vat_is_off(): void
    {
        app(SettingsService::class)->set('master_data.tax_enabled', false);
        $this->assertStringNotContainsString("][tax]'", $this->get(route('purchase.bill.create'))->assertOk()->getContent(),
            '⛔ ভ্যাট বন্ধ, অথচ বিলের পর্দায় ভ্যাটের ঘর।');

        app(SettingsService::class)->set('master_data.tax_enabled', true);
        $this->assertStringContainsString("][tax]'", $this->get(route('purchase.bill.create'))->assertOk()->getContent(),
            'ভ্যাট চালু, অথচ বিলের পর্দায় ভ্যাটের ঘর নেই — দাবিটা অন্ধ।');
    }

    // ── ৬ · লরির ভাড়া ─────────────────────────────────────────────────

    /**
     * ভাড়া টাকার অনুপাতে ভাগ, শেষ সারি বাকিটুকু নেয়, যোগফল হুবহু।
     *
     * হাতে কষা — তিনটা সারির মালমূল্য সমান (২১০), ভাড়া ১০০:
     * ```
     * A  ৩ × ৮০ = ২৪০ − ছাড় ৩০ = ২১০, ভ্যাট ৩১.৫ → সারি ২৪১.৫
     * B  ৭ × ৩০ = ২১০                            → সারি ২১০
     * C  ১ × ২১০ = ২১০                           → সারি ২১০
     * মালের মোট ৬৩০ · বিলের মোট ৬৬১.৫
     *
     * ভাগ: ১০০ × ২১০ ÷ ৬৩০ = ৩৩.৩৩৩৩, ৩৩.৩৩৩৩, আর শেষ সারি ৩৩.৩৩৩৪
     * A  ২৪৩.৩৩৩৩ ÷ ৩ → ৩ @ ৮১.১১১১
     * B  ২৪৩.৩৩৩৩ ÷ ৭ → ৩৪.৭৬১৮… কাটা পড়ে, বাকি ০.০০০৭ → ৭ @ ৩৪.৭৬১৯
     * C  ২৪৩.৩৩৩৪    → ১ @ ২৪৩.৩৩৩৪
     * স্তরের যোগফল ৭৩০ = মাল ৬৩০ + ভাড়া ১০০
     *
     * ১১২০ +৭৩০ · ২১২০ +৩১.৫ · ২১১১ −৬৬১.৫ · ২১১৬ −১০০
     * ```
     *
     * ⚠️ A-র ছাড় আর ভ্যাট ইচ্ছে করে রাখা: ভাগের ভিত্তি **ছাড়ের পরে, ভ্যাটের
     * আগে** মালমূল্য। ভ্যাটসহ ধরলে A বেশি ভাড়া পেত (২৪১.৫ বনাম ২১০)।
     */
    public function test_the_lorry_fare_is_spread_by_value_and_the_last_line_takes_the_remainder(): void
    {
        $before = $this->snapshot();

        $bill = $this->buy([
            'transport_cost' => '100',
            'carrier_name' => 'করিম ট্রান্সপোর্ট',
            'lines' => [
                ['product_id' => $this->a->id, 'qty' => '3', 'rate' => '80', 'discount' => '30', 'tax' => '31.5'],
                ['product_id' => $this->b->id, 'qty' => '7', 'rate' => '30', 'tax' => '0'],
                ['product_id' => $this->c->id, 'qty' => '1', 'rate' => '210', 'tax' => '0'],
            ],
        ]);

        $this->assertSame(0, bccomp((string) $bill->transport_cost, '100', 4),
            'ভাড়াটা পর্দার দরজা পেরিয়ে বিলে পৌঁছায়নি — নিয়ন্ত্রকের ছাঁকনিতে হারিয়েছে।');
        $this->assertSame(PurchaseBill::MATCH_MATCHED, $bill->match_state,
            'ভাড়াওয়ালা বিলটা "মেলেনি" দাগ খেয়েছে — ভাড়ার জোড়া মূল্য-পার্থক্য বানিয়েছে।');

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '730',
                StandardChart::VAT_PAYABLE => '31.5',
                StandardChart::PAYABLE => '-661.5',
                StandardChart::TRANSPORT_PAYABLE => '-100',
            ],
            layers: [
                $this->a->id => ['3', '243.3333'],
                $this->b->id => ['7', '243.3333'],
                $this->c->id => ['1', '243.3334'],
            ],
            supplier: '-661.5',
        );

        $this->assertBillLayers($bill, [
            [$this->a->id, '3', '81.1111'],
            [$this->b->id, '7', '34.7619'],
            [$this->c->id, '1', '243.3334'],
        ]);

        /*
         * ⓘ বাকি পয়সাটা শেষ সারির ঘাড়ে — প্রথম বা মাঝের নয়। ⛔ কোথাও না
         * বসলে স্তরে ৭২৯.৯৯৯৯ আর খাতায় ৭৩০, আর ফারাকটা মাস শেষে।
         */
        $total = CostLayer::query()
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->get()
            ->reduce(fn (string $s, CostLayer $l) => bcadd($s, bcmul((string) $l->qty_in, (string) $l->unit_cost, 4), 4), '0');

        $this->assertSame(0, bccomp($total, '730', 4),
            "বিলের স্তরগুলোর যোগফল {$total}, হওয়ার কথা মাল ৬৩০ + ভাড়া ১০০ = ৭৩০ — ভাড়ার ভাগে পয়সা হারিয়েছে বা বেড়েছে।");
    }

    // ── ৭ · ফ্রি মাল ও উপহার ────────────────────────────────────────────

    /**
     * ফ্রি কার্টন আর উপহার শূন্য দামে ঢোকে, আর কারো FIFO দর পাতলা করে না।
     *
     * হাতে কষা — আগের কেনা: A ১০ @ ১০০, G ৫ @ ৪০। আজকের বিল:
     * ```
     * A  ১০ × ১২০ = ১২০০, সাথে ফ্রি ২ · উপহার G ৩টা (A-র সাথে)
     *
     * ১১২০ +১২০০ · ২১১১ −১২০০ · আর কিছু নড়ে না
     * A-র নতুন স্তর ১০ @ ১২০ — ১২ @ ১০০ নয়
     * G-র স্তর অক্ষত: ৫ @ ৪০ = ২০০ — ৮ @ ২৫ নয়
     * মজুদ: A অপেক্ষায় +১০, ফ্রি-অপেক্ষায় +২ · G ফ্রি-অপেক্ষায় +৩
     * FIFO: A থেকে ১১ বের হলে ১০ × ১০০ + ১ × ১২০ = ১১২০
     * ```
     *
     * ⛔ ফ্রি ২টা স্তরে মিশলে A-র দর ১০০-তে নামত, আর বেচার দিন প্রতিটা
     * এককে ২০ টাকা বেশি মুনাফা দেখাত — মিল যা কোনোদিন দেয়নি।
     */
    public function test_free_goods_and_gifts_come_in_at_zero_cost_and_leave_fifo_intact(): void
    {
        /*
         * ⚠️ ডেমোতে G-র আগে থেকেই স্তর আছে (১৮০০ একক), তাই G-র দাবি
         * **আগের কেনার আগের ছবি থেকে** পার্থক্য — পরম সংখ্যা নয়।
         */
        $gAtStart = $this->layerTotals()[$this->g->id] ?? ['0', '0'];
        $aAtStart = $this->layerTotals()[$this->a->id] ?? ['0', '0'];

        // আগের কেনা — FIFO-র পুরনো স্তর আর উপহার পণ্যের নিজের দর
        $this->buy(['lines' => [
            ['product_id' => $this->a->id, 'qty' => '10', 'rate' => '100', 'tax' => '0'],
            ['product_id' => $this->g->id, 'qty' => '5', 'rate' => '40', 'tax' => '0'],
        ]]);

        $before = $this->snapshot();
        $stockBefore = $this->states();

        $bill = $this->buy([
            'lines' => [
                ['product_id' => $this->a->id, 'qty' => '10', 'rate' => '120', 'free_qty' => '2', 'tax' => '0'],
            ],
            'gifts' => [
                ['product_id' => $this->g->id, 'qty' => '3', 'against_product_id' => $this->a->id, 'remarks' => 'মিলের উপহার'],
            ],
        ]);

        $this->assertSame(0, bccomp((string) $bill->lines->first()->free_qty, '2', 4),
            'ফ্রি পরিমাণটা বিলের সারিতে বসেনি।');
        $this->assertCount(1, $bill->giftLines, 'উপহারের সারিটা বিলে লেখা হয়নি।');

        $this->assertFiveMatches(
            before: $before,
            ledger: [
                StandardChart::INVENTORY => '1200',
                StandardChart::PAYABLE => '-1200',
            ],
            layers: [
                $this->a->id => ['10', '1200'],
                $this->g->id => ['0', '0'],
            ],
            supplier: '-1200',
        );

        $this->assertBillLayers($bill, [[$this->a->id, '10', '120']]);

        /*
         * মজুদের গোনায় ফ্রি ও উপহার আছে — কেবল দামের স্তরে নেই।
         *
         * ⓘ অর্থাৎ ইচ্ছে করেই তাকের পরিমাণ ≠ স্তরের পরিমাণ: A-র স্তর +১০,
         * গুদামে +১২ (১০ কেনা + ২ ফ্রি)। ফ্রি মাল `:free` উৎসে কেবল চলাচল,
         * কোনো স্তর নয় ([[PurchaseBillService::bringInFree()]]) — শূন্য দামের
         * মালের মূল্য শূন্য, তাই ১১২০ = স্তরের মোট মিলটা তবু অক্ষত।
         */
        $stockAfter = $this->states();

        foreach ([
            [$this->a, 'unplaced', '10'], [$this->a, 'unplaced_free', '2'],
            [$this->g, 'unplaced', '0'], [$this->g, 'unplaced_free', '3'],
        ] as [$product, $room, $moved]) {
            $delta = bcsub($stockAfter[$product->id][$room], $stockBefore[$product->id][$room], 4);

            $this->assertSame(0, bccomp($delta, $moved, 4),
                "পণ্য {$product->id}-এর '{$room}' ঘর নড়েছে {$delta}, হাতে গোনা {$moved} — ফ্রি বা উপহার ভুল ঘরে বসেছে।");
        }

        /*
         * ⭐ G-র গড় দর ৪০-ই থাকে। ⛔ উপহারের ৩টা স্তরে শূন্য দামে বসলে
         * গড় ২০০ ÷ ৮ = ২৫ হত, আর G বেচার দিন প্রতিটায় ১৫ টাকা মিথ্যা মুনাফা।
         */
        $gNow = $this->layerTotals()[$this->g->id] ?? ['0', '0'];
        $gQty = bcsub($gNow[0], $gAtStart[0], 4);
        $gValue = bcsub($gNow[1], $gAtStart[1], 4);
        $this->assertSame(0, bccomp($gQty, '5', 4), "G-র স্তরে দুই কেনায় পরিমাণ বেড়েছে {$gQty}, হওয়ার কথা ৫ — উপহার স্তরে ঢুকেছে।");
        $this->assertSame(0, bccomp($gValue, '200', 4), "G-র স্তরে দুই কেনায় মূল্য বেড়েছে {$gValue}, হওয়ার কথা ২০০।");

        /*
         * ⭐ FIFO টান — পুরনোটা আগে, আর নতুন স্তরের দর ১২০, ১০০ নয়।
         *
         * ⚠️ ডেমোতে A-র আগে থেকেই স্তর আছে, আর FIFO সেগুলোই আগে টানে। তাই
         * টানটা = ডেমোর গোটা পরিমাণ + ১১, আর প্রত্যাশিত খরচ = ডেমোর গোটা
         * মূল্য + ১১২০ — অর্থাৎ ডেমোর অংশ বাদ দিলে বাকিটা হুবহু ১১২০।
         *
         * ⓘ এটা শেষে, পাঁচ মিলের পরে: টানটা স্তর বদলায়।
         */
        $drawn = app(CostLayerService::class)->issue(
            $this->a->fresh(), bcadd($aAtStart[0], '11', 4), 'fifo_probe', $bill->id,
        );
        $ours = bcsub($drawn['cost'], $aAtStart[1], 4);

        $this->assertSame(0, bccomp($ours, '1120', 4),
            "ডেমোর স্তর বাদে A-র ১১টা বের করার খরচ {$ours}, হাতে কষা ১০ × ১০০ + ১ × ১২০ = ১১২০ — "
            .'ফ্রি কার্টন দর পাতলা করেছে, নাকি FIFO-র ক্রম ভেঙেছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * পর্দার দরজা দিয়ে একটা সরাসরি ক্রয়।
     *
     * @param  array<string, mixed>  $overrides
     */
    private function buy(array $overrides): PurchaseBill
    {
        $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'P57-'.fake()->unique()->numberBetween(10000, 99999),
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail()->load(['lines', 'giftLines']);

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status,
            "বিলটা নিশ্চিত হয়নি ({$bill->status}) — অনুমোদনে আটকালে পাঁচ মিলের কোনোটাই মাপা যায় না।");

        return $bill;
    }

    /**
     * এই বিলের স্তরগুলো, ক্রমে — [পণ্য, পরিমাণ, একক দর]।
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $expected
     */
    private function assertBillLayers(PurchaseBill $bill, array $expected): void
    {
        $actual = CostLayer::query()
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->orderBy('id')
            ->get()
            ->map(fn (CostLayer $l) => [(int) $l->product_id, bcadd((string) $l->qty_in, '0', 4), bcadd((string) $l->unit_cost, '0', 4)])
            ->all();

        $expected = array_map(fn (array $e) => [$e[0], bcadd($e[1], '0', 4), bcadd($e[2], '0', 4)], $expected);

        $this->assertSame($expected, $actual,
            'বিলের স্তরগুলো হাতে কষা [পণ্য, পরিমাণ, দর]-এর সাথে মেলেনি — মালের দাম ভুল বসেছে।');
    }

    /**
     * পাঁচ মিলের আগের ছবি।
     *
     * @return array{accounts: array<string, string>, supplier: string, layers: array<int, array{0: string, 1: string}>}
     */
    private function snapshot(): array
    {
        return [
            'accounts' => $this->accountBalances(),
            'supplier' => $this->supplierBalance(),
            'layers' => $this->layerTotals(),
        ];
    }

    /**
     * ⭐ পাঁচটা মিল — চেকলিস্ট §১, হাতে কষা অঙ্কে।
     *
     * ⓘ সব সংখ্যা **আগে-পরের পার্থক্য** (স্বাক্ষরিত, ডেবিট ধনাত্মক), আর
     * তালিকায় না থাকা প্রতিটা খাত/পণ্যের প্রত্যাশা শূন্য।
     *
     * @param  array{accounts: array<string, string>, supplier: string, layers: array<int, array{0: string, 1: string}>}  $before
     * @param  array<string, string>  $ledger  খাতের কোড → নিট নড়াচড়া (ডেবিট − ক্রেডিট)
     * @param  array<int, array{0: string, 1: string}>  $layers  পণ্য → [পরিমাণ, মূল্য]
     * @param  string  $supplier  সরবরাহকারীর প্রদেয় সারির নিট (ডেবিট − ক্রেডিট)
     * @param  array<string, string>  $money  টাকার খাতের কোড → নিট
     * @param  array<string, string>  $profit  আয়-ব্যয়ের যে খাত নড়ার কথা
     */
    private function assertFiveMatches(
        array $before,
        array $ledger,
        array $layers,
        string $supplier,
        array $money = [],
        array $profit = [],
    ): void {
        $after = $this->snapshot();
        $codes = array_unique([...array_keys($before['accounts']), ...array_keys($after['accounts']), ...array_keys($ledger)]);

        $moved = [];

        foreach ($codes as $code) {
            $moved[$code] = bcsub($after['accounts'][$code] ?? '0', $before['accounts'][$code] ?? '0', 4);
        }

        // (১) খতিয়ান — ডেবিট = ক্রেডিট, আর প্রতিটা খাত হুবহু
        $net = array_reduce($moved, fn (string $s, string $m) => bcadd($s, $m, 4), '0');
        $this->assertSame(0, bccomp($net, '0', 4), "মিল ১: খতিয়ানের নড়াচড়া মেলেনি — ডেবিট − ক্রেডিট = {$net}।");

        foreach ($codes as $code) {
            $want = $ledger[$code] ?? '0';

            $this->assertSame(0, bccomp($moved[$code], $want, 4),
                "মিল ১: খাত {$code} নড়েছে {$moved[$code]}, হাতে কষা {$want}।"
                .(isset($ledger[$code]) ? '' : ' ⛔ এই খাতের নড়ার কথাই ছিল না।'));
        }

        // (২) মজুদ — প্রতিটা পণ্যের স্তর, আর ১১২০ = স্তরের মোট
        $valueMoved = '0';
        $products = array_unique([...array_keys($before['layers']), ...array_keys($after['layers']), ...array_keys($layers)]);

        foreach ($products as $id) {
            $was = $before['layers'][$id] ?? ['0', '0'];
            $now = $after['layers'][$id] ?? ['0', '0'];
            $want = $layers[$id] ?? ['0', '0'];

            $qty = bcsub($now[0], $was[0], 4);
            $value = bcsub($now[1], $was[1], 4);
            $valueMoved = bcadd($valueMoved, $value, 4);

            $this->assertSame(0, bccomp($qty, $want[0], 4), "মিল ২: পণ্য {$id}-এর স্তরে পরিমাণ নড়েছে {$qty}, হাতে গোনা {$want[0]}।");
            $this->assertSame(0, bccomp($value, $want[1], 4), "মিল ২: পণ্য {$id}-এর স্তরে মূল্য নড়েছে {$value}, হাতে কষা {$want[1]}।");
        }

        $inventory = $moved[StandardChart::INVENTORY] ?? '0';
        $this->assertSame(0, bccomp($inventory, $valueMoved, 4),
            "মিল ২: খাতায় মজুদ (১১২০) নড়েছে {$inventory}, গুদামের স্তর নড়েছে {$valueMoved} — দুই খাতা আলাদা হয়ে গেছে।");

        $books = $after['accounts'][StandardChart::INVENTORY] ?? '0';
        $shelf = array_reduce($after['layers'], fn (string $s, array $l) => bcadd($s, $l[1], 4), '0');
        $this->assertSame(0, bccomp($books, $shelf, 4),
            "মিল ২: উদ্বৃত্তপত্রের মজুদ {$books}, স্তরের মোট মূল্য {$shelf} — সমান নয়।");

        // (৩) সরবরাহকারীর খাতা
        $supplierMoved = bcsub($after['supplier'], $before['supplier'], 4);
        $this->assertSame(0, bccomp($supplierMoved, $supplier, 4),
            "মিল ৩: সরবরাহকারীর নামে প্রদেয় নড়েছে {$supplierMoved}, হাতে কষা {$supplier}।");

        // (৪) নগদ ও ব্যাংক — টাকার প্রতিটা খাত
        foreach ($this->moneyCodes() as $code) {
            $want = $money[$code] ?? '0';
            $this->assertSame(0, bccomp($moved[$code] ?? '0', $want, 4),
                "মিল ৪: টাকার খাত {$code} নড়েছে ".($moved[$code] ?? '0').", হাতে গোনা {$want}।");
        }

        // (৫) মুনাফা — আয়-ব্যয়ের কোনো খাত নড়ে না, তালিকার বাইরে
        foreach ($this->profitCodes() as $code) {
            $want = $profit[$code] ?? '0';
            $this->assertSame(0, bccomp($moved[$code] ?? '0', $want, 4),
                "মিল ৫: আয়-ব্যয়ের খাত {$code} নড়েছে ".($moved[$code] ?? '0')
                ." — কেনাকাটায় মুনাফা বদলানোর কথা নয় (প্রত্যাশা {$want})।");
        }
    }

    /** @return array<string, string> খাতের কোড → ডেবিট − ক্রেডিট */
    private function accountBalances(): array
    {
        return LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->groupBy('accounts.code')
            ->selectRaw('accounts.code as code, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net')
            ->pluck('net', 'code')
            ->map(fn ($net) => bcadd((string) $net, '0', 4))
            ->all();
    }

    private function supplierBalance(): string
    {
        $net = LedgerEntry::query()
            ->where('account_id', Account::query()->where('code', StandardChart::PAYABLE)->value('id'))
            ->where('party_type', 'supplier')
            ->where('party_id', $this->supplier->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        return bcadd((string) $net, '0', 4);
    }

    /** @return array<int, array{0: string, 1: string}> পণ্য → [স্তরে পরিমাণ, মূল্য] */
    private function layerTotals(): array
    {
        return CostLayer::query()
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_remaining) as qty, SUM(qty_remaining * unit_cost) as value')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => [
                bcadd((string) $row->qty, '0', 4),
                bcadd((string) $row->value, '0', 4),
            ]])
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function states(): array
    {
        $stock = app(StockService::class);

        return collect([$this->a, $this->b, $this->c, $this->g])
            ->mapWithKeys(fn (Product $p) => [$p->id => $stock->statesFor($p, $this->warehouse)])
            ->all();
    }

    /** @return list<string> */
    private function moneyCodes(): array
    {
        return Account::query()->whereNotNull('money_kind')->where('is_group', false)->pluck('code')->all();
    }

    /** @return list<string> */
    private function profitCodes(): array
    {
        return Account::query()
            ->whereIn('type', [Account::INCOME, Account::EXPENSE])
            ->where('is_group', false)
            ->pluck('code')
            ->all();
    }
}
