<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা সংখ্যা কোনো দিক নয়।
 *
 * ── হোম পর্দার দুইটা অনুপস্থিত অংশ ──────────────────────────────────
 * নমুনায় ছিল, পর্দায় ছিল না:
 *
 *   • **কালপর্ব** — আজ · এই মাস · এই বছর। আগে আজ ও এই মাস একসাথে
 *     দেখানো হত, একটার নিচে আরেকটা, আর "এই বছর" বলে কিছুই ছিল না।
 *   • **রেখা** — "আজ ৪,০৫০" একটা বিন্দু, আর একটা বিন্দু দিয়ে কোনো
 *     দিক বোঝা যায় না।
 */
class OneNumberIsNotADirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function sell(string $rate, ?string $on = null): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $on ?? now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $rate]],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
    }

    // ── কালপর্ব ─────────────────────────────────────────────────────

    /** তিনটা তাবই পর্দায় আছে। */
    public function test_the_three_periods_are_offered(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('core.dashboard.today'))
            ->assertSee(__('core.dashboard.this_month'))
            ->assertSee(__('core.dashboard.this_year'));
    }

    /**
     * একটা সময়ে একটাই দল।
     *
     * আগে আজ ও এই মাস দুইটাই একসাথে দেখানো হত, আর পর্দার উপরের
     * অর্ধেকটা আটটা কার্ডে ভরে যেত।
     */
    public function test_only_the_chosen_period_is_shown(): void
    {
        $this->sell('1000');

        $today = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(__('sales::dashboard.sales_today'), $today);
        $this->assertStringNotContainsString(__('sales::dashboard.sales_this_year'), $today);

        $year = $this->get(route('dashboard', ['period' => 'year']))->assertOk()->getContent();

        $this->assertStringContainsString(__('sales::dashboard.sales_this_year'), $year);
        $this->assertStringNotContainsString(__('sales::dashboard.sales_today'), $year);
    }

    /**
     * বাছাটা ঠিকানায় থাকে।
     *
     * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────────
     * Alpine দিয়ে লুকিয়ে-দেখিয়ে করলে "এই বছরের পর্দাটা দেখো" বলে
     * কাউকে লিংক পাঠানো যেত না, আর রিফ্রেশ করলেই আজকের পর্দায় ফিরে
     * আসত।
     */
    public function test_the_choice_survives_a_link(): void
    {
        $this->get(route('dashboard', ['period' => 'month']))
            ->assertOk()
            ->assertSee(__('sales::dashboard.sales_this_month'));
    }

    /** আজে-বাজে কালপর্ব দিলে আজকেই দেখায়, ভাঙে না। */
    public function test_a_nonsense_period_falls_back_to_today(): void
    {
        $this->get(route('dashboard', ['period' => 'drop-table']))
            ->assertOk()
            ->assertSee(__('sales::dashboard.sales_today'));
    }

    /** বছরের সংখ্যাগুলো সত্যিই আছে — তাব থাকলেই যথেষ্ট নয়। */
    public function test_the_year_has_real_figures_behind_it(): void
    {
        $this->sell('1000');

        $groups = app(DashboardRegistry::class)->forUser($this->owner);

        $this->assertNotEmpty($groups['year'] ?? [],
            'তাবটা আছে, পেছনে কোনো সংখ্যা নেই — খালি একটা পর্দা।');
    }

    // ── রেখা ────────────────────────────────────────────────────────

    /**
     * সাত দিনের রেখাটা সত্যিই আঁকা হয়।
     *
     * সাত দিনে সপ্তাহের ছকটা একবার পুরো দেখা যায় — শুক্রবারে কম,
     * বৃহস্পতিবারে বেশি।
     */
    public function test_a_week_of_movement_is_drawn(): void
    {
        $this->sell('1000', now()->subDays(3)->toDateString());
        $this->sell('4000');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<polyline', false);
    }

    /**
     * সব সমান হলে কিছুই আঁকা হয় না।
     *
     * সমতল একটা রেখা দেখতে "স্থির ব্যবসা"-র মতো, অথচ সত্যিটা "কিছুই
     * ঘটেনি"। মিথ্যা ছবি না আঁকাই ভালো।
     */
    public function test_a_flat_week_draws_nothing(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('<polyline', false);
    }

    /** সংখ্যাগুলো সত্যিই সাত দিনের — কম বা বেশি নয়। */
    public function test_the_series_is_seven_days_long(): void
    {
        $this->sell('1000');

        $today = collect(app(DashboardRegistry::class)->forUser($this->owner)['today'] ?? []);

        $withSpark = $today->first(fn (Widget $w) => $w->spark !== []);

        $this->assertNotNull($withSpark, 'কোনো কার্ডেই সাত দিনের রেখা নেই।');
        $this->assertCount(7, $withSpark->spark);
    }

    /**
     * যেদিন কিছু হয়নি সেদিনও একটা বিন্দু।
     *
     * বাদ দিলে সাত দিনের ছয়টা বিন্দু সমান দূরত্বে বসত আর বন্ধের দিনটা
     * অদৃশ্য হয়ে যেত — অথচ ওটাই ছকের অর্ধেক ব্যাখ্যা।
     */
    public function test_a_quiet_day_is_still_a_point(): void
    {
        $this->sell('1000');

        $today = collect(app(DashboardRegistry::class)->forUser($this->owner)['today'] ?? []);
        $withSpark = $today->first(fn (Widget $w) => $w->spark !== []);

        $zeros = array_filter($withSpark->spark, fn ($v) => (float) $v === 0.0);

        $this->assertNotEmpty($zeros, 'চুপচাপ দিনগুলো সিরিজ থেকে বাদ পড়েছে।');
    }
}
