<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ⛔ ভাউচার ফর্ম নিজের JavaScript পাতায় ছাপছিল — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── কী দেখা গিয়েছিল ──────────────────────────────────────────────────
 * মালিক Receipt Voucher পাতাটা খুলে দেখেন তারিখের ঘরের নিচে কাঁচা কোড:
 * `r.ok ? r.json() : null) .then(d ⇒ { this.due = …` — আর তারপর Payment
 * Voucher-এও একই জিনিস।
 *
 * ── কারণটা একটা মন্তব্য ──────────────────────────────────────────────
 * `simple-form.blade.php`-এর ১২৪ নম্বর লাইনে `x-data="{` শুরু, আর ২১০-এ
 * শেষ হওয়ার কথা। ⛔ কিন্তু ১৫৫ নম্বর লাইনের **মন্তব্যের ভিতরে** একটা
 * সাধারণ ডবল কোট ছিল:
 *
 *     * সরবরাহকারীর ঘরে "−৫,০০০" বসত আর কেউ ভাবতেন
 *
 * ⓘ ব্রাউজারের কাছে মন্তব্য বলে কিছু নেই — ওটা একটা HTML অ্যাট্রিবিউট,
 * আর প্রথম `"`-তেই সেটা **শেষ**। বাকি ৫৫ লাইন JS তখন পাতার লেখা।
 *
 * ── ⚠️ কেন এটা ধরা কঠিন ──────────────────────────────────────────────
 * ⛔ কোনো ত্রুটি হয় না। Blade কম্পাইল করে, সার্ভার ২০০ ফেরে, লগ ফাঁকা,
 * পরীক্ষাগুলো সবুজ। ⓘ একমাত্র লক্ষণ হলো **পাতায় তাকানো**।
 *
 * ⚠️ আর এটা আজ **দ্বিতীয়বার**: এর আগে একটা ব্লেড মন্তব্যের ভিতরে লেখা
 * `@php` ফুটারের ৯৯ লাইন গিলে ফেলেছিল, আর তার আগে একটা ডকব্লকের ভিতরে
 * `*​/` মন্তব্যটা তাড়াতাড়ি বন্ধ করে দিয়েছিল। ⓘ রিপোর ইতিহাসেও আছে —
 * কমিট `7f1c6c43`, *"a comment that printed itself"*।
 *
 * ⭐ তিনবার একই আকারের ভুল মানে ওটা মনে রাখার জিনিস নয়, **মাপার** জিনিস।
 *
 * ── কীভাবে মাপা হয় ───────────────────────────────────────────────────
 * অ্যাট্রিবিউটের শুরু থেকে পরের `"` পর্যন্ত যা পাওয়া গেল, তাতে `{` আর
 * `}` সমান কি না। ⓘ অসমান মানে অ্যাট্রিবিউটটা নিজের শেষের আগেই থেমেছে।
 *
 * ⭐ `@js()` এখানে নিরাপদ, তাই আলাদা ছাড় দিতে হয়নি: Laravel-এর
 * `Js::from()` ডবল কোটকে `"` করে দেয়।
 */
class NoAttributeEndsBeforeItMeansToTest extends TestCase
{
    /**
     * Alpine-এর যেসব অ্যাট্রিবিউটে সাধারণত JS থাকে।
     *
     * @var list<string>
     */
    private const WATCHED = ['x-data', 'x-init', 'x-effect', 'x-model', 'x-show', 'x-bind', 'x-on'];

