<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Metrics\Metric;
use App\Core\Metrics\MetricRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Dashboard\SalesWidgets;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই প্রশ্ন, একটাই উত্তর।
 *
 * ── যে ভুলটা ঘটেছিল ─────────────────────────────────────────────────
 * "আজকের বিক্রয়" চার জায়গায় হিসাব হত, আর একবার তারা দুইটা আলাদা উত্তর
 * দিয়েছিল: কাউন্টারের ঘরটা খসড়াও গুনত, হোম পর্দা গুনত না। ক্রেতা টাকা
 * আনতে গেছেন, বিলটা কাউন্টারে ঝুলছে — অথচ ক্যাশিয়ারের "আজ কত বিক্রি"
 * ঘরে ওই টাকাটা যোগ হয়ে বসে আছে। দিনশেষে হাতের নগদ কম পড়ত, আর কেউ
 * বুঝত না কেন।
 */
class OneQuestionOneAnswerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /** কাউন্টারে একটা বিক্রয়, নগদে। */
    private function sell(string $amount): array
    {
        return app(PosService::class)->checkout([
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'paid' => $amount,
        ], [
            ['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => $amount],
        ]);
    }

    // ── দুই পর্দা, এক সংখ্যা ────────────────────────────────────────

    /**
     * হোম পর্দা আর কাউন্টার — একই ক্যাশিয়ারের একই দিনে একই সংখ্যা।
     *
     * মালিক নিজেই বিক্রি করলে দুইটা প্রশ্নের উত্তর মিলতে বাধ্য: গোটা
     * কোম্পানির আজ, আর তাঁর নিজের কাউন্টারের আজ। না মিললে কোথাও একটা
     * নিয়ম আলাদা হয়ে গেছে।
     */
    public function test_the_counter_and_the_home_screen_give_the_same_number(): void
    {
        $this->sell('300.00');
        $this->sell('200.00');

        $counter = app(PosService::class)->todaysTotal();
        $company = SalesMetrics::salesToday()->value();

        $this->assertSame(0, bccomp($counter, '500', 4), "কাউন্টার বলছে {$counter}, ৫০০ নয়।");
        $this->assertSame(0, bccomp($counter, $company, 4),
            "একই প্রশ্নে দুইটা উত্তর: কাউন্টার {$counter}, হোম পর্দা {$company}।");
    }

    /**
     * ধরে রাখা বিল কোথাও গোনা হয় না — এটাই আসল ভুলটা ছিল।
     *
     * ── কেন এই পরীক্ষাটা সবচেয়ে জরুরি ───────────────────────────────
     * খসড়া গোনা হলে সংখ্যাটা বড় দেখাত, আর ড্রয়ারে টাকা কম থাকত। যিনি
     * শিফট মেলান তিনি ঘাটতি পেতেন এমন টাকার, যেটা কেউ কোনোদিন দেয়নি।
     */
    public function test_a_parked_bill_is_counted_by_neither(): void
    {
        $this->sell('300.00');

        $before = app(PosService::class)->todaysTotal();

        $result = $this->sell('700.00');
        $result['invoice']->update(['status' => DocumentStatus::DRAFT]);

        $this->assertSame(0, bccomp(app(PosService::class)->todaysTotal(), $before, 4),
            'ধরে রাখা বিলটা কাউন্টারের সংখ্যায় যোগ হয়েছে — ড্রয়ারে ওই টাকা নেই।');

        $this->assertSame(0, bccomp(SalesMetrics::salesToday()->value(), $before, 4),
            'ধরে রাখা বিলটা হোম পর্দার সংখ্যায় যোগ হয়েছে।');
    }

    // ── সংজ্ঞাটা দেখা যায় ───────────────────────────────────────────

    /**
     * সংখ্যাটার পাশে লেখা থাকে সে কী গোনে।
     *
     * যে সংখ্যার সংজ্ঞা লুকানো, দুইজন মানুষ তার দুই অর্থ করে — আর সেটা
     * ধরা পড়ে ছয় মাস পরে, সিদ্ধান্তটা নেওয়া হয়ে যাওয়ার পর।
     */
    public function test_the_home_screen_carries_the_definition_beside_the_figure(): void
    {
        $this->sell('300.00');

        $widget = collect(SalesWidgets::widgets())
            ->firstWhere('label', __('sales::dashboard.sales_today'));

        $this->assertNotNull($widget, 'হোম পর্দায় "আজকের বিক্রয়" ঘরটাই নেই।');

        /*
         * সংজ্ঞাটা `definition`-এ, `hint`-এ নয়।
         *
         * আগে দুইটাই এক ঘরে ছিল, আর তুলনার লেখা ("গত সোমবারের তুলনায়")
         * বসাতে গিয়ে সংজ্ঞাটা নীরবে সরে গিয়েছিল — এই টেস্টটাই সেটা
         * ধরেছে। এখন দুইটা আলাদা ঘর: সংজ্ঞা টুলটিপে, তুলনা পর্দায়।
         */
        $this->assertNotNull($widget->definition, 'সংখ্যাটার সংজ্ঞা নেই।');
        $this->assertStringContainsString(__('core.status.confirmed'), $widget->definition);
        $this->assertStringNotContainsString(__('core.status.draft'), $widget->definition);
        $this->assertSame(Money::format('300', 2), $widget->value);
    }

    /** পর্দাতেও সংজ্ঞাটা সত্যিই ছাপা হয়। */
    public function test_the_definition_reaches_the_rendered_page(): void
    {
        $this->sell('300.00');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('core.metric.by_transaction_date'), false);
    }

    // ── রেজিস্ট্রি ──────────────────────────────────────────────────

    /**
     * প্রতিটা ঘোষিত সংখ্যা সত্যিই গোনা যায়।
     *
     * একটা ভাঙা সংজ্ঞা এখানে ধরা পড়ে, ব্যবহারকারীর পর্দায় নয়।
     */
    public function test_every_declared_metric_can_actually_be_counted(): void
    {
        $metrics = app(MetricRegistry::class)->all();

        $this->assertNotSame([], $metrics, 'কোনো মডিউলই কোনো সংখ্যার সংজ্ঞা ঘোষণা করেনি।');

        foreach ($metrics as $key => $metric) {
            $this->assertSame($key, $metric->key);
            $this->assertIsNumeric($metric->value(), "মেট্রিক '{$key}' সংখ্যা ফেরায়নি।");
            $this->assertNotSame('', trim($metric->definition()));
        }
    }

    /** চাবি ধরে চাওয়া যায় — নাহলে রেজিস্ট্রিটা কেবল একটা তালিকা। */
    public function test_a_metric_can_be_asked_for_by_key(): void
    {
        $this->sell('300.00');

        $metric = app(MetricRegistry::class)->get('sales.today');

        $this->assertInstanceOf(Metric::class, $metric);
        $this->assertSame(0, bccomp($metric->value(), '300', 4));
    }

    /**
     * অজানা চাবি চাইলে ব্যতিক্রম, নীরবে শূন্য নয়।
     *
     * শূন্য একটা বিশ্বাসযোগ্য উত্তর — "আজ কিছু বিক্রি হয়নি" আর "সংখ্যাটা
     * হারিয়ে গেছে" পর্দায় দেখতে একই রকম।
     */
    public function test_an_unknown_key_is_an_error_not_a_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MetricRegistry::class)->get('sales.yesterday');
    }
}
