<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দ্বিতীয়বার কিনতে গেলে গতবারের চারটা সংখ্যা পর্দায় থাকে।
 *
 * ── ⭐ মালিকের কথা, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"একবার ক্রয় করার পর দ্বিতীয়বার করার সময়, রেট সহ চারটে বক্স অটো
 * বসবে। ক্রয় মূল্য বাড়লে বা কমলে বাকিগুলো সেম % অনুযায়ী নোটিশ দিয়ে
 * বাড়বে কমবে।"*
 *
 * ── ⓘ দ্বিতীয় অর্ধেকটা আগে থেকেই ছিল ───────────────────────────────
 * দর বদলালে নীতি ধরে দাম নাড়ানোর নিয়মটা `pricing.js`-এ, ১৪টা পরীক্ষাসহ।
 * সরাসরি ক্রয়ের পর্দা পণ্যের নীতিও পেত ([[DirectPurchaseService]])।
 *
 * ⛔ **ফাঁক ছিল একটাই**: বিলের পর্দায় ঐ নীতিটা কোনোদিন পাঠানো হত না।
 * ⚠️ ফলে বাক্স চারটা খালি বসে থাকত আর নাড়ানোর মতো কিছুই থাকত না —
 * জিনিসটা অর্ধেক বানানো ছিল, আর অর্ধেকটা নীরব।
 *
 * ⓘ মালিক বিল দিয়েই কেনেন (PBL-0001, PBL-0002), তাই তাঁর কাছে
 * ব্যবস্থাটা কোনোদিন কাজ করেনি।
 */
final class TheSecondPurchaseRemembersTheFirstTest extends TestCase
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

    public function test_a_bought_product_carries_its_last_rate_and_policy(): void
    {
        $product = $this->pricedProduct();

        $pricing = $this->pricingOnTheBillScreen();

        $this->assertArrayHasKey($product->id, $pricing, implode("\n", [
            '⛔ বিলের পর্দা এই পণ্যের গতবারের দর পায়নি।',
            '',
            '⚠️ তখন চারটা বাক্স খালি বসে থাকে, আর "দর বদলালে দাম নড়ে"',
            'নিয়মটার নাড়ানোর মতো কিছুই থাকে না।',
        ]));

        $row = $pricing[$product->id];

        $this->assertSame('172.5400', $row['rate']);
        $this->assertSame('215.6800', $row['sale']);
        $this->assertSame('margin', $row['anchor']);
        $this->assertSame('20.0000', $row['pct']);
    }

    /**
     * ⛔ যে পণ্য কোনোদিন কেনা হয়নি, তার "গতবার" বলে কিছু নেই।
     *
     * ⚠️ ০ বসিয়ে দিলে সেটা একটা **সংখ্যা** হিসেবে পড়া হত, আর প্রথমবার
     * কেনার সময় দরের ঘরে ০ বসে থাকত — মানুষ সেটা মুছে টাইপ করতেন,
     * নয়তো ভুলে ০-তেই কিনে ফেলতেন।
     */
    public function test_a_product_never_bought_is_left_out(): void
    {
        $fresh = Product::query()->where('purchase_price', 0)->orWhereNull('purchase_price')->first();

        if ($fresh === null) {
            $this->markTestSkipped('ডেমোর প্রতিটা পণ্যেরই দর বসানো আছে।');
        }

        $this->assertArrayNotHasKey($fresh->id, $this->pricingOnTheBillScreen(),
            '⛔ কোনোদিন না-কেনা পণ্যও গতবারের দর নিয়ে এসেছে।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের "নেই" দাবিটা চিরকাল সবুজ থাকত যদি মানচিত্রটাই খালি ফিরত।
     * ⚠️ তাই মাপা হয় ওখানে সত্যিই সারি আছে — আজ রাতে এই ফাঁদে দুইবার
     * পড়েছি, আর দুইবারই এমন একটা দাবিই ধরিয়ে দিয়েছে।
     */
    public function test_the_map_is_not_simply_empty(): void
    {
        $this->pricedProduct();

        $this->assertGreaterThan(0, count($this->pricingOnTheBillScreen()),
            'দরের মানচিত্রটাই খালি — দাবিগুলো তখন কিছুই প্রমাণ করে না।');
    }

    private function pricedProduct(): Product
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        $product->forceFill([
            'purchase_price' => '172.5400',
            'sale_price' => '215.6800',
            'pricing_anchor' => 'margin',
            'pricing_pct' => '20',
        ])->save();

        return $product;
    }

    /** @return array<int, array<string, string>> */
    private function pricingOnTheBillScreen(): array
    {
        $response = $this->get(route('purchase.bill.create'));

        $response->assertOk();

        return (array) $response->viewData('pricing');
    }
}
