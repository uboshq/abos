<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * গাড়ির চালক কাগজে প্রতিটা দর পড়ে ফেলতে পারতেন।
 *
 * ── ⓘ নিয়মটা নতুন নয়, [[SalesPrintController]]-এর মাথায় লেখা ──────────
 * *"গেটপাস আর ডেলিভারি অর্ডারে দাম থাকে না। থাকলে গাড়ির চালক থেকে
 * দারোয়ান পর্যন্ত সবাই জেনে যেতেন কোন গ্রাহক কী দরে কেনেন, অথচ কারও
 * ওটা জানার দরকার নেই — আর ওই তথ্যটা ফাঁস হলে দর নিয়ে দরকষাকষি শুরু হয়।"*
 *
 * ── ⛔ আর পাহারাটা ঐ কথাটা মাপত না ───────────────────────────────────
 * `SalesPrintTest::test_the_gatepass_and_delivery_order_carry_no_prices()`
 * দেখে **পতাকাটা নামানো আছে কি না** (`showMoney === false`) আর মোটের ঘর
 * খালি কি না। ⚠️ দুইটাই ডকুমেন্টের **অভিপ্রায়**, কাগজ নয়।
 *
 * ⛔ ২২ সেপ্টেম্বর ২০২৬-এ কলামগুলো সুইচের আওতায় এসেছে
 * ([[PrintProfile::columnsFor()]])। ⓘ গেটপাস আর চালান **একই প্রোফাইল**
 * ব্যবহার করে (`target: 'challan'`), অর্থাৎ চালানের কলাম-তালিকায় দর ও
 * টাকা বসানো — আর গেটপাসের দাম না-ছাপা এখন **একটা মাত্র শর্তের** উপর
 * দাঁড়িয়ে: `columnsFor()` ভিতরে `! $showMoney` দেখে দুইটা কলাম ফেলে দেয়।
 *
 * ⚠️ কেউ ঐ শর্তটা সরালে পুরনো পাহারাটা **সবুজই থাকত** — পতাকা তো নামানোই
 * আছে — আর দর ছাপা হয়ে চালকের হাতে চলে যেত। ⓘ ফাঁসটা নীরব: পাতা ২০০,
 * দেখতেও ঠিক, কেবল একটা কলাম বেশি।
 *
 * ── ⭐ তাই এই ফাইলটা কাগজটাই পড়ে ─────────────────────────────────────
 * ডকুমেন্টের পতাকা নয়, **রেন্ডার করা HTML**: দরের শিরোনাম আছে কি না, আর
 * হাতে বসানো একটা চেনা দর পাতায় উঠল কি না।
 */
