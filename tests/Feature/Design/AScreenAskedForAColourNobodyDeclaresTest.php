<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\LookSchema;
use Tests\TestCase;

/**
 * পর্দা যে টোকেন চায়, সেটা কেউ ঘোষণা করেছে তো? — থিম ইঞ্জিনের ধাপ ৪ (অংশ ১১)।
 *
 * ── কী নীরবে ভাঙে ────────────────────────────────────────────────────
 * `bg-(--color-surfase-card)` লিখলে Tailwind অভিযোগ করে না, ব্রাউজারও
 * করে না। ঘোষণাহীন একটা CSS ভেরিয়েবল **খালি** — তাই ঘরটা রং ছাড়া
 * থেকে যায়, আর পাতাটা দেখতে হয় প্রায় ঠিক, নয় একটু অদ্ভুত।
 *
 * `AWrongTokenNameDoesNothingTest` এই একই ভুলটা ধরে **রূপের দিক থেকে**:
 * কেউ একটা রূপে অচেনা নাম বসালে সেভের সময় ফেরে। এটা ধরে **পর্দার দিক
 * থেকে**: পাতাটা এমন কিছু চাইছে যা কোনো রূপ বা ডিফল্ট কোনোদিন দেয় না।
 *
 * দুইটা দিক, কারণ ভুলটা দুই দিক থেকেই ঢোকে, আর একদিক পাহারা দিলে
 * অন্যদিক খোলা থাকে।
 *
 * ── কেন এটা ধাপ ৪-এ ─────────────────────────────────────────────────
 * এতদিন টোকেনের তালিকা ছিল একটা CSS ফাইল, তাই "ঘোষিত কি না" প্রশ্নের
 * উত্তর দেওয়ার কেউ ছিল না। ধাপ ১-এ `LookSchema` এল, আর তার সাথেই
 * প্রশ্নটা জিজ্ঞেস করা সম্ভব হলো।
 */
class AScreenAskedForAColourNobodyDeclaresTest extends TestCase
{
    /**
     * যেসব ফাইল বাদ, আর কেন।
     *
     * @var array<string, string>
     */
    private const SKIPPED = [
        'welcome.blade.php' => 'Laravel-এর নিজের স্বাগত পাতা — আমাদের টোকেন ব্যবস্থার বাইরে, আর ব্যবহারকারী কোনোদিন দেখেন না',
    ];

    /**
     * প্রতিটা পর্দার চাওয়া টোকেন কেউ না কেউ ঘোষণা করেছে।
     */
    public function test_every_token_a_screen_asks_for_is_declared_somewhere(): void
    {
        $known = LookSchema::known();
        $orphans = [];

        foreach ($this->asked() as $token => $files) {
            if (in_array($token, $known, true)) {
                continue;
            }

            /*
             * মডিউলের রংগুলো জোড়া লাগিয়ে বানানো — `'--color-module-'.$code`।
             *
             * উৎসে তাই কেবল উপসর্গটুকু দেখা যায়, পুরো নামটা নয়। নিচের
             * পরীক্ষাটা ওই নামগুলো আলাদা করে মেলায়, কারণ ওখানে প্রশ্নটা
             * অন্য: "প্রতিটা মডিউলের কি একটা রং আছে?"
             */
            if (str_ends_with($token, '-')) {
                continue;
            }

            $orphans[] = $token.' — '.implode(', ', array_unique($files));
        }

        $this->assertSame([], $orphans, implode("\n", [
            'এই টোকেনগুলো পর্দায় চাওয়া হচ্ছে, অথচ কোথাও ঘোষিত নয়:',
            ...$orphans,
            '',
            'ঘোষণাহীন ভেরিয়েবল খালি — ঘরটা রং ছাড়াই থেকে যায়, আর কেউ',
            'ভুলের বার্তা পান না। বানানটা দেখুন, নয়তো tokens.css-এ ডিফল্ট বসান।',
        ]));
    }

