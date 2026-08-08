<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * কোনো x- অ্যাট্রিবিউটের ভেতরে যেন উদ্ধৃতি চিহ্ন না থাকে।
 *
 * ── কেন এই পাহারাটা লেখা হল ──────────────────────────────────────────
 * ক্রয়ের লাইন-এডিটরে `x-data="{ … }"`-এর ভেতরে একটা মন্তব্য বসানো
 * হয়েছিল, আর সেই মন্তব্যে সাধারণ উদ্ধৃতি ছিল: "৪০%"। HTML-এ অ্যাট্রিবিউটের
 * সীমানা ওই চিহ্নেই, তাই ব্রাউজার ওখানেই x-data শেষ ধরে নেয় — বাকিটা
 * এলোমেলো HTML হয়ে যায়, Alpine কিছু পড়তে পারে না, আর কনসোলে আসে
 * `rows is not defined`।
 *
 * ফল ছিল সম্পূর্ণ: ক্রয় আদেশ, চালান ও বিল — তিনটা পর্দাতেই লাইন যোগ
 * করা যেত না, অর্থাৎ একটাও ক্রয় করা অসম্ভব।
 *
 * ── কেন কোনো টেস্ট এটা ধরেনি ─────────────────────────────────────────
 * সার্ভার ঠিকই ২০০ আর পুরো HTML ফেরত দিচ্ছিল। ভাঙাটা ব্রাউজারে, আর
 * পর্দার আকার বা স্ট্যাটাস কোড দেখে সেটা বোঝার উপায় নেই। ধরা পড়েছে
 * কেবল সত্যিকারের ব্রাউজারে বোতামটা চেপে।
 *
 * তাই পাহারাটা উৎসেই: অ্যাট্রিবিউটটা লেখার সময়ই ভুলটা ধরা পড়ে, আর
 * ব্রাউজার লাগে না।
 */
class AlpineAttributesAreWellFormedTest extends TestCase
{
    public function test_no_alpine_attribute_carries_a_quote_that_would_close_it(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file) ?: '';

            /*
             * ডাবল কোটে ঘেরা প্রতিটা x- অ্যাট্রিবিউট।
             *
             * ভেতরে আরেকটা ডাবল কোট থাকলে regex সেখানেই থামত, তাই
             * খোঁজা হয় উল্টো দিক থেকে: অ্যাট্রিবিউটের শুরু থেকে ওই
             * লাইনের শেষ পর্যন্ত নিয়ে দেখা হয় মাঝখানে কী আছে।
             *
             * @ দিয়ে শুরু হওয়াগুলোও (@click, @input) একই নিয়মে চলে।
             */
            preg_match_all(
                '/(?:x-[a-z:.\-]+|@[a-z:.\-]+)="((?:[^"]|\n)*)"/m',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $body = $match[1];

                /*
                 * Blade-এর নিজের ছাপানো অংশগুলো বাদ।
                 *
                 * {{ __('…') }} বা {{ route('…') }} রেন্ডার হওয়ার সময়
                 * বদলে যায়, আর ওদের ভেতরের উদ্ধৃতি HTML-এ পৌঁছায় না।
                 */
                $withoutBlade = preg_replace('/\{\{.*?\}\}/s', '', $body) ?? $body;

                if (str_contains($withoutBlade, '"')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "এই ভিউগুলোর কোনো x- অ্যাট্রিবিউটের ভেতরে উদ্ধৃতি চিহ্ন আছে।\n"
            ."HTML ওখানেই অ্যাট্রিবিউটটা শেষ ধরে নেয়, আর Alpine পুরো\n"
            ."অভিব্যক্তিটাই হারায় — পর্দা দেখতে ঠিকই থাকে, শুধু কিছু কাজ করে না।\n"
            .'ব্যাখ্যা লিখতে হলে Blade মন্তব্যে লিখুন, ওটা HTML-এ যায় না।');
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
