<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * একটা পর্দা যে ভিউ চায়, সেটা সত্যিই আছে তো?
 *
 * ── কোন ভুল থেকে এই ফাইলটা এসেছে ─────────────────────────────────────
 * ৩ সেপ্টেম্বর ২০২৬-এ রান্নাঘরের পর্দাগুলো মজুদ থেকে রেস্টুরেন্ট মডিউলে
 * সরানো হলো। কন্ট্রোলার, ভিউ, ভাষা — সব গেল, কিন্তু **একটা partial
 * মজুদেই রয়ে গেল**:
 *
 *     app/Modules/Inventory/Resources/views/kitchen/partials/portions.blade.php
 *     ← ফাইলটা এখানে
 *     view('restaurant::kitchen.partials.portions')
 *     ← অথচ ডাকা হচ্ছে এখান থেকে
 *
 * ⚠️ **আর ধরা পড়েছে লাইভে, ডিপ্লয়ের পরে।**
 *
 * কারণটা এই রাতের বারবার ফেরা ফাঁদ: **সূত্রটা কেবল তখনই চলে যখন ডেটা
 * আছে।** ওই লাইনটা টেবিলের একটা সারির ভেতরে — খালি রান্নাঘরে টেবিলটাই
 * খালি, তাই ভিউটা কোনোদিন খোঁজা হয় না। উন্নয়নের মেশিনে টিকিট শূন্য,
 * তাই পাতাটা ২০০ দিত। লাইভে টিকিট আছে, তাই ওখানে ৫০০।
 *
 * **অর্থাৎ পর্দাটা ভাঙত কেবল সেখানেই যেখানে মানুষ সত্যিই কাজ করছেন।**
 *
 * ── কেন কম্পাইল বা lint ধরে না ───────────────────────────────────────
 * `view('...')` একটা স্ট্রিং। PHP-র কাছে ওটা কেবল অক্ষর, আর Blade
 * ফাইলটা খোঁজে **রেন্ডারের মুহূর্তে**। তাই ভুল নামের দাম দিতে হয়
 * চালানোর সময়, আর কেবল সেই পথে যেখানে লাইনটা সত্যিই চলে।
 *
 * ── এই পরীক্ষাটা যা করে ──────────────────────────────────────────────
 * প্রতিটা ব্লেড ও PHP ফাইলে লেখা `view('module::path')` সূত্র খুঁজে
 * বের করে, আর Laravel-কেই জিজ্ঞেস করে ভিউটা আদৌ আছে কি না। **ডেটা
 * লাগে না, রেন্ডার লাগে না** — তাই খালি মেশিনেও ধরা পড়ে।
 */
class EveryViewAScreenAsksForExistsTest extends TestCase
{
    public function test_no_screen_asks_for_a_view_that_is_not_there(): void
    {
        $missing = [];
        $seen = 0;

        foreach ($this->sourceFiles() as $path) {
            $source = File::get($path);

            /*
             * মন্তব্য ছেঁটে নেওয়া — ব্যাখ্যায় একটা ভিউয়ের নাম লেখা
             * থাকতেই পারে ("আগে এখানে … ছিল, কেন সরানো হলো"), আর
             * সেটা ভুল নয়, সেটাই ব্যাখ্যা।
             */
            $source = (string) preg_replace('#/\*.*?\*/#su', '', $source);
            $source = (string) preg_replace('/\{\{--.*?--\}\}/su', '', $source);

            /*
             * শুধু `module::path` ধরনের সূত্র — কোরের সাধারণ ভিউ
             * (`dashboard.module`) নয়। ⚠️ কারণ মডিউল সরানোর সময়
             * এই namespace-ওয়ালাগুলোই ভাঙে, আর ওখানেই ফাঁদটা।
             */
            preg_match_all(
                "/view\(\s*'([a-z_]+::[a-zA-Z0-9_.\-]+)'/",
                $source,
                $found,
            );

            foreach (array_unique($found[1]) as $name) {
                $seen++;

                if (! View::exists($name)) {
                    $missing[] = basename($path).": {$name}";
                }
            }
        }

        // মাপটা নিজেই ভেঙে গেলে যেন চুপচাপ সবুজ না থাকে
        $this->assertGreaterThan(5, $seen, 'একটাও ভিউ-সূত্র পড়া যায়নি — regex বদলে গেছে?');

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['এই পর্দাগুলো এমন ভিউ চায় যা নেই — পাতাটা ৫০০ দেবে,',
                'আর সম্ভবত কেবল সেখানেই যেখানে ডেটা আছে:'],
            $missing,
        )));
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $out = [];

        foreach ([base_path('app'), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }
}
