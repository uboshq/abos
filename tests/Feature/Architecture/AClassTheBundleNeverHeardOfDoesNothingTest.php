<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * বান্ডিলে নেই এমন শ্রেণি লেখা মানে কিছুই না লেখা।
 *
 * ── ⛔ কী ঘটেছিল, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * মালিক বললেন একটা ড্রপডাউন ছোট করতে। `sm:w-56` লিখলাম, পর্দা খুললাম —
 * **কিছুই বদলায়নি**। ⓘ কোনো ত্রুটি নয়, কোনো সতর্কবার্তা নয়; ক্লাসটা
 * HTML-এ দিব্যি বসে আছে, আর ব্রাউজার সেটা চুপচাপ উপেক্ষা করছে।
 *
 * ⚠️ কারণ CSS **আগে থেকে বিল্ড করা** — লাইভে node নেই, তাই বান্ডিলটা
 * গিটে কমিট করা থাকে। ⛔ Tailwind বিল্ডের সময় যে শ্রেণিগুলো দেখেছিল
 * কেবল সেগুলোই ওতে আছে; পরে লেখা যেকোনো নতুন শ্রেণি **নীরবে মৃত**।
 *
 * ⭐ আর এটাই আজ রাতের বারবার ফিরে আসা ছাঁচ: **কাজটা হয়নি, অথচ কোথাও
 * কিছু লাল নয়**। ⓘ একই পরিবারের বাকিগুলো —
 * [[ADirectiveInsideAComponentTagStopsItCompilingTest]] (ট্যাগ কম্পাইল
 * হয় না) আর [[ABladeCommentInsidePhpIsNotACommentTest]]।
 *
 * ── ⚠️ কেন পুরো `class="…"` ভেঙে দেখা হয় না ──────────────────────────
 * ব্লেডে শ্রেণি প্রায়ই **যুক্ত হয়ে** তৈরি হয়: `text-{{ $tone }}`,
 * `@class([...])`, বা চলকের ভিতরে পুরো তালিকা। ⛔ ওগুলো ভেঙে প্রতিটা
 * শব্দ খুঁজলে শত শত মিথ্যা অভিযোগ আসত, আর তখন কেউ পাহারাটাকেই ছাড়ের
 * তালিকায় ফেলে দিত — যেমনটা ঘটেছিল ঘড়ির পাহারার বেলায়, আর তাতে তার
 * **আসল** দুইটা অন্ধ জায়গা মাসখানেক ঢাকা পড়েছিল।
 *
 * ⭐ তাই এখানে কেবল আক্ষরিক `class="…"` দেখা হয়, আর ভিতরে `{{ }}` বা
 * `@` থাকলে গোটা ঘরটাই বাদ — "জানি না" বলাই সৎ।
 */
class AClassTheBundleNeverHeardOfDoesNothingTest extends TestCase
{
    /**
     * যেসব উপসর্গ দেখলে বোঝা যায় শ্রেণিটা Tailwind-এর।
     *
     * ⓘ হাতে লেখা শ্রেণি (`ui-list`, `bottom-nav-item`, `num`) app.css-এ
     * থাকে, আর সেগুলোও বান্ডিলে আছে — তাই আলাদা করার দরকার নেই।
     * ⚠️ কিন্তু Alpine-এর `x-`, আর ডেটা-অ্যাট্রিবিউটের নাম শ্রেণি নয়।
     *
     * @var list<string>
     */
    private const SKIP = ['x-', 'js-', 'data-'];

