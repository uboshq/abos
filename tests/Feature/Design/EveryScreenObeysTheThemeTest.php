<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * প্রতিটা পর্দা থিম মানবে — আজ, আর ছয় সপ্তাহ পরেও।
 *
 * ── এই পরীক্ষাটা কীসের বিরুদ্ধে ──────────────────────────────────────
 * ABOS আটটা চেহারা পাচ্ছে (docs/Plan — আটটা থিম.md)। চেহারা বদলায়
 * টোকেন আর শেল ধরে, তাই কোনো পর্দাকে কিছু জানতে হয় না — **যদি** পর্দাটা
 * টোকেনই ব্যবহার করে।
 *
 * সমস্যাটা আজকের নয়, পরের মাসের। কেউ একটা নতুন পাতা লিখবেন, তাতে
 * একটা হার্ডকোড করা রং বা একটা `h-9` থাকবে, আর ওই পাতাটা থিম মানবে
 * না। **কেউ মাসের পর মাস টের পাবে না**, কারণ তিনি নিজে যে থিম
 * ব্যবহার করেন সেটাতে হয়তো ঠিকই দেখায়। ধরা পড়বে যেদিন অন্য কেউ
 * অন্য থিমে ওই পাতাটা খুলবেন — সম্ভবত কোনো গ্রাহক।
 *
 * নিয়ম লিখে রাখলে ভাঙে; পরীক্ষা লিখে রাখলে ভাঙে না।
 *
 * ── ছাড়ের তালিকা নিয়ে একটা কড়া কথা ──────────────────────────────────
 * প্রতিটা ছাড়ের পাশে **কারণ লেখা বাধ্যতামূলক**, আর সেটাও একটা টেস্ট
 * দিয়ে বাঁধা। কারণ ছাড়া নাম যোগ করা গেলে ছাড়ের তালিকাটাই আস্তে আস্তে
 * পুরো তালিকা হয়ে যেত, আর পরীক্ষাটা এমন এক পাহারা হত **যেটা সবকিছু
 * পাশ করায়**। এ প্রকল্পে ঠিক ওরকম তিনটা পাহারা আগে ধরা পড়েছে।
 */
class EveryScreenObeysTheThemeTest extends TestCase
{
    /**
     * হার্ডকোড রং যেখানে চলে — আর কেন।
     *
     * @var array<string, string>
     */
    private const COLOUR_ALLOWED = [
        'resources/views/print/' => 'ছাপা থিম মানে না — বিল সবার জন্য এক, ব্যক্তির পছন্দ নয়',
        'resources/views/welcome.blade.php' => 'Laravel-এর নিজের স্বাগত পাতা; ব্যবহারকারী কোনোদিন দেখেন না',
    ];

    /**
     * কাঁচা `<table>` যেখানে চলে — আর কেন।
     *
     * @var array<string, string>
     */
    private const TABLE_ALLOWED = [
        'resources/views/components/ui/table.blade.php' => 'কম্পোনেন্টটাই — টেবিলটা এখানেই লেখা',
        'resources/views/print/' => 'ছাপার কাগজ নিজের ছাঁচে; থিমের সাথে সম্পর্ক নেই',
    ];

    /**
     * থিমের আটটা নাম। কোনো পর্দায় এগুলোর একটাও থাকবে না।
     *
     * @var list<string>
     */
    private const THEMES = [
        'classic', 'tiles', 'suite', 'apps', 'dynamic', 'rose', 'navy', 'redwood',
    ];

