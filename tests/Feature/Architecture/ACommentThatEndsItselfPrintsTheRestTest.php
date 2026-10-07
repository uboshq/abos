<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * একটা মন্তব্য নিজেকে শেষ করে দিলে বাকিটা পর্দায় ছাপা হয়।
 *
 * ── ⛔ কী ঘটেছিল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * `coa/form.blade.php`-এর মাথায় একটা ব্লেড মন্তব্যে লেখা হয়েছিল
 * *"`{{-- --}}` ব্রাউজারে যায় না"* — ব্যাখ্যা করতে গিয়ে, মন্তব্যের
 * **ভিতরেই** বন্ধ করার চিহ্নটা বসিয়ে।
 *
 * ব্লেড প্রথম বন্ধ-চিহ্নেই থেমে যায়। ফলে মন্তব্যটা ওখানেই শেষ হয়, আর
 * বাকি লাইনগুলো — ভেরিয়েবলের নাম, ব্যাখ্যা, তীর চিহ্ন — **পাতার মাথায়
 * ছাপা হতে থাকে**। মালিক সেটা লাইভে দেখেছেন, একটা নতুন খাত বানাতে গিয়ে।
 *
 * ── ⚠️ কেন কোনো পাহারা এটা ধরেনি ────────────────────────────────────
 * পাতাটা **২০০ দেয়**, ব্লেড কম্পাইল হয়, `php -l` পরিষ্কার, আর কোনো
 * ব্যতিক্রম ওঠে না। ⓘ একমাত্র লক্ষণ হলো পর্দায় বাড়তি লেখা — অর্থাৎ
 * এটা ধরা পড়ে কেবল **চোখে দেখে**, আর ঠিক সেভাবেই ধরা পড়েছে।
 *
 * ⭐ [[AQuoteInsideAnAttributeEndsItEarlyTest]] হুবহু একই আকারের রোগ
 * পাহারা দেয় — একটা ডবল কোট অ্যাট্রিবিউটটা মাঝপথে শেষ করে দেয়। এটা
 * তার যমজ, শুধু মন্তব্যের জন্য।
 */
final class ACommentThatEndsItselfPrintsTheRestTest extends TestCase
{
    public function test_no_blade_comment_closes_itself_in_the_middle(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->scan($file->getPathname(), $offenders, $checked);
        }

        foreach (File::directories(app_path('Modules')) as $module) {
            $views = $module.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'views';

            if (! File::isDirectory($views)) {
                continue;
            }

            foreach (File::allFiles($views) as $file) {
                $this->scan($file->getPathname(), $offenders, $checked);
            }
        }

        /*
         * ⚠️ শূন্য সংগ্রহে চালানো assertion সবসময় সবুজ।
         *
         * খোঁজাটা ভেঙে গেলে বা ফোল্ডারের নাম বদলালে নিচের দাবিটা নীরবে
         * পাস করত, আর পাহারাটা অলংকার হয়ে যেত। ⓘ আজ ঠিক এই ফাঁদে
         * একাধিকবার পড়া হয়েছে, তাই সংখ্যাটা আগে দাবি করা হয়।
         */
        $this->assertGreaterThan(200, $checked,
            'একটাও ব্লেড ফাইল পাওয়া গেল না — খোঁজাটা কি আর কাজ করছে?');

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'এই মন্তব্যগুলো নিজেদের মাঝপথে শেষ করে দিয়েছে, আর তার পরের',
            'লাইনগুলো পাতায় ছাপা হবে:',
            '',
            '⚠️ পাতাটা তবু ২০০ দেবে আর ব্লেড কম্পাইলও হবে — একমাত্র লক্ষণ',
            'পর্দায় বাড়তি লেখা।',
            '',
            'মন্তব্যের ভিতরে বন্ধ করার চিহ্নটা লিখতে হলে অক্ষরগুলো ভেঙে',
            'লিখুন, অথবা শব্দে বলুন ("ব্লেডের মন্তব্য")।',
            '',
            ...$offenders,
        ]));
    }

    /**
     * একটা ফাইলের প্রতিটা মন্তব্য দেখা।
     *
     * ⓘ যুক্তিটা সরল: খোলা চিহ্নের পরে **প্রথম** বন্ধ চিহ্নেই মন্তব্যটা
     * শেষ। তাই ঐ দুইয়ের মাঝখানে আরেকটা খোলা চিহ্ন থাকলে বোঝা যায় কেউ
     * মন্তব্যের ভিতরে মন্তব্যের কথা লিখতে গিয়ে ফাঁদে পড়েছেন।
     *
     * @param  list<string>  $offenders
     */
    private function scan(string $path, array &$offenders, int &$checked): void
    {
        if (! str_ends_with($path, '.blade.php')) {
            return;
        }

        $checked++;
        $source = File::get($path);
        $open = '{{--';
        $close = '--}}';
        $at = 0;

        while (($start = strpos($source, $open, $at)) !== false) {
            $end = strpos($source, $close, $start + strlen($open));

            if ($end === false) {
                $offenders[] = $this->shortPath($path).' — মন্তব্য বন্ধই হয়নি';

                return;
            }

            $inside = substr($source, $start + strlen($open), $end - $start - strlen($open));

            if (str_contains($inside, $open)) {
                $line = substr_count(substr($source, 0, $start), "\n") + 1;
                $offenders[] = $this->shortPath($path).':'.$line;
            }

            $at = $end + strlen($close);
        }
    }

    /*
     * ⚠️ `name()` নয় — ওটা PHPUnit-এর নিজের `final` পদ্ধতি, আর ঢাকতে
     * গেলে গোটা সুইট বুট হওয়ার আগেই থেমে যায়। ⓘ প্রথম চেষ্টায় ঠিক
     * সেটাই হয়েছে, আর ভালো যে ওটা **জোরে** ভেঙেছে — নীরবে নয়।
     */
    private function shortPath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