    /**
     * আজ যেগুলো সত্যিই মৃত — আর কেন এখনো আছে।
     *
     * ── ⚠️ এই তালিকাটা "ছাড়" নয়, একটা **খাতা** ───────────────────────
     * প্রতিটা সারি একটা সত্যিকারের ফাঁক: শ্রেণিটা ব্লেডে লেখা, আর
     * ব্রাউজার সেটা উপেক্ষা করে। ⛔ সারানোর পথ একটাই — **বান্ডিল নতুন
     * করে বিল্ড করা**, আর সেটা এই সেশনের হাতে নেই (লাইভে node নেই, বিল্ড
     * করে সমন্বয়কারী)। ⓘ বিল্ডের পর সারিগুলো একে একে উঠে যাবে, আর তখন
     * পাহারাটা নিজেই বলবে কোনটা এখনো বাকি।
     *
     * ⚠️ তালিকা বড় হতে থাকলে সেটাই খবর: মানে বান্ডিল আর ব্লেড আলাদা
     * পথে হাঁটছে।
     *
     * @var array<string, string>
     */
    private const STALE = [
        /*
         * ⓘ আজ তালিকাটা খালি — আর সেটাই স্বাভাবিক অবস্থা।
         *
         * ⚠️ ২১ সেপ্টেম্বর ২০২৬-এ এখানে সাতটা সারি ছিল, আর সবগুলোই
         * সারানো হয়েছে: `no-print` → `print-hide` (নিয়মটা app.css-এ
         * আছেই), `shell` তুলে দেওয়া (কোনো সংজ্ঞাই ছিল না), আর দুইটা
         * `2xl:` সারি বাদ — পাশে `lg:` আছেই, তাই বড় পর্দায় কেবল
         * বাড়তি সাজটুকু যায়, ছকটা নয়।
         *
         * ⭐ কোনো শ্রেণি যদি সত্যিই বান্ডিলের অপেক্ষায় থাকে, সেটা এখানে
         * **কারণসহ** লিখুন — ছাড় হিসেবে নয়, খাতা হিসেবে। ⚠️ আর বিল্ডের
         * পর ওটা বান্ডিলে ফিরে এলে নিচের দাবিটা লাল হয়ে বলবে "তুলে দিন"।
         */
    ];

