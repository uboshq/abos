<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * কোনো পর্দা এক হাজার লাইন ছাড়িয়ে বাড়ে না।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৪.১, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"দুই কাউন্টার পর্দা ভাগ করা… লক্ষ্য: প্রতিটি ব্লেড ফাইল ১,০০০ লাইনের
 * নিচে।"*
 *
 * ⓘ তখন বিক্রয়ের কাউন্টার **৪,৪১৩ লাইন**, ক্রয়ের **৩,৭৩৫**। ১৯ সেপ্টেম্বর
 * ২০২৬-এ দুইটাই ভাগ হয়েছে — JS মডিউলে, আর বাকিটা partial-এ — আর এখন
 * অ্যাপের **একটা ব্লেডও** এক হাজার ছাড়ায় না।
 *
 * ── ⚠️ কেন লক্ষ্যটা পূরণ হওয়ার পরেও পাহারা লাগে ──────────────────────
 * পর্দাগুলো এক দিনে ৪,৪১৩ লাইনে পৌঁছায়নি। ⛔ প্রতিটা বদলে কয়েকটা লাইন
 * যোগ হয়েছে, প্রতিটা বদল যুক্তিসঙ্গত ছিল, আর কেউ কোনোদিন থেমে বলেননি
 * "এটা এখন খুব বড়" — কারণ কোনো একটা দিন সেটা বলার মতো ছিল না।
 *
 * ⭐ তাই সীমাটা যন্ত্রের হাতে। ⓘ ছাড়ালে দাবিটা লাল হয় **ঠিক সেই বদলে**,
 * যখন ভাগ করা সহজ — চার হাজার লাইনে পৌঁছানোর পরে নয়।
 *
 * ── ⓘ কেন এক হাজার, আর কেন ফাইলপ্রতি ────────────────────────────────
 * সংখ্যাটা নিরীক্ষার, আমাদের নয়। ⚠️ আর মাপটা ফাইলপ্রতি, কারণ কষ্টটা ফাইলে:
 * একটা ৪,০০০ লাইনের ফাইলে কেউ খুঁজে পান না কোন `x-data` কোন `</div>`-এর
 * ভিতরে, আর ঠিক সেখানেই একই বাগ চার জায়গায় ঢুকেছিল (কমিট 9197153)।
 */
final class NoScreenGrowsPastAThousandLinesTest extends TestCase
{
    private const CEILING = 1000;

    /**
     * ⭐ কোনো ব্লেড সীমা ছাড়ায় না।
     */
    public function test_no_blade_is_longer_than_the_ceiling(): void
    {
        $over = [];

        foreach ($this->blades() as $path => $lines) {
            if ($lines > self::CEILING) {
                $over[] = sprintf('%5d  %s', $lines, $path);
            }
        }

        $this->assertSame([], $over, sprintf(
            "এই পর্দাগুলো %d লাইন ছাড়িয়েছে:\n%s\n\n".
            "⭐ ভাগ করার সহজ পথ — একটা সম্পূর্ণ `<section>` বা `<aside>`\n".
            "   `partials/`-এ সরিয়ে `@include` দিয়ে ডাকুন। ⓘ partial মূল পাতার\n".
            "   সব চলক পায়, তাই আচরণ বদলায় না; কেবল `@php`-তে বানানো চলক\n".
            "   যেন একই partial-এর ভিতরেই ব্যবহার হয়।\n\n".
            '⚠️ দুই কাউন্টার (Sales/Purchase direct) কীভাবে ভাগ হয়েছে সেটাই নমুনা।',
            self::CEILING,
            implode("\n", $over),
        ));
    }

    /**
     * ⓘ সেটআপের দাবি — স্ক্যানারটা সত্যিই ব্লেড পড়ছে তো?
     *
     * ⚠️ পথটা একদিন বদলালে গোনাটা শূন্য হত, আর উপরের দাবিটা **চিরকাল
     * সবুজ** থাকত — কিছুই না দেখে।
     */
    public function test_the_scanner_actually_reads_the_screens(): void
    {
        $blades = $this->blades();

        $this->assertGreaterThan(200, count($blades),
            'ব্লেড এত কম হতে পারে না — স্ক্যানারটাই কিছু খুঁজে পাচ্ছে না।');

        $this->assertGreaterThan(500, max($blades),
            'সবচেয়ে বড় ব্লেডও ৫০০ লাইনের কম — গোনাটাই ভুল।');
    }

    /** @return array<string, int> পথ → লাইন */
    private function blades(): array
    {
        $found = [];

        foreach ([base_path('app'), base_path('resources/views')] as $root) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                $found[$relative] = substr_count((string) file_get_contents($file->getPathname()), "\n") + 1;
            }
        }

        return $found;
    }
}
