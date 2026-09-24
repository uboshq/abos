<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Alpine-এর কোনো বাঁধন বাইরের ফাংশন ডাকে না।
 *
 * ── ⛔ কী ভাঙে, আর কতটা নীরবে ────────────────────────────────────────
 * প্রকল্পটা **`@alpinejs/csp`** ব্যবহার করে (`resources/js/app.js`),
 * আর সেই সংস্করণে অভিব্যক্তি চলে একটা **সীমিত পরিসরে**: কেবল
 * কম্পোনেন্টের নিজের ঘর ও মেথড, আর সাধারণ অপারেটর। ⛔ `Number`,
 * `parseFloat`, `Math`, `JSON` — এগুলো ওখানে **নেই**।
 *
 * ⚠️ আর না থাকলে Alpine ব্যতিক্রম ছোঁড়ে না — সে ঐ বাঁধনটা **চুপচাপ
 * এড়িয়ে যায়**। ⓘ ব্লেড কম্পাইল হয়, পাতা ২০০ দেয়, কনসোলে কিছু নেই।
 * পর্দার ঐ টুকরোটা শুধু **কোনোদিন আঁকা হয় না**।
 *
 * ── ⭐ কেন এই পাহারাটা আজ লেখা হলো, ২৫ সেপ্টেম্বর ২০২৬ ────────────────
 * সরাসরি বিক্রয়ের পর্দায় *"বাকিতে দেওয়া যাবে"* সারিটা লেখা ছিল
 * `x-if="(Number(customer.limit) || 0) > 0"`। ⛔ সারিটা **বসানোর দিন
 * থেকেই মরা** — একটা সংখ্যাও কোনোদিন দেখা যায়নি।
 *
 * ⚠️ মালিক তিনবার বলেছেন *"বসেনি"*, আর তিনবারই ভুল জায়গায় খোঁজা
 * হয়েছে: সার্ভারের পেলোড, ডেটাবেসের সীমা, ডিপ্লয়, ক্যাশ। ⓘ কোডটা
 * পড়তে নিখুঁত লাগে — ঐটাই ফাঁদ।
 *
 * ⭐ সারাই: হিসাবটা একটা getter-এ, আর পর্দায় কেবল নাম — ঠিক যেভাবে
 * এই প্রকল্পের বাকি প্রতিটা কাজ করা বাঁধন লেখা (`giftDraft`,
 * `depositExcess > 0`, `entryUnits.length === 0`)।
 */
final class NoAlpineBindingCallsAGlobalTest extends TestCase
{
    /**
     * ⛔ যে নামগুলো CSP-র পরিসরে নেই।
     *
     * ⚠️ তালিকাটা হাতে লেখা, আর সেটাই ঠিক: ⓘ ব্রাউজারের গ্লোবাল
     * তালিকা থেকে পড়লে `money`-র মতো কম্পোনেন্টের নিজের মেথডও ধরা
     * পড়ত, আর পাহারাটা মিথ্যা অভিযোগ করত।
     *
     * @var list<string>
     */
    private const NOT_IN_SCOPE = [
        'Number', 'String', 'Boolean', 'parseInt', 'parseFloat',
        'Math', 'JSON', 'Date', 'Object', 'Array', 'isNaN',
    ];

    /**
     * ⓘ যে অ্যাট্রিবিউটগুলোর ভিতরটা Alpine **মূল্যায়ন করে**।
     *
     * ⚠️ `x-data` বাদ: ওটা কম্পোনেন্টের নাম আর তার যুক্তি বহন করে,
     * আর ওখানে ব্লেড থেকে `@js(...)` দিয়ে আসল মান বসানো হয় — ঐ
     * লেখাটা সার্ভারের, Alpine-এর অভিব্যক্তি নয়।
     *
     * @var list<string>
     */
    private const EVALUATED = ['x-if', 'x-show', 'x-text', 'x-html', 'x-model', 'x-effect'];

