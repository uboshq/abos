<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * যে হ্যান্ডলারগুলো লেখা হয়েছে, পর্দায় কেউ সত্যিই ডাকে কি না।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ ব্যাংক ঋণের ফর্মে `pickedBank()` লেখা ছিল — ব্যাংক বাছলে শাখার নাম
 * বসিয়ে দেওয়ার কাজ। ফাংশনটা নিখুঁত, আর **তিনটা ইউনিট পরীক্ষা** তাকে
 * সবুজ বলত।
 *
 * ⚠️ কিন্তু ঐ তিনটা পরীক্ষা ফাংশনটাকে **সরাসরি ডাকত** (`f.pickedBank(3)`),
 * আর ব্লেডের `select`-এ কোনো `x-on:change` ছিল না। অর্থাৎ পরীক্ষাগুলো
 * প্রমাণ করত *ফাংশনটা কাজ করে*, এটা নয় যে *কেউ ওটাকে ডাকে*।
 *
 * ⭐ মালিক বললেন *"শাখা auto bose na, bosar kotha cilo"* — আর ঠিক
 * বললেন। ৮৮টা JS পরীক্ষা সবুজ, আর পর্দায় কিচ্ছু হত না।
 *
 * ── ⓘ এই পাহারাটা কী মাপে ───────────────────────────────────────────
 * Alpine কম্পোনেন্টের প্রতিটা হ্যান্ডলার-ধাঁচের মেথড (যেটা `get` নয়,
 * ডেটা নয়) অন্তত একটা ব্লেড ফাইলে নাম ধরে ডাকা হয় কি না। ⚠️ এটা
 * আচরণ মাপে না — কেবল **তারটা আছে কি না** বলে, আর ভুলটা ঠিক ঐ আকারেই
 * হয়েছিল।
 */
final class EveryAlpineHandlerIsActuallyWiredTest extends TestCase
{
    /**
     * যেগুলো ব্লেড থেকে ডাকা হয় না, আর সেটাই ঠিক।
     *
     * ⓘ কারণসহ — কারণ কারণটা লেখাই এখানে আসল কাজ।
     *
     * @var array<string, string>
     */
    private const NOT_WIRED_ON_PURPOSE = [
        'init' => 'Alpine নিজে ডাকে, ব্লেডে লেখা হয় না',
        'destroy' => 'Alpine নিজে ডাকে',

        /*
         * ⛔ এটা "ইচ্ছাকৃত" নয় — এটা **মৃত কোড**, আর সেটা এখানে লিখে
         * রাখাই সৎ পথ।
         *
         * ⓘ `forms.js:792` — এককের নাম id থেকে ফেরায়, আর সেটা কোথাও
         * ডাকা হয় না: ব্লেডে নয়, JS-এও নয়। ২১ সেপ্টেম্বর ২০২৬-এ এই
         * পাহারাটা বসানোর দিনেই ধরা পড়ল।
         *
         * ⚠️ মোছা হয়নি কারণ ঐ ফাইলে তখন অন্য সেশনের অকমিটেড কাজ ছিল।
         * যিনি প্যাক-এককের কাজ ধরবেন, তিনি মুছে এই সারিটাও তুলে দেবেন।
         */
        'nameOf' => 'মৃত — কেউ ডাকে না; প্যাক-এককের কাজের সাথে মুছতে হবে',
    ];

    public function test_no_handler_is_written_without_anyone_calling_it(): void
    {
        $handlers = $this->handlersIn(base_path('resources/js/components'));

        /*
         * ⚠️ আগে এটা: তালিকাটা খালি হলে নিচের লুপ একবারও চলত না, আর
         * পরীক্ষাটা চিরকাল সবুজ থাকত — ঠিক যে রোগ সারাতে সে লেখা হয়েছে।
         */
        $this->assertGreaterThan(
            15,
            count($handlers),
            'হ্যান্ডলার মাত্র '.count($handlers).'টা পাওয়া গেল — খোঁ�জাটাই ভেঙেছে।'
        );

        $blade = $this->allBladeText();
        $orphans = [];

        foreach ($handlers as $name => $where) {
            if (isset(self::NOT_WIRED_ON_PURPOSE[$name])) {
                continue;
            }

            /*
             * ⓘ `name(` — ডাকার চেহারা; নিছক নামটা মন্তব্যেও থাকতে পারে।
             *
             * ⭐ ব্লেড **অথবা** JS — দুইটার যেকোনো একটা হলেই চলে।
             * ⚠️ কিছু মেথড ভিতরের সহায়ক: `factors()` বা `dropped()` অন্য
             * মেথড থেকে ডাকা হয়, পর্দা থেকে নয়। ⛔ ওদের অনাথ বলাটা ভুল
             * হত, আর ভুল অভিযোগ করা পাহারা কয়েক দিনেই বন্ধ করে দেওয়া হয়।
             */
            if (! str_contains($blade, $name.'(') && ! $this->calledWithinJs($name)) {
                $orphans[] = $name.'()  ← '.$where;
            }
        }

        sort($orphans);

        $this->assertSame([], $orphans, implode("\n", array_merge(
            ['⛔ এই হ্যান্ডলারগুলো লেখা আছে, কিন্তু কোনো ব্লেড ওদের ডাকে না:', ''],
            $orphans,
            ['',
                '⚠️ ইউনিট পরীক্ষা এগুলোকে সবুজ বলবে, কারণ সে নিজেই সরাসরি ডাকে।',
                'পর্দায় কিছুই ঘটবে না।',
                '',
                'হয় `x-on:...` বসান, নয় উপরের NOT_WIRED_ON_PURPOSE-এ কারণসহ লিখুন।']
        )));
    }