final class TheDriverCouldReadEveryPriceOnThePaperTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    // ── আসল দাবি ──────────────────────────────────────────────────────

    /**
     * ⛔ দাম-ছাড়া কাগজে দরের কলামই আঁকা হয় না।
     *
     * ⓘ প্রশ্নটা কাগজের, অভিপ্রায়ের নয়: শিরোনামের সারিতে *"দর"* বা
     * *"টাকা"* লেখা উঠল কি না।
     */
    public function test_a_no_money_paper_draws_no_rate_or_amount_column(): void
    {
        $cols = $this->columnsOnAChallanPaper(showMoney: false);

        $this->assertNotContains('rate', $cols,
            'দাম ছাড়া কাগজে দরের কলাম আঁকা হচ্ছে — চালক প্রতিটা দর পড়ে ফেলবেন।');

        $this->assertNotContains('amount', $cols,
            'দাম ছাড়া কাগজে টাকার কলাম আঁকা হচ্ছে।');
    }

    /**
     * ⛔ আর এটাই দ্বিতীয় অর্ধেক — নাহলে উপরেরটা একটা চিরকাল-খালি
     * তালিকাতেও পাস করত।
     *
     * ⚠️ চালানের কাগজে দর থাকে (গ্রাহক ওটা দেখেন), গেটপাসে থাকে না।
     * ⓘ দুইটাই **একই প্রোফাইল** থেকে আসে, তাই পার্থক্যটা সত্যিই
     * `showMoney`-র উপর দাঁড়িয়ে আছে কি না — সেটাই মাপা।
     */
    public function test_the_same_paper_with_money_does_draw_them(): void
    {
        $cols = $this->columnsOnAChallanPaper(showMoney: true);

        $this->assertContains('rate', $cols,
            'টাকাসহ কাগজেও দরের কলাম নেই — তাহলে উপরের পরীক্ষাটা কিছুই প্রমাণ করে না।');

        $this->assertContains('amount', $cols);
    }

    // ── আর কাগজটা সত্যিই আঁকা হলে ─────────────────────────────────────

    /**
     * ⭐ রেন্ডার করা HTML-এ চেনা দরটা নেই।
     *
     * ── ⚠️ কেন কলামের তালিকা যথেষ্ট নয় ─────────────────────────────
     * উপরের দুইটা [[PrintProfile]]-কে জিজ্ঞেস করে, আর সে ঠিক উত্তরই দেয়।
     * ⛔ কিন্তু ব্লেডটা যদি কোনোদিন তালিকাটা উপেক্ষা করে নিজে একটা ঘর
     * এঁকে বসে, ঐ দুইটা সবুজই থাকত। ⓘ তাই এখানে **পাতাটা** পড়া হয়।
     *
     * ⚠️ দরটা হাতে বসানো একটা চেনা সংখ্যা — পরিমাণ বা নম্বরের সাথে
     * মিশে যাওয়ার সুযোগ নেই।
     */
    public function test_the_rendered_gatepass_does_not_print_the_rate(): void
    {
        $challan = $this->aChallanWithARateOf('777.77');

        $html = $this->paperFor(route('sales.print.gatepass', $challan));

        $this->assertStringNotContainsString('777.77', $html,
            'গেটপাসের কাগজে দরটা ছাপা হয়েছে — চালক ও দারোয়ান দর জেনে গেলেন।');
    }

    /** ⓘ আর চালানের কাগজে ওটা থাকে — নাহলে উপরেরটা শূন্যের উপর দাঁড়াত। */
    public function test_the_rendered_challan_does_print_it(): void
    {
        $challan = $this->aChallanWithARateOf('777.77');

        $html = $this->paperFor(route('sales.print.challan', $challan));

        $this->assertStringContainsString('777.77', $html,
            'চালানের কাগজেও দর নেই — তাহলে উপরের পরীক্ষাটা কেবল একটা খালি পাতা মাপছে।');
    }

    // ── ⭐ মালিকের সুইচ — ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────

    /**
     * ⭐ সুইচ বন্ধ করলে চালানের কাগজ থেকে দাম উধাও।
     *
     * ⓘ মালিকের কথা: *"দাম থাকবে না থাকবে, তার সুইচ থাকবে"*।
     * ⚠️ একটা টিক, আর তাতে চারটাই — দর, টাকা, মোট, কথায় লেখা অঙ্ক।
     */
    public function test_the_owner_can_switch_the_prices_off(): void
    {
        $challan = $this->aChallanWithARateOf('777.77');

        $this->assertStringContainsString('777.77', $this->paperFor(
            route('sales.print.challan', $challan)
        ), 'সুইচ চালু অবস্থাতেই দর নেই — তাহলে নিচের মাপটার মানে থাকে না।');

        $this->switchPricesOff();

        $html = $this->paperFor(route('sales.print.challan', $challan));

        $this->assertStringNotContainsString('777.77', $html,
            'সুইচ বন্ধ করার পরেও চালানে দর ছাপা হচ্ছে — সুইচটা কিছুই করছে না।');
    }

    /**
     * ⛔ আর সুইচ বন্ধ হলে **মোট**ও যায়।
     *
     * ⚠️ এটাই আসল কারণ যে সুইচটা একটা, চারটা নয়। ⓘ কেবল কলাম নামালে
     * কাগজে একটাও দর থাকত না, অথচ নিচে *"সর্বমোট ১,৫৫৫"* বসে থাকত —
     * সংখ্যাটা রইল, উৎসটা গেল, আর পাঠক বুঝতেই পারতেন না ওটা কীসের।
     */
    public function test_switching_prices_off_takes_the_total_with_it(): void
    {
        $challan = $this->aChallanWithARateOf('777.77');

        $this->switchPricesOff();

        $html = $this->paperFor(route('sales.print.challan', $challan));

        $this->assertStringNotContainsString('1,555.54', $html,
            'দর গেছে, অথচ মোটটা রয়ে গেছে — সংখ্যাটার উৎস কাগজে নেই।');
    }

    /**
     * ⛔ আর সুইচ **চালু** করেও গেটপাসে দর আনা যায় না।
     *
     * ⚠️ দুইটার ভূমিকা আলাদা: `showMoney` **নিয়ম** (গেটপাস কোডেই না
     * বলে), আর সুইচটা **পছন্দ**। ⓘ শর্তটা **এবং**, তাই পছন্দ কখনো
     * নিয়মকে ছাপিয়ে যেতে পারে না।
     *
     * ⛔ উল্টোটা হলে মালিক নিজের অজান্তে চালকের কাগজে দর এনে ফেলতেন —
     * তিনি ভাবতেন চালানের সুইচ দিচ্ছেন।
     */
    public function test_the_switch_cannot_put_prices_on_a_gatepass(): void
    {
        $challan = $this->aChallanWithARateOf('777.77');

        // ⓘ সুইচটা চালুই আছে (ডিফল্ট), তবু গেটপাসে দর নেই।
        $this->assertStringNotContainsString('777.77', $this->paperFor(
            route('sales.print.gatepass', $challan)
        ), 'সুইচ চালু বলে গেটপাসেও দর উঠেছে — পছন্দ নিয়মকে ছাপিয়ে গেছে।');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** ⓘ চালানের কাগজে দামের সুইচটা নামিয়ে দেওয়া। */
    private function switchPricesOff(): void
    {
        $parts = array_values(array_diff(PrintProfile::PARTS, ['prices']));

        app(SettingsService::class)->set('print.challan.parts', $parts);
    }

    /**
     * ⓘ চালানের প্রোফাইল A4-তে যে কলামগুলো আঁকবে।
     *
     * @return list<string>
     */
    private function columnsOnAChallanPaper(bool $showMoney): array
    {
        return PrintProfile::for('challan', app(SettingsService::class))
            ->columnsFor(PaperSize::of('a4'), $showMoney, hasFree: false);
    }

    /**
     * ⓘ চালানটা নিজে বানানো — ডেমোতে একটাও নেই।
     *
     * ⚠️ প্রথম খসড়ায় ডেমো থেকে একটা তুলে আনার চেষ্টা করেছিলাম, আর
     * চারটাই লাল হয়েছে *"No query results"* নিয়ে। ⛔ ডেটা খুঁজে নেওয়া
     * আর ডেটা বানানো এক নয়: খুঁজে নিলে পরীক্ষাটা ডেমোর গড়নের উপর
     * দাঁড়ায়, আর সেটা বদলালে পরীক্ষাটা এমন কারণে লাল হয় যার সাথে
     * বাগের কোনো সম্পর্ক নেই।
     */
    private function aChallanWithARateOf(string $rate): DeliveryChallan
    {
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $challan = DeliveryChallan::query()->create([
            'branch_id' => $here->defaultBranch()?->id,
            'document_no' => 'CHL-RATE-0001',
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'vehicle_no' => 'ঢাকা মেট্রো-ট ১১-১১১১',
            'driver_name' => 'চালক',
            'total' => '1555.5400',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        DeliveryChallanLine::query()->create([
            'delivery_challan_id' => $challan->id,
            'product_id' => Product::query()->firstOrFail()->id,
            'line_no' => 1,
            'delivered_qty' => '2.0000',

            // ⭐ চেনা দরটা — পরিমাণ বা নম্বরের সাথে মেশার সুযোগ নেই।
            'rate' => $rate,
            'amount' => '1555.5400',
        ]);

        return $challan->fresh('lines');
    }

    /**
     * ⭐ কাগজটা সত্যিই আঁকা — PDF-এর ভিতরে না ঢুকে।
     *
     * ── ⚠️ কেন composer-এর ভিতরে রেন্ডার করা যায় না ──────────────────
     * ⛔ composer-এর ভিতরে একই ভিউ আবার রেন্ডার করলে সে নিজেই নিজেকে
     * ডাকে — অসীম পুনরাবৃত্তি, আর ৫১২MB শেষ। ⓘ এই ফাঁদে একবার পড়া
     * হয়েছে (২২ সেপ্টেম্বর ২০২৬)।
     *
     * ⭐ তাই ভিতরে কেবল **ডেটাটা ধরে রাখা**, আর রেন্ডার অনুরোধের পরে।
     */
    private function paperFor(string $url): string
    {
        $data = null;

        View::composer('print.document', function ($view) use (&$data) {
            $data ??= $view->getData();
        });

        $this->actingAs($this->user)->get($url)->assertOk();

        $this->assertNotNull($data, "{$url} কোনো কাগজ পাঠায়নি।");

        return (string) view('print.document', $data)->render();
    }
}
