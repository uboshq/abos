<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক কেজির ভগ্নাংশ এলে স্তর মালের চেয়ে বড় হয়ে যেত।
 *
 * ── ⛔ যা ঘটত, ২৭ সেপ্টেম্বর ২০২৬ (নিরীক্ষা §২, abos-7c প্রমাণ দিয়েছেন) ──
 * [[CostLayerService::receiveWorth()]] বিলের টাকাটা হুবহু রাখে: কয়েকটা একক
 * এক পয়সা বেশি দরে বসায়, বাকিটা কম দরে। ⓘ কৌশলটা ধরে নেয় পরিমাণ
 * **পূর্ণসংখ্যক একক** — তখন *"কয়টা একক এক পয়সা বেশি"* প্রশ্নটার মানে হয়।
 *
 * ⚠️ ০.৭ কেজি আর ১০০ টাকার বিলে সেটা ভেঙে পড়ত, আর সংখ্যাগুলো মাপা:
 *
 *     low     = ১৪২.৮৫৭১
 *     residue = ০.০০০১
 *     higher  = ১        ← কোড এটাকে **পরিমাণ** ধরে
 *     rest    = −০.৩০০০  ← ঋণাত্মক, তাই দ্বিতীয় স্তর বসতই না
 *
 * ⛔ ফল: স্তরে ১.০ কেজি @ ১৪২.৮৫৭২ — অথচ এসেছিল ০.৭, আর বিল ছিল ১০০।
 * ⓘ অর্থাৎ **০.৩ একক মজুদ আর ৪২.৮৫৭২ টাকার মূল্য শূন্য থেকে তৈরি হত।**
 *
 * ⚠️ আর ঋণাত্মক `rest` **নীরবে** বাদ পড়ত, কারণ শর্তটা `> 0` — কোনো
 * ব্যতিক্রম নেই, কোনো লাল নেই, কেবল মজুদ-খাত আর মজুদ রিপোর্ট আলাদা।
 *
 * ── ⭐ সারাইয়ে কিছুই ছাড়তে হয়নি, আর সেটাও মাপা ─────────────────────
 * ⓘ প্রথমে ভেবেছিলাম ভগ্নাংশে পরিমাণ বা মূল্য — একটা ছাড়তেই হবে।
 * ⚠️ মাপা বলল উল্টো:
 *     ০.৭ × ১৪২.৮৫৭১ = ৯৯.৯৯৯৯
 *     ০.৭ × ১৪২.৮৫৭২ = ১০০.০০০০   ← হুবহু
 * ⭐ তাই পুরো পরিমাণটা উঁচু দরে বসালে **দুইটাই** ঠিক থাকে।
 *
 * ── ⓘ এই ফাইলটা কী মাপে, আর কী মাপে না ───────────────────────────────
 * ⭐ মাপে: স্তরের মোট পরিমাণ = যা এল · স্তরের মোট মূল্য = বিল · আর
 *   পূর্ণসংখ্যায় আগের আচরণ অটুট।
 * ⛔ মাপে না: মজুদের তাক (`StockService`) — সেটা আলাদা ঘর, আর এই বাগ
 *   কেবল খরচের স্তরের।
 */