    public function test_no_alpine_attribute_is_cut_short_by_a_quote_inside_it(): void
    {
        $cut = [];
        $counted = 0;

        foreach ($this->bladeFiles() as $path) {
            foreach ($this->cutAttributesIn((string) file_get_contents($path), $counted) as $report) {
                $cut[] = $this->relative($path).' — '.$report;
            }
        }

        /*
         * ⭐ প্রথমে গুনতির দাবি, তারপর ফলাফলের।
         *
         * ⚠️ এই লাইনটা না থাকলে পরীক্ষাটা একদিন সবুজ থাকত **অথচ অন্ধ** —
         * কেউ ফোল্ডারের নাম বদলালে বা glob ভাঙলে "কিছু পাইনি" আর "সব
         * ঠিক আছে" দেখতে একই রকম। ⓘ আজ রাতেই এই রিপোতে ৪৪৬টা আছে।
         */
        $this->assertGreaterThan(
            300,
            $counted,
            'এত কম Alpine অ্যাট্রিবিউট পাওয়া গেল যে সন্দেহ হয় পরীক্ষাটা আসলে কিছুই দেখছে না।',
        );

        $this->assertSame([], $cut, implode("\n", [
            'একটা অ্যাট্রিবিউট নিজের শেষের আগেই থেমে গেছে — ভিতরে একটা সাধারণ " আছে।',
            'লক্ষণ: পাতায় কাঁচা JavaScript ছাপা হবে, কোনো ত্রুটি ছাড়া।',
            'সারাই: মন্তব্য বা লেখার কোটগুলো “ ” করুন, নাহলে একক কোট।',
            ...$cut,
        ]));
    }

    /**
     * ⭐ পাহারাটা নিজে দেখে কি না — একটা জানা-ভাঙা নমুনায়।
     *
     * ⚠️ উপরের পরীক্ষাটা সবুজ থাকে **দুইটা কারণে**: সত্যিই সব ঠিক, নয়তো
     * খোঁজাটাই কাজ করছে না। ⓘ দুইটাকে আলাদা করার একমাত্র উপায় হলো
     * ইচ্ছাকৃতভাবে ভাঙা একটা নমুনা দেখানো আর দাবি করা যে ওটা ধরা পড়ে।
     */
    public function test_the_guard_actually_sees_the_bug_it_was_written_for(): void
    {
        // ⓘ হুবহু সেই আকার যেটা ভাউচার ফর্মে ছিল: মন্তব্যের ভিতরে ডবল কোট।
        $broken = <<<'BLADE'
            <section x-data="{
                due: null,

                /*
                 * সরবরাহকারীর ঘরে "−৫,০০০" বসত আর কেউ ভাবতেন
                 */
                load() { this.due = 1; },
            }">
            </section>
            BLADE;

        $counted = 0;

        $this->assertNotSame(
            [],
            $this->cutAttributesIn($broken, $counted),
            'জানা-ভাঙা নমুনাটাও ধরা পড়ল না — অর্থাৎ উপরের সবুজটা মূল্যহীন।',
        );

        // আর সারানো রূপটা যেন মিথ্যা অভিযোগ না তোলে
        $counted = 0;

        $this->assertSame([], $this->cutAttributesIn(str_replace(['"−৫,০০০"'], ['“−৫,০০০”'], $broken), $counted));
    }

    /**
     * @return list<string>
     */
    private function cutAttributesIn(string $source, int &$counted): array
    {
        $cut = [];

        foreach (self::WATCHED as $name) {
            $offset = 0;

            while (($start = strpos($source, $name.'="', $offset)) !== false) {
                $valueStart = $start + strlen($name) + 2;
                $end = strpos($source, '"', $valueStart);
                $offset = $end === false ? $valueStart : $end + 1;
                $counted++;

                if ($end === false) {
                    continue;
                }

                $value = substr($source, $valueStart, $end - $valueStart);

                if (substr_count($value, '{') === substr_count($value, '}')) {
                    continue;
                }

                $cut[] = sprintf(
                    '%s শুরু লাইন %d, থেমেছে লাইন %d',
                    $name,
                    substr_count(substr($source, 0, $start), "\n") + 1,
                    substr_count(substr($source, 0, $end), "\n") + 1,
                );
            }
        }

        return $cut;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([base_path('resources/views'), base_path('app/Modules')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
