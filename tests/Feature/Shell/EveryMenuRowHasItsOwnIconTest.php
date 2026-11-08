<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Navigation\LucideIcons;
use App\Core\Navigation\MenuLook;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রঙিন মডিউল-মেনু — মালিক, ৮ নভেম্বর ২০২৬: "গ্রুপ ওয়াইজ বিভিন্ন কালার … পাশে ছোট ছোট আইকন" · "একই আইকন দুইটাই বসবে না,
 * প্রত্যেকটার জন্য ইউনিক আইকন দিতে হবে" · "লালের পাশে মেরুন দিলে লালই দেখা যায় … পাশাপাশি একই রকমের কালার দিবা না"
 * (নমুনা "রঙিন মডিউল মেনু", "ok")।
 *
 * দাবি:
 *   ১. তালিকায় এক মডিউলে কোনো আইকন দুবার নেই, প্রতিটা আইকন সত্যিই আছে, প্রতিটা দলের নাম দুই ভাষায়
 *   ২. মালিকের মেনুর প্রতিটা সারির দল আর আইকন আছে — কোনো সারি রং ছাড়া নয়
 *   ৩. পাশাপাশি দুই দলের রং রং-চাকায় অন্তত ৬০° দূরে (লাল আর মেরুন পাশাপাশি নয়)
 *   ৪. যে রং বসতে পারে তার প্রত্যেকটার CSS নিয়ম আর টোকেন আছে
 *   ৫. আসল পাতার মেনুতে দলের মাথা, রঙিন সারি আর নিজের আইকন আঁকা হয়
 */
