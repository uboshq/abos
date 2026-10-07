<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * দুই ভাষায় একই চাবি — প্ল্যান WP-0.2, §৯.৪-এর চার নম্বর।
 *
 * ── কেন এটা একটা পাহারা দাবি করে ─────────────────────────────────────
 * দ্বিভাষিক হওয়া ABOS-এর নিয়ম ৯, আর নিয়মটা যেভাবে ভাঙে সেটা চোখে পড়ে
 * না: কেউ ইংরেজিতে নতুন একটা লেখা যোগ করে, বাংলায় করতে ভুলে যায়।
 * লারাভেল তখন কোনো ভুল বলে না — সে **চাবিটাই ছাপিয়ে দেয়**। ফলে বাংলা
 * পর্দায় বসে থাকে `sales::field.last_purchase`, আর যিনি ওটা পড়েন তিনি
 * ভাবেন ব্যবস্থাটাই ভাঙা।
 *
 * উল্টোটাও হয়, আর সেটা আরও নীরব: বাংলায় আছে, ইংরেজিতে নেই। যাঁরা
 * ইংরেজিতে চালান তাঁরাই কেবল দেখেন, আর তাঁরা সংখ্যায় কম।
 *
 * ── কেন শুধু মেনুর লেবেল নয় ──────────────────────────────────────────
 * আগের চেষ্টায় কেবল মেনুর লেখাগুলো মেলানো হত। কিন্তু ভুলটা সবচেয়ে বেশি
 * হয় ঘরের নাম, বার্তা ও যাচাইয়ের লেখায় — মেনু একবার লেখা হয়, ঘরের নাম
 * প্রতিটা নতুন পর্দায় বাড়ে। তাই এখানে **প্রতিটা ফাইলের প্রতিটা চাবি**,
 * বাসা-বাঁধা চাবিগুলো সমেত।
 */
class BothLanguagesSayTheSameThingTest extends TestCase
{
    private const LANGUAGES = ['en', 'bn'];

    /**
     * এক ভাষায় আছে অথচ অন্যটায় নেই — এমন একটা চাবিও নয়।
     */
    public function test_no_module_says_something_in_only_one_language(): void
    {
        $problems = [];
        $checked = 0;

        foreach ($this->modulePaths() as $code => $langPath) {
            $keys = [];

            foreach (self::LANGUAGES as $language) {
                $keys[$language] = $this->keysUnder($langPath.DIRECTORY_SEPARATOR.$language);
                $checked += count($keys[$language]);
            }

            foreach (self::LANGUAGES as $language) {
                $others = array_diff(self::LANGUAGES, [$language]);

                foreach ($others as $other) {
                    foreach (array_diff($keys[$language], $keys[$other]) as $key) {
                        $problems[] = "{$code}: {$key} — {$language}-এ আছে, {$other}-এ নেই";
                    }
                }
            }
        }

        sort($problems);
        $problems = array_values(array_unique(array_filter($problems)));

        $this->assertGreaterThan(500, $checked,
            'অনুবাদের ফাইলগুলোই পড়া হয়নি — এই পরীক্ষাটা তখন কিছুই দেখছে না।');

        $this->assertSame([], $problems, implode("\n", [
            'এই লেখাগুলো এক ভাষায় আছে, অন্যটায় নেই।',
            'যে ভাষায় নেই, সেখানে পর্দায় কাঁচা চাবিটাই ছাপা হবে —',
            'কোনো ভুলের বার্তা ছাড়াই।',
            ...$problems,
        ]));
    }