    /** @return array<string, string> path => source */
    private function blades(): array
    {
        $found = [];

        foreach (['app/Modules', 'resources/views'] as $root) {
            $dir = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root)),
            );

            foreach ($dir as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $path = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');

                $found[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }

    /** @param  array<string, string>  $allowed */
    private function isAllowed(string $path, array $allowed): bool
    {
        foreach (array_keys($allowed) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * কোনো পর্দায় হার্ডকোড করা রং নেই।
     *
     * ছয়-অঙ্ক, তিন-অঙ্ক আর `rgb()` — তিনটাই দেখা হয়। ২০ আগস্ট প্রথম
     * মাপায় কেবল ছয়-অঙ্ক গোনা হয়েছিল, আর সংখ্যাটা ভুল এসেছিল।
     *
     * মন্তব্যের ভেতরের রং বাদ: `ui/button.blade.php`-এ প্যালেটের
     * ব্যাখ্যায় তিনটা কোড লেখা আছে, আর ওগুলো কোনো পিক্সেল আঁকে না।
     */
    public function test_no_screen_hardcodes_a_colour(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::COLOUR_ALLOWED)) {
                continue;
            }

            // ব্লেড ও HTML মন্তব্য সরিয়ে ফেলা — ওখানে কোড ব্যাখ্যা থাকে
            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->|\/\*.*?\*\//s', '', $source) ?? $source;

            if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলোয় রং হাতে লেখা, তাই থিম বদলালে ওগুলো বদলাবে না।',
            'টোকেন ব্যবহার করুন: var(--color-…)। ইচ্ছাকৃত হলে COLOUR_ALLOWED-এ',
            'কারণসহ লিখুন।',
            ...$offenders,
        ]));
    }

    /**
     * কোনো **নিয়ন্ত্রণের** উচ্চতা হাতে লেখা নেই।
     *
     * ── কেন নিয়মটা সংকীর্ণ, আর সেটাই ইচ্ছাকৃত ───────────────────────
     * প্রথম খসড়ায় যেকোনো `h-<সংখ্যা>` ধরা হচ্ছিল। ওটা ধরে ফেলছিল
     * বিভাজক রেখা (`h-8 w-px`) আর লগইনের লোগো (`h-12 w-auto`) — যেগুলো
     * নিয়ন্ত্রণ নয়, আর যাদের উচ্চতা থিমের সাথে বদলানোর কথাও নয়।
     *
     * চওড়া পাহারার একটাই পরিণতি: বাড়তে থাকা ছাড়ের তালিকা। আর যে
     * তালিকা বাড়তেই থাকে, সে একদিন পুরো তালিকা হয়ে যায় — তখন
     * পরীক্ষাটা এমন এক পাহারা, যেটা সবকিছু পাশ করায়।
     *
     * তাই নিয়মটা ঠিক সেই জিনিসটাই ধরে যেটা ভুল ছিল: **একই `class`-এ
     * `h-<সংখ্যা>` আর `rounded-(--radius…)` একসাথে** — অর্থাৎ একটা ঘর
     * বা বোতাম। ২০ আগস্ট ওরকম ৭২ জায়গা ছিল, ২৩টা ফাইলে।
     *
     * ইনলাইন `height: <সংখ্যা>px`-ও ধরা হয়, তবে `min-height`,
     * `max-height` বা `line-height` নয় — ওই তিনটার শেষেও "height:"
     * থাকে, আর সেটাই প্রথমবার তিনটা নিরীহ ফাইলকে দোষী দেখিয়েছিল।
     */
    public function test_no_control_hardcodes_a_height(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::COLOUR_ALLOWED)) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            $control = preg_match(
                '/class="[^"]*(?:(?<![-\w])h-\d+\b[^"]*rounded-\(--radius|rounded-\(--radius[^"]*(?<![-\w])h-\d+\b)/s',
                $code,
            );

            $inline = preg_match('/(?<![-\w])height:\s*\d+px/', $code);

            if ($control || $inline) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলোয় উচ্চতা হাতে লেখা। ঘন থিমে ওগুলো একা আগের',
            'মাপে বসে থাকবে, আর পর্দাটা অর্ধেক বদলে যাবে।',
            'টোকেন: h-(--spacing-field), h-(--spacing-field-compact),',
            'h-(--spacing-command)।',
            ...$offenders,
        ]));
    }

    /**
     * কোনো পর্দা তার থিমের নাম জানে না।
     *
     * `@if ($ui === 'apps')` একবার লেখা শুরু হলে ছয় মাসে আটটা আলাদা
     * পণ্য দাঁড়িয়ে যাবে — দেখতে নয়, কাজে।
     */
    public function test_no_screen_knows_which_theme_it_is_in(): void
    {
        $offenders = [];
        $pattern = '/\$ui\b|[\'"](?:'.implode('|', self::THEMES).')[\'"]/';

        foreach ($this->blades() as $path => $source) {
            // শেলের নিজের partial-গুলো জানবে — ওদের কাজই সেটা
            if (str_contains($path, '/components/shell/')) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            if (preg_match($pattern, $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলো জানে তারা কোন থিমে আছে। জানার কথা নয় —',
            'যা বদলায় তা টোকেন আর শেল, পাতার কোড নয়।',
            ...$offenders,
        ]));
    }

    /** প্রতিটা ছাড়ের একটা লিখিত কারণ আছে। */
    public function test_every_exception_carries_a_reason(): void
    {
        foreach ([self::COLOUR_ALLOWED, self::TABLE_ALLOWED] as $list) {
            foreach ($list as $path => $reason) {
                $this->assertNotSame('', trim($reason), "কারণ ছাড়া ছাড়: {$path}");
            }
        }
    }
}