    public function test_no_alpine_binding_calls_a_global(): void
    {
        $offences = [];

        foreach ($this->bladeFiles() as $path) {
            $body = File::get($path);

            foreach (self::EVALUATED as $attribute) {
                preg_match_all('/'.preg_quote($attribute, '/').'="([^"]*)"/', $body, $m);

                foreach ($m[1] as $expression) {
                    foreach (self::NOT_IN_SCOPE as $name) {
                        if (preg_match('/\b'.$name.'\s*[(.]/', $expression) !== 1) {
                            continue;
                        }

                        $offences[] = $this->shortPath($path).'  →  '.$attribute.'="'.$expression.'"';

                        break;
                    }
                }
            }
        }

        sort($offences);

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['⛔ এই Alpine বাঁধনগুলো বাইরের ফাংশন ডাকে, আর `@alpinejs/csp`-এ', 'সেগুলো নেই:', ''],
            $offences,
            ['',
                '⚠️ Alpine ব্যতিক্রম ছোঁড়ে না — সে বাঁধনটা চুপচাপ এড়িয়ে যায়।',
                'পাতা ২০০ দেয়, কনসোল পরিষ্কার, আর পর্দার ঐ টুকরোটা কোনোদিন',
                'আঁকা হয় না।',
                '',
                '⭐ হিসাবটা কম্পোনেন্টের একটা getter-এ নিন, আর পর্দায় কেবল',
                '   নামটা লিখুন — যেমন `x-if="hasCreditLimit"`।']
        )));
    }

    /**
     * ⛔ আর পাহারাটা সত্যিই তাকায় কি না — নিজেই মেপে দেখে।
     *
     * ⚠️ উপরের দাবিটা *"খারাপ কিছু পাওয়া গেল না"* দেখে পাশ করে। ⓘ ফাইল
     * খোঁজা বা ছাঁচটা ভাঙলে সে **চিরকাল সবুজ** থাকত — আর ঠিক সেই
     * রোগটা সারাতেই এই ফাইলটা লেখা।
     */
    public function test_the_search_itself_found_something(): void
    {
        $files = $this->bladeFiles();

        $this->assertGreaterThan(100, count($files),
            '⛔ মাত্র '.count($files).'টা ব্লেড পাওয়া গেল — খোঁজাটাই ভেঙেছে।');

        $bound = 0;

        foreach ($files as $path) {
            $bound += preg_match_all('/x-(if|show|text)="/', File::get($path));
        }

        $this->assertGreaterThan(100, $bound,
            '⛔ মাত্র '.$bound.'টা বাঁধন পাওয়া গেল — ছাঁচটা ফাইলের সাথে মেলেনি।');

        /*
         * ⓘ আর ছাঁচটা সত্যিই একটা অপরাধ চেনে।
         *
         * ⛔ উপরের দুইটা গুনতি বলে তালিকা ভরা, কিন্তু **মিলিয়ে দেখাটা
         * কাজ করে কি না** তা বলে না। ⚠️ regex কোনোদিন না মিললেও ঐ
         * দুইটা দাবি সবুজ থাকত।
         */
        $this->assertSame(1, preg_match('/\bNumber\s*[(.]/', 'x-if="(Number(a) || 0) > 0"'),
            '⛔ ছাঁচটা একটা স্পষ্ট অপরাধও চিনতে পারছে না।');

        $this->assertSame(0, preg_match('/\bNumber\s*[(.]/', 'x-if="hasCreditLimit"'),
            '⛔ ছাঁচটা নিরীহ বাঁধনকেও অপরাধ বলছে — মিথ্যা অভিযোগ করা পাহারা কেউ রাখে না।');
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $roots = array_filter([
            resource_path('views'),
            app_path('Modules'),
        ], fn (string $dir) => File::isDirectory($dir));

        $out = [];

        foreach ($roots as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    private function shortPath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