final class EveryMenuRowHasItsOwnIconTest extends TestCase
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

    public function test_the_catalog_gives_each_row_its_own_real_icon_and_each_group_a_name(): void
    {
        $data = MenuLook::data();
        $this->assertNotEmpty($data['modules'], '⛔ রঙিন মেনুর তালিকা ফাঁকা');

        foreach ($data['modules'] as $module => $rows) {
            $icons = array_map(fn (array $entry) => $entry[1], array_values($rows));
            $twice = array_unique(array_diff_assoc($icons, array_unique($icons)));
            $this->assertSame([], array_values($twice), "⛔ {$module}: একই আইকন একাধিক সারিতে — মালিক: \"প্রত্যেকটার জন্য ইউনিক আইকন\"");

            foreach ($rows as $key => [$kind, $icon]) {
                $this->assertTrue(LucideIcons::has($icon), "⛔ {$module} {$key}: আইকন \"{$icon}\" নেই — সারিটা চিহ্ন ছাড়া আঁকা হবে");
                $this->assertArrayHasKey($kind, $data['kinds'], "⛔ {$module} {$key}: দল \"{$kind}\" তালিকায় নেই");
            }
        }

        foreach (array_keys($data['kinds']) as $kind) {
            foreach (['bn', 'en'] as $locale) {
                $name = trans('core.menu_kind.'.$kind, [], $locale);
                $this->assertNotSame('core.menu_kind.'.$kind, $name, "⛔ দল \"{$kind}\"-এর {$locale} নাম নেই — মেনুতে কাঁচা চাবি দেখাবে");
            }
        }
    }

    public function test_every_row_of_the_owners_menu_is_coloured_and_neighbour_groups_stand_apart(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner);
        $this->assertNotEmpty($menu);
        $rows = 0;

        foreach ($menu as $module) {
            $panel = collect($module['groups'])->flatten(1)->values()->all();
            $looks = MenuLook::forRows((string) $module['code'], $panel);
            $previous = null;
            $seen = [];

            foreach ($looks as $i => $look) {
                $rows++;
                $label = $module['code'].' '.($panel[$i]['key'] ?? '?');
                $this->assertNotNull($look['kind'], "⛔ {$label}: মেনুর সারি দল আর রং ছাড়া — রঙিন মেনুর তালিকায় বসানো নেই");
                $this->assertNotNull($look['icon'], "⛔ {$label}: সারির নিজের আইকন নেই");
                $this->assertNotContains($look['icon'], $seen, "⛔ {$label}: আইকনটা এই মেনুতে আগেই বসেছে");
                $seen[] = $look['icon'];

                if ($look['starts']) {
                    // ⓘ ৬০ সংখ্যাটা এখানে লেখা, ক্লাসের ধ্রুবক নয় — ধ্রুবক নামলে দাবিও নামত
                    if ($previous !== null) {
                        $this->assertGreaterThanOrEqual(60.0, MenuLook::apart((string) $look['colour'], $previous),
                            "⛔ {$label}: দল \"{$look['kind']}\"-এর রং {$look['colour']} আগের দলের {$previous}-এর খুব কাছে — মালিক: \"পাশাপাশি একই রকমের কালার দিবা না\"");
                    }
                    $previous = (string) $look['colour'];
                }
            }
        }

        $this->assertGreaterThan(100, $rows, '⛔ মালিকের মেনু প্রায় ফাঁকা — দাবিটা কিছুই দেখল না');
    }

    public function test_red_and_maroon_count_as_the_same_colour(): void
    {
        // ⓘ মালিকের নিজের উদাহরণ — লাল আর মেরুন; লাল আর নীল দূরে; ধূসর আর রঙিন দূরে
        $this->assertLessThan(60.0, MenuLook::apart('#dc2626', '#9f1239'), '⛔ লাল আর মেরুন "দূরে" ধরা হল');
        $this->assertGreaterThanOrEqual(60.0, MenuLook::apart('#dc2626', '#2563eb'));
        $this->assertGreaterThanOrEqual(60.0, MenuLook::apart(MenuLook::NEUTRAL, '#dc2626'));
    }

    public function test_every_colour_that_can_land_has_its_css_rule_and_token(): void
    {
        $data = MenuLook::data();
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $colours = array_unique(array_merge(MenuLook::PALETTE, [MenuLook::NEUTRAL], array_map(fn (array $k) => $k[1], array_values($data['kinds']))));

        foreach ($colours as $colour) {
            $hex = ltrim($colour, '#');
            $this->assertStringContainsString('[data-mc="'.$hex.'"] { --menu-g: var(--color-menu-'.$hex.'); }', $css, "⛔ রং {$colour}-এর CSS নিয়ম নেই — সারিটা রং ছাড়া আঁকা হবে");
            $this->assertMatchesRegularExpression('/--color-menu-'.$hex.':/', $tokens, "⛔ রং {$colour}-এর টোকেন নেই");
        }
    }

    public function test_a_real_page_draws_group_heads_coloured_rows_and_their_icons(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))->assertOk()->getContent();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $this->assertGreaterThanOrEqual(2, $xpath->query('//p[contains(@class, "menu-kind-head")][@data-mc]')->length, '⛔ মেনুতে দলের রঙিন মাথা নেই');
        $rows = $xpath->query('//*[contains(concat(" ", @class, " "), " menu-coloured ")]');
        $this->assertGreaterThan(5, $rows->length, '⛔ মেনুর সারি রঙিন নয়');

        $icons = [];
        foreach ($rows as $row) {
            $this->assertNotSame('', (string) $row->getAttribute('data-mc'), '⛔ রঙিন সারির রং নেই');
            $icon = $xpath->query('.//svg[@data-licon]', $row)->item(0)?->getAttribute('data-licon');
            $this->assertNotNull($icon, '⛔ রঙিন সারির আইকন আঁকা হয়নি');
            $icons[] = $icon;
        }
        $this->assertSame(count($icons), count(array_unique($icons)), '⛔ পাতার মেনুতে একই আইকন দুবার');
    }

    /**
     * ⭐ মেনু লুকালে উপরের বার — মালিক, ৯ নভেম্বর ২০২৬ (নমুনা "মেনু লুকালে উপরে দলের বার": "ok — eta korai ache sudu color kora
     * baki"): প্রতিটা ঘর তার দলের রঙে আর চিহ্নে, পাশাপাশি দুই আলাদা দল অন্তত ৬০° দূরে, ড্রপডাউনের প্রতিটা সারি নিজের চিহ্নে।
     */
    public function test_the_bar_under_the_top_bar_wears_the_group_colours_too(): void
    {
        foreach ([route('accounts.voucher.create', ['type' => 'receipt']), route('sales.order.index')] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            $cells = $xpath->query('//*[@data-module-bar]//nav/*[@data-mc]');
            $this->assertGreaterThanOrEqual(5, $cells->length, "⛔ {$url}: উপরের বারের ঘর রঙিন নয়");

            $previous = null;
            foreach ($cells as $cell) {
                $kind = (string) $cell->getAttribute('data-bar-kind');
                $colour = '#'.$cell->getAttribute('data-mc');
                $this->assertNotSame('', $kind, "⛔ {$url}: রঙিন ঘরের দল নেই");
                $this->assertNotNull($xpath->query('.//svg[@data-licon]', $cell)->item(0), "⛔ {$url}: ঘরে দলের চিহ্ন নেই");

                if ($previous !== null && $previous[0] !== $kind) {
                    $this->assertGreaterThanOrEqual(60.0, MenuLook::apart($colour, $previous[1]),
                        "⛔ {$url}: বারে পাশাপাশি দুই দল \"{$previous[0]}\" আর \"{$kind}\" কাছাকাছি রঙে — মালিক: \"পাশাপাশি একই রকমের কালার দিবা না\"");
                }
                $previous = [$kind, $colour];
            }

            $drops = $xpath->query('//*[@data-module-bar]//*[@data-modulebar-fold][contains(@class, "modulebar-drop")]');
            $this->assertGreaterThan(0, $drops->length, "⛔ {$url}: ড্রপডাউন রঙিন নয়");
            foreach ($drops as $drop) {
                foreach ($xpath->query('./a', $drop) as $item) {
                    $this->assertNotNull($xpath->query('.//svg[@data-licon]', $item)->item(0), "⛔ {$url}: ড্রপডাউনের সারি \"".trim($item->textContent).'"-এর চিহ্ন নেই');
                }
            }
        }
    }

    public function test_the_bar_colours_keep_one_colour_per_group_and_part_close_neighbours(): void
    {
        // ⓘ একই দল পরপর — একই রং; আলাদা দল কাছাকাছি রঙে — দ্বিতীয়টা সরে
        $colours = MenuLook::forBar(['master', 'master', 'papers', 'master']);
        $this->assertSame($colours[0], $colours[1], '⛔ একই দলের দুই ঘর দুই রঙে');
        $this->assertSame($colours[0], $colours[3], '⛔ একই দল দূরে ফিরে এসে অন্য রঙে');
        $this->assertNull(MenuLook::forBar([null])[0], '⛔ দল না জানা ঘর রং পেল');

        $close = MenuLook::forBar(['ledger', 'fin']);
        $this->assertGreaterThanOrEqual(60.0, MenuLook::apart($close[0], $close[1]), '⛔ পাশাপাশি দুই সবুজ দল');
    }
}
