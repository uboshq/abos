<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\Accent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ২৩ নভেম্বর ২০২৬:
 *   · ABOS Navy-র মডিউল-রেল সাদা — চিহ্ন নিজের মডিউলের রঙের টাইলে, পড়া যায়
 *   · প্রোফাইল › চেহারা › রং-এ তিনটা নতুন রং — সাদা, Redwood, Navy — যারা ফ্রেমের জমিনও রাঙায় (Navy রূপের বার আর রেল)
 * ⛔ সব নিয়ম কেবল `[data-ui='navy']`-এ — SAP, Dynamic আর বাকি রূপের একটা পিক্সেলও নড়ে না।
 */
class TheNavyRailIsWhiteAndThreeFrameColoursTest extends TestCase
{
    use RefreshDatabase;

    private const FRAME_KEYS = ['white', 'redwood', 'navy'];

    /** WCAG-এর সূত্র — AppearanceTest-এর মতোই। */
    private function contrast(string $a, string $b): float
    {
        $luminance = function (string $rgb): float {
            $linear = array_map(
                fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
                array_map('floatval', explode(' ', $rgb)),
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        [$light, $dark] = $luminance($a) > $luminance($b) ? [$luminance($a), $luminance($b)] : [$luminance($b), $luminance($a)];

        return ($light + 0.05) / ($dark + 0.05);
    }

    /** tokens.css-এর নিয়মগুলো (মন্তব্য বাদ), প্রতিটা `selector => body`। */
    private function tokenRules(): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/tokens.css')));
        preg_match_all('~([^{}@]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);

        return array_map(fn (array $r): array => [trim($r[1]), $r[2]], $m);
    }

    public function test_the_navy_rail_is_white_in_the_light_theme_only(): void
    {
        $found = false;

        foreach ($this->tokenRules() as [$selector, $body]) {
            if ($selector === ":root[data-ui='navy']:not([data-theme='dark']) nav[data-rail]") {
                $found = true;
                $this->assertMatchesRegularExpression('~background:\s*#ffffff;~', $body, '⛔ Navy-র রেল সাদা নয়।');
                // নামের কালি সাদায় পড়া যায় (AA ৪.৫ঃ১)
                $this->assertMatchesRegularExpression('~--rail-label-ink:\s*#41525e;~', $body);
                $this->assertGreaterThanOrEqual(4.5, $this->contrast('65 82 94', '255 255 255'));
            }
        }

        $this->assertTrue($found, '⛔ ABOS Navy-র সাদা রেলের নিয়ম নেই।');
    }

    /** রেল আর ফ্রেমের প্রতিটা নতুন নিয়ম কেবল Navy রূপে। */
    public function test_the_rail_and_frame_rules_touch_only_the_navy_look(): void
    {
        foreach ($this->tokenRules() as [$selectorList, $body]) {
            if (! str_contains($selectorList, 'data-accent-frame') && ! str_contains($body, '--frame-')) {
                continue;
            }

            foreach (explode(',', $selectorList) as $selector) {
                $this->assertStringContainsString("[data-ui='navy']", $selector, "⛔ ফ্রেমের রং Navy-র বাইরে: {$selector}");
                $this->assertStringContainsString(":not([data-theme='dark'])", $selector, "⛔ ফ্রেমের রং রাতেও: {$selector}");
            }
        }
    }

    public function test_the_three_frame_colours_are_offered_in_both_languages(): void
    {
        foreach (self::FRAME_KEYS as $key) {
            $this->assertContains($key, Accent::keys(), "⛔ রং {$key} তালিকায় নেই।");

            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame("core.accent.{$key}", __("core.accent.{$key}", [], $locale), "⛔ {$key}-এর {$locale} নাম নেই।");
            }
        }

        $this->assertSame('সাদা', __('core.accent.white', [], 'bn'));
    }

    /** কেবল এই তিনটাই ফ্রেম রাঙায়; বাকি রং আগের মতো কেবল বোতামের। */
    public function test_only_the_three_carry_a_frame(): void
    {
        foreach (Accent::all() as $key => $accent) {
            $this->assertSame(in_array($key, self::FRAME_KEYS, true), isset($accent['frame']), "⛔ {$key}-এর ফ্রেম ভুল।");
            $this->assertSame(isset($accent['frame']), str_contains(Accent::styleFor($key), '--frame-bg:'), "⛔ {$key}-এর --frame-* ভুল।");
        }
    }

    /** ফ্রেমের কালি জমিনে, খোঁজার ঘরে আর হোভারে পড়া যায় (AA ৪.৫ঃ১)। */
    public function test_the_frame_ink_reads_on_every_frame_surface(): void
    {
        foreach (self::FRAME_KEYS as $key) {
            $frame = Accent::frame($key);
            $this->assertNotNull($frame);

            foreach (['bg', 'field', 'hover'] as $surface) {
                $ratio = $this->contrast($frame['ink'], $frame[$surface]);
                $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s: কালি/%s %.2f:1', $key, $surface, $ratio));
            }

            $muted = $this->contrast($frame['ink_muted'], $frame['bg']);
            $this->assertGreaterThanOrEqual(4.5, $muted, sprintf('%s: ফিকে কালি %.2f:1', $key, $muted));
        }

        $this->assertSame('#8a2f23', Accent::get('redwood')['swatch'], '⛔ Redwood রূপের গাঢ় ইটরঙা নয়।');
    }

    public function test_the_page_marks_a_frame_colour_and_only_a_frame_colour(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $owner->forceFill(['accent' => 'redwood'])->save();
        $this->actingAs($owner->fresh())->get('/')
            ->assertOk()
            ->assertSee('data-accent-frame="redwood"', false)
            ->assertSee('--frame-bg:138 47 35;', false);

        $owner->forceFill(['accent' => 'emerald'])->save();
        $this->actingAs($owner->fresh())->get('/')
            ->assertOk()
            ->assertDontSee('data-accent-frame=', false);
    }
}