    /**
     * Alpine কম্পোনেন্টের হ্যান্ডলার-ধাঁচের মেথডগুলো।
     *
     * @return array<string, string>
     */
    private function handlersIn(string $dir): array
    {
        $out = [];

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'js' || str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            /*
             * ⓘ `name (args) {` — অবজেক্ট-লিটারেলের মেথড, যেভাবে Alpine
             * কম্পোনেন্টগুলো লেখা হয়। ⛔ `get name ()` বাদ, ওগুলো পড়ার
             * জিনিস, ডাকার নয়।
             */
            preg_match_all('/^\s{8}(?!get\s)([a-z][A-Za-z0-9]*)\s*\([^)]*\)\s*\{/m', $src, $m);

            foreach ($m[1] as $name) {
                $out[$name] ??= $file->getFilename();
            }
        }

        return $out;
    }

    /**
     * অন্য কোনো JS মেথড এটাকে ডাকে কি না।
     *
     * ⓘ `this.name(` বা `.name(` — সংজ্ঞার সারিটা (`name (args) {`,
     * ফাঁকসহ) এই ছাঁচে পড়ে না, তাই নিজেকে নিজে গোনার ভয় নেই।
     * ⛔ পরীক্ষার ফাইল বাদ — ওরা সবাইকেই ডাকে, আর তাহলে পাহারাটা
     * ঠিক ঐ জিনিসটাই মেনে নিত যেটা ধরার জন্য সে লেখা।
     */
    private function calledWithinJs(string $name): bool
    {
        foreach (File::files(base_path('resources/js/components')) as $file) {
            if ($file->getExtension() !== 'js' || str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            if (preg_match('/[.]'.preg_quote($name, '/').'\(/', file_get_contents($file->getPathname()))) {
                return true;
            }
        }

        return false;
    }

    private function allBladeText(): string
    {
        $text = '';

        $walker = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walker as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $text .= $this->withoutComments(file_get_contents($file->getPathname()));
            }
        }

        foreach (File::directories(base_path('app/Modules')) as $module) {
            $views = $module.'/Resources/views';

            if (! is_dir($views)) {
                continue;
            }

            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walker as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $text .= $this->withoutComments(file_get_contents($file->getPathname()));
                }
            }
        }

        return $text;
    }

    /**
     * মন্তব্য ছেঁটে ফেলা — ব্লেডের, PHP-র, আর `//`-এর।
     *
     * ── ⛔ কেন, আর এটা কীভাবে ধরা পড়ল ───────────────────────────────
     * এই পাহারাটা লেখার পর আমি ইচ্ছে করে তারটা কেটে দেখলাম সে লাল হয়
     * কি না। ⚠️ **হয়নি** — কারণ `institution-picker`-এর docblock-এ
     * ব্যবহারের নমুনা হিসেবে `'onPick' => 'pickedBank(...)'` লেখা ছিল,
     * আর পাহারাটা সেই **মন্তব্যটাকেই** ডাক বলে গুনছিল।
     *
     * ⭐ অর্থাৎ পাহারাটা ঠিক ঐ রোগেই ভুগছিল যেটা ধরতে সে লেখা: সে
     * তাকাচ্ছিল, কিন্তু ভুল জিনিসের দিকে। ⓘ ভেঙে না দেখলে আজও জানতাম
     * না — আর এই ফাইলটাই তার প্রমাণ।
     */
    private function withoutComments(string $blade): string
    {
        return preg_replace(
            ['/\{\{--.*?--\}\}/s', '~/\*.*?\*/~s', '~^\s*//.*$~m'],
            '',
            $blade
        ) ?? $blade;
    }
}
