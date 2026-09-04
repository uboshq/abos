<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBillGiftLine;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রয়ে উপহার — মিল যা সাথে দিয়ে দেয়, অথচ বিলে নেই।
 *
 * ── মালিকের নির্দেশ (৪ সেপ্টেম্বর ২০২৬) ──────────────────────────────
 * *"ফ্রি পণ্য ক্রয়ে থাকবে ও stock-এ free আলাদা manage হবে"* ·
 * *"উপহার কোন পণ্যের সাথে আসল তাও manage করতে হবে"*
 *
 * ── এখানে যা যাচাই হয় ───────────────────────────────────────────────
 * পর্দাটা খোলে কিনা নয়। এখানে দেখা হয় উপহারটা **সত্যিই গুদামে ঢোকে**
 * কিনা, **ফ্রি ভাণ্ডারে** ঢোকে কিনা (কেনা মজুদে নয়), জোড়াটা লেখা থাকে
 * কিনা, আর বিলের টাকার অঙ্কে সে **হাত দেয় না** কিনা।
 *
 * ⚠️ শেষেরটা সবচেয়ে নীরব: উপহার বিলের মোটে যোগ হয়ে গেলে সরবরাহকারীকে
 * বেশি টাকা দেখানো হত, আর কেউ ধরত না — কারণ সংখ্যাটা "একটু বেশি", ভুল
 * নয়।
 */
class TheMillSentABucketWithTheSoapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $soap;

    private Product $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $products = Product::query()->orderBy('id')->take(2)->get();

        $this->soap = $products->first();
        $this->bucket = $products->last();

        /*
         * দুইটা আলাদা পণ্য লাগে, আর সেটা এই পরীক্ষার শর্ত।
         *
         * একই পণ্য হলে "ফ্রি পরিমাণ" আর "উপহার" আলাদা করে দেখাই যেত না
         * — অথচ পার্থক্যটাই এই কাজের বিষয়।
         */
        $this->assertNotSame($this->soap->id, $this->bucket->id,
            'ডেমোতে দুইটা আলাদা পণ্য নেই — এই পরীক্ষাটা তখন কিছুই দেখছে না।');
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function freeQty(Product $product): string
    {
        return $this->stock()->freeQty($product, $this->warehouse);
    }

    /**
     * @param  list<array<string, mixed>>  $gifts
     * @return array{bill: \App\Modules\Purchase\Models\PurchaseBill, payment: mixed}
     */
    private function buy(array $gifts = [], string $qty = '100', string $rate = '60'): array
    {
        return app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'MEG-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [['product_id' => $this->soap->id, 'qty' => $qty, 'rate' => $rate]],
            $gifts,
        );
    }

    /** @return list<array<string, mixed>> */
    private function bucketGift(string $qty = '1'): array
    {
        return [[
            'product_id' => $this->bucket->id,
            'against_product_id' => $this->soap->id,
            'qty' => $qty,
            'remarks' => 'মিল দিয়েছে',
        ]];
    }

    public function test_the_gift_is_written_against_the_product_it_came_with(): void
    {
        $result = $this->buy($this->bucketGift());

        $gift = PurchaseBillGiftLine::query()
            ->where('purchase_bill_id', $result['bill']->id)
            ->firstOrFail();

        $this->assertSame($this->bucket->id, (int) $gift->product_id);

        /*
         * ⭐ এই একটা লাইনই মালিকের প্রশ্নের উত্তর।
         *
         * জোড়াটা ছাড়া "সাবানে আসল ক্রয়দর কত পড়ল" হিসাবটা করা যায় না —
         * দশ কার্টনের টাকা এগারো কার্টনে ভাগ হয়, দশে নয়।
         */
        $this->assertSame($this->soap->id, (int) $gift->against_product_id,
            'উপহারটা কোন পণ্যের সাথে এল সেটা লেখা হয়নি — তাহলে আসল ক্রয়দরের হিসাবটাই করা যাবে না।');

        $this->assertSame(0, bccomp((string) $gift->qty, '1', 4));
    }

    public function test_the_gift_lands_in_the_free_pool_not_in_the_bought_stock(): void
    {
        $freeBefore = $this->freeQty($this->bucket);
        $onHandBefore = $this->stock()->availableQty($this->bucket, $this->warehouse);

        $this->buy($this->bucketGift('3'));

        $this->assertSame(0, bccomp($this->freeQty($this->bucket), bcadd($freeBefore, '3', 4), 4),
            'উপহারটা ফ্রি ভাণ্ডারে ঢোকেনি — মালিকের নির্দেশ ছিল free আলাদা থাকবে।');

        /*
         * ⚠️ কেনা মজুদ এক চুলও নড়েনি — আর এটাই আসল পরীক্ষা।
         *
         * মিশে গেলে গড় ক্রয়দর নিচে নামত আর মুনাফা বেশি দেখাত, প্রতিটা
         * উপহারে একটু করে। কোনো টেস্ট ভাঙত না, কোনো লগে উঠত না।
         */
        $this->assertSame(0, bccomp($this->stock()->availableQty($this->bucket, $this->warehouse), $onHandBefore, 4),
            'উপহারটা কেনা মজুদে ঢুকে গেছে — তাহলে গড় ক্রয়দর মিথ্যা বলবে।');
    }

    public function test_the_gift_does_not_touch_the_money(): void
    {
        $plain = $this->buy();
        $withGift = $this->buy($this->bucketGift('5'));

        $this->assertSame(
            0,
            bccomp((string) $plain['bill']->total, (string) $withGift['bill']->total, 4),
            'উপহার বিলের মোট বদলে দিয়েছে — সরবরাহকারীকে তখন বেশি টাকা দেখানো হত।'
        );

        // আর উপহারটা যেন বিলের একটা সাধারণ সারি হয়ে না বসে।
        $this->assertCount(1, $withGift['bill']->fresh(['lines'])->lines,
            'উপহারটা বিলের সারি হয়ে গেছে — তাহলে "কোন পণ্যের সাথে এল" চিরতরে হারাত।');
    }

    /**
     * ⛔ লট ধরা পণ্য উপহার হিসেবে নেওয়া যায় না — আর থামাটা নীরব নয়।
     *
     * নম্বর ছাড়া লট ঢুকিয়ে দিলে ওটা একটা ফাঁকা ঘর থাকত না, **একটা
     * মিথ্যা** হত: ব্যবস্থা দাবি করত মালটা ট্র্যাক করা আছে, অথচ রিকলের
     * দিন কেউ খুঁজে পেত না।
     */
    public function test_a_lot_tracked_product_cannot_be_taken_as_a_gift_here(): void
    {
        $this->bucket->forceFill(['track_batch' => true])->save();

        try {
            $this->buy($this->bucketGift());

            $this->fail('লট ধরা পণ্য উপহার হিসেবে ঢুকে গেছে — মেয়াদ ও রিকল দুইটাই তখন মিথ্যা বলবে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('gifts', $e->errors());

            /*
             * ⭐ বার্তাটা **কোন পণ্যে** আটকেছে সেটা বলে।
             *
             * ── কেন দাবিটা বদলেছে, ৪ সেপ্টেম্বর ২০২৬ ─────────────────
             * আগে এখানে দেখা হত বার্তায় "GRN" আছে কি না — অর্থাৎ
             * বার্তাটা একটা **পথ** দেখায় কি না। মেপে দেখা গেল সেই পথটা
             * নেই: GRN-এর পর্দাতেও লটের ঘর নেই, কোনো ক্রয়-পর্দাতেই নেই।
             *
             * ⛔ অর্থাৎ পুরনো বার্তাটা কেবল অসম্পূর্ণ ছিল না, **মিথ্যা**
             * ছিল — ব্যবহারকারী GRN-এ গিয়ে একই ব্যতিক্রম পেতেন আর
             * ভাবতেন ব্যবস্থাটাই ভাঙা।
             *
             * তাই এখন দাবিটা সেটাই যা সত্যিই দরকারি আর সত্যিই সম্ভব:
             * দশ লাইনের কোনটায় আটকেছে, বার্তাটা তার নাম বলুক। নাহলে
             * মানুষটাকে এক এক করে খুঁজতে হত।
             */
            $this->assertStringContainsString($this->bucket->name(), $e->errors()['gifts'][0],
                'বার্তাটা বলে দেয়নি কোন পণ্যে আটকেছে — দশ লাইনের কার্টে ওটা না বললে খুঁজতে হয়।');
        }

        // আর কিছুই বসেনি — অর্ধেক অবস্থা নেই।
        $this->assertSame(0, PurchaseBillGiftLine::query()->count(),
            'থেমে যাওয়ার পরেও একটা উপহারের সারি বসে গেছে।');
    }

    public function test_a_purchase_without_gifts_writes_none(): void
    {
        $this->buy();

        $this->assertSame(0, PurchaseBillGiftLine::query()->count());
    }

    /**
     * পর্দার উপহারের ঘরগুলো সত্যিই ফর্মের সাথে যায়।
     *
     * ⓘ এই রিপো এই শিক্ষাটা একবার দামে কিনেছে: ঘরগুলো `::name` দিয়ে
     * লেখা ছিল, Blade সাধারণ `<input>`-এ `::` খোলে না, তাই নামটা কখনো
     * বসত না — পর্দায় সারি দেখা যেত, অথচ সার্ভার বলত কিছুই আসেনি।
     */
    public function test_the_gift_fields_are_bound_to_the_form(): void
    {
        $html = $this->get(route('purchase.direct.create'))->assertOk()->getContent();

        foreach (['product_id', 'qty', 'against_product_id'] as $field) {
            $this->assertStringContainsString(
                ':name="`gifts[${line.key}-${gi}]['.$field.']`"',
                $html,
                "উপহারের {$field} ঘরটার নাম Alpine দিয়ে বাঁধা নেই — ফর্মে ওটা যাবে না।"
            );
        }

        $this->assertStringNotContainsString('::name=', $html,
            'রেন্ডার করা HTML-এ `::name` রয়ে গেছে — Alpine ওটা উপেক্ষা করে।');
    }
}
