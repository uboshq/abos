<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * ডিলার সম্পর্কে আমাদের নিজেদের কথা ডিলার পড়বেন না।
 *
 * ── কী পাহারা দেওয়া হচ্ছে ────────────────────────────────────────────
 * `customer_conduct_notes` টেবিলে থাকে আমাদের নিজেদের পর্যবেক্ষণ —
 * "৯০ দিন নেয়", "গাড়ি গেটে দাঁড় করিয়ে রাখে", "ছেলে এসে মাল নেয়"।
 * ওটা কাউন্টারে বিক্রয়কর্মীর কাজে লাগে।
 *
 * ⚠️ ডিলার ওটা পড়লে **সম্পর্কই শেষ**। আর ক্ষতিটা ফেরানো যায় না —
 * একবার দেখে ফেললে ভুলে যাওয়ানো যায় না।
 *
 * ── কেন নামের উপর ভরসা করা যায় না ────────────────────────────────────
 * টেবিলের নাম, মডেলের নাম, সবই ইঙ্গিত দেয় যে ওটা ভিতরের জিনিস। কিন্তু
 * **নাম কাউকে থামায় না**: একদিন কেউ পোর্টালের পাতায় ডিলারের তথ্য
 * দেখাতে গিয়ে সম্পর্কটা লোড করবেন, আর কোথাও কিছু লাল হবে না।
 *
 * ⓘ আজই এই রিপোতে ঠিক ওই ধরনের নীরব জিনিস ধরা পড়েছে — ছয়টা রঙের
 * টোকেন যা কোথাও সংজ্ঞায়িত ছিল না। CSS চুপ ছিল, জায়গাটা ফাঁকা আঁকা
 * হচ্ছিল, আর দেখে ভুল মনে হত না — **ডিজাইন মনে হত**।
 *
 * ── কেন নিষেধ নয়, ঘোষণা ─────────────────────────────────────────────
 * [[EveryRouteIsGuardedTest::OPEN_TO_THE_WORLD]]-এর ধাঁচেই। কোনোদিন
 * সত্যিই দরকার হলে (ধরা যাক ডিলার নিজের "ভালো" পতাকাগুলো দেখবেন)
 * নামটা তালিকায় বসিয়ে **কারণ লিখতে হবে** — তখন সিদ্ধান্তটা কারো
 * চোখের সামনে ঘটে, নীরবে নয়।
 */
class WhatWeSayAboutADealerStaysOnOurSideTest extends TestCase
{
    /**
     * পোর্টালের যেসব ফাইল ডিলার নিজে দেখেন।
     *
     * ⓘ পথটা `str_contains` দিয়ে মেলানো হয়, তাই নতুন কোনো পোর্টাল
     * পর্দা যোগ হলে সে **নিজে থেকেই** পাহারার ভিতরে আসে — তালিকায়
     * নাম লেখার দরকার নেই। উল্টোটা করলে (ফাইলের নাম গোনা) নতুন পাতা
     * নীরবে বাইরে থেকে যেত।
     *
     * @var list<string>
     */
    private const PORTAL_PATHS = [
        'Sales/Http/Controllers/PortalController.php',
        'Sales/Resources/views/portal/',
    ];

    /**
     * যেগুলো ইচ্ছাকৃতভাবে ছাড় পায় — আর কেন।
     *
     * আজ খালি, আর সেটাই ঠিক: ডিলারের নিজের পাতায় আমাদের পর্যবেক্ষণের
     * কোনো কাজ নেই।
     *
     * @var array<string, string>
     */
    private const ALLOWED = [];

    /**
     * যে শব্দগুলো পোর্টালের কোডে থাকা মানেই সন্দেহ।
     *
     * ⓘ `conduct` একাই যথেষ্ট নয় — কেউ `$customer->conducts` না লিখে
     * সরাসরি টেবিলের নামেও পৌঁছাতে পারেন।
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'CustomerConduct',
        'ConductService',
        'customer_conduct_notes',
        'conduct',
    ];

    public function test_no_portal_screen_reads_what_we_wrote_about_the_dealer(): void
    {
        $files = $this->portalFiles();

        /*
         * ⚠️ শূন্যটা আগে দেখে নেওয়া।
         *
         * পোর্টালের ফাইলগুলো খুঁজে না পেলে নিচের লুপটা কিছুই ঘোরাত না
         * আর পরীক্ষাটা **চিরকাল সবুজ** থাকত — কেউ পোর্টালে যা খুশি
         * লিখলেও। পথ বদলালে (মডিউল সরানো, ফোল্ডারের নাম) ঠিক সেটাই
         * ঘটত, নীরবে।
         */
        $this->assertNotEmpty($files, 'পোর্টালের একটাও ফাইল পাওয়া গেল না — খোঁজাটাই ভেঙেছে।');

        $offenders = [];

        foreach ($files as $path => $code) {
            if (isset(self::ALLOWED[$path])) {
                continue;
            }

            foreach (self::FORBIDDEN as $word) {
                if (stripos($code, $word) !== false) {
                    $offenders[] = $path.' — '.$word;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'পোর্টালের পর্দা ডিলার সম্পর্কে আমাদের নিজেদের কথা ছুঁয়েছে।',
            '',
            ...$offenders,
            '',
            'ওই নোটগুলো কাউন্টারের জন্য — "৯০ দিন নেয়", "গাড়ি দাঁড় করিয়ে',
            'রাখে"। ডিলার পড়লে সম্পর্কই শেষ, আর ওটা ফেরানো যায় না।',
            '',
            'সত্যিই দরকার হলে উপরের ALLOWED-এ পথটা বসিয়ে এক লাইনে কারণ',
            'লিখুন — তখন সিদ্ধান্তটা কারো চোখের সামনে ঘটবে, নীরবে নয়।',
        ]));
    }

    /**
     * পোর্টালের ফাইল ও তাদের কোড — মন্তব্য বাদ দিয়ে।
     *
     * ⓘ মন্তব্য বাদ দেওয়া হয়, কারণ **কেন জিনিসটা এখানে নেই** সেটা
     * ব্যাখ্যা করতে গিয়ে নামটা লিখতেই হয় — আর তখন ব্যাখ্যাটাই পরীক্ষা
     * লাল করে দিত।
     *
     * @return array<string, string>
     */
    private function portalFiles(): array
    {
        $out = [];
        $root = base_path('app/Modules');

        if (! is_dir($root)) {
            return $out;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            $inPortal = false;

            foreach (self::PORTAL_PATHS as $needle) {
                if (str_contains($path, $needle)) {
                    $inPortal = true;
                }
            }

            if (! $inPortal) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            $code = (string) preg_replace(
                ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#(?<!:)//[^\n]*#'],
                '',
                $code,
            );

            $out[$path] = $code;
        }

        return $out;
    }
}
