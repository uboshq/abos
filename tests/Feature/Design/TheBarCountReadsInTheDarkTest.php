<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * ⭐ অনুমোদন ভাগ ৩, দফা ১৯ — ওপরের বারের আইকনের রং থিম থেকে, অন্ধকার মোডে ঠিক (পরিকল্পনার পাতা ৭)।
 *
 * ⛔ আগে: বারের তিন সংখ্যা-ব্যাজ (অনুমোদন, সই-হয়েছে-পোস্ট-হয়নি, ঘণ্টা) নিজে `text-white` আর `bg-(--color-danger)` /
 * `bg-(--color-brand-500)` লিখত। গাঢ় থিমে danger = #ef4444 → সাদা ১০px সংখ্যা ৩.৭৬:১; আর কোম্পানির নিজের অ্যাকসেন্ট হালকা হলে
 * (TDEPOT #3b82f6) ঘণ্টার নীল ব্যাজ দুই থিমেই ৩.৬৮:১ — WCAG AA ৪.৫ লাগে (ব্রাউজারে মাপা, fe ২৯ অক্টোবর)।
 *
 * দাবি:
 *   ১৯ক — থিমের টোকেন: --color-count-urgent আলো আর গাঢ় দুই থিমেই সাদা কালিতে ৪.৫:১; নীলটা অ্যাকসেন্টের গাঢ় ধাপে, থিম-স্থির;
 *   ১৯খ — তিন ব্যাজ (আর ঘণ্টার ভেতরের "জরুরি" চিপ) রং কেবল এই টোকেন থেকে নেয়, নিজে লেখে না।
 */
final class TheBarCountReadsInTheDarkTest extends TestCase
{
    private const DARK = ":root[data-theme='dark'] {";

    public function test_dofa19a_the_count_tokens_read_on_white_ink_in_both_themes(): void
    {
        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        $at = (int) strpos($tokens, self::DARK);
        $this->assertGreaterThan(0, $at, 'প্রস্তুতিটাই ভুল — tokens.css-এ গাঢ় থিমের খণ্ড নেই।');
        [$light, $dark] = [substr($tokens, 0, $at), substr($tokens, $at)];

        $this->assertSame('#ffffff', $this->resolve('color-count-ink', $light, ''), '⛔ দফা ১৯: সংখ্যার কালি সাদা নয়।');
        $this->assertSame(1, substr_count($tokens, '--color-count-ink:'), '⛔ দফা ১৯: সংখ্যার কালি গাঢ় থিমে আবার লেখা — অন্ধকারে বদলাবে।');

        foreach (['আলো' => [$light, ''], 'গাঢ়' => [$dark, $light]] as $theme => [$scope, $fallback]) {
            $red = $this->resolve('color-count-urgent', $scope, $fallback);
            $ratio = $this->contrast($red, '#ffffff');
            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('⛔ দফা ১৯: %s থিমে লাল সংখ্যা-ব্যাজ (%s) সাদা লেখায় %.2f:১ — ৪.৫ লাগে।', $theme, $red, $ratio));
        }

        foreach (['--color-count-info:', '--color-count-info-ink:'] as $token) {
            $this->assertSame(1, substr_count($tokens, $token), "⛔ দফা ১৯: {$token} গাঢ় থিমে আবার লেখা — নীল ব্যাজ থিম-স্থির থাকার কথা।");
        }
        $this->assertSame('var(--color-on-brand-from)', $this->raw('color-count-info', $light), '⛔ দফা ১৯: নীল ব্যাজ অ্যাকসেন্টের গাঢ় ধাপে নয় — হালকা অ্যাকসেন্টে সাদা সংখ্যা পড়া যায় না।');
        $this->assertSame('var(--color-on-brand-ink)', $this->raw('color-count-info-ink', $light), '⛔ দফা ১৯: নীল ব্যাজের কালি থিম-স্থির সাদা নয়।');
        $blue = $this->resolve('color-on-brand-from', $light, '');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($blue, '#ffffff'), "⛔ দফা ১৯: নীল সংখ্যা-ব্যাজ ({$blue}) সাদা লেখায় ৪.৫:১ নয়।");
    }

    public function test_dofa19b_the_bar_badges_take_their_colour_only_from_the_tokens(): void
    {
        $badges = [
            'approval-quick-access' => ['{{ $pending > 99','bg-(--color-count-urgent)', 'text-(--color-count-ink)'],
            'signed-unposted' => ['data-signed-unposted-count', 'bg-(--color-count-urgent)', 'text-(--color-count-ink)'],
            'notification-bell' => ['data-notify-count', "'bg-(--color-count-urgent) text-(--color-count-ink)' => \$urgent", "'bg-(--color-count-info) text-(--color-count-info-ink)' => ! \$urgent"],
            'notification-bell ' => ['data-critical', 'bg-(--color-count-urgent)', 'text-(--color-count-ink)'],
        ];

        foreach ($badges as $file => [$mark, $bg, $ink]) {
            $source = (string) file_get_contents(resource_path('views/components/shell/'.trim($file).'.blade.php'));
            $at = strpos($source, $mark);
            $this->assertNotFalse($at, "প্রস্তুতিটাই ভুল — {$file}-এ {$mark} নেই।");
            $from = (int) strrpos(substr($source, 0, (int) $at), '<span');
            $badge = substr($source, $from, (int) strpos($source, '</span>', (int) $at) - $from);

            $this->assertStringContainsString($bg, $badge, "⛔ দফা ১৯: {$file}-এর সংখ্যা-ব্যাজের জমিন থিমের টোকেনে নয়।");
            $this->assertStringContainsString($ink, $badge, "⛔ দফা ১৯: {$file}-এর সংখ্যা-ব্যাজের কালি থিমের টোকেনে নয়।");
            foreach (['text-white', 'bg-(--color-danger)', 'bg-(--color-brand-500)'] as $literal) {
                $this->assertStringNotContainsString($literal, $badge, "⛔ দফা ১৯: {$file}-এর সংখ্যা-ব্যাজ নিজে {$literal} লেখে — গাঢ় থিমে পড়া যায় না।");
            }
        }
    }

    private function raw(string $token, string $scope): ?string
    {
        return preg_match('/--'.preg_quote($token, '/').':\s*([^;]+);/', $scope, $m) ? trim($m[1]) : null;
    }

    /** ⓘ var(--x) গাঢ় খণ্ডে না পেলে আলোর খণ্ডে খোঁজে — CSS-এর মতোই; rgb(var(--accent-…, r g b) / 1) এর ডিফল্ট ত্রয়ী নেয়। */
    private function resolve(string $token, string $scope, string $fallback): string
    {
        $value = $this->raw($token, $scope) ?? $this->raw($token, $fallback);
        $this->assertNotNull($value, "প্রস্তুতিটাই ভুল — --{$token} ঘোষিত নেই।");

        if (preg_match('/^var\(--([a-z0-9-]+)\)$/', (string) $value, $m)) {
            return $this->resolve($m[1], $scope, $fallback);
        }
        if (preg_match('/^rgb\(var\(--[a-z0-9-]+,\s*(\d+)\s+(\d+)\s+(\d+)\)\s*\/\s*1\)$/', (string) $value, $m)) {
            return sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]);
        }

        return strtolower(trim(preg_replace('/\/\*.*$/', '', (string) $value) ?? ''));
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $linear = array_map(
                fn (string $c): float => ($v = (int) hexdec($c) / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4,
                [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)],
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }
}
