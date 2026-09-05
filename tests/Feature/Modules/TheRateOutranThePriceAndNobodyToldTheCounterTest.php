<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ক্রয়দর দামকে ছাড়িয়ে গেল, আর কাউন্টার জানতেই পারল না।
 *
 * ── মালিকের শর্ত, ৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * *"দাম বাড়ার সাথে সাথে নোটিশ দিয়ে দিবে যখন প্রোডাক্ট অ্যাড করবে। আর বিল
 * কনফার্ম করার সময় যদি sales price না বাড়ায়, তখন সফটওয়্যার ওয়ার্নিং দিয়ে
 * নিজেই বাড়াবে।"*
 *
 * ── কেন দুইটা আলাদা মুহূর্তে দুই আচরণ ───────────────────────────────
 * পর্দায় মানুষটা কাগজ হাতে দাঁড়িয়ে, আর তিনি এমন কিছু জানতে পারেন যা নিয়ম
 * জানে না — এবারের দরটা একটা অফার, বা এক চালানের বাড়তি ভাড়া। ⓘ তাই ওখানে
 * **জিজ্ঞেস করা হয়**, বসানো হয় না।
 *
 * ⛔ কিন্তু বিল একবার নিশ্চিত হয়ে গেলে কাউন্টার থেমে থাকে না — সে পুরনো
 * দামেই বেচতে থাকে। ⚠️ নোটিশ একটা কাগজ, বিক্রি একটা ঘটনা; যে নোটিশ কেউ
 * পড়েনি সে একটা টাকাও বাঁচায় না।
 *
 * ── এই ফাইলটা কেন দরকার ─────────────────────────────────────────────
 * ⚠️ এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। ⓘ কেবল প্রতিটা বিক্রি একটু কম
 * মুনাফায় হয় — বা লোকসানে — আর বছরশেষে কেউ ধরতে পারে না কেন কম পড়ল।
 */
class TheRateOutranThePriceAndNobodyToldTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

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
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function payload(array $line): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'RATE-'.fake()->unique()->numberBetween(1000, 9999),
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '10',
                ...$line,
            ]],
        ];
    }

    /**
     * নীতিটা কাগজ থেকে পণ্যে পৌঁছায়।
     *
     * ⓘ এটাই বাকি সবকিছুর ভিত: নীতি জমা না হলে পরের বার ব্যবস্থা জানত না
     * এই পণ্যটা কত শতাংশে বেচার কথা, আর চুপ করে থাকত — ৬ সেপ্টেম্বরের আগে
     * ঠিক তাই হত।
     */
    public function test_the_policy_travels_from_the_paper_to_the_product(): void
    {
        $this->post(route('purchase.direct.store'), $this->payload([
            'rate' => '100',
            'sales_price' => '166.6667',
            'pricing_anchor' => 'margin',
            'pricing_pct' => '40',
        ]))->assertRedirect();

        $this->product->refresh();

        $this->assertSame('margin', $this->product->pricing_anchor);
        $this->assertEqualsWithDelta(40.0, (float) $this->product->pricing_pct, 0.0001);
    }

    /** ⛔ আসল পরীক্ষাটা: দর বেড়েছে, দাম বাড়ানো হয়নি — সফটওয়্যার বাড়াবে। */
    public function test_the_software_raises_a_price_the_buyer_forgot_to_raise(): void
    {
        $this->product->forceFill([
            'pricing_anchor' => 'margin',
            'pricing_pct' => '40',
        ])->save();

        // দর ১২০ হলে ৪০% মার্জিনে দাম হওয়ার কথা ২০০ — কিন্তু ১৬৬.৬৭ লেখা হল
        $this->post(route('purchase.direct.store'), $this->payload([
            'rate' => '120',
            'sales_price' => '166.6667',
        ]))->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(
            200.0,
            (float) $bill->lines->first()->sales_price,
            0.01,
            "ক্রয়দর ১২০ হওয়ার পরেও বিক্রয়দর ১৬৬.৬৭-ই রয়ে গেছে।\n"
            .'৪০% মার্জিনের নীতিতে ওটা ২০০ হওয়ার কথা — নাহলে প্রতিটা বিক্রি '
            .'কম মুনাফায় হত, আর কোনো ত্রুটিবার্তা আসত না।',
        );

        $this->assertEqualsWithDelta(
            200.0,
            (float) $this->product->fresh()->sale_price,
            0.01,
            'লাইনের দাম বেড়েছে কিন্তু পণ্যে বসেনি — কাউন্টার তাহলে পুরনো দামেই বেচত।',
        );
    }

    /**
     * ⛔ লোকসানের ঘরটা — মালিকের কথা: *"অবশ্যই বদলাবে"*।
     *
     * ⚠️ ক্রয়দর বিক্রয়দরকে ছাড়িয়ে গেলে প্রতিটা বিক্রি একটা লোকসান। ⓘ এখানে
     * অপেক্ষা করার কোনো যুক্তি নেই — নোটিশ পড়া হোক বা না হোক, মাল বিক্রি
     * হয়েই যায়।
     */
    public function test_a_price_below_cost_is_always_raised(): void
    {
        $this->product->forceFill([
            'pricing_anchor' => 'markup',
            'pricing_pct' => '50',
        ])->save();

        // ২০০ টাকায় কিনে ১৫০-এ বেচা — প্রতিটা বিক্রিতে ৫০ টাকা ক্ষতি
        $this->post(route('purchase.direct.store'), $this->payload([
            'rate' => '200',
            'sales_price' => '150',
        ]))->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $price = (float) $bill->lines->first()->sales_price;

        $this->assertGreaterThan(
            200.0,
            $price,
            'বিক্রয়দর ক্রয়দরের নিচেই রয়ে গেছে — অর্থাৎ প্রতিটা বিক্রি একটা লোকসান, '
            .'আর কোথাও কিছু ভাঙা দেখাত না।',
        );

        $this->assertEqualsWithDelta(300.0, $price, 0.01);
    }

    /**
     * নীতি নেই মানে ব্যবস্থার কিছু বলার নেই।
     *
     * ⚠️ ডিফল্টে কোনো নীতি ধরে নেওয়া হয় না। ⓘ ধরে-নেওয়া নিয়মে দাম বদলানো
     * সবচেয়ে খারাপ হত: কেউ কোনোদিন বলেননি এই পণ্যটা কত শতাংশে বেচা হয়।
     */
    public function test_a_product_without_a_policy_is_left_alone(): void
    {
        $this->product->forceFill(['pricing_anchor' => null, 'pricing_pct' => null])->save();

        $this->post(route('purchase.direct.store'), $this->payload([
            'rate' => '500',
            'sales_price' => '150',
        ]))->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(
            150.0,
            (float) $bill->lines->first()->sales_price,
            0.01,
            'নীতি ছাড়া পণ্যেও দাম বদলে গেছে — অর্থাৎ ব্যবস্থা এমন একটা নিয়ম '
            .'ধরে নিয়েছে যা কেউ বলেনি।',
        );
    }

    /**
     * ⛔ কেবল বাড়ায়, কমায় না।
     *
     * ⓘ দর কমলে পুরনো বেশি দামে বেচতে থাকা ক্ষতি নয়। ⚠️ আর দাম কমানো একটা
     * ব্যবসায়িক সিদ্ধান্ত — কখন অফার দেওয়া হবে, কখন প্রতিযোগীর সাথে মেলানো
     * হবে। মেশিন সেটা নিতে পারে না।
     *
     * ⓘ মালিক এই যুক্তিটা আলাদা করে গ্রহণ করেছেন, ৬ সেপ্টেম্বর ২০২৬।
     */
    public function test_a_cheaper_rate_never_cuts_the_price_by_itself(): void
    {
        $this->product->forceFill([
            'pricing_anchor' => 'margin',
            'pricing_pct' => '40',
        ])->save();

        $this->post(route('purchase.direct.store'), $this->payload([
            'rate' => '50',
            'sales_price' => '200',
        ]))->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(
            200.0,
            (float) $bill->lines->first()->sales_price,
            0.01,
            'দর কমতেই সফটওয়্যার নিজে থেকে দাম কমিয়ে দিয়েছে — ওটা ব্যবসার '
            .'সিদ্ধান্ত, মেশিনের নয়।',
        );
    }
}
