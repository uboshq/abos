<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Navigation\LucideIcons;
use App\Core\Navigation\MenuLook;
use App\Core\Support\CompanyContext;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বাঁয়ের মেনুর দল ভাঁজ করা, আইকনসহ — মালিক, ২০ নভেম্বর ২০২৬ (D365/SAP/Oracle-এর বাঁ নেভিগেশন: মডিউল → দল → পাতা)।
 *
 * দাবি:
 *   ১. প্রতিটা দলের নিজের আইকন আছে, আর সেটা সত্যিই Lucide-এর তালিকায়
 *   ২. তিন ABOS রূপে (rose · navy · blue) দলের মাথা বোতাম — দলের আইকন + নাম, তীর নেই, `aria-expanded`
 *   ৩. পাতা খুললে কেবল এই পাতার দল খোলা; বাকি দলের সারি `hidden`
 *   ৪. ভাঁজের রূপে এক দলের একটাই মাথা (ছড়ানো সারিগুলো দলে একসাথে)
 *   ৫. SAP, Dynamic আর বাকি রূপে কোনো বোতাম বা ভাঁজের চিহ্ন নেই — মাথা আগের `<p>`
 *   ৬. ভাঁজের প্রতিটা CSS নিয়ম তিন ABOS রূপে বাঁধা
 */