    /**
     * প্রতিটা মডিউলের নিজের একটা রং আছে।
     *
     * ── কেন এটা আলাদা করে মাপা ───────────────────────────────────────
     * নামগুলো কোডে জোড়া লাগিয়ে বানানো, তাই উপরের খোঁজায় ধরা পড়ে না।
     * আর ভুলটা ঘটে ঠিক তখন, যখন কেউ একটা **নতুন মডিউল** যোগ করেন:
     * মেনু আসে, আইকন আসে, রংটা আসে না — আর রেলের ঘরটা বর্ণহীন হয়ে
     * বসে থাকে, যেটা দেখতে ভাঙা নয়, কেবল অসমাপ্ত।
     */
    public function test_every_module_has_a_colour_of_its_own(): void
    {
        $known = LookSchema::known();
        $without = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            if (! in_array('--color-module-'.$module->code, $known, true)) {
                $without[] = $module->code;
            }
        }

        $this->assertSame([], $without, implode("\n", [
            'এই মডিউলগুলোর নিজের কোনো রং ঘোষিত নেই:',
            ...$without,
            '',
            'tokens.css-এ `--color-module-<code>` বসান — নাহলে রেলের ঘরটা বর্ণহীন থাকে।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই কিছু দেখছে।
     *
     * ── কেন এটা লাগে ────────────────────────────────────────────────
     * `asked()` যদি কোনোদিন খালি ফেরাত — একটা পথ বদলে, একটা রেগেক্স
     * ভুলে — তবে উপরের পরীক্ষাটা চিরকাল সবুজ থাকত আর কেউ জানত না।
     *
     * এই প্রকল্পে ঠিক ওরকম পাহারা আগে চারবার ধরা পড়েছে, তাই প্রতিটা
     * খোঁজার সাথে একটা করে "সে সত্যিই খুঁজছে" থাকে।
     */
    public function test_the_search_actually_finds_the_screens(): void
    {
        $asked = $this->asked();

        $this->assertGreaterThan(50, count($asked), 'পর্দাগুলোয় টোকেন খুঁজে পাওয়া যাচ্ছে না — খোঁজাটাই ভেঙেছে।');

        $this->assertArrayHasKey('--color-surface-card', $asked,
            'সবচেয়ে বেশি ব্যবহৃত টোকেনটাই পাওয়া যায়নি।');
    }

    /**
     * প্রতিটা ব্লেডে চাওয়া টোকেনগুলো — নাম => কোন কোন ফাইলে।
     *
     * ── কেন `--tw-*` বাদ ─────────────────────────────────────────────
     * ওগুলো Tailwind-এর নিজের ভিতরের ভেরিয়েবল, আমাদের ঘোষণার জিনিস
     * নয়। ওগুলো ধরলে পাহারাটা এমন সব নাম নিয়ে অভিযোগ করত যেগুলো নিয়ে
     * আমাদের কিছুই করার নেই — আর তখন কেউ পাহারাটাকে বিশ্বাস করত না।
     *
     * @return array<string, list<string>>
     */
    private function asked(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (isset(self::SKIPPED[$file->getFilename()])) {
                continue;
            }

            $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            /*
             * দুইটা লেখার ভঙ্গি: Tailwind-এর `bg-(--x)` আর সাধারণ
             * `var(--x)`। ইনলাইন স্টাইলে দ্বিতীয়টাই ব্যবহার হয়, তাই
             * একটাই ধরলে অর্ধেক পর্দা অদেখা থাকত।
             */
            preg_match_all(
                '/[a-z-]+-\((--[a-zA-Z0-9_-]+)\)|var\(\s*(--[a-zA-Z0-9_-]+)/',
                (string) file_get_contents($file->getPathname()),
                $hits,
                PREG_SET_ORDER,
            );

            foreach ($hits as $hit) {
                $token = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');

                if ($token === '' || str_starts_with($token, '--tw-')) {
                    continue;
                }

                $found[$token][] = $short;
            }
        }

        return $found;
    }

    /**
     * বাদ দেওয়া ফাইলের পাশে কারণ লেখা বাধ্যতামূলক।
     *
     * কারণ ছাড়া নাম যোগ করা গেলে বাদের তালিকাটা আস্তে আস্তে পুরো
     * তালিকা হয়ে যেত, আর পাহারাটা হত এমন একটা যেটা সবকিছু পাশ করায়।
     */
    public function test_every_skipped_file_says_why(): void
    {
        foreach (self::SKIPPED as $file => $why) {
            $this->assertNotSame('', trim($why), "{$file} বাদ দেওয়ার কারণ লেখা নেই।");
        }
    }
}
