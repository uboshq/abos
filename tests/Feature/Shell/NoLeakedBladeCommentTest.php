<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * কোনো মন্তব্য যেন পাতায় ছাপা না হয়।
 *
 * ── কেন এই পাহারাটা লেখা হল ──────────────────────────────────────────
 * বিক্রয়ের তিনটা ফর্মের প্রথম লাইনে লেখা ছিল `{--`, `{{--` নয় — একটা
 * করে ব্রেস কম। Blade ওটাকে মন্তব্য বলে চেনেনি, তাই পুরো লেখাটা হুবহু
 * HTML-এ চলে গিয়েছিল: "{-- বিক্রয় আদেশ — তৈরি ও সম্পাদনা…"।
 *
 * চোখে পড়েনি, কারণ লেখাটা পাতার একদম উপরে আর রঙের কারণে প্রায় অদৃশ্য।
 * কিন্তু পর্দা-পাঠক ওটা পড়ে শোনায় — অর্থাৎ যিনি চোখে দেখেন না, তিনি
 * প্রতিটা বিক্রয় পর্দা খুললেই আগে ডেভেলপারের নোট শুনতেন।
 *
 * ── কেন খোঁজা হয় উৎসে, রেন্ডার করা পাতায় নয় ────────────────────────
 * রেন্ডার করে দেখতে হলে প্রতিটা পর্দা খুলতে হত, আর যে পর্দায় যাওয়া
 * হয়নি সেখানকার ভুল থেকেই যেত। উৎসে খুঁজলে একটাও বাদ যায় না।
 */
class NoLeakedBladeCommentTest extends TestCase
{
    public function test_no_view_carries_a_half_written_comment_marker(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file) ?: '';

            /*
             * `{--` যেটার আগে আরেকটা `{` নেই — অর্থাৎ ভাঙা শুরু।
             * আর `--}` যেটার পরে আরেকটা `}` নেই — ভাঙা শেষ।
             *
             * ── পরে/আগে ফাঁকা জায়গা কেন খোঁজা হয় ────────────────────
             * CSS-এর কাস্টম প্রপার্টিও দেখতে একইরকম: `{--tw-shadow:…`।
             * প্রথম চেষ্টায় এই পাহারাটা Laravel-এর নিজের welcome পাতাকে
             * দোষ দিয়েছিল, কারণ ওতে ইনলাইন Tailwind CSS আছে।
             *
             * তফাতটা সরল: Blade মন্তব্যের পরে ফাঁকা জায়গা আসে
             * (`{-- বিক্রয় আদেশ`), CSS প্রপার্টির পরে অক্ষর (`{--tw`)।
             *
             * মিথ্যা অভিযোগ করা পাহারা সবচেয়ে খারাপ — কয়েকদিনেই সবাই
             * ওটাকে উপেক্ষা করতে শেখে, আর তখন আসল ভুলটাও পার হয়ে যায়।
             */
            $badOpen = preg_match('/(?<!\{)\{--\s/', $source) === 1;
            $badClose = preg_match('/\s--\}(?!\})/', $source) === 1;

            if ($badOpen || $badClose) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "এই ভিউগুলোয় Blade মন্তব্যের চিহ্ন ভাঙা ({--, {{-- নয়)।\n"
            ."Blade ওটা মন্তব্য বলে চেনে না, তাই পুরো লেখাটা পাতায় ছাপা হয় —\n"
            .'চোখে হয়তো পড়ে না, কিন্তু পর্দা-পাঠক পড়ে শোনায়।');
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