final class AFractionOfAKiloMadeALayerBiggerThanTheGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবিটা ─────────────────────────────────────────────────

    public function test_a_fraction_makes_a_layer_no_bigger_than_what_came(): void
    {
        $this->receiveWorth('0.7', '100.0000');

        $this->assertSame('0.7000', $this->layerQty(),
            'স্তরে যা বসেছে তা মালের চেয়ে বেশি — শূন্য থেকে মজুদ তৈরি হয়েছে।');
    }

    public function test_the_bill_is_still_exactly_preserved(): void
    {
        /*
         * ⚠️ এই দাবিটা ছাড়া সারাইটা "বাড়তিটা ফেলে দাও" হয়ে যেতে পারত,
         * আর তখন পরিমাণ ঠিক হত কিন্তু **মূল্য কম** বসত — আর সেটা মজুদ-খাত
         * আর বিলের মধ্যে গরমিল, অর্থাৎ আগের বাগেরই উল্টো পিঠ।
         */
        $this->receiveWorth('0.7', '100.0000');

        $this->assertSame('100.0000', $this->layerValue(),
            'স্তরের মোট মূল্য বিলের সাথে মেলে না।');
    }

    // ── ⓘ যা বদলায়নি, আর বদলানো চলবে না ─────────────────────────────

    public function test_a_whole_number_behaves_exactly_as_before(): void
    {
        /*
         * ⓘ ৩টা একক আর ১০০ টাকা: low = ৩৩.৩৩৩৩, residue = ০.০০০১,
         * তাই ১টা একক বসে ৩৩.৩৩৩৪-এ আর ২টা ৩৩.৩৩৩৩-এ। ⭐ এটাই আসল
         * কৌশলটা, আর সারাইটা এখানে কিছুই বদলায় না — কারণ ১ ≤ ৩।
         */
        $this->receiveWorth('3', '100.0000');

        $this->assertSame('3.0000', $this->layerQty());
        $this->assertSame('100.0000', $this->layerValue());
        $this->assertSame(2, $this->mine()->count(),
            'পূর্ণসংখ্যায় দুইটা স্তর হওয়ার কথা — এক পয়সার ধাপটা এখনো কাজ করে।');
    }

    public function test_a_fraction_that_divides_cleanly_needs_no_step(): void
    {
        /*
         * ⓘ ০.৫ কেজি আর ১০০ টাকা: দর ঠিক ২০০, কোনো অবশিষ্ট নেই।
         * ⭐ এই দাবিটা পাহারা দেয় যেন সীমাটা ভালো ক্ষেত্রেও হাত না দেয়।
         */
        $this->receiveWorth('0.5', '100.0000');

        $this->assertSame('0.5000', $this->layerQty());
        $this->assertSame('100.0000', $this->layerValue());
        $this->assertSame(1, $this->mine()->count(),
            'অবশিষ্ট শূন্য হলে একটাই স্তর হওয়ার কথা।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⛔ কেবল **এই পরীক্ষার** স্তরগুলো।
     *
     * ⓘ প্রথমে সব স্তর যোগ করতাম, আর চারটা দাবিই লাল হয়েছিল —
     * সারাইয়ের আগেও, পরেও। ⚠️ কারণ [[DemoSeeder]] নিজেই খরচের স্তর
     * বানায় (লাইন ৮১৬), তাই যোগফলে বীজের মালও ঢুকত।
     *
     * ⭐ শিক্ষা: লাল দেখে কোডকে দোষ দেওয়া যায় না — **মাপটাই ভুল
     * জনসংখ্যা গুনছিল**। ⓘ সারাইয়ের আগে আর পরে একই লাল দেখাটাই ছিল
     * সংকেত।
     */
    private function mine(): \Illuminate\Support\Collection
    {
        return CostLayer::query()->where('source_type', 'test:fraction')->get();
    }

    private function receiveWorth(string $qty, string $value): void
    {
        app(CostLayerService::class)->receiveWorth(
            product: $this->product,
            qty: $qty,
            value: $value,
            sourceType: 'test:fraction',
            sourceId: 1,
            documentNo: 'FR-1',
        );
    }

    /** ⓘ সব স্তরের পরিমাণ যোগ — bcmath-এ, float-এ নয়। */
    private function layerQty(): string
    {
        return $this->mine()->reduce(
            fn (string $sum, CostLayer $l) => bcadd($sum, (string) $l->qty_in, 4),
            '0',
        );
    }

    /** ⓘ পরিমাণ × দর, স্তরে স্তরে — মোট মূল্য এভাবেই মেলে। */
    private function layerValue(): string
    {
        return $this->mine()->reduce(
            fn (string $sum, CostLayer $l) => bcadd(
                $sum,
                bcmul((string) $l->qty_in, (string) $l->unit_cost, 4),
                4,
            ),
            '0',
        );
    }
}
