<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রিপোর্ট বলত কখন কিনতে হবে, কোনোদিন বলত না কতটা।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * `products.reorder_level` আর ড্যাশবোর্ডের *"এর নিচে নেমেছে"* তালিকা
 * আগে থেকেই আছে, আর সেটা প্রশ্নের প্রথম অর্ধেকের উত্তর দেয়। ⚠️ দ্বিতীয়
 * অর্ধেকটা — **কতটা কিনব** — কোথাও লেখা ছিল না, তাই মানুষ আন্দাজে
 * অর্ডার দিতেন।
 *
 * ⓘ আন্দাজ দুই দিকেই ভুল হয়: কম কিনলে দুই সপ্তাহ পরে আবার একই
 * তালিকায়, বেশি কিনলে টাকা গুদামে পড়ে থাকে।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. যে পণ্য স্তরের নিচে নেমেছে সে তালিকায় আসে
 *   ২. যে পণ্য উপরে আছে সে আসে না
 *   ৩. যার স্তরই বলা নেই সে আসে না
 *   ৪. প্রস্তাবটা সর্বোচ্চ মজুদ ধরে হিসাব হয়
 *   ৫. কিছুই বলা না থাকলে প্রস্তাব **খালি**, বানানো সংখ্যা নয়
 *   ৬. দরজাটা বন্ধ, যার চাবি নেই তার কাছে
 *
 * ⓘ (৫) সবচেয়ে সহজে ভাঙে। ⛔ একটা বানানো সংখ্যা বসালে সেটা দিয়েই
 * অর্ডার চলে যেত, আর কেউ জানত না সংখ্যাটা কোথা থেকে এল।
 */
