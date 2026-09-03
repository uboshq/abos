<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * নাম থেকে বানানো কোড খালি হয়ে বসতে পারে — কে সেটা সামলায়।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * `CodeFromName::base()` ইংরেজি অক্ষর ছাড়া সব ফেলে দেয় — তার নিজের
 * মন্তব্যেই লেখা: *"বাংলা নামে কিছুই টেকে না"*। তাই পুরো বাংলা নাম
 * দিলে সে **খালি স্ট্রিং** ফেরত দেয়, আর সেটাই সবচেয়ে বিপজ্জনক ফল:
 *
 *   ব্যতিক্রম নেই · ত্রুটি নেই · লগে কিছু নেই · টেস্ট সবুজ
 *
 * শুধু কোডের ঘরটা ফাঁকা বসে যায়। আর কোড বসে **প্রতিটা ডকুমেন্টের
 * নম্বরে**, তাই ছয় মাস পর কেউ দেখেন প্রতিটা চালানের নম্বর একটা হাইফেন
 * দিয়ে শুরু — আর তখন ওই নম্বরগুলো আর বদলানো যায় না।
 *
 * ⓘ **এটা কাল্পনিক নয়** — ৩ সেপ্টেম্বর ২০২৬-এ প্রথম দরজার (`/setup`)
 * শাখার নাম বাংলায় ডিফল্ট দেওয়া ছিল, আর ফেলনা একটা ইনস্টলে হেঁটে
 * দেখতে গিয়ে ধরা পড়ে শাখার কোড খালি বসেছে। **কোড পড়ে ধরা পড়ত না।**
 *
 * ── কেন নিষেধ নয়, ঘোষণা ──────────────────────────────────────────────
 * সহায়কটা নিষিদ্ধ করা যায় না — ওটা মালিকের নিয়ম (`CMP-0001` নয়,
 * `Trade Depot` → `TRA`), আর ওটা রোজ কাজে লাগে। তাই পথটা
 * [[EveryRouteIsGuardedTest::OPEN_TO_THE_WORLD]]-এর মতোই: **প্রতিটা
 * ডাক তালিকায় থাকবে, আর পাশে লেখা থাকবে খালি ফলটা কে সামলায়।**
 * নতুন কেউ ডাকলে তাকে হয় পাহারা বসাতে হবে, নয় কারণ লিখতে হবে।
 *
 * ⚠️ ── এই পাহারার সীমা, আর সেটা এখানেই লেখা থাকা দরকার ────────────────
 * এটা কেবল **এই একটা সহায়কের নাম ধরে** খোঁজে। কেউ কাল `CodeSuggester`
 * দিয়ে fallback ছাড়া, বা নিজের হাতে `substr()` লিখে একই ভুল করলে এই
 * টেস্ট **চুপ থাকবে**। আসল প্রশ্নটা "কে কোন সহায়ক ডাকল" নয় — *"কোডের
 * ঘর খালি বসতে পারে কি না"*, আর ওটা স্থিরভাবে ধরা এই টেস্টের নাগালের
 * বাইরে।
 *
 * সীমাটা লিখে রাখা হলো ইচ্ছাকৃতভাবে: **যে পাহারা নিজের সীমা জানায়, সে
 * অন্তত মিথ্যা আশ্বাস দেয় না।**
 */
class ACodeMadeFromANameCanComeOutEmptyTest extends TestCase
{
    /**
     * প্রতিটা ডাক, আর খালি ফলটা সেখানে কে সামলায়।
     *
     * চাবি = রিপোর সাপেক্ষে পথ। মান = **কেন এটা নিরাপদ**, এক লাইনে।
     */
    private const HANDLED = [
        'app/Modules/SystemAdmin/Http/Controllers/CompanyController.php' => 'assertCodeExists() খালি হলে ValidationException — কোম্পানি ও শাখা দুইটাতেই',

        'app/Modules/SystemAdmin/Services/FirstRun.php' => 'খালি হলে LogicException; পর্দার যাচাইও ইংরেজি অক্ষর বাধ্যতামূলক করে',

        'app/Modules/MasterData/Http/Controllers/MasterListController.php' => 'ফলটা MasterListService-এ যায়, আর assertCodeIsFree() খালি কোড ফেরায়',

        'app/Core/Engines/Coding/CodeSuggester.php' => 'খালি হলে fallbackPrefix থেকে কোড বানায় (Location · Scheme · MasterList সবাই প্রিফিক্স দেয়)',
    ];