final class MenuGroupsFoldTest extends TestCase
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

    public function test_every_group_kind_has_its_own_real_icon(): void
    {
        $kinds = MenuLook::data()['kinds'];
        $this->assertNotEmpty($kinds);

        foreach (array_keys($kinds) as $kind) {
            $icon = MenuLook::kindIcon($kind);
            $this->assertNotNull($icon, "⛔ দল \"{$kind}\"-এর আইকন নেই — মাথার বোতাম চিহ্ন ছাড়া আঁকা হবে");
            $this->assertTrue(LucideIcons::has((string) $icon), "⛔ দল \"{$kind}\"-এর আইকন \"{$icon}\" Lucide-এর তালিকায় নেই");
        }
    }

    public function test_the_three_abos_looks_and_nothing_else_fold(): void
    {
        $this->assertSame(['rose', 'navy', 'blue'], Ui::FOLD_MENU_LOOKS);

        foreach (Ui::keys() as $look) {
            $this->assertSame(in_array($look, ['rose', 'navy', 'blue'], true), Ui::foldsMenuGroups($look), "⛔ রূপ {$look}");
        }
    }

    public function test_in_each_abos_look_the_group_heads_are_buttons_with_icon_and_only_the_current_group_is_open(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $xpath = $this->menuOf($look, route('sales.order.index'));

            $nav = $xpath->query('//nav[@data-menu-fold-nav]');
            $this->assertSame(1, $nav->length, "⛔ {$look}: ভাঁজের মেনু নেই");
            $this->assertSame('sales', $nav->item(0)->getAttribute('data-menu-fold-nav'));

            $heads = $xpath->query('//nav[@data-menu-fold-nav]//*[@data-menu-fold]');
            $this->assertGreaterThan(3, $heads->length, "⛔ {$look}: দলের মাথা নেই");

            $open = [];
            $kinds = [];
            foreach ($heads as $head) {
                $key = $head->getAttribute('data-menu-fold');
                $this->assertSame('button', strtolower($head->nodeName), "⛔ {$look} {$key}: মাথা বোতাম নয়");
                $this->assertSame('button', $head->getAttribute('type'));
                $this->assertContains($head->getAttribute('aria-expanded'), ['true', 'false'], "⛔ {$look} {$key}: aria-expanded নেই");
                $this->assertSame(1, $xpath->query('.//svg[@data-licon]', $head)->length, "⛔ {$look} {$key}: দলের আইকন নেই");
                $this->assertNotSame('', trim($head->textContent), "⛔ {$look} {$key}: দলের নাম নেই");
                // ⓘ তীর নেই — মালিক: আন্তর্জাতিক মানে দলের মাথায় তীর থাকে না
                $this->assertSame(0, $xpath->query('.//*[contains(@class, "chev") or contains(@data-licon, "chevron")]', $head)->length,
                    "⛔ {$look} {$key}: মাথায় তীর");

                $kinds[] = $head->getAttribute('data-menu-kind');
                if ($head->getAttribute('aria-expanded') === 'true') {
                    $open[] = $key;
                }
            }

            $this->assertSame(count($kinds), count(array_unique($kinds)), "⛔ {$look}: এক দলের দুইটা মাথা — ভাঁজের মেনুতে দল একসাথে থাকার কথা");
            $this->assertCount(1, $open, "⛔ {$look}: পাতা খুললে কেবল এই পাতার দল খোলা থাকার কথা");

            $current = $xpath->query('//nav[@data-menu-fold-nav]//a[@aria-current="page"]');
            $this->assertSame(1, $current->length);
            $this->assertSame($open[0], $current->item(0)->getAttribute('data-menu-fold-row'), "⛔ {$look}: খোলা দলটা এই পাতার দল নয়");
            $this->assertFalse($current->item(0)->hasAttribute('hidden'));

            foreach ($xpath->query('//nav[@data-menu-fold-nav]//a[@data-menu-fold-row]') as $row) {
                $this->assertSame($row->getAttribute('data-menu-fold-row') !== $open[0], $row->hasAttribute('hidden'),
                    "⛔ {$look}: \"{$row->textContent}\" — বন্ধ দলের সারি লুকানো, খোলা দলের সারি দেখা যাওয়ার কথা");
            }
        }
    }

    public function test_every_module_menu_folds_the_same_way(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();

        foreach ([route('accounts.voucher.list'), route('inventory.stock.index'), route('purchase.order.index'), route('module.dashboard', ['module' => 'hr'])] as $url) {
            $xpath = $this->menuOf('rose', $url);
            $this->assertSame(1, $xpath->query('//nav[@data-menu-fold-nav]')->length, "⛔ {$url}: ভাঁজের মেনু নেই");
            $this->assertGreaterThan(0, $xpath->query('//nav[@data-menu-fold-nav]//button[@data-menu-fold]')->length, "⛔ {$url}: দলের মাথা নেই");
        }
    }

    public function test_sap_dynamic_and_every_other_look_keep_the_old_heads(): void
    {
        foreach (array_diff(Ui::keys(), ['rose', 'navy', 'blue']) as $look) {
            $html = $this->pageIn($look, route('sales.order.index'));

            $this->assertStringNotContainsString('data-menu-fold', $html, "⛔ {$look}: ভাঁজের চিহ্ন এই রূপে এল — রূপটা বদলে যাবে");
            $this->assertStringNotContainsString('menu-fold-head', $html, "⛔ {$look}: ভাঁজের বোতাম এই রূপে এল");
        }
    }

    /**
     * ⭐ মডিউলের মাথা — কেবল "ABOS" (rose) রূপে (মালিক, ২০ নভেম্বর ২০২৬, নমুনা "চ"; একই দিনের সংশোধন: Navy আর Blue আগের মতো):
     * মডিউলের রঙের বর্গে মডিউলের চিহ্ন, পাশে নাম; এক করা মডিউলে এক করা নাম আর প্রধান মডিউলের চিহ্ন ও রং।
     */
    public function test_only_the_abos_look_wears_the_module_heading_with_its_icon_colour_and_name(): void
    {
        $picker = (array) config('abos.module_picker');
        app()->setLocale('bn');

        foreach ([route('purchase.order.index') => ['purchase', 'ক্রয়'], route('accounts.voucher.list') => ['accounts', 'হিসাব ও অর্থ'],
            route('customer.index') => ['sales', 'বিক্রয়']] as $url => [$lead, $name]) {
            $xpath = $this->menuOf('rose', $url);
            $head = $xpath->query('//*[@data-site-map]//*[@data-module-head]');

            $this->assertSame(1, $head->length, "⛔ {$url}: ABOS রূপে মডিউলের মাথা নেই");
            $this->assertSame($lead, $head->item(0)->getAttribute('data-module-head'));

            $icon = $xpath->query('.//*[@data-module-head-colour]', $head->item(0))->item(0);
            $this->assertNotNull($icon);
            $this->assertSame($picker[$lead]['colour'], $icon->getAttribute('data-module-head-colour'), "⛔ {$lead}: মাথার বর্গ মডিউলের রঙে নয়");
            $this->assertStringContainsString('background: '.$picker[$lead]['colour'], $icon->getAttribute('style'));
            $this->assertSame($picker[$lead]['icon'], $xpath->query('.//svg[@data-licon]', $icon)->item(0)?->getAttribute('data-licon'), "⛔ {$lead}: মাথায় মডিউলের চিহ্ন নেই");
            $this->assertSame($name, trim($xpath->query('.//*[contains(@class, "module-head-name")]', $head->item(0))->item(0)?->textContent ?? ''));
        }

        foreach (array_diff(Ui::keys(), ['rose']) as $look) {
            $this->assertStringNotContainsString('data-module-head', $this->pageIn($look, route('purchase.order.index')),
                "⛔ {$look}: মডিউলের নতুন মাথা এই রূপে এল — কেবল ABOS-এ");
        }

        $css = (string) file_get_contents(resource_path('css/abos-look.css'));
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $rules);
        foreach ($rules[1] as $selector) {
            $selector = trim((string) preg_replace('#/\*.*?\*/#s', '', $selector));
            if (str_contains($selector, 'module-head')) {
                $this->assertStringStartsWith(":root[data-ui='rose']", $selector, '⛔ মাথার CSS রূপে বাঁধা নয়');
            }
        }
        $this->assertStringNotContainsString('module-head', (string) file_get_contents(resource_path('css/app.css')));
    }

    public function test_every_fold_css_rule_is_bound_to_the_three_abos_looks(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $rules);
        $found = 0;

        foreach ($rules[1] as $selector) {
            if (! str_contains($selector, 'menu-fold')) {
                continue;
            }

            $found++;
            $this->assertMatchesRegularExpression("/:root:is\\(\\[data-ui='rose'\\](, \\[data-ui='navy'\\])?, \\[data-ui='blue'\\]\\)/", $selector,
                '⛔ ভাঁজের CSS নিয়ম রূপে বাঁধা নয় — SAP/Dynamic বদলে যেতে পারে: '.trim($selector));
        }

        $this->assertGreaterThan(4, $found, '⛔ ভাঁজের CSS নিয়ম পাওয়া গেল না');
    }

    public function test_the_script_wakes_before_alpine_and_knows_the_same_three_looks(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertMatchesRegularExpression('/foldMenuGroups\(\)\s*\nAlpine\.start\(\)/', $app, '⛔ ভাঁজ Alpine-এর আগে জাগে না');

        $js = (string) file_get_contents(resource_path('js/menu-fold.js'));
        $this->assertStringContainsString("export const FOLD_LOOKS = ['rose', 'navy', 'blue']", $js, '⛔ JS আর PHP-র রূপের তালিকা আলাদা');
    }

    private function pageIn(string $look, string $url): string
    {
        $this->owner->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($this->owner->fresh())->get($url)->assertOk()->getContent();
    }

    private function menuOf(string $look, string $url): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$this->pageIn($look, $url));
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