final class TheReportSaidWhenToBuyAndNeverHowMuchTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    // ── ১–৩ · কে তালিকায় আসে ─────────────────────────────────────────

    public function test_a_product_below_its_level_shows_up(): void
    {
        $short = $this->stocked('ফুরিয়ে আসা পণ্য', onHand: '5', reorder: '20');

        $this->assertStringContainsString($short->name(), $this->sheet(),
            'স্তরের নিচে নেমে যাওয়া পণ্যটাই তালিকায় নেই — তাহলে রিপোর্টটা '
            .'যে প্রশ্নের জন্য লেখা, সেটারই উত্তর দেয় না।');
    }

    public function test_a_product_above_its_level_stays_out(): void
    {
        $plenty = $this->stocked('যথেষ্ট আছে', onHand: '90', reorder: '20');

        $this->assertStringNotContainsString($plenty->name(), $this->sheet(),
            'যে পণ্য যথেষ্ট আছে সেটাও কেনার তালিকায় এসেছে — তাহলে তালিকাটা '
            .'গোটা পণ্য-তালিকা হয়ে যেত, আর যে দশটা সত্যিই লাগে সেগুলো হারাত।');
    }

    public function test_a_product_with_no_level_set_stays_out(): void
    {
        /*
         * ⛔ স্তর বলা না থাকলে *"ফুরিয়ে আসছে"* বলে কিছু মাপা যায় না।
         *
         * ── ⚠️ আর "বলা নেই" মানে **শূন্য**, খালি নয় ───────────────────
         * ⓘ `reorder_level` ঘরটা `NOT NULL DEFAULT 0` — প্রথম চেষ্টায়
         * এখানে `null` বসাতে গিয়ে ডেটাবেসই থামিয়ে দিয়েছে, আর সেটাই
         * আসল খবরটা দিল: ⛔ রিপোর্টের `whereNotNull` ছাঁকনিটা কিছুই
         * ছাঁকত না।
         *
         * ⚠️ তখন শর্তটা দাঁড়াত `available <= 0`, অর্থাৎ **শূন্য মজুদের
         * প্রতিটা পণ্য** কেনার তালিকায় ঢুকে পড়ত।
         */
        $unset = $this->stocked('স্তর বলা নেই', onHand: '0', reorder: '0');

        $this->assertStringNotContainsString($unset->name(), $this->sheet(),
            'যে পণ্যের পুনঃক্রয়ের স্তরই বলা নেই সেটাও তালিকায় এসেছে।');
    }

    // ── ৪ ও ৫ · প্রস্তাবিত পরিমাণ ─────────────────────────────────────

    public function test_the_suggestion_fills_up_to_the_maximum(): void
    {
        /*
         * ⓘ সর্বোচ্চ ১০০, হাতে ৫ — অর্থাৎ ৯৫ কিনলে লক্ষ্যে পৌঁছায়।
         * ⚠️ সংখ্যাটা পর্দায় সত্যিই বসে কি না, সেটাই মাপা হয়।
         */
        $this->stocked('সর্বোচ্চ বলা আছে', onHand: '5', reorder: '20', max: '100');

        $this->assertStringContainsString('95', $this->sheet(),
            'সর্বোচ্চ মজুদ ১০০ আর হাতে ৫ — প্রস্তাব ৯৫ হওয়ার কথা, কিন্তু '
            .'সংখ্যাটা পর্দায় নেই।');
    }

    public function test_nothing_is_invented_when_nothing_was_decided(): void
    {
        /*
         * ⛔ সর্বোচ্চও নেই, একবারের পরিমাণও নেই — তবু স্তর আছে, তাই
         * সারিটা আসে। ⓘ প্রস্তাবটা তখন স্তর পর্যন্ত ভরা (২০ − ৫ = ১৫),
         * ⚠️ আর সেটা বানানো নয়: স্তরটা মালিক নিজেই বসিয়েছেন।
         */
        $this->stocked('কেবল স্তর বলা', onHand: '5', reorder: '20');

        $this->assertStringContainsString('15', $this->sheet(),
            'সর্বোচ্চ বলা না থাকলে প্রস্তাবটা স্তর পর্যন্ত ভরার কথা।');
    }

    public function test_the_suggestion_never_goes_negative(): void
    {
        /*
         * ⛔ হাতে থাকা মাল সর্বোচ্চের **উপরে**, অথচ স্তরের নিচে —
         * অসম্ভব শোনালেও সম্ভব: কেউ সর্বোচ্চকে স্তরের নিচে বসিয়ে
         * দিলে। ⚠️ `GREATEST(..., 0)` ছাড়া প্রস্তাবটা ঋণাত্মক হত, আর
         * কেউ ওটা পড়ে ভাবতেন বিক্রি করতে বলা হচ্ছে।
         */
        $product = $this->stocked('উল্টো বসানো', onHand: '15', reorder: '20', max: '10');

        /*
         * ⚠️ সংখ্যাটা **রিপোর্টের সারি থেকে** পড়া হয়, পাতার লেখা থেকে নয়।
         *
         * ── ⛔ প্রথম চেষ্টাটা পাতায় `-5` খুঁজত, আর সেটা ভুল পরীক্ষা ─────
         * ⓘ পণ্যের কোডটা md5 থেকে তৈরি (`RPL-5a3f…`), তাই হরফগুলোর
         * ভিতরেই `-5` থাকতে পারত — আর তখন পরীক্ষাটা লাল হত এমন কিছুতে
         * যা সংখ্যার সাথে সম্পর্কহীন।
         *
         * ⭐ সারিটা ধরে সংখ্যাটা পড়লে দাবিটা ঠিক যা মাপার কথা তাই মাপে।
         */
        $row = collect(app(ReportEngine::class)
            ->run('inventory.replenishment')->rows)
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($row, 'সারিটাই তালিকায় নেই।');

        $this->assertGreaterThanOrEqual(0, (float) $row['suggested'],
            'প্রস্তাবিত অর্ডারটা ঋণাত্মক এসেছে — কেউ ওটা পড়ে ভাবতেন '
            .'বিক্রি করতে বলা হচ্ছে।');
    }

    // ── ৬ · দরজাগুলো ─────────────────────────────────────────────────

    public function test_every_new_report_opens_for_someone_with_the_key(): void
    {
        /*
         * ⚠️ এই একটা পরীক্ষা চারটা রিপোর্টের **ঠিকানা** মাপে।
         *
         * ⛔ ABOS-এ রিপোর্ট নিবন্ধন করা আর ঠিকানা বসানো দুইটা আলাদা
         * কাজ: স্লাগের তালিকাটা স্পষ্ট, নিয়ম দিয়ে বানানো নয়। ⓘ ঠিক
         * এই কারণেই `expiring` একদিন মেনুতে ছিল আর ক্লিকে ৪০৪ দিত,
         * আর সেই গল্পটা কন্ট্রোলারের মন্তব্যে লেখা আছে।
         */
        $reports = [
            route('inventory.report.show', ['slug' => 'replenishment']),
            route('inventory.report.show', ['slug' => 'reserved']),
            route('purchase.report.show', ['slug' => 'price-history']),
            route('purchase.report.show', ['slug' => 'supplier-performance']),
        ];

        foreach ($reports as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
        }
    }

    public function test_the_buying_sheet_is_closed_without_the_report_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('inventory.report.show', ['slug' => 'replenishment']))
            ->assertForbidden();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function sheet(): string
    {
        return (string) $this->actingAs($this->owner)
            ->get(route('inventory.report.show', ['slug' => 'replenishment']))
            ->assertOk()
            ->getContent();
    }

    private function stocked(
        string $name,
        string $onHand,
        ?string $reorder,
        ?string $max = null,
    ): Product {
        $product = Product::query()->create([
            'code' => 'RPL-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'reorder_level' => $reorder,
            'max_level' => $max,
            'is_active' => true,
        ]);

        if (bccomp($onHand, '0', 4) > 0) {
            app(StockService::class)->move(
                product: $product,
                warehouse: $this->warehouse,
                sourceType: 'test.opening',
                sourceId: $product->id,
                floor: $onHand,
            );
        }

        return $product;
    }
}
