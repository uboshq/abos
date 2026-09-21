<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `{{-- --}}` টেমপ্লেটের মন্তব্য — PHP-র ভিতরে ওটা মন্তব্য নয়, ত্রুটি।
 *
 * ── ⛔ কী ঘটেছিল, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * মজুদের পাতায় একটা লিংক সারিয়ে ব্যাখ্যাটা `{{-- --}}` দিয়ে লেখা হলো,
 * অথচ জায়গাটা ছিল একটা `@php` ব্লকের ভিতরের PHP অ্যারে। ⚠️ ফল:
 * **গোটা পাতাটা ৫০০**, আর ধরা পড়েছে অন্য একজনের চোখে।
 *
 * ── ⓘ কেন Blade ওটা মুছে দেয় না ───────────────────────────────────────
 * Blade কম্পাইল করার আগে `@php … @endphp`-এর ভিতরটা **আলাদা করে তুলে
 * রাখে** (`storePhpBlocks`), আর মন্তব্য-মোছার ধাপটা তখন ওখানে পৌঁছায়ই
 * না। ⭐ তাই টেমপ্লেটের অন্য যেকোনো জায়গায় `{{-- --}}` নিরীহ, কেবল
 * এখানেই সেটা হুবহু PHP-তে বসে যায় — আর `{{` দিয়ে কোনো PHP বাক্য শুরু
 * হতে পারে না।
 *
 * ── ⚠️ কেন সোর্স-পড়া পাহারাগুলো এটা ধরত না ────────────────────────────
 * ⓘ ওরা ফাইলটা **পড়ে**, রেন্ডার করে না। আর যে পাহারা পাতা খোলে
 * ([[NoLeakedBladeTest]]) সে খোঁজে ফাঁস হওয়া সোর্স — একটা ৫০০-র পাতায়
 * ফাঁস হওয়া সোর্স থাকে না, থাকে ত্রুটির পাতা। ⛔ তাই সে চুপ করে পাশ
 * করত।
 *
 * ⭐ পাশের [[EveryMenuPageOpensBeforeItIsDeployedTest]] এটা ধরে **পাতা
 * ভাঙার পর**; এই পাহারাটা ধরে **লেখার সময়**, আর নামটাও বলে দেয়।
 */
class ABladeCommentInsidePhpIsNotACommentTest extends TestCase
{
    /**
     * `@php … @endphp` ব্লকগুলো — ভিতরের লেখাটুকু।
     *
     * ⓘ `@php(...)` এক-লাইনের রূপটা এখানে আসে না: ওটা এক্সপ্রেশন, আর
     * তার ভিতরে মন্তব্য লেখার জায়গাই নেই।
     */
    private const BLOCK = '/@php\b(?!\s*\()(.*?)@endphp/s';

    public function test_no_blade_comment_hides_inside_a_php_block(): void
    {
        $broken = [];
        $blocks = 0;

        foreach ($this->blades() as $path) {
            preg_match_all(self::BLOCK, File::get($path), $found);

            foreach ($found[1] as $body) {
                $blocks++;

                if (str_contains($this->withoutPhpComments($body), '{{--')) {
                    $broken[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }

        /*
         * ⭐ "কিছু নেই" তখনই অর্থবহ, যখন জানা থাকে সত্যিই খোঁজা হয়েছে —
         * আর ছাঁকনিটা খুঁজে পেতে পারে।
         */
        $this->assertGreaterThan(
            200,
            $blocks,
            "কেবল {$blocks}টা `@php` ব্লক পড়া হয়েছে — তালিকা খালি, কারণ খোঁজাই হয়নি।",
        );

        $this->assertSame(
            1,
            preg_match(self::BLOCK, "@php\n    \$x = [\n        {{-- ব্যাখ্যা --}}\n    ];\n@endphp", $sample),
            'ছাঁকনিটা একটা সত্যিকারের `@php` ব্লকও চিনতে পারে না।',
        );

        $this->assertStringContainsString('{{--', $sample[1], 'নমুনার ভিতরের মন্তব্যটাই ধরা পড়ছে না।');

        $broken = array_values(array_unique($broken));
        sort($broken);

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['এই ফাইলগুলোয় `{{-- --}}` একটা `@php` ব্লকের ভিতরে বসে আছে:', ''],
            $broken,
            [
                '',
                '⚠️ ওখানে ওটা মন্তব্য নয় — Blade `@php`-র ভেতরটা আলাদা করে',
                'তুলে রাখে, তাই লেখাটা হুবহু PHP-তে পৌঁছায় আর পাতা ৫০০ দেয়।',
                '',
                'PHP-র মন্তব্য লিখুন: `/* … */` বা `//`।',
            ],
        )));
    }

    /**
     * PHP-র নিজের মন্তব্যগুলো ছেঁটে ফেলা।
     *
     * ── ⚠️ কেন, আর এটা প্রথম চালেই ধরা পড়েছে ──────────────────────────
     * ⛔ পাহারাটা বসানোর সাথে সাথেই একটা **মিথ্যা অভিযোগ** করল: যে
     * ফাইলটা এইমাত্র সারানো হয়েছে, তার `/* … *​/` ব্যাখ্যার ভিতরে
     * লেখা ছিল *"`{{-- --}}` কেবল টেমপ্লেটের জায়গায় চলে"* — অর্থাৎ
     * ভুলটার **বর্ণনা**, ভুলটা নয়।
     *
     * ⓘ মিথ্যা অভিযোগ করা পাহারা মিস করা পাহারার চেয়ে ছোট সমস্যা নয়:
     * কয়েক দিনেই তাকে ছাড় দেওয়া হয়, আর তখন সে আসলটাও ধরে না।
     */
    private function withoutPhpComments(string $body): string
    {
        $body = (string) preg_replace('#/\*.*?\*/#su', '', $body);

        return (string) preg_replace('#//[^\n]*#', '', $body);
    }

    /** @return list<string> */
    private function blades(): array
    {
        $out = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }
}