    /**
     * কোরের নিজের লেখাগুলোও দুই ভাষায়।
     *
     * আলাদা পরীক্ষা, কারণ কোরের ফাইলগুলো মডিউলের রেজিস্ট্রিতে নেই — আর
     * বাদ পড়লে ঠিক সেগুলোই বাদ পড়ত যেগুলো প্রতিটা পর্দায় দেখা যায়
     * (সংরক্ষণ, বাতিল, মুছুন)।
     */
    public function test_the_core_speaks_both_languages_too(): void
    {
        $keys = [];

        foreach (self::LANGUAGES as $language) {
            $keys[$language] = $this->keysUnder(base_path('lang'.DIRECTORY_SEPARATOR.$language));
        }

        $problems = [];

        foreach (self::LANGUAGES as $language) {
            foreach (array_diff(self::LANGUAGES, [$language]) as $other) {
                foreach (array_diff($keys[$language], $keys[$other]) as $key) {
                    /*
                     * ⚠️ ফাইলে না থাকা আর পর্দায় কাঁচা চাবি ছাপা — এক জিনিস নয়।
                     *
                     * `lang/` ফোল্ডারের কিছু নামস্থান **লারাভেলের নিজের**:
                     * `validation.required`-এর ইংরেজি বাক্যটা ফ্রেমওয়ার্কের
                     * ভেতরেই লেখা আছে। বাংলা নেই, তাই বাংলাটা আমাদের লিখতেই
                     * হয় — কিন্তু তার মানে এই নয় যে ইংরেজিটাও **আবার** লিখতে
                     * হবে।
                     *
                     * ⚠️ লিখলে ৫৭টা বাক্য ফ্রেমওয়ার্কের কপি হয়ে বসে থাকত, আর
                     * পরের আপগ্রেডে ওগুলো নীরবে পুরনো হয়ে যেত — পাহারাটা তখন
                     * সবুজ থাকত, অথচ লেখাগুলো ভুল।
                     *
                     * তাই প্রশ্নটা বদলে গেল: *"ফাইলে আছে কি"* নয়,
                     * **"পর্দায় সত্যিই শব্দ আসে কি"**। `Lang::has()` ঠিক
                     * সেটাই মাপে — ফ্রেমওয়ার্কের ফাইলসহ।
                     *
                     * ⓘ পাহারাটা এতে দুর্বল হয়নি, **সরাসরি** হয়েছে: যে চাবির
                     * কোনো ভাষাতেই শব্দ নেই সে আগের মতোই ধরা পড়ে। মডিউলের
                     * পরীক্ষাটায় এই ছাড় নেই, আর থাকার দরকারও নেই —
                     * `sales::`-এর পেছনে কোনো ফ্রেমওয়ার্ক দাঁড়িয়ে নেই।
                     */
                    if (Lang::has($key, $other)) {
                        continue;
                    }

                    $problems[] = "core: {$key} — {$language}-এ আছে, {$other}-এ নেই";
                }
            }
        }

        sort($problems);

        $this->assertNotEmpty($keys['en'], 'কোরের অনুবাদ ফাইলই পাওয়া যায়নি।');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * অনুবাদের মান খালি নয়।
     *
     * খালি স্ট্রিং চাবির পরীক্ষায় ধরা পড়ত না — চাবিটা দুই ভাষাতেই আছে,
     * শুধু একটায় কিছু লেখা নেই। পর্দায় তখন একটা ফাঁকা ঘর বসে, আর ফাঁকা
     * ঘর কেউ ভুল বলে চেনে না।
     */
    public function test_no_translation_is_an_empty_string(): void
    {
        $empty = [];

        $roots = [...array_values($this->modulePaths()), base_path('lang')];

        foreach ($roots as $root) {
            foreach (self::LANGUAGES as $language) {
                foreach ($this->flatten($this->loadAll($root.DIRECTORY_SEPARATOR.$language)) as $key => $value) {
                    if (is_string($value) && trim($value) === '') {
                        $empty[] = "{$language}: {$key}";
                    }
                }
            }
        }

        sort($empty);

        $this->assertSame([], $empty,
            "এই লেখাগুলোর অনুবাদ খালি — পর্দায় ফাঁকা ঘর বসবে:\n".implode("\n", $empty));
    }

    /**
     * কোড যে চাবি চায়, তার শব্দ আছে তো?
     *
     * ── উপরের দুইটা পরীক্ষার ফাঁক ────────────────────────────────────
     * প্রথমটা দেখে **দুই ভাষায় একই চাবি আছে কি না**, দ্বিতীয়টা দেখে
     * **মান খালি কি না**। কিন্তু একটা চাবি যদি **কোনো ভাষাতেই না
     * থাকে**, দুইটাই চুপ — কারণ অনুপস্থিতিটা তখন প্রতিসম।
     *
     * ঠিক সেভাবেই আটটা চাবি লুকিয়ে ছিল (৩ সেপ্টেম্বর ২০২৬)। Laravel
     * অচেনা চাবির বদলে **চাবিটাই ছাপে**, তাই পর্দায় দেখা যেত
     * `system_admin::menu.backups`, `sales::sort.latest`,
     * `master_data::validation.default_cannot_be_deactivated` —
     * ব্যবহারকারীর কাছে যা নিছক আবর্জনা। একটা ধরা পড়েছিল স্ক্রিনশট
     * দেখে; বাকি সাতটা এই মাপটা লিখে।
     *
     * ── কেন `[,)]` লাগে ─────────────────────────────────────────────
     * `__('restaurant::state.'.$t->state)` — এখানে উদ্ধৃতির ভেতরটা
     * চাবি নয়, **চাবির শুরু**। বন্ধনী বা কমা না খুঁজলে এই জোড়া-লাগানো
     * চাবিগুলোকেই "নেই" বলা হত, আর পরীক্ষাটা ছয়টা মিথ্যা অভিযোগ নিয়ে
     * শুরু হত — তারপর কেউ ওটাকে বিশ্বাস করত না।
     */
    public function test_every_key_the_code_asks_for_has_words_in_it(): void
    {
        $missing = [];
        $seen = 0;

        foreach (File::allFiles(base_path('app')) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            /*
             * দুই ধরনের চাবিই দেখা হয়:
             *
             *   accounts::dashboard.title    মডিউলের, `::` সহ
             *   core.message.saved           কোরের, `::` ছাড়া
             *
             * ⚠️ প্রথম দিন কেবল উপরেরটা দেখা হত, আর তাতে সাতটা কোরের
             * চাবি ফাঁক দিয়ে বেরিয়ে গিয়েছিল — তার তিনটা **প্রত্যাখ্যানের
             * বার্তা** (বন্ধ পর্দা, কোম্পানি/শাখায় অনুমতি নেই)। অর্থাৎ
             * ঠিক যে মুহূর্তে মানুষ একটা কারণ খুঁজছেন, তখনই তিনি একটা
             * কাঁচা চাবি দেখতেন (৩ সেপ্টেম্বর ২০২৬, লাইভে ধরা)।
             *
             * `[,)]` শর্তটা দুইটার জন্যই লাগে — `__('restaurant::state.'
             * . $x)` ধরনের জোড়া-লাগানো চাবিকে "নেই" বলা থেকে বাঁচায়।
             */
            $source = File::get($file->getPathname());

            preg_match_all(
                "/__\('([a-z_]+(?:::|\.)[a-z_.]+[a-z_])'\s*[,)]/",
                $source,
                $found,
            );

            foreach (array_unique($found[1]) as $key) {
                $seen++;

                foreach (self::LANGUAGES as $language) {
                    if (__($key, [], $language) === $key) {
                        $missing[] = "{$file->getFilename()}  [{$language}]  {$key}";
                    }
                }
            }
        }

        // মাপটা নিজেই ভেঙে গেলে যেন চুপচাপ সবুজ না থাকে
        $this->assertGreaterThan(2000, $seen, 'চাবিই পড়া যায়নি — regex বদলে গেছে?');

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['কোড এই চাবিগুলো চায়, কিন্তু কোনো ভাষাতেই শব্দ নেই —',
                'পর্দায় চাবিটাই দেখা যাবে:'],
            $missing,
        )));
    }

    /** @return array<string, string> module code => lang directory */
    private function modulePaths(): array
    {
        $paths = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $lang = $module->dir('Resources', 'lang');

            if (is_dir($lang)) {
                $paths[$module->code] = $lang;
            }
        }

        return $paths;
    }

    /**
     * একটা ভাষার ফোল্ডারের সব চাবি — `ফাইল.বাসা.চাবি` আকারে।
     *
     * @return list<string>
     */
    private function keysUnder(string $directory): array
    {
        $keys = array_keys($this->flatten($this->loadAll($directory)));

        sort($keys);

        return $keys;
    }

    /**
     * ফোল্ডারের প্রতিটা php ফাইল — ফাইলের নামই প্রথম স্তর।
     *
     * @return array<string, mixed>
     */
    private function loadAll(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $loaded = [];

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $contents = require $file;

            if (is_array($contents)) {
                $loaded[basename($file, '.php')] = $contents;
            }
        }

        return $loaded;
    }

    /**
     * বাসা-বাঁধা অ্যারেকে সমতল করা — `field.address.line1`।
     *
     * বাসা না ভাঙলে দুইটা ভাষায় ভেতরের চাবি আলাদা হলেও পরীক্ষাটা পাশ
     * করত, কারণ উপরের স্তরের নামটা দুইটাতেই আছে।
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