    public function test_every_call_says_who_handles_the_empty_result(): void
    {
        $found = $this->callers();

        // ⚠️ শূন্যটা আগে দেখে নেওয়া — খুঁজে কিছু না পেলে নিচের দুইটা
        //    তুলনাই "০ বনাম ০" মিলিয়ে চিরকাল সবুজ থাকত, আর পাহারাটা
        //    থাকত কেবল অলংকার হিসেবে।
        $this->assertNotEmpty($found, 'CodeFromName-এর একটাও ডাক পাওয়া গেল না — খোঁজাটাই ভেঙেছে।');

        $undeclared = array_diff($found, array_keys(self::HANDLED));

        $this->assertSame([], array_values($undeclared), implode("\n", [
            'নাম থেকে কোড বানানো হচ্ছে, কিন্তু খালি ফলটা কে সামলায় তা কোথাও লেখা নেই:',
            ...$undeclared,
            '',
            'খালি কোড কোনো ব্যতিক্রম ছুঁড়ে না — সে নীরবে বসে যায়, আর ডকুমেন্টের',
            'নম্বরে গিয়ে ছয় মাস পর দেখা দেয়। পাহারা বসান, তারপর উপরের HANDLED',
            'তালিকায় ফাইলটা যোগ করে এক লাইনে লিখুন কে সামলায়।',
        ]));
    }

    /**
     * তালিকায় এমন নাম নেই তো যেটা আর ডাকেই না?
     *
     * ⚠️ এই দিকটাই বেশি জরুরি: বাসি সারি মানে পাহারাটা **কম** পাহারা
     * দিচ্ছে অথচ দেখাচ্ছে বেশি। `routes/auth.php`-তে ঠিক এই রোগেই একটা
     * মন্তব্য লেখা ছিল "৩ বারের পর ক্যাপচা আসে" — যা কোনোদিন বানানোই
     * হয়নি, আর পরের জন পড়ে খোঁজা বন্ধ করে দিতেন।
     */
    public function test_the_list_has_no_stale_rows(): void
    {
        $found = $this->callers();

        $gone = array_diff(array_keys(self::HANDLED), $found);

        $this->assertSame([], array_values($gone), implode("\n", [
            'HANDLED-এ এই ফাইলগুলো লেখা আছে, কিন্তু তারা আর CodeFromName ডাকে না:',
            ...$gone,
            '',
            'সারিটা মুছে দিন — নাহলে তালিকাটা এমন একটা পাহারার কথা বলে যা আর নেই।',
        ]));
    }

    /**
     * কারা `CodeFromName` ডাকে — নিজের সংজ্ঞার ফাইলটা বাদে।
     *
     * @return list<string>
     */
    private function callers(): array
    {
        $root = base_path();
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            // সংজ্ঞার ফাইলটা নিজে ডাকে না, সে-ই জিনিসটা
            if ($path === 'app/Core/Support/CodeFromName.php') {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            /*
             * মন্তব্যে নাম থাকলেই ডাক নয়।
             *
             * এই রিপোর মন্তব্যগুলো লম্বা আর `[[CodeFromName::forQuery()]]`
             * লেখা থাকে বহু জায়গায়। শুধু `CodeFromName::` খুঁজলে
             * তালিকাটা মন্তব্যে ভরে যেত, আর তখন প্রতিটা ব্যাখ্যা লিখতে
             * গিয়ে কেউ পাহারাটাই তুলে দিত।
             */
            if (preg_match('/(?<!\[\[)CodeFromName::(suggest|forQuery)\s*\(/', $body) === 1) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }
}
