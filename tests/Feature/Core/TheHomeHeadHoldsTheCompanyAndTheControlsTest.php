<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হোমের মাথা আর চার্টের লেখা — মালিক, ৫ অক্টোবর ২০২৬:
 * *"company branch selector dewar kotha"* · *"ei gulo ড্যাশবোর্ড er niche niye aso"* ·
 * *"amount gulo color pipe er vitore dile full buzazabe"* · *"kobe theke kobe porjonto eta likhbe"*।
 *
 * ⓘ দাবি: শিরোনামের নিচে কোম্পানি-শাখা বাছাই (টপবারের একই ফর্ম, শাখা বদলের দরজাসহ); ফিল্টার · লেআউট · সময়
 * মাথার কোণে নয়, নিচের সারিতে; হোমের সময়-নির্ভর চার্টে তারিখের লেখা; লম্বা দণ্ডের মান দণ্ডের ভেতরে খাড়া করে।
 */
final class TheHomeHeadHoldsTheCompanyAndTheControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_head_holds_the_switcher_and_the_controls_and_the_charts_say_their_dates(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ বিক্রির দণ্ড লম্বা হোক — ভেতরে মান বসার মতো
        app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );

        foreach ([false, true] as $v2) {
            config(['abos.dashboards_v2' => $v2]);
            $page = $this->xpath($this->get(route('dashboard'))->assertOk()->getContent());

            // ── টাকার বাক্স শিরোনামের সাথে একই মাথার সারিতে (মালিক, ৬ অক্টোবর ২০২৬: *"সোজা উপরে বসবে"*) ──
            $head = $page->query('//section[@data-command-head]');
            $this->assertSame(1, $head->length, '⛔ হোমের মাথার সারি নেই।');
            $this->assertSame(1, $page->query('.//*[@data-home-title]', $head->item(0))->length, '⛔ শিরোনাম মাথার সারিতে নেই।');
            $this->assertSame(1, $page->query('.//*[@data-money-position]', $head->item(0))->length, '⛔ টাকার বাক্স শিরোনামের সারিতে নেই।');
            $this->assertStringContainsString('align-items: flex-start', $head->item(0)->getAttribute('style'), '⛔ বাক্সটা উপরে সাঁটা নয়।');
            $this->assertSame(1, $page->query('//h1')->length, '⛔ পাতায় দুইটা শিরোনাম — পুরনো শিরোনামের সারি রয়ে গেছে।');
            // ⓘ শিরোনাম আর কোম্পানির সারি একদম কাছাকাছি (মালিক, ৬ অক্টোবর ২০২৬) — মাঝে সোনালি রেখার ফাঁকা নয়; ১০৮০-তে মাপা ১১px
            $this->assertSame(0, $page->query('.//*[@data-gold-hairline]', $head->item(0))->length, '⛔ শিরোনামের নিচে আবার ফাঁকার রেখা।');

            // ── কোম্পানি-শাখা বাছাই মাথার সারিতে, শাখা বদলের দরজাসহ ──
            $company = $page->query('//section[@data-command-head]//*[@data-home-company]');
            $this->assertSame(1, $company->length, '⛔ মাথায় কোম্পানি-শাখা বাছাই নেই।');
            $this->assertGreaterThan(0, $page->query('.//form[contains(@action, "/branch/switch")]', $company->item(0))->length,
                '⛔ বাছাইয়ে শাখা বদলের দরজা নেই।');

            // ── তিন বোতাম মাথার নিচের সারিতে, শিরোনামের কোণে নয় ──
            foreach (['data-home-filter', 'data-layout-menu', 'data-period-menu'] as $mark) {
                $this->assertSame(1, $page->query("//section[@data-command-head]//*[@data-home-controls]//*[@{$mark}]")->length,
                    "⛔ {$mark} মাথার নিচের সারিতে নেই।");
                $this->assertSame(1, $page->query("//*[@{$mark}]")->length, "⛔ {$mark} পাতায় একবারের বেশি।");
            }

            // ── হোমের চার্টে তারিখ — অন্তত বিক্রয়ের ──
            $salesRange = $page->query('//*[@data-picture="sales"]//*[@data-chart-range]');
            $this->assertSame(1, $salesRange->length, '⛔ বিক্রয়ের চার্টে "কবে থেকে কবে" নেই।');
            $this->assertMatchesRegularExpression('/২০২৬|2026/u', $salesRange->item(0)->textContent);

            // ── লম্বা দণ্ডের মান দণ্ডের ভেতরে, খাড়া ──
            $inside = $page->query('//*[@data-picture="sales"]//span[@data-bar-value and contains(@style, "writing-mode: vertical-rl")]');
            $this->assertGreaterThan(0, $inside->length, '⛔ বিক্রির লম্বা দণ্ডের মান দণ্ডের ভেতরে নয়।');
            $this->assertNotSame('', trim($inside->item(0)->textContent));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