    public function test_every_class_a_blade_writes_exists_in_the_bundle(): void
    {
        $known = $this->bundleClasses();

        $this->assertGreaterThan(
            500,
            count($known),
            'বান্ডিল থেকে যথেষ্ট শ্রেণি পড়া যায়নি — পাহারাটা তখন সবকিছুকেই "নেই" বলত।',
        );

        $missing = [];
        $looked = 0;

        foreach ($this->blades() as $path) {
            $source = File::get($path);

            /*
             * ⚠️ আক্ষরিক `class="…"` মাত্র — `:class="…"` বা
             * `x-bind:class="…"` নয়।
             *
             * ⛔ প্রথম খসড়ায় নোঙরটা ছিল `\bclass="`, আর সেটা Alpine-এর
             * `:class="busy && 'opacity-70'"`-ও ধরে ফেলত: `&&`, `busy`,
             * `'opacity-70'` — তিনটাকেই "শ্রেণি" ভেবে অভিযোগ করত
             * (২১ সেপ্টেম্বর ২০২৬, প্রথম চালেই)। ⓘ ওগুলো চলার সময়ে
             * তৈরি হয়, আর এখানে বসে বলা যায় না কী হবে।
             */
            preg_match_all('/(?<![:\w-])class="([^"]*)"/', $source, $found);

            foreach ($found[1] as $list) {
                /*
                 * ⚠️ ভিতরে ব্লেড বা নির্দেশ থাকলে গোটা ঘরটা বাদ — শ্রেণিটা
                 * চলার সময়ে তৈরি হয়, আর তখন এখানে বসে বলা যায় না সে কী
                 * হবে। ⓘ অর্ধেক জেনে অভিযোগ করার চেয়ে না করা ভালো।
                 */
                /*
                 * ⓘ `'` মানে ঘরটা আসলে একটা PHP স্ট্রিংয়ের টুকরা —
                 * `'<span class="text-2xs '.$tone.'">'` ধরনের লেখা।
                 * ⚠️ ওখানে শ্রেণিটা জোড়া লেগে তৈরি হয়, তাই বাদ; নইলে
                 * `.'` বা `text-sm'` কে শ্রেণি ভেবে অভিযোগ আসত (প্রথম
                 * চালে এসেছিলও)।
                 */
                if (str_contains($list, '{{') || str_contains($list, '@')
                    || str_contains($list, '$') || str_contains($list, "'")) {
                    continue;
                }

                foreach (preg_split('/\s+/', trim($list)) ?: [] as $class) {
                    if ($class === '' || $this->skipped($class)) {
                        continue;
                    }

                    $looked++;

                    if (isset(self::STALE[$class])) {
                        continue;
                    }

                    if (! isset($known[$class])) {
                        $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                        $missing[] = "{$short} — {$class}";
                    }
                }
            }
        }

        /*
         * ⭐ "কিছু নেই" তখনই অর্থবহ, যখন জানা থাকে সত্যিই খোঁজা হয়েছে —
         * আর তুলনাটা কাজ করে।
         */
        $this->assertGreaterThan(
            2000,
            $looked,
            "কেবল {$looked}টা শ্রেণি মেলানো হয়েছে — তালিকা খালি, কারণ খোঁজাই হয়নি।",
        );

        $this->assertArrayHasKey(
            'num',
            $known,
            'বান্ডিল থেকে চেনা শ্রেণিও পড়া যাচ্ছে না — তুলনাটাই ভাঙা।',
        );

        /*
         * ⭐ খাতাটা বাস্তবের সাথে মিলে থাকে — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ বান্ডিল নতুন করে বিল্ড হলে উপরের সারিগুলোর একটা-দুইটা কাজ
         * করতে শুরু করবে, আর তখন তালিকায় পড়ে থাকা নামটা **মিথ্যা** হয়ে
         * যাবে — পড়ে মনে হবে ফাঁকটা এখনো আছে। ⓘ তাই যেটা আর মৃত নয়,
         * সেটা তালিকা থেকেও উঠে যেতে বাধ্য।
         */
        $revived = [];

        foreach (array_keys(self::STALE) as $class) {
            if (isset($known[$class])) {
                $revived[] = $class;
            }
        }

        $this->assertSame([], $revived, implode("\n", array_merge(
            ['এই শ্রেণিগুলো এখন বান্ডিলে আছে — মৃতের খাতা থেকে তুলে দিন:', ''],
            $revived,
        )));

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['এই শ্রেণিগুলো ব্লেডে লেখা আছে, কিন্তু বিল্ড করা CSS-এ নেই:', ''],
            array_slice($missing, 0, 40),
            [
                '',
                '⚠️ ব্রাউজার ওগুলো চুপচাপ উপেক্ষা করে — কোনো ত্রুটি নয়, কেবল',
                '"কিছু হলো না"। লাইভে node নেই, তাই বান্ডিল যা জানে কেবল তাই চলে।',
                '',
                'হয় বান্ডিলে আছে এমন শ্রেণি বেছে নিন, নয় নতুন করে বিল্ড করান।',
            ],
        )));
    }

    /**
     * বিল্ড করা CSS-এ যে শ্রেণিগুলো সত্যিই আছে।
     *
     * ⓘ Tailwind বিশেষ অক্ষরগুলো `\` দিয়ে পালায় (`sm\:w-56`, `w-1\/2`),
     * তাই তুলনার আগে ওগুলো তুলে নেওয়া হয়।
     *
     * @return array<string, true>
     */
    private function bundleClasses(): array
    {
        $out = [];
        $sheets = File::glob(public_path('build/assets/*.css'));

        /*
         * ⭐ ছাপার পাতাগুলোর নিজের `<style>`-ও গোনা হয় — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ ওরা Tailwind ব্যবহার করে না: কাগজের ছকটা `print/layout.blade.php`-এর
         * ভিতরের একটা স্টাইল ব্লকে লেখা (`sig-line`, `grand`, `meta`…)।
         * ⚠️ কেবল বান্ডিল দেখলে পাহারাটা ঐ শ্রেণিগুলোকে "নেই" বলত — আর
         * সেটা হত খাঁটি মিথ্যা অভিযোগ।
         */
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')
                && str_contains(File::get($file->getPathname()), '<style')) {
                $sheets[] = $file->getPathname();
            }
        }

        foreach ($sheets as $file) {
            preg_match_all('/\.((?:[a-zA-Z0-9_-]|\\\\.)+)/', File::get($file), $found);

            foreach ($found[1] as $name) {
                $out[str_replace('\\', '', $name)] = true;
            }
        }

        return $out;
    }

    private function skipped(string $class): bool
    {
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
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
