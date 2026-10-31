<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রঙিন ERP, ধাপ ১ — মডিউলের রং (ড্যাশবোর্ডের পরিকল্পনা §৩.২, §৩.৫, §৩.৬; মালিক, ২৪ অক্টোবর ২০২৬: "full erp RGB ekta color dibe")।
 *
 * ⛔ আগে: ABOS রূপ (navy) ষোলোটা মডিউলকেই এক টিল বানাত, linear এক ধূসর — তাই অনেকের পর্দায় সব মডিউল এক রঙের; আর
 * tokens.css-এর সাতটা রঙে সাদা চিহ্ন ৪.৫ঃ১-এর নিচে (হলুদ ১.৯২, আকাশি ২.৭৭, কমলা ২.৮০ …)।
 *
 * দাবি:
 *   ক — রেলে আসতে পারে এমন প্রতিটা মডিউলের নিজের `--color-module-<কোড>`, আর সাদা লেখা তার উপরে ≥ ৪.৫ঃ১ (WCAG AA, লেখা);
 *   খ — কোনো রূপ (Looks/*.php) `--color-module-*` বসায় না — বসালে html-এর inline style কোম্পানির সুইচকে হারিয়ে দিত;
 *   গ — কোম্পানির সুইচ `system.colorful_modules`: চালু (ডিফল্ট) হলে পাতায় `data-modules` নেই, বন্ধ হলে `data-modules="calm"`,
 *       আর শান্ত ব্লক রঙের প্রতিটা মডিউলকে ব্র্যান্ডের এক রঙে ফেরায় — একটাও বাদ পড়লে সুইচ বন্ধেও সেটা রঙিন থাকত।
 */
final class TheModuleColoursCarryWhiteTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_k_every_module_has_its_own_colour_and_white_text_reads_on_it(): void
    {
        $light = $this->rootBlocks();
        $problems = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $token = '--color-module-'.$module->code;
            if (preg_match('/'.preg_quote($token, '/').'\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $light, $hit) !== 1) {
                $problems[] = "{$token}: নেই — রেলের টাইল রংহীন বসত";

                continue;
            }
            if (($ratio = $this->onWhite($hit[1])) < 4.5) {
                $problems[] = sprintf('%s: %s — সাদা লেখা %.2f:1 (৪.৫ঃ১ চাই)', $token, $hit[1], $ratio);
            }
        }

        $this->assertSame([], $problems, "⛔ রঙিন ERP: এই মডিউলের রং নেই, বা তার উপরে সাদা লেখা পড়া যায় না:\n".implode("\n", $problems));
    }

    public function test_kh_no_look_sets_a_module_colour(): void
    {
        $culprits = [];
        foreach (glob(app_path('Core/Support/Looks/*.php')) ?: [] as $file) {
            $values = require $file;
            foreach (['light', 'dark'] as $theme) {
                foreach (array_keys($values[$theme] ?? []) as $name) {
                    if (str_starts_with((string) $name, '--color-module-')) {
                        $culprits[] = basename($file)." ({$theme}): {$name}";
                    }
                }
            }
        }

        $this->assertSame([], $culprits, "⛔ রঙিন ERP: রূপ আবার মডিউলের রং বসাচ্ছে — সুইচ বন্ধ বা চালু, পর্দা রূপের রঙেই থাকত:\n".implode("\n", $culprits));
    }

    public function test_g_the_company_switch_turns_every_module_to_one_colour_and_back(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy'])->save();

        $on = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*data-modules=/', $on, '⛔ রঙিন ERP: সুইচ চালু (ডিফল্ট), তবু পাতা শান্ত এক রঙে।');

        app(SettingsService::class)->set('system.colorful_modules', false);
        $off = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-modules="calm"/', $off, '⛔ রঙিন ERP: সুইচ বন্ধ, তবু পাতা রঙিন — শান্ত রূপে ফেরার পথ নেই।');

        preg_match("/:root\\[data-modules='calm'\\]\\s*\\{(.*?)\\}/s", $this->css(), $calm);
        $left = [];
        foreach (app(ModuleRegistry::class)->all() as $module) {
            if (preg_match('/--color-module-'.preg_quote($module->code, '/').'\s*:\s*var\(--color-brand-700\)\s*;/', $calm[1] ?? '') !== 1) {
                $left[] = $module->code;
            }
        }
        $this->assertSame([], $left, '⛔ রঙিন ERP: সুইচ বন্ধেও এই মডিউলগুলো নিজের রঙে থাকত: '.implode(', ', $left));
    }

    public function test_gh_each_rail_tile_is_painted_in_its_own_module_colour(): void
    {
        // ⛔ ৩০ অক্টোবর ২০২৬ ছবিতে ধরা: `:root`-এর `--rail-tile-bg: var(--color-module-current, …)` গোড়াতেই হিসাব হত, তাই সব
        // টাইল ব্র্যান্ডের এক নীলে; আর navy.php-র inline `--rail-tile-bg: transparent` রঙিন চালু থাকলেও টাইল মুছে দিত।
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy'])->save();
        $html = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();

        preg_match_all('/<span data-rail-tile[^>]*style="([^"]*)"/s', $html, $tiles);
        $this->assertGreaterThan(5, count($tiles[1]), '⛔ রঙিন ERP: রেলে `data-rail-tile` টাইল নেই — জমিন টাইলের ঘরে হিসাব হয় না।');
        $own = array_filter($tiles[1], fn (string $style) => preg_match('/--color-module-current:\s*var\(--color-module-[a-z_]+/', $style) === 1);
        $this->assertCount(count($tiles[1]), $own, '⛔ রঙিন ERP: কোনো টাইল নিজের মডিউলের রং বসায় না।');

        $this->assertMatchesRegularExpression('/(?:^|\})\s*\[data-rail-tile\]\s*\{[^}]*--rail-tile-bg:\s*var\(--color-module-current/', $this->css(),
            '⛔ রঙিন ERP: টাইলের জমিন টাইলের ঘরে হিসাব হয় না — সব টাইল এক রঙে পড়ত।');
        preg_match('/style="([^"]*)"/', (string) substr($html, (int) strpos($html, '<html')), $root);
        $this->assertStringNotContainsString('--rail-tile-bg', $root[1] ?? '', '⛔ রঙিন ERP: রূপ inline-এ রেলের টাইল বসায় — সুইচ চালু থাকলেও টাইল স্বচ্ছ থাকত।');
    }

    /** tokens.css মন্তব্য ছাড়া */
    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/tokens.css')));
    }

    /** আলো থিমের `:root { … }` — গাঢ় আর শান্ত ব্লক বাদে */
    private function rootBlocks(): string
    {
        preg_match_all('/(?:^|\n):root\s*\{(.*?)\n\}/s', $this->css(), $blocks);

        return implode("\n", $blocks[1]);
    }

    private function onWhite(string $hex): float
    {
        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $l = 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));

        return 1.05 / ($l + 0.05);
    }
}
