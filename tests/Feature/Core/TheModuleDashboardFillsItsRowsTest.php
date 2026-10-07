<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\Widget;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
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
 * নতুন মডিউল ড্যাশবোর্ডের সারি — ৬ অক্টোবর ২০২৬-এর ১০৮০p যাচাইয়ে ধরা তিনটা কথা, প্রতিটা মডিউলে:
 *
 * ১. চার্টের সারি ভরা — তিন ঘরের সারিতে প্রতিটা সারির ঘরের যোগ ৩; একা একটা বাক্স আর পাশে খালি জায়গা নয়।
 * ২. সূচকের ঘর মালিকের নিয়মে (১ অক্টোবর ২০২৬): ছয়টা পর্যন্ত এক লাইনে, বেশি হলে যত কম লাইন সম্ভব, লাইনে পাঁচটার বেশি নয়।
 * ৩. "ব্যতিক্রম কেন্দ্র"-এ কেবল করণীয় (`todo`) — আজ/মাস/বছর/মূল সূচকের সংখ্যা উপরের ঘরেই, দ্বিতীয়বার নয়
 *    (বিক্রয়ের পাতায় "আজকের বিক্রয়" তিনবার দেখা গিয়েছিল)।
 */
final class TheModuleDashboardFillsItsRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_module_page_fills_its_rows_and_shows_each_figure_once(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ একটা বিক্রি — যাতে দিনের আর মাসের দল শূন্য না হয় (শূন্য হলে কেন্দ্রে এমনিতেই আসত না, দাবি অন্ধ থাকত)
        app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );

        $looked = ['panels' => 0, 'stats' => 0, 'notTodo' => 0];
        $wrong = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            if ($module->dashboard === null || ! app(SettingsService::class)->get($module->code.'.enabled', true)) {
                continue;
            }

            $response = $this->get(route('module.dashboard', ['module' => $module->code]));
            if ($response->getStatusCode() !== 200) {
                continue;
            }
            $page = $this->xpath((string) $response->getContent());

            // ── ১. চার্টের সারি ভরা ──
            $row = 0;
            foreach ($page->query('//*[@data-panel]') as $panel) {
                $looked['panels']++;
                $span = (int) $panel->getAttribute('data-span');
                if ($row + $span > 3) {
                    $wrong[] = "{$module->code}: চার্টের সারি ভরার আগেই পরের বাক্স এল ({$row} + {$span})";
                }
                $row = ($row + $span) % 3;
            }
            if ($row !== 0) {
                $wrong[] = "{$module->code}: শেষ চার্টের সারিতে ফাঁকা ({$row}/৩)";
            }

            // ── ২. সূচকের ঘর ──
            $grid = $page->query('//*[@data-stat-grid]')->item(0);
            if ($grid !== null) {
                $count = $page->query('.//*[@data-stat]', $grid)->length;
                $want = $count <= 6 ? max(1, $count) : (int) ceil($count / ceil($count / 5));
                $looked['stats'] += $count;
                if ((int) $grid->getAttribute('data-stat-cols') !== $want) {
                    $wrong[] = "{$module->code}: {$count}টা সূচক {$grid->getAttribute('data-stat-cols')} ঘরে, হওয়ার কথা {$want}";
                }
            }

            // ── ৩. কেন্দ্রে কেবল করণীয় ──
            $inCentre = array_map(fn ($n) => trim($n->textContent),
                iterator_to_array($page->query('//*[@data-dashboard-exceptions]//span[contains(@class, "min-w-0 text-sm font-semibold")]')));
            foreach ($module->widgets as $provider) {
                foreach ($provider::widgets() as $widget) {
                    /** @var Widget $widget */
                    if ($widget->group === 'todo') {
                        continue;
                    }
                    $looked['notTodo']++;
                    if (in_array($widget->label, $inCentre, true)) {
                        $wrong[] = "{$module->code}: \"{$widget->label}\" ({$widget->group}) ব্যতিক্রম কেন্দ্রে — এটা করণীয় নয়";
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $looked['panels'], 'চার্ট প্রায় দেখাই হয়নি — দাবি অন্ধ।');
        $this->assertGreaterThan(30, $looked['stats'], 'সূচক প্রায় দেখাই হয়নি — দাবি অন্ধ।');
        $this->assertGreaterThan(3, $looked['notTodo'], 'করণীয়-নয় এমন উইজেট নেই — তৃতীয় দাবি অন্ধ।');
        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
