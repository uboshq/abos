<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ডিজাইন টোকেন — অলঙ্ঘনীয় শর্ত ৩।
 *
 * এই টেস্টগুলো রঙের সিদ্ধান্তগুলো পাহারা দেয়। সেকশন ১৪.৭-এ হিসাব করে দেখা
 * গিয়েছিল মূল প্যালেটের ১১টা জোড়া WCAG AA পাশ করে না; সেগুলো সংশোধন করা
 * হয়েছে। ছয় মাস পরে কেউ "আগের সবুজটা সুন্দর ছিল" বলে #10B981 ফিরিয়ে আনলে
 * এখানেই ধরা পড়বে, ব্যবহারকারীর চোখে নয়।
 */
class DesignTokenTest extends TestCase
{
    private string $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = file_get_contents(resource_path('css/tokens.css'));
    }

    private function value(string $token): string
    {
        $pattern = '/--'.preg_quote($token, '/').':\s*([^;]+);/';

        $this->assertMatchesRegularExpression($pattern, $this->tokens, "Token --{$token} is missing.");
        preg_match($pattern, $this->tokens, $m);

        return trim($m[1]);
    }

    /** WCAG-এর সূত্র — সেকশন ১৪.৭-এ যেভাবে হিসাব করা হয়েছিল। */
    private function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channels = array_map(
                fn (string $c): float => (int) hexdec($c) / 255,
                [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)],
            );

            $linear = array_map(
                fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
                $channels,
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        $l1 = $luminance($a);
        $l2 = $luminance($b);

        [$light, $dark] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function textOnWhite(): array
    {
        return [
            'heading' => ['color-ink'],
            'body text' => ['color-ink-body'],
            'muted text' => ['color-ink-muted'],
            'placeholder' => ['color-ink-placeholder'],
            'primary button fill' => ['color-brand-500'],
            'success button fill' => ['color-success'],
            'danger button fill' => ['color-danger'],
            'income' => ['color-income'],
            'expense' => ['color-expense'],
            'profit' => ['color-profit'],
            'receivable' => ['color-receivable'],
            'payable' => ['color-payable'],
        ];
    }

    #[DataProvider('textOnWhite')]
    public function test_every_colour_used_for_text_passes_aa_on_white(string $token): void
    {
        $ratio = $this->contrast($this->value($token), '#ffffff');

        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            sprintf('--%s is %.2f:1 on white — WCAG AA needs 4.5:1 for normal text.', $token, $ratio),
        );
    }

    public static function badgePairs(): array
    {
        return [
            'success' => ['color-badge-success-ink', 'color-badge-success-bg'],
            'pending' => ['color-badge-pending-ink', 'color-badge-pending-bg'],
            'danger' => ['color-badge-danger-ink', 'color-badge-danger-bg'],
            'info' => ['color-badge-info-ink', 'color-badge-info-bg'],
            'draft' => ['color-badge-draft-ink', 'color-badge-draft-bg'],
            'inventory' => ['color-badge-inventory-ink', 'color-badge-inventory-bg'],
        ];
    }

    #[DataProvider('badgePairs')]
    public function test_badge_pairs_pass_aa(string $ink, string $background): void
    {
        $ratio = $this->contrast($this->value($ink), $this->value($background));

        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            sprintf('%s on %s is %.2f:1.', $ink, $background, $ratio),
        );
    }

    public function test_the_warning_button_keeps_its_amber_by_using_dark_ink(): void
    {
        // অ্যাম্বার সাদা লেখা নিয়ে ২.১৫ — অচল। রং রাখতে হলে লেখা গাঢ় করতে হয়,
        // আর সেটাই অ্যাম্বার বাটনের স্ট্যান্ডার্ড আচরণ।
        $ratio = $this->contrast($this->value('color-warning'), $this->value('color-warning-ink'));

        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('Amber with its ink is %.2f:1.', $ratio));
    }

    public function test_the_dark_theme_is_readable_on_its_own_surfaces(): void
    {
        $dark = substr($this->tokens, (int) strpos($this->tokens, "data-theme='dark'"));

        preg_match('/--color-ink:\s*([^;]+);/', $dark, $ink);
        preg_match('/--color-surface-app:\s*([^;]+);/', $dark, $surface);

        $ratio = $this->contrast(trim($ink[1]), trim($surface[1]));

        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('Dark theme text is %.2f:1.', $ratio));
    }

    public function test_the_chart_palette_keeps_the_corrected_order(): void
    {
        // সেকশন ১৪.৮ — এই আটটা ক্রম ভ্যালিডেটরে পাশ করেছে। স্লেট বাদ দেওয়া
        // হয়েছিল (চার্টে ধূসর দেখায়) আর পিংক লালের পাশ থেকে সরানো হয়েছিল।
        $expected = ['#2563eb', '#d97706', '#7c3aed', '#059669', '#dc2626', '#0891b2', '#db2777', '#4d7c0f'];

        foreach ($expected as $i => $hex) {
            $this->assertSame($hex, $this->value('color-chart-'.($i + 1)));
        }

        $this->assertStringNotContainsString(
            '--color-chart-9',
            $this->tokens,
            'A ninth series folds into "Other" — it never gets a generated colour.',
        );
    }

    public function test_the_logo_red_and_gold_are_not_reused_as_status_colours(): void
    {
        $red = $this->value('color-brand-red');
        $gold = $this->value('color-brand-gold');

        // সেকশন ১৭.৩ — ভুলের বার্তা আর ব্র্যান্ড এক দেখালে ব্যবহারকারী
        // দুটোর পার্থক্য শেখে না।
        $this->assertNotSame($red, $this->value('color-danger'));
        $this->assertNotSame($gold, $this->value('color-warning'));
    }

    public function test_gold_is_never_offered_as_text_on_a_light_surface(): void
    {
        // সাদায় ১.৬৩ — অচল। টোকেনটা আছে শুধু লোগো ও গাঢ় ব্যাকগ্রাউন্ডের জন্য,
        // আর সেটা মন্তব্যে লেখা থাকতে হবে যাতে কেউ ভুল জায়গায় না বসায়।
        $ratio = $this->contrast($this->value('color-brand-gold'), '#ffffff');

        $this->assertLessThan(3.0, $ratio);
        $this->assertMatchesRegularExpression(
            '/গোল্ড.*(সাদায়|লোগো)/u',
            $this->tokens,
            'The gold token needs the comment explaining where it may and may not be used.',
        );
    }

    public function test_the_four_breakpoints_match_the_plan(): void
    {
        // সেকশন ২০.২ — চারটাই, নতুন আবিষ্কার নয়। পাঁচটা স্তর মানে
        // পাঁচগুণ টেস্টিং, কোনো লাভ ছাড়াই।
        $this->assertSame('768px', $this->value('breakpoint-md'));
        $this->assertSame('1024px', $this->value('breakpoint-lg'));
        $this->assertSame('1440px', $this->value('breakpoint-xl'));
        $this->assertSame('1920px', $this->value('breakpoint-2xl'));
    }

    public function test_the_component_sizes_match_the_login_spec(): void
    {
        // সেকশন ১৬.৮
        $this->assertSame('64px', $this->value('spacing-header'));
        $this->assertSame('48px', $this->value('spacing-field'));
        $this->assertSame('12px', $this->value('radius-field'));
        $this->assertSame('20px', $this->value('radius-card'));
        $this->assertSame('460px', $this->value('spacing-login-card'));
        $this->assertSame('44px', $this->value('spacing-logo'));
    }

    public function test_touch_targets_are_at_least_44px(): void
    {
        // সেকশন ২০.৫ — এর ছোট হলে আঙুল দিয়ে টেপা যায় না।
        $this->assertSame('44px', $this->value('spacing-touch'));
    }

    public function test_content_has_a_maximum_width_so_large_screens_do_not_stretch_it(): void
    {
        // সেকশন ২০.১ — "ফিট হওয়া" মানে "টেনে লম্বা করা" নয়।
        $this->assertNotEmpty($this->value('spacing-content-max'));
        $this->assertNotEmpty($this->value('spacing-prose-max'));
    }

    public function test_the_bangla_font_is_in_the_stack_because_poppins_has_no_bangla(): void
    {
        $this->assertStringContainsString('Hind Siliguri', $this->value('font-sans'));
        $this->assertStringContainsString('Hind Siliguri', $this->value('font-bangla'));
    }

    public function test_fonts_are_served_from_our_own_host(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("url('/fonts/", $css);
        $this->assertStringNotContainsString('fonts.googleapis.com', $css);
        $this->assertStringNotContainsString('fonts.gstatic.com', $css);

        foreach (['poppins-400', 'poppins-600', 'hind-siliguri-400-bengali', 'hind-siliguri-600-bengali'] as $file) {
            $this->assertFileExists(public_path("fonts/{$file}.woff2"));
        }
    }

    public function test_motion_is_switched_off_when_the_reader_asks_for_less(): void
    {
        $this->assertStringContainsString('prefers-reduced-motion', $this->tokens);
    }
}
