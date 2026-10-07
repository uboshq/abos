<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * এক কাগজে দুই রকম অঙ্ক।
 *
 * ── ⛔ মালিক নিজে চোখে ধরেছেন, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────
 * ছাপা কাগজের **উপরে** প্রতিষ্ঠানের ফোন ইংরেজি অঙ্কে (ওটা ডেটা, যেমন
 * টাইপ করা তেমন), আর **নিচে** হটলাইন বাংলা অঙ্কে — ওটা অনুবাদের লেখায়
 * হাতে বসানো ছিল।
 *
 * ⓘ মালিকের সিদ্ধান্ত: **ইংরেজি অঙ্ক** (*"eng rako"*)। ⚠️ শব্দ বাংলাই
 * থাকে; বদলটা কেবল অঙ্কের।
 *
 * ── ⓘ কেন নিয়মটা এদিকেই যায় ──────────────────────────────────────────
 * মেপে দেখা গেছে গোটা রিপোতে **অঙ্ক বাংলায় বদলানোর কোনো কোডই নেই** —
 * টাকা, তারিখ, পরিমাণ, নথি নম্বর, সবই ইংরেজি অঙ্কে ছাপে। ⭐ অর্থাৎ
 * হাতে লেখা বাংলা অঙ্ক সবসময়ই **একমাত্র ব্যতিক্রম** হয়ে দাঁড়াবে।
 *
 * ⛔ আর হটলাইনটা ডায়াল করার জিনিস: বাংলা অঙ্কে লেখা নম্বর ফোনে তুলতে
 * হলে মানুষকে মনে মনে অনুবাদ করতে হয়।
 *
 * ── ⚠️ পর্দার গদ্য এই নিয়মের বাইরে ───────────────────────────────────
 * *"সর্বোচ্চ ১০ MB"* বা *"৩০ দিন চলবে"* — এগুলো বাংলা বাক্য, কাগজের
 * অঙ্ক নয়, আর ওখানে বাংলা অঙ্কই স্বাভাবিক। ⓘ তাই পাহারাটা কেবল
 * **ছাপার ব্লেডগুলো** যে চাবি ডাকে সেগুলোতেই তাকায়।
 */
final class OnePaperShouldNotCarryTwoKindsOfDigitsTest extends TestCase
{
    /** ⓘ বাংলা অঙ্ক ০–৯, ইউনিকোড U+09E6…U+09EF। */
    private const BENGALI_DIGIT = '/[\x{09E6}-\x{09EF}]/u';

    public function test_no_printed_line_is_written_in_bengali_digits(): void
    {
        $offenders = [];

        foreach ($this->printedStrings() as $key => $value) {
            if (preg_match(self::BENGALI_DIGIT, $value) === 1) {
                $offenders[] = $key.' → '.$value;
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['ছাপা কাগজের এই লেখাগুলোয় বাংলা অঙ্ক বসে আছে —'],
            array_map(static fn (string $one): string => '  '.$one, $offenders),
            [
                'মালিকের সিদ্ধান্ত (২২ সেপ্টেম্বর ২০২৬): কাগজে অঙ্ক ইংরেজিতে।',
                'শব্দ বাংলাই থাকুক, কেবল অঙ্কগুলো ইংরেজি করুন।',
            ],
        )));
    }

    /**
     * ⛔ পাহারাটা নিজে তাকায় কি না।
     *
     * ── ⚠️ এটা কল্পনা নয়, এই ফাইলটা লেখার দিনই ঘটেছে ────────────────
     * প্রথম খসড়ার সুইপটা `core.print.hotline` চাবিটা খুঁজত `print.php`
     * ফাইলে (`bits[-2]`), অথচ ওটা `core.php`-র ভিতরের চাবি। ⓘ ফলে সে
     * **কিছুই না পেয়ে** "একটাও নেই" বলত — আর ঠিক তখনই কাগজে একটা
     * বাংলা অঙ্ক বসে ছিল।
     *
     * ⭐ তাই দুইটা মাপ: চাবিগুলো সত্যিই মিলছে কি না, আর একটা বাংলা অঙ্ক
     * সামনে দিলে ধরা পড়ে কি না।
     */
    public function test_the_sweep_is_actually_reading_the_words(): void
    {
        $found = $this->printedStrings();

        $this->assertGreaterThan(
            20,
            count($found),
            'ছাপার চাবিগুলোর কোনো মানই মিলছে না — তাহলে উপরের পরীক্ষাটা শূন্য সংগ্রহে চলছে, '
            .'আর শূন্য সংগ্রহে চালানো assertion সবসময় সবুজ।'
        );

        // ⓘ হাতে বানানো একটা নমুনা — ধরা না পড়লে নিয়মটাই অকেজো।
        $this->assertSame(1, preg_match(self::BENGALI_DIGIT, 'হটলাইন ০১৯১১০৪৮১৮৫'));
        $this->assertSame(0, preg_match(self::BENGALI_DIGIT, 'হটলাইন 01911048185'));
    }

    /**
     * ছাপার ব্লেডগুলো যে চাবিগুলো ডাকে, আর তাদের বাংলা মান।
     *
     * @return array<string, string>
     */
    private function printedStrings(): array
    {
        $values = $this->bengaliWords();
        $found = [];

        foreach ($this->printBlades() as $blade) {
            $code = (string) file_get_contents($blade);

            preg_match_all("/__\(\s*'([a-zA-Z0-9_.:]+)'/", $code, $keys);

            foreach ($keys[1] as $key) {
                /*
                 * ⚠️ `core.print.hotline` মানে ফাইল `core.php`, ভিতরের
                 * চাবি `print.hotline` — অর্থাৎ **প্রথম** অংশটা ফাইল,
                 * শেষেরটা পাতা। ⛔ মাঝেরটাকে ফাইল ধরলে কিছুই মিলত না।
                 */
                $bare = str_contains($key, '::') ? substr(strrchr($key, ':'), 1) : $key;
                $bits = explode('.', $bare);

                if (count($bits) < 2) {
                    continue;
                }

                $slot = $bits[0].'|'.$bits[count($bits) - 1];

                if (isset($values[$slot])) {
                    $found[$key] = $values[$slot];
                }
            }
        }

        return $found;
    }

    /**
     * ⓘ প্রতিটা বাংলা অনুবাদ ফাইল সমতল করা — `ফাইল|পাতা` ধরে।
     *
     * @return array<string, string>
     */
    private function bengaliWords(): array
    {
        $files = array_merge(
            File::glob(base_path('lang/bn/*.php')),
            File::glob(app_path('Modules/*/Resources/lang/bn/*.php')),
        );

        $values = [];

        foreach ($files as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            $code = (string) file_get_contents($file);

            preg_match_all("/'([a-zA-Z0-9_]+)'\s*=>\s*'([^']*)'/", $code, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $values[$stem.'|'.$pair[1]] ??= $pair[2];
            }
        }

        return $values;
    }

    /**
     * ⓘ যে ব্লেডগুলো সত্যিই কাগজে যায়।
     *
     * @return list<string>
     */
    private function printBlades(): array
    {
        $blades = [];

        foreach (File::allFiles(base_path()) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }

            if (str_contains($path, '/print/') || str_starts_with($file->getFilename(), 'print')) {
                $blades[] = $file->getPathname();
            }
        }

        return $blades;
    }
}
