<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সুইচটা ছিল, কিন্তু কোনো পর্দা ওটা পর্যন্ত পৌঁছাত না।
 *
 * ── ⓘ মালিকের নিয়ম, ২২–২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"লট ছাড়া মাল ঢুকবেও না, বেরোবেও না।"* ⓘ লট মানে একসাথে আসা মালের
 * একটা চালান, যার নিজের মেয়াদ আছে।
 *
 * ── ⛔ যা মেপে ধরা পড়েছে ────────────────────────────────────────────
 * `products.track_batch` ঘরটা **আগস্ট থেকেই আছে**, আর মজুদের কোড ওটা
 * দেখে চলে। ⚠️ কিন্তু ঘরটা `fillable`-এ ছিল না, আর কোনো ফর্ম-ঘর বা
 * যাচাইও ছিল না — অর্থাৎ **চালু করার কোনো পথই ছিল না**।
 *
 * ⓘ ধরা পড়েছে ফ্রি মালের অনুপাতের কাজে: পরীক্ষায়
 * `update(['track_batch' => true])` লিখে দেখি Laravel নীরবে ওটা ফেলে
 * দেয়। ⛔ লাইভে ১৭৭টা পণ্যের **একটাতেও** লট চালু নেই, আর সেটাই কারণ।
 *
 * ── ⚠️ কেন এটা ফ্রির নিয়মের ভিত্তি ──────────────────────────────────
 * অনুপাতটা লট ধরে হিসাব হয় ([[FreeRatio]])। লট না থাকলে *"কোন চালানের
 * মাল"* প্রশ্নের উত্তর নেই, তাই অনুপাতেরও উত্তর নেই — গোটা নিয়মটাই
 * নীরবে শূন্য ফেরত দিত।
 */
final class TheLotSwitchExistedButNoScreenCouldReachItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ পর্দা থেকে লট চালু করা যায়।
     */
    public function test_the_switch_can_be_turned_on_from_the_screen(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        $this->save($product, lots: true);

        $this->assertTrue((bool) $product->fresh()->track_batch, 'সুইচটা এখনো পর্দা থেকে বসানো যাচ্ছে না।');
    }

    /**
     * ⛔ আর বন্ধও করা যায়।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা একা থাকলে *"সবসময় চালু"* লিখে দিলেও সবুজ থাকত — আর তখন
     * ওটা সুইচ নয়, একটা স্থির সিদ্ধান্ত। ⓘ চাল-ডাল-সাবানের মতো যে
     * মালে লট নেই, তাদের বন্ধ রাখার পথ থাকা চাই।
     */
    public function test_the_switch_can_be_turned_off_again(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        $this->save($product, lots: true);

        /*
         * ⚠️ মাঝের অবস্থাটা যাচাই করা হয়, আর সেটা মেপে শেখা।
         *
         * ⛔ ছাড়া দাবিটা **কখনো লাল হত না**: সুইচটা পুরোপুরি অকেজো হলেও
         * পণ্যটা তো এমনিতেই বন্ধ থাকে, তাই শেষের `assertFalse` সবুজ।
         * ⓘ মিউট্যান্ট চালিয়ে ধরা পড়েছে — `fillable` থেকে ঘরটা তুলে
         * নেওয়ার পরেও এই দাবি পাশ করছিল।
         */
        $this->assertTrue((bool) $product->fresh()->track_batch, 'চালুই হয়নি, তাই বন্ধ করার দাবিটা কিছুই মাপছে না।');

        $this->save($product, lots: false);

        $this->assertFalse((bool) $product->fresh()->track_batch, 'সুইচটা আর বন্ধ করা যাচ্ছে না।');
    }

    /**
     * ⭐ আর নতুন পণ্যের ফর্মে ঘরটা আগে থেকেই টিক দেওয়া।
     *
     * ── ⓘ কেন ডিফল্টে চালু ──────────────────────────────────────────
     * মালিকের নিয়ম *"লট ছাড়া মাল ঢুকবেও না"*। ⚠️ ডিফল্টে বন্ধ রাখলে
     * প্রতিটা নতুন পণ্যে কেউ টিক দিতে ভুলত, আর ভুলটা ধরা পড়ত ছয় মাস
     * পরে — যখন ঐ মালের মেয়াদ বা ফ্রির অনুপাত কিছুই বলা যেত না।
     *
     * ⓘ যাঁর দরকার নেই তিনি টিক তুলে নেবেন; ভুলে যাওয়ার দিকটা তখন
     * নিরাপদ দিকে পড়ে।
     */
    public function test_a_new_product_starts_with_lots_on(): void
    {
        $html = (string) $this->get(route('inventory.product.create'))->assertOk()->getContent();

        $at = strpos($html, 'name="track_batch" value="1"');

        $this->assertNotFalse($at, 'নতুন পণ্যের ফর্মে লটের ঘরটাই নেই।');

        /*
         * ⚠️ দাবিটা ঘরের **নিজের** ট্যাগে, পাতার কোথাও `checked` আছে
         * কি না তাতে নয় — পাতায় আরও চেকবক্স থাকে, আর ওভাবে দেখলে
         * দাবিটা কখনো লাল হত না।
         */
        $tag = substr($html, $at, 120);

        $this->assertStringContainsString('checked', $tag, 'নতুন পণ্যে লট ডিফল্টে চালু নেই।');
    }

    /** পণ্যটা পর্দা থেকে সংরক্ষণ করা — কেবল সুইচটা বদলে। */
    private function save(Product $product, bool $lots): void
    {
        $this->put(route('inventory.product.update', $product), [
            'code' => $product->code,
            'name_en' => $product->name_en,
            'name_bn' => $product->name_bn,
            'unit_id' => $product->unit_id,
            'track_batch' => $lots ? '1' : '0',
        ])->assertSessionHasNoErrors();
    }
}
