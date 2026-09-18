<?php

declare(strict_types=1);

namespace App\Core\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `unsafe-eval` রাখার দাম — গুনে দেখা, অনুমান করে নয়।
 *
 * ── ⛔ নিরীক্ষার ধাপ ৩.১, আর কেন এটা এক বসায় হয় না ───────────────────
 * *"CSP থেকে `unsafe-inline` ও `unsafe-eval` তোলা — এটি এই পরিকল্পনার
 * সবচেয়ে কঠিন নিরাপত্তা-কাজ।"* ⓘ `unsafe-inline` চলে গেছে (nonce বসেছে)।
 *
 * ⚠️ `unsafe-eval` রয়ে গেছে, কারণ Alpine অ্যাট্রিবিউটের ভিতরের **লেখাটা
 * মূল্যায়ন করে**। CSP সংস্করণ (`@alpinejs/csp`) কেবল **নাম** বোঝে:
 *
 *     x-show="open"            ✅ একটা নাম — চলে
 *     x-show="method === 'cash'" ⛔ এক্সপ্রেশন — চলে না
 *
 *     @click="open = ! open"     ⛔ এক্সপ্রেশন — চলে না
 *
 * ⭐ অর্থাৎ প্রতিটা এক্সপ্রেশনকে কম্পোনেন্টের একটা getter বা method
 * বানাতে হবে, আর প্রতিটা `x-data`-কে `Alpine.data()`-এ নিবন্ধিত নাম।
 *
 * ── ⛔ সংখ্যাটা একবার নীরবে পুরনো হয়ে গিয়েছিল ─────────────────────────
 * [[ContentSecurityPolicy]]-র মন্তব্যে হাতে লেখা ছিল **৭২টা ফাইল**।
 * ⚠️ ১৮ সেপ্টেম্বর ২০২৬-এ গুনে দেখা গেল **৯২টা ফাইলে ১,৯২৮টা এক্সপ্রেশন**।
 *
 * ⓘ একটা "মাপা কারণ" পুরনো হয়ে গেলে সেটা আর কারণ থাকে না, একটা **বিশ্বাস**
 * হয়ে যায় — আর নিরাপত্তার সিদ্ধান্ত বিশ্বাসের উপর দাঁড়ালে কেউ আর প্রশ্ন
 * করে না, কারণ উত্তরটা তো "লেখাই আছে"।
 *
 * ── ⭐ তাই সংখ্যাটা এখন কোড গুনে বের করে ──────────────────────────────
 * হাতে লেখা নয়। ⓘ ঋণ বাড়লেও জানা যায়, শোধ হলেও — আর শূন্য হলে
 * `unsafe-eval` তুলে দেওয়ার দিন।
 */
final class AlpineDebt
{
    /**
     * যে অ্যাট্রিবিউটগুলোর ভিতরের লেখা Alpine মূল্যায়ন করে।
     *
     * @var list<string>
     */
    private const DIRECTIVES = ['x-data', 'x-show', 'x-text', 'x-html', 'x-model', 'x-if', 'x-for'];

    /** @var list<string> */
    private const PREFIXES = ['x-on:', 'x-bind:', '@', ':'];

    /**
     * ঋণের হিসাব — কয়টা এক্সপ্রেশন, কয়টা ফাইলে।
     *
     * @return array{expressions: int, files: int, names: int}
     */
    public static function count(): array
    {
        $names = 0;
        $expressions = 0;
        $files = [];

        foreach (self::blades() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'x-data')) {
                continue;
            }

            preg_match_all(
                '/(?:^|\s)((?:x-on:|x-bind:|@|:)?[a-zA-Z][\w:.-]*)="([^"]*)"/m',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $name, $value]) {
                if (! self::isAlpine($name)) {
                    continue;
                }

                if (self::isJustAName($value)) {
                    $names++;

                    continue;
                }

                $expressions++;
                $files[$path] = true;
            }
        }

        return [
            'expressions' => $expressions,
            'files' => count($files),
            'names' => $names,
        ];
    }

    private static function isAlpine(string $name): bool
    {
        if (in_array($name, self::DIRECTIVES, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * লেখাটা কি কেবল একটা নাম বা পথ — নাকি একটা এক্সপ্রেশন।
     *
     * ⓘ `open` ও `form.name` নিরাপদ; `a === b`, `! open`, `f()` নয়।
     */
    private static function isJustAName(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z_$][\w$]*(\.[a-zA-Z_$][\w$]*)*$/', $value) === 1;
    }

    /** @return \Generator<string> */
    private static function blades(): \Generator
    {
        foreach ([base_path('app'), base_path('resources/views')] as $root) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
