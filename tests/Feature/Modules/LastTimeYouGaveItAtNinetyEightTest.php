<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\LastPaidRate;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "গতবার আপনি ৯৮-এ দিয়েছিলেন, আজ ১০৫ কেন?"
 *
 * ── মালিকের চাওয়া (৪ সেপ্টেম্বর ২০২৬) ───────────────────────────────
 * সংখ্যাটার একটাই কাজ — **দরাদরি**। তাই তিনটা শর্ত, আর তিনটাই এখানে
 * পরীক্ষা হয়:
 *
 *   ১. দরটা **এই** সরবরাহকারীর, যে কারো নয়
 *   ২. সাথে **তারিখ**, নাহলে সংখ্যাটা তর্কে টেকে না
 *   ৩. আগে কেনা না থাকলে **শূন্য নয়** — শূন্য মানে "ফ্রি দিয়েছিল"
 *
 * ⚠️ এক নম্বরটাই সবচেয়ে জরুরি: অন্য সরবরাহকারীর দর দেখিয়ে দরাদরি করতে
 * গেলে ধরা পড়তে হয়, আর তখন পুরো সংখ্যাটার উপর বিশ্বাস চলে যায়।
 */
class LastTimeYouGaveItAtNinetyEightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $mill;

    private Supplier $otherMill;

    private Warehouse $warehouse;

    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->soap = Product::query()->firstOrFail();

        $suppliers = Supplier::query()->orderBy('id')->take(2)->get();

        $this->mill = $suppliers->first();
        $this->otherMill = $suppliers->last();

        $this->assertNotSame($this->mill->id, $this->otherMill->id,
            'ডেমোতে দুইটা আলাদা সরবরাহকারী নেই — তাহলে "অন্যের দর ফাঁস হয় না" প্রমাণই করা যায় না।');
    }

    private function buy(Supplier $from, string $rate, ?string $on = null): PurchaseBill
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $from->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => $on ?? now()->toDateString(),
                'supplier_bill_no' => 'MEG-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [['product_id' => $this->soap->id, 'qty' => '10', 'rate' => $rate]],
        );

        return $result['bill'];
    }

    /** @return array<int, array{rate: string, on: string}> */
    private function rates(Supplier $from): array
    {
        return app(LastPaidRate::class)->forSupplier((int) $from->id);
    }

    public function test_it_gives_back_the_rate_and_the_day(): void
    {
        $this->buy($this->mill, '98', '2026-08-12');

        $found = $this->rates($this->mill);

        $this->assertArrayHasKey($this->soap->id, $found);
        $this->assertSame(0, bccomp($found[$this->soap->id]['rate'], '98', 4));

        /*
         * ⭐ তারিখটা ছাড়া সংখ্যাটা তর্কে টেকে না।
         *
         * গত সপ্তাহের ৯৮ আর ছয় মাস আগের ৯৮ এক জিনিস নয়; দ্বিতীয়টা
         * দিয়ে দরাদরি করতে গেলে সরবরাহকারীর কাছেই ধরা পড়তে হয়।
         */
        $this->assertSame('2026-08-12', substr($found[$this->soap->id]['on'], 0, 10),
            'তারিখটা আসেনি — সংখ্যাটা তখন আর দরাদরির কাজে লাগে না।');
    }

    /**
     * ⛔ অন্য সরবরাহকারীর দর এখানে আসে না।
     *
     * এটাই এই সেবাটার একমাত্র কারণ। `products.purchase_price` কোম্পানি-
     * ব্যাপী শেষ দর — সেটা দেখিয়ে **এই** সরবরাহকারীকে চাপ দেওয়া যায় না,
     * আর চেষ্টা করলে কথাটা তাঁরই ঠিক হত।
     */
    public function test_one_suppliers_rate_never_shows_up_under_another(): void
    {
        $this->buy($this->otherMill, '75');

        $this->assertSame([], $this->rates($this->mill),
            'অন্য সরবরাহকারীর দর এই সরবরাহকারীর নামে দেখানো হচ্ছে।');

        // আর যাঁর কাছ থেকে সত্যিই কেনা হয়েছে, তাঁর ঘরে ঠিকই আছে।
        $this->assertArrayHasKey($this->soap->id, $this->rates($this->otherMill));
    }

    /**
     * "শেষ" মানে তারিখে শেষ, বসানোয় শেষ নয়।
     *
     * ⚠️ পুরনো তারিখের একটা বিল আজ বসানো হতেই পারে — কাগজ দেরিতে এসেছে।
     * শুধু আইডি ধরে সাজালে ওই বিলটাই "গতবার" হয়ে বসত, আর পর্দা ছয় মাস
     * আগের দর দেখাত।
     */
    public function test_the_latest_by_date_wins_not_the_latest_typed(): void
    {
        $this->buy($this->mill, '105', '2026-08-20');

        /*
         * পরে বসানো, কিন্তু তারিখে পুরনো।
         *
         * ⚠️ দুইটা তারিখই **খোলা অর্থবছরের ভিতরে** রাখতে হয়। প্রথমে
         * ২০২৬-০১-০৫ লেখা হয়েছিল, আর ব্যবস্থা ঠিকই থামিয়ে দিয়েছে:
         * "কোনো খোলা অর্থবছরে পড়ে না"। ⓘ পাহারাটা কাজ করছে বলেই
         * টেস্টটা লাল হয়েছিল — কোডের ভুলে নয়।
         */
        $this->buy($this->mill, '60', '2026-07-05');

        $found = $this->rates($this->mill);

        $this->assertSame(0, bccomp($found[$this->soap->id]['rate'], '105', 4),
            'পরে বসানো পুরনো বিলটা "গতবার" হয়ে বসেছে — তারিখ নয়, আইডি ধরে সাজানো হচ্ছে।');
    }

    /**
     * বাতিল হওয়া বিলের দর দেখানো হয় না।
     *
     * বাতিল মানে ঘটনাটা ঘটেনি। ওই দর দেখিয়ে দরাদরি করতে গেলে
     * সরবরাহকারী বলতেন "ওটা তো ফেরত গেছে" — আর তিনিই ঠিক।
     */
    public function test_a_cancelled_bill_is_not_a_price_history(): void
    {
        $bill = $this->buy($this->mill, '98');

        $bill->forceFill(['status' => DocumentStatus::CANCELLED])->save();

        $this->assertSame([], $this->rates($this->mill),
            'বাতিল বিলের দর এখনো "গতবার" হিসেবে দেখানো হচ্ছে।');
    }

    public function test_a_product_never_bought_from_this_supplier_is_simply_absent(): void
    {
        $this->buy($this->mill, '98');

        $other = Product::query()->where('id', '<>', $this->soap->id)->firstOrFail();

        /*
         * ⛔ শূন্য নয়, **অনুপস্থিত** — আর পার্থক্যটা পর্দায় গিয়ে বড় হয়।
         *
         * শূন্য ফেরত দিলে পর্দা লিখত "গতবার ০.০০", আর সেটা পড়া যেত
         * "মিল ফ্রি দিয়েছিল" — একটা মিথ্যা, যেটা দরাদরিতে ব্যবহার হত।
         */
        $this->assertArrayNotHasKey($other->id, $this->rates($this->mill));
    }

    /** দরজাটা অন্য কোম্পানির সরবরাহকারী দেখায় না। */
    public function test_the_door_does_not_open_on_another_companys_supplier(): void
    {
        $outsider = Company::query()->where('code', '<>', 'TDEPOT')->firstOrFail();

        /*
         * ⚠️ সরবরাহকারীটা এখানে **বানিয়ে নেওয়া হয়**, খুঁজে নয়।
         *
         * প্রথমে খুঁজে না পেলে টেস্টটা `markTestSkipped` করত — আর সেটাই
         * ঘটেছিল, কারণ ডেমোর দ্বিতীয় কোম্পানিতে একটাও সরবরাহকারী নেই।
         * **এড়িয়ে যাওয়া টেস্ট সবুজ দেখায় অথচ কিছুই প্রমাণ করে না**, আর
         * এই দরজাটার ফাঁক মানে অন্য কোম্পানির দর ফাঁস — চুপচাপ।
         */
        $mine = CompanyContext::id();

        CompanyContext::set($outsider->id, null);

        $theirs = Supplier::query()->create([
            'code' => 'SUP-OUTSIDE',
            'name_en' => 'Someone Else Traders',
            'name_bn' => 'অন্য কারো ট্রেডার্স',
        ]);

        CompanyContext::set($mine, null);

        $this->get(route('purchase.direct.last_rates', ['supplier' => $theirs->id]))
            ->assertNotFound();
    }

    /**
     * ⭐ সংখ্যাটা কার্টের সারিতে বসে, এন্ট্রি স্ট্রিপে ভেসে থাকে না।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া হয় ───────────────────────────
     * এন্ট্রি স্ট্রিপেও একটা "শেষ ক্রয়দর" আছে, আর সেটা **কার্টে যাওয়ামাত্র
     * মিলিয়ে যায়**। মালিকের নকশায় কারণটা লেখা: বারো লাইন পরে, তিনটা
     * গতবারের চেয়ে দামি — আর যিনি চূড়ান্ত বোতাম চাপতে যাচ্ছেন তিনি
     * জানতেই পারবেন না।
     *
     * কেউ একদিন সংখ্যাটা আবার স্ট্রিপে তুলে নিলে এই টেস্ট লাল হবে।
     */
    public function test_the_number_lives_on_the_cart_row(): void
    {
        $html = $this->get(route('purchase.direct.create'))->assertOk()->getContent();

        $this->assertStringContainsString('lastRateFor(line)', $html,
            'গতবারের দর কার্টের সারিতে বাঁধা নেই — স্ট্রিপে থাকলে সারিটা কার্টে গেলেই মিলিয়ে যায়।');

        $this->assertStringContainsString(__('purchase::message.first_from_supplier'), $html,
            'আগে না কেনা পণ্যের জন্য কোনো কথা লেখা নেই — তখন ঘরটা খালি দেখাত, আর কেউ ওটা ০ পড়ত।');

        /*
         * ⛔ "গতবারের দর" শিরোনামের নিচে শূন্য যেন কখনো না বসে।
         *
         * পর্দা সংখ্যাটা `lastRateFor(line)` থেকেই নেয়, আর ওটা না পেলে
         * `null` — তাই শূন্য বসার পথই নেই। এই দাবিটা তার পাহারা।
         */
        $this->assertStringNotContainsString('lastRateFor(line)?.rate || 0', $html,
            'অনুপস্থিত দরকে শূন্য বানানো হচ্ছে — শূন্য মানে "ফ্রি দিয়েছিল", আর সেটা মিথ্যা।');
    }
}
