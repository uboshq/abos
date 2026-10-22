<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ⊞ সব মডিউলের টাইল — প্রতিটা মডিউল নিজের রঙে, Odoo-র মতো; রেল ABOS রূপে টিলই থাকে (মালিক, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *  - পাতায় প্রতিটা টাইল লঞ্চারের নিজের টোকেন পরে (`--color-launcher-<কোড>`, নিজের কোডের) — রেলের `--color-module-*` নয়;
 *  - নিবন্ধিত প্রতিটা মডিউলের (রেলে যা আসতে পারে) একটা লঞ্চার-টোকেন আছে, tokens.css-এ একবারই, এক জায়গায়;
 *  - দুই মডিউলের একই রং নয়;
 *  - সাদা আইকনের সাথে প্রতিটা ≥ ৩ঃ১ (WCAG, বড় চিহ্ন) — আলোয়; গাঢ় অংশ কোনোটা বদলালে সেটাও ≥ ৩ঃ১;
 *  - বাকি নিয়ন্ত্রণের লাল আর মালিকের কেন্দ্রের সোনালি-বাদামি আগের মানেই (মালিক);
 *  - কোনো রূপ (Looks/*.php) লঞ্চারের রং বদলায় না — টোকেন কেবল tokens.css-এ।
 */
final class TheLauncherWearsEachModulesOwnColourTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tile_on_the_page_wears_its_own_launcher_token(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy'])->save();

        $html = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $sheet = substr($html, (int) strpos($html, 'data-module-launcher-sheet'));

        preg_match_all('/data-launcher-tile="([^"]+)".*?<span class="relative grid size-\[64px\][^"]*"\s+style="background: ([^"]*)"/s', $sheet, $m, PREG_SET_ORDER);
        $this->assertGreaterThan(5, count($m), 'প্রস্তুতিটাই ভুল — লঞ্চারে টাইল নেই।');

        $wrong = [];
        foreach ($m as [, $code, $background]) {
            if (trim($background) !== 'var(--color-launcher-'.$code.')') {
                $wrong[] = "{$code}: {$background}";
            }
        }
        $this->assertSame([], $wrong, "⛔ এই টাইলগুলো লঞ্চারের নিজের রং পরে না:\n".implode("\n", $wrong));
    }

    public function test_every_module_has_one_unique_readable_launcher_colour_in_one_place(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/tokens.css')));
        preg_match_all("/:root\\[data-theme='dark'\\][^{]*\\{(.*?)\\n\\}/s", $css, $darkBlocks);
        $dark = implode("\n", $darkBlocks[1]);
        $light = str_replace($darkBlocks[0], '', $css);

        $colours = [];
        $problems = [];
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $token = '--color-launcher-'.$module->code;
            $found = preg_match_all('/'.preg_quote($token, '/').'\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $light, $hits);

            if ($found !== 1) {
                $problems[] = "{$token}: {$found} বার ঘোষিত (একবার চাই)";

                continue;
            }

            $hex = strtolower($hits[1][0]);
            $colours[$module->code] = $hex;

            if (($ratio = $this->onWhite($hex)) < 3.0) {
                $problems[] = sprintf('%s: %s সাদা আইকনের সাথে %.2f:1 (৩ঃ১ চাই)', $token, $hex, $ratio);
            }

            if (preg_match('/'.preg_quote($token, '/').'\s*:\s*(#[0-9a-fA-F]{6})/', $dark, $darkHit) === 1
                && ($ratio = $this->onWhite($darkHit[1])) < 3.0) {
                $problems[] = sprintf('%s (গাঢ়): %s — %.2f:1', $token, $darkHit[1], $ratio);
            }
        }

        $this->assertGreaterThan(15, count($colours), 'প্রস্তুতিটাই ভুল — মডিউলই পাওয়া গেল না।');
        $this->assertSame([], $problems, "⛔ লঞ্চারের রং:\n".implode("\n", $problems));

        $shared = array_filter(array_count_values($colours), fn (int $n): bool => $n > 1);
        $this->assertSame([], $shared, '⛔ দুই মডিউলের লঞ্চারে একই রং — টাইল চেনা যায় না।');

        $this->assertSame('#9f1239', $colours['credit_control'] ?? null, '⛔ বাকি নিয়ন্ত্রণের লাল বদলেছে (মালিক: আগের মতোই)।');
        $this->assertSame('#a16207', $colours['executive'] ?? null, '⛔ মালিকের কেন্দ্রের সোনালি-বাদামি বদলেছে (মালিক: আগের মতোই)।');

        foreach (glob(app_path('Core/Support/Looks/*.php')) ?: [] as $look) {
            $this->assertStringNotContainsString('--color-launcher-', (string) file_get_contents($look),
                '⛔ '.basename($look).' লঞ্চারের রং বদলায় — রং কেবল tokens.css-এ, এক জায়গায়।');
        }
    }

    /** সাদার সাথে বৈসাদৃশ্য (WCAG 2.x আপেক্ষিক উজ্জ্বলতা) */
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
