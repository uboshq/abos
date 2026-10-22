<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⛔ শুধু-আইকনের বোতামের নাম ছিল কেবল Alpine-এর হাতে — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (WCAG 2.2 §4.1.2 Name, Role, Value)।
 *
 * <p>লাইভ নিরীক্ষায় ৩১১টা পাতায় ৯৬৬টা নামহীন বোতাম — প্রায় সবই layout-এর (সাইডবার, পূর্ণ পর্দা, স্ক্যানারের কোণ)। নাম ছিল
 * `:aria-label`/`:title`-এ, যা Alpine চালু হলে বসে; সার্ভারের পাঠানো পাতায় নাম নেই, তাই স্ক্রিন-রিডার, ধীর ফোন বা স্ক্রিপ্ট
 * আটকানো ব্রাউজারে বোতামটা বোবা।
 *
 * <p>নিয়ম: যে `<button>`-এর ভেতরে পড়ার মতো লেখা নেই (কেবল আইকন, svg, ফাঁকা span), তার নিজের **স্থির** `aria-label`,
 * `aria-labelledby` বা `title` থাকবে। ⓘ Alpine-এর বাঁধা নাম পাশে থাকতে পারে — চালু হলে ওটাই বদলায়।
 * ⓘ লেখা ধরা হয়: `{{ }}`, `__()`, সাধারণ অক্ষর, `sr-only` লেখা; আর বোতামে বা ভেতরে `x-text` (চালু পাতায় লেখা আসে)।
 */
final class AnIconButtonWithNoNameSaysNothingTest extends TestCase
{
    private const BUTTON = '/<button\b(?<attrs>(?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(?<body>.*?)<\/button>/s';

    public function test_every_icon_only_button_carries_a_static_name(): void
    {
        $looked = 0;
        $complaints = [];

        foreach ($this->blades() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all(self::BUTTON, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $m) {
                $looked++;
                $attrs = $m['attrs'][0];

                if (self::named($attrs) || self::hasText($m['body'][0])) {
                    continue;
                }

                $complaints[] = sprintf('%s:%d', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                    substr_count(substr($source, 0, $m[0][1]), "\n") + 1);
            }
        }

        // ⛔ কয়টা বোতাম দেখা হলো — ছাঁচ ভাঙলে শূন্য দেখে সবুজ থাকত
        $this->assertGreaterThan(300, $looked, 'মাত্র '.$looked.'টা বোতাম চোখে পড়ল — খোঁজার ছাঁচ ভেঙেছে কি না দেখুন।');

        $this->assertSame([], $complaints,
            "এই বোতামগুলোয় লেখা নেই, স্থির নামও নেই — সার্ভারের পাতায় বোবা (স্থির aria-label বা title দিন):\n  "
            .implode("\n  ", $complaints));
    }

    public function test_the_rule_tells_a_mute_button_from_a_named_one(): void
    {
        $this->assertFalse(self::named(' type="button" :aria-label="x ? \'a\' : \'b\'"'), 'বাঁধা নাম একা স্থির নাম নয়');
        $this->assertTrue(self::named(' aria-label="খুলুন" :aria-label="x"'));
        $this->assertTrue(self::named(' title="পূর্ণ পর্দা"'));
        $this->assertTrue(self::named(' x-text="label"'));
        $this->assertFalse(self::hasText('<x-ui.icon name="list" :size="18" />'));
        $this->assertFalse(self::hasText('<span class="dot"></span><svg><path d="M0"/></svg>'));
        $this->assertTrue(self::hasText('&times;<span class="sr-only">{{ __(\'x\') }}</span>'));
        $this->assertTrue(self::hasText(' সংরক্ষণ '));
    }

    /** স্থির নাম, বা চালু পাতায় নিজের লেখা (`x-text`) */
    private static function named(string $attrs): bool
    {
        return preg_match('/(?<![:\w-])(aria-label|aria-labelledby|title)\s*=/', $attrs) === 1
            || preg_match('/(?<![\w-])x-text\s*=/', $attrs) === 1;
    }

    private static function hasText(string $body): bool
    {
        $b = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);

        if (preg_match('/\{\{.*?\}\}|\{!!.*?!!\}|@lang\(|__\(|x-text\s*=|x-html\s*=/s', $b) === 1) {
            return true;
        }

        $b = (string) preg_replace('/<svg\b.*?<\/svg>/s', '', $b);
        $b = (string) preg_replace('/<x-[\w.:-]+\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\/?>/s', '', $b);
        $b = (string) preg_replace('/<\/x-[\w.:-]+>/', '', $b);
        $b = (string) preg_replace('/<[^>]+>/', '', $b);
        $b = (string) preg_replace('/@\w+(\s*\(.*?\))?/', '', $b);

        return trim($b) !== '';
    }

    /**
     * কমিট করা ব্লেডগুলো — ডিস্কে যা আছে তার সবটা নয় ([[AChartLabelRowCouldNotShrinkTest::blades()]]-এর কারণেই)।
     *
     * @return list<string>
     */
    private function blades(): array
    {
        $lines = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $l) => base_path($l), array_filter($lines, fn (string $l) => str_ends_with($l, '.blade.php'))),
            'is_file',
        ));
    }
}
