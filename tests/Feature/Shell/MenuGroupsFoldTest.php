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
 *   ২. তিন ABOS রূপে (rose · navy · blue) দলের মাথা বোতাম — দলের রঙের টাইলে আইকন + নাম, ডানে তীর ›, `aria-expanded`
 *      (মালিক, ২১ নভেম্বর ২০২৬, নমুনা "বাঁয়ের মেনু — ভাঁজ" — আগের "তীর নেই" এর জায়গায়)
 *   ৩. পাতা খুললে কেবল এই পাতার দল খোলা; বাকি দলের সারি `hidden`
 *   ৪. ভাঁজের রূপে এক দলের একটাই মাথা (ছড়ানো সারিগুলো দলে একসাথে)
 *   ৫. প্রতিটা দল নিজের ঘরে: মাথা, তারপর সারির ঘর; সারির চিহ্নে ছোপ নেই (২১ নভেম্বর ২০২৬)
 *   ৬. SAP, Dynamic আর বাকি রূপে কোনো বোতাম, ঘর বা ভাঁজের চিহ্ন নেই — মাথা আগের `<p>`; তাদের CSS-এ ভাঁজের কিছু নেই
 *   ৭. ভাঁজের প্রতিটা CSS নিয়ম তিন ABOS রূপে বাঁধা, আর নমুনার মাপগুলো CSS-এ হুবহু
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
                // ⓘ ২১ নভেম্বর ২০২৬ থেকে ডানে তীর › আছে, খোলা দলে ঘোরে — মালিকের নমুনা "বাঁয়ের মেনু — ভাঁজ" (আগের "তীর নেই" এর জায়গায়)
                $this->assertSame(1, $xpath->query('.//*[@data-menu-fold-chev]', $head)->length, "⛔ {$look} {$key}: মাথায় ডানের তীর নেই");
                // ⓘ দলের নিজের রঙের টাইল — রং resources/menu-look.php থেকে, Blue রূপেও দলের নিজের (ব্র্যান্ড-নীল নয়)
                $tile = $xpath->query('.//*[@data-menu-fold-tile]', $head)->item(0);
                $this->assertNotNull($tile, "⛔ {$look} {$key}: দলের রঙিন টাইল নেই");
                $this->assertSame('#'.$head->getAttribute('data-mc'), $tile->getAttribute('data-menu-fold-tile'), "⛔ {$look} {$key}: টাইল দলের রঙে নয়");
                $this->assertStringContainsString('background: #'.$head->getAttribute('data-mc'), $tile->getAttribute('style'));

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

    /**
     * ⭐ মালিক, ২১ নভেম্বর ২০২৬ (নমুনা "বাঁয়ের মেনু — ভাঁজ", "নতুন" মেনু): প্রতিটা দল নিজের ঘরে — আগে মাথা, তারপর সারির ঘর;
     * সারিগুলো সেই ঘরেই, সারির চিহ্ন খালি (কোনো রঙিন বা সাদা ছোপ markup-এ নেই), এই পাতার সারি খোলা দলের ঘরে।
     */
    public function test_in_each_abos_look_every_group_is_one_box_with_its_head_then_its_indented_rows(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $xpath = $this->menuOf($look, route('sales.order.index'));

            $boxes = $xpath->query('//nav[@data-menu-fold-nav]/div[@data-menu-fold-grp]');
            $this->assertGreaterThan(3, $boxes->length, "⛔ {$look}: দলের ঘর নেই");
            $this->assertSame($xpath->query('//nav[@data-menu-fold-nav]//button[@data-menu-fold]')->length, $boxes->length,
                "⛔ {$look}: প্রতিটা দলের মাথা নিজের ঘরে নয়");

            foreach ($boxes as $box) {
                $key = $box->getAttribute('data-menu-fold-grp');
                $this->assertStringContainsString('menu-fold-grp', $box->getAttribute('class'));

                $children = $xpath->query('./*', $box);
                $this->assertSame(2, $children->length, "⛔ {$look} {$key}: ঘরে মাথা আর সারির ঘর ছাড়া আর কিছু");
                $this->assertSame('button', strtolower($children->item(0)->nodeName));
                $this->assertSame($key, $children->item(0)->getAttribute('data-menu-fold'));
                $this->assertSame($key, $children->item(1)->getAttribute('data-menu-fold-items'), "⛔ {$look} {$key}: মাথার পরেই সারির ঘর নয়");
                $this->assertStringContainsString('menu-fold-items', $children->item(1)->getAttribute('class'));

                $rows = $xpath->query('./a', $children->item(1));
                $this->assertGreaterThan(0, $rows->length, "⛔ {$look} {$key}: দলের ঘরে সারি নেই");
                foreach ($rows as $row) {
                    $this->assertSame($key, $row->getAttribute('data-menu-fold-row'), "⛔ {$look} {$key}: অন্য দলের সারি এই ঘরে");
                    // ⓘ সারির চিহ্ন খালি — `.menu-icon`-এ কোনো inline রং বা ছোপ নেই (ছোপ CSS-এ সরানো, নিচের CSS-দাবি)
                    foreach ($xpath->query('.//*[contains(@class, "menu-icon")]', $row) as $icon) {
                        $this->assertSame('', $icon->getAttribute('style'), "⛔ {$look} {$key}: সারির চিহ্নের পেছনে ছোপ");
                    }
                }
            }

            // ⓘ কোনো দলের সারি ঘরের বাইরে নেই
            $this->assertSame(0, $xpath->query('//nav[@data-menu-fold-nav]/a[@data-menu-fold-row]')->length, "⛔ {$look}: দলের সারি দলের ঘরের বাইরে");

            $current = $xpath->query('//nav[@data-menu-fold-nav]//a[@aria-current="page"]')->item(0);
            $this->assertNotNull($current);
            $this->assertSame('true', $xpath->query('ancestor::div[@data-menu-fold-grp]/button', $current)->item(0)?->getAttribute('aria-expanded'),
                "⛔ {$look}: এই পাতার সারি খোলা দলের ঘরে নয়");
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
            // ⓘ ২১ নভেম্বর ২০২৬-এর নতুন গড়ন (দলের ঘর, সারির ঘর, তীর, টাইল) — SAP, Dynamic আর বাকিরা একটা পিক্সেলও বদলায় না
            foreach (['menu-fold-grp', 'menu-fold-items', 'menu-fold-chev', 'menu-fold-icon', 'menu-fold-name'] as $mark) {
                $this->assertStringNotContainsString($mark, $html, "⛔ {$look}: \"{$mark}\" এই রূপে এল");
            }
        }

        // ⓘ ভাঁজের CSS কেবল app.css (তিন ABOS রূপে বাঁধা) আর abos-look.css-এ — SAP (fiori) বা Dynamic-এর ফাইলে নয়
        foreach (glob(resource_path('css/*.css')) ?: [] as $file) {
            if (in_array(basename($file), ['app.css', 'abos-look.css'], true)) {
                continue;
            }
            $this->assertStringNotContainsString('menu-fold', (string) file_get_contents($file), '⛔ '.basename($file).': ভাঁজের নিয়ম অন্য রূপের ফাইলে');
            $this->assertStringNotContainsString('--fold-', (string) file_get_contents($file), '⛔ '.basename($file).': ভাঁজের রং অন্য রূপের ফাইলে');
        }

        $abos = (string) file_get_contents(resource_path('css/abos-look.css'));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $abos, $rules, PREG_SET_ORDER);
        $found = 0;
        foreach ($rules as [, $selector, $body]) {
            $selector = trim((string) preg_replace('#/\*.*?\*/#s', '', $selector));
            if (! str_contains($selector, 'menu-fold') && ! str_contains($body, '--fold-')) {
                continue;
            }
            $found++;
            $this->assertStringContainsString(":root[data-ui='rose']", $selector, '⛔ abos-look.css-এর ভাঁজের নিয়ম rose-এ বাঁধা নয়: '.$selector);
        }
        $this->assertGreaterThan(1, $found, '⛔ abos-look.css-এ ভাঁজের নিয়ম পাওয়া গেল না');
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

        // ⓘ রূপের নিজের রঙের চলক (navy) — নামে "menu-fold" নেই, তবু রূপে বাঁধা
        foreach ($rules[0] as $rule) {
            if (preg_match('/^\s*--fold-/m', $rule) === 1) {
                $this->assertMatchesRegularExpression("/:root\\[data-ui='(rose|navy|blue)'\\]/", $rule, '⛔ ভাঁজের রঙের চলক রূপে বাঁধা নয়');
            }
        }
    }

    /**
     * ⭐ মালিকের নমুনার মাপ, হুবহু (মালিক, ২১ নভেম্বর ২০২৬, নমুনা "বাঁয়ের মেনু — ভাঁজ", `.grp`/`.gbtn`/`.it`) —
     * মাথা বাঁ ঘেঁষে ১০px, ১৪.৫px মোটা, ২৬px/৭ টাইল, তীর ৯০° ঘোরে, দলের মাঝে দাগ; সারি ৪৬px ডানে, ২৩px-এ খাড়া দাগ, ১৬px খালি চিহ্ন;
     * এই পাতা সাদা, নীল লেখা, আধা-মোটা, সোনালি দাগ, ডান কিনারা গোল।
     */
    public function test_the_fold_css_carries_the_owners_sample_values(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $p = "\\[data-site-map\\] ";

        $rule = function (string $selectorTail) use ($css): string {
            $quoted = preg_quote($selectorTail, '/');
            $this->assertMatchesRegularExpression('/'.$quoted.'\s*\{/', $css, '⛔ নিয়ম নেই: '.$selectorTail);
            preg_match('/'.$quoted.'\s*\{([^}]*)\}/', $css, $m);

            return (string) ($m[1] ?? '');
        };

        $this->assertStringContainsString('border-top: 1px solid', $rule('[data-site-map] .menu-fold-grp'));

        $head = $rule('[data-site-map] button.menu-fold-head');
        foreach (['padding: 10px 12px 10px 10px', 'font-size: 14.5px', 'font-weight: 700', 'gap: 10px', 'text-align: left', 'width: 100%'] as $want) {
            $this->assertStringContainsString($want, $head, "⛔ দলের মাথা: {$want}");
        }
        $this->assertStringContainsString('background: var(--fold-deep', $rule("[data-site-map] button.menu-fold-head[aria-expanded='true']"), '⛔ খোলা দল গাঢ় নয়');

        $tile = $rule('[data-site-map] .menu-fold-icon');
        foreach (['width: 26px', 'height: 26px', 'border-radius: 7px', 'color: #ffffff'] as $want) {
            $this->assertStringContainsString($want, $tile, "⛔ দলের টাইল: {$want}");
        }
        $this->assertStringContainsString('margin-left: auto', $rule('[data-site-map] .menu-fold-chev'));
        $this->assertStringContainsString('rotate(90deg)', $rule("[data-site-map] button.menu-fold-head[aria-expanded='true'] .menu-fold-chev"));

        $this->assertStringContainsString('padding: 4px 0 8px', $rule('[data-site-map] .menu-fold-items'));

        $row = $rule('[data-site-map] .menu-fold-items > a[data-menu-fold-row]');
        foreach (['padding: 6px 12px 6px 46px', 'font-weight: 400', 'gap: 10px'] as $want) {
            $this->assertStringContainsString($want, $row, "⛔ উপ-সারি: {$want}");
        }
        $guide = $rule('[data-site-map] .menu-fold-items > a[data-menu-fold-row]::before');
        $this->assertStringContainsString('left: 23px', $guide, '⛔ খাড়া দাগ ২৩px-এ নয়');
        $this->assertStringContainsString('border-left: 1px solid', $guide);

        $icon = $rule('[data-site-map] .menu-fold-items > a[data-menu-fold-row] .menu-icon');
        foreach (['width: 16px', 'height: 16px', 'background: none', 'box-shadow: none'] as $want) {
            $this->assertStringContainsString($want, $icon, "⛔ উপ-সারির চিহ্ন খালি নয়: {$want}");
        }

        $on = $rule('[data-site-map] .menu-fold-items > a[data-menu-fold-row][aria-current]');
        foreach (['background: var(--fold-on-bg, #ffffff)', 'color: var(--fold-on-ink, #1b55c4)', 'font-weight: 600', 'border-radius: 0 8px 8px 0'] as $want) {
            $this->assertStringContainsString($want, $on, "⛔ এই পাতার সারি: {$want}");
        }
        $this->assertStringContainsString('border-left: 3px solid #fbbf24', $rule('[data-site-map] .menu-fold-items > a[data-menu-fold-row][aria-current]::before'), '⛔ সোনালি দাগ নেই');

        $this->assertMatchesRegularExpression('/'.$p.'nav\[data-menu-fold-nav\]\s*\{[^}]*padding-inline: 0/', $css, '⛔ দল পুরো চওড়ায় নয়');
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
