<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * একটা অ্যাপস্ট্রফি, আর গোটা পাতা ৫০০।
 *
 * ── ⛔ কী ঘটেছিল, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * কার্ডের ঘরের লেখা যোগ করতে গিয়ে ইংরেজি ফাইলে বসেছিল:
 *
 *     'card_bank' => 'Card's bank',
 *
 * ⓘ PHP একক কোটে অ্যাপস্ট্রফি পেলে স্ট্রিংটা **ওখানেই শেষ** করে, আর
 * পরের অক্ষরগুলো অর্থহীন হয়ে যায়। ফল: `ParseError`, আর যে **কোনো** পাতা
 * ঐ ফাইলের একটা চাবি চাইলেই ৫০০।
 *
 * ── ⚠️ কেন এটা একটা বড় ফাঁদ ─────────────────────────────────────────
 * ভাষার ফাইল দেখতে তথ্য, কোড নয় — তাই "শুধু একটা লাইন যোগ করছি" ভেবে
 * কেউ `php -l` চালায় না। ⛔ কিন্তু ওগুলো PHP ফাইল, আর একটা ভাঙা ফাইল
 * **গোটা ভাষাটাই** অচল করে দেয়, কেবল ঐ চাবিটা নয়।
 *
 * ⓘ আর ভুলটা লেখার সময় চোখেও পড়ে না: `'Card's bank'` পড়তে গিয়ে মানুষ
 * বাক্যটা পড়ে, কোটগুলো গোনে না।
 *
 * ── ⭐ কেন বাকি পাহারাগুলো এটা ধরেনি ────────────────────────────────
 * [[BothLanguagesSayTheSameThingTest]] চাবি মেলায় — কিন্তু ফাইলটা
 * পার্সই না হলে সে চাবি পড়তেই পারে না, আর তখন **সে নিজেই ভেঙে পড়ে**
 * অন্য একটা বার্তা নিয়ে। ⚠️ ভাঙা ফাইলের আসল কারণটা ঐ বার্তায় থাকে না।
 *
 * ⭐ তাই এই পরীক্ষাটা সবার আগে, আর সবচেয়ে সরল প্রশ্নটাই করে: **ফাইলটা
 * কি PHP হিসেবে পড়া যায়?**
 */
final class EveryLanguageFileStillParsesTest extends TestCase
{
    /**
     * ⛔ প্রতিটা ভাষার ফাইল পার্স হয়, আর একটা অ্যারে ফেরায়।
     */
    public function test_every_language_file_parses(): void
    {
        $broken = [];
        $checked = 0;

        foreach ($this->languageFiles() as $path) {
            $checked++;

            /*
             * ⓘ `include` নয়, `php -l` — কারণ include করলে ভাঙা ফাইলটা
             * এই প্রক্রিয়াটাকেই মেরে ফেলত, আর তখন কোন ফাইলে সমস্যা তা
             * বলার কেউ থাকত না। ⚠️ পরীক্ষাটা ব্যর্থ হত একটা fatal দিয়ে,
             * আর তালিকাটা কখনো ছাপা হত না।
             */
            $output = [];
            $status = 0;
            exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);

            if ($status !== 0) {
                $broken[] = $this->shortPath($path).' — '.trim(implode(' ', $output));
            }
        }

        /*
         * ⚠️ শূন্য সংগ্রহে দাবিটা সবসময় সবুজ — আজ একাধিকবার এই ফাঁদে
         * পড়া হয়েছে, তাই সংখ্যাটা আগে দাবি করা।
         */
        $this->assertGreaterThan(100, $checked,
            'একটাও ভাষার ফাইল পাওয়া গেল না — খোঁজাটা কি আর কাজ করছে?');

        sort($broken);

        $this->assertSame([], $broken, implode("\n", [
            'এই ভাষার ফাইলগুলো PHP হিসেবে পড়া যায় না:',
            '',
            '⛔ একটা ভাঙা ফাইল গোটা ভাষাটাই অচল করে — যে কোনো পাতা ঐ',
            'ফাইলের একটা চাবি চাইলেই ৫০০।',
            '',
            'ⓘ সবচেয়ে সাধারণ কারণ: একক কোটের ভিতরে অ্যাপস্ট্রফি —',
            "    'Card's bank'   ⛔      'The card\\'s bank'   ✅",
            '',
            ...$broken,
        ]));
    }

    /**
     * ⭐ প্রতিটা ফাইল একটা অ্যারে ফেরায়, অন্য কিছু নয়।
     *
     * ⓘ পার্স হওয়া আর কাজের হওয়া এক নয়: কেউ `return;` লিখে ফেললে
     * ফাইলটা দিব্যি পার্স হয়, আর `__()` চুপচাপ চাবিটাই ছাপাতে থাকে।
     * ⚠️ পাতা ২০০ দেয়, কিছুই ভাঙে না — কেবল পর্দায় `accounts::field.branch`
     * লেখা দেখা যায়।
     */
    public function test_every_language_file_returns_an_array(): void
    {
        $wrong = [];

        foreach ($this->languageFiles() as $path) {
            $value = require $path;

            if (! is_array($value)) {
                $wrong[] = $this->shortPath($path).' — '.get_debug_type($value);
            }
        }

        sort($wrong);

        $this->assertSame([], $wrong, implode("\n", [
            'এই ফাইলগুলো অ্যারে ফেরায় না:',
            '',
            '⚠️ পার্স হয় বলে কোনো ত্রুটি আসে না — কেবল প্রতিটা চাবির',
            'জায়গায় চাবির নামটাই ছাপা হয়।',
            '',
            ...$wrong,
        ]));
    }

    /**
     * @return list<string>
     */
    private function languageFiles(): array
    {
        $paths = [];

        foreach (File::allFiles(base_path('lang')) as $file) {
            $paths[] = $file->getPathname();
        }

        foreach (File::directories(app_path('Modules')) as $module) {
            $lang = $module.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'lang';

            if (! File::isDirectory($lang)) {
                continue;
            }

            foreach (File::allFiles($lang) as $file) {
                $paths[] = $file->getPathname();
            }
        }

        return array_values(array_filter($paths, fn (string $p): bool => str_ends_with($p, '.php')));
    }

    private function shortPath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
