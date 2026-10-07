<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * একই নামে দুইটা চাবি — আর প্রথমটার সবকিছু নীরবে হারিয়ে যায়।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `lang/bn/core.php`-এ `'look' => [...]` ইতিমধ্যেই ছিল — স্কিমা ও গেটের
 * বার্তাগুলো। ধাপ ৩-এর পর্দার লেখাগুলো যোগ করার সময় দ্বিতীয় একটা
 * `'look' => [...]` বসে গেল।
 *
 * PHP কোনো ভুল বলে না। অ্যারের লিটারালে একই চাবি দুইবার থাকলে **শেষেরটা
 * জেতে**, আর আগেরটার সবকিছু মুছে যায়। ফলে পর্দায় বসে থাকল
 * `core.look.title` — চাবিটাই, লেখাটা নয়।
 *
 * ── কেন দ্বিভাষিক পরীক্ষাটা এটা ধরতে পারে না ──────────────────────────
 * `BothLanguagesSayTheSameThingTest` বাংলা ও ইংরেজির চাবি মেলায়। কিন্তু
 * ভুলটা সাধারণত **দুই ফাইলেই একসাথে** হয় — যিনি বাংলায় যোগ করেন তিনি
 * ইংরেজিতেও করেন, একই জায়গায়। দুই দিকেই একই চাবিগুলো হারায়, তাই
 * তুলনাটা নিখুঁত মেলে আর পরীক্ষাটা সবুজ থাকে।
 *
 * দুইটা পাহারা একই ভুলের দিকে অন্ধ হলে সংখ্যায় দুইটা, কাজে একটাও নয়।
 *
 * ── কেন টোকেন ধরে পড়া, `require` করে নয় ──────────────────────────────
 * ফাইলটা `require` করলে PHP নিজেই ডুপ্লিকেটটা মিলিয়ে দেয় — তখন হাতে
 * আসে একটাই চাবি, আর প্রশ্নটাই করা যায় না। তাই লেখাটা টোকেন ধরে পড়া
 * হয়, যেখানে দুইটা ঘোষণা দুইটাই দেখা যায়।
 */
class ADuplicateKeySwallowedTheWordsTest extends TestCase
{
    public function test_no_language_file_declares_the_same_key_twice(): void
    {
        $found = [];

        foreach ($this->files() as $file) {
            foreach ($this->repeatedKeys($file) as $key => $lines) {
                $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

                $found[] = "{$short} — '{$key}' (লাইন ".implode(', ', $lines).')';
            }
        }

        $this->assertSame([], $found, implode("\n", [
            'একই ফাইলে একই চাবি একাধিকবার ঘোষিত — শেষেরটা আগেরটা মুছে দেয়:',
            ...$found,
            '',
            'ব্লকগুলো এক করে দিন। PHP এখানে কোনো ভুল বলে না, তাই পর্দায়',
            'চাবিটাই ছাপা হয় আর মানুষ ভাবেন ব্যবস্থাটাই ভাঙা।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই ধরে।
     *
     * উপরেরটা আজকের অবস্থা মাপে; এটা মাপে খোঁজার কাজটা কাজ করে কি না।
     * `repeatedKeys()` সবসময় খালি ফেরালে উপরেরটাও দিব্যি সবুজ থাকত।
     */
    public function test_the_guard_actually_finds_one(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lang').'.php';

        file_put_contents($file, <<<'PHP'
            <?php

            return [
                'look' => ['a' => 'one'],
                'other' => ['look' => 'নেস্টেড, তাই গোনা হবে না'],
                'look' => ['b' => 'two'],
            ];
            PHP);

        $repeated = $this->repeatedKeys($file);

        unlink($file);

        $this->assertSame(['look'], array_keys($repeated),
            'ডুপ্লিকেটটা ধরা পড়েনি, অথবা ভিতরের চাবিটাও গোনা হয়েছে।');
    }

    /**
     * পাহারাটা সত্যিই অনুবাদগুলোর দিকে তাকাচ্ছে।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে দরকার ─────────────────────────────
     * উপরের দুইটা পরীক্ষাই "খারাপ কিছু পাওয়া গেল না" দেখে পাশ করে।
     * `files()` একদিন খালি তালিকা ফেরালে — পথ বদলালে, ফোল্ডারের নাম
     * বদলালে, গ্লব ভাঙলে — প্রথমটা **তবু সবুজ** থাকত, কারণ শূন্য ফাইলে
     * শূন্য ডুপ্লিকেট।
     *
     * ⛔ ঠিক এই ভুলটাই এখানে হয়েছিল, আর দুই বছর কেউ টের পায়নি: পাহারাটা
     * ৮টা ফাইল দেখে সবুজ বলত, আর ৩৫২টার ভিতরে ১৬টা দ্বৈত চাবি বসে ছিল।
     *
     * সংখ্যাটা আলগা করে ধরা (১০০), কারণ প্রশ্নটা "কয়টা অনুবাদ ফাইল আছে"
     * নয় — প্রশ্নটা "তালিকাটা সত্যিই ভরে উঠছে তো?"
     */
    public function test_the_guard_looks_where_the_words_live(): void
    {
        $files = $this->files();

        $this->assertGreaterThan(100, count($files),
            'অনুবাদ ফাইলের তালিকা প্রায় খালি — পথ বা গ্লব ভেঙেছে। '
            .'এটা না ধরলে উপরের পাহারাটা কিছু না দেখেই সবুজ থাকত।');

        $inModules = array_filter($files,
            fn (string $file) => str_contains(str_replace('\\', '/', $file), '/app/Modules/'));

        $this->assertNotEmpty($inModules,
            'মডিউলের ভিতরের অনুবাদগুলো তালিকায় নেই। ABOS-এর প্রায় সব '
            .'অনুবাদ ওখানেই থাকে — ওগুলো বাদ গেলে পাহারাটা ৪% দেখে।');
    }

    /**
     * পাহারাটা যেখানে তাকাত না — আর ভুলগুলো ঠিক ওখানেই ছিল।
     *
     * ── ১২ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────────
     * এই তালিকা আগে কেবল `lang_path()`-এর ভিতরটা দেখত, অর্থাৎ মূল
     * `lang/` ফোল্ডারের **৮টা** ফাইল। কিন্তু ABOS-এর অনুবাদ মডিউলের
     * ভিতরে থাকে — প্রতিটা মডিউলের `Resources/lang/` ঘরে — আর সেখানে
     * **৩৫২টা** ফাইল, যেগুলো ModuleServiceProvider `loadTranslationsFrom()`
     * দিয়ে তোলে।
     *
     * ⛔ ফলে পাহারাটা ৪% দেখত আর ৯৬% দেখত না, অথচ প্রতিবার সবুজ হয়ে
     * বলত "কোনো দ্বৈত চাবি নেই"। PHPStan বসানোর পর ঐ ৯৬%-এ **১৬টা**
     * দ্বৈত চাবি পাওয়া গেল, আর তার আটটা জোড়ার অন্তত চারটায় দুইটা লেখার
     * **অর্থই আলাদা** ছিল — অর্থাৎ পর্দায় ভুল কথা বসে ছিল।
     *
     * ⚠️ একটা পাহারা যেটা ভুল জায়গায় তাকায়, সে অনুপস্থিত পাহারার চেয়ে
     * খারাপ: অনুপস্থিতি অন্তত কাউকে সন্দেহ করায়, আর এটা প্রতিবার সবুজ
     * সার্টিফিকেট দিত।
     *
     * @return list<string>
     */
    private function files(): array
    {
        return array_merge(
            glob(lang_path('*/*.php')) ?: [],
            glob(base_path('app/Modules/*/Resources/lang/*/*.php')) ?: [],
        );
    }

    /**
     * উপরের স্তরে একাধিকবার ঘোষিত চাবিগুলো — চাবি => কোন কোন লাইনে।
     *
     * ── কেন কেবল উপরের স্তর ──────────────────────────────────────────
     * ভিতরের স্তরেও ডুপ্লিকেট সম্ভব, কিন্তু ওখানে ব্লকগুলো ছোট আর
     * চোখে পড়ে। যে ভুলটা আসলে ঘটে সেটা উপরের স্তরে: ফাইলটা লম্বা,
     * ব্লকদুইটার মাঝে তিনশো লাইন, আর কেউ স্ক্রল করে দেখে না।
     *
     * @return array<string, list<int>>
     */
    private function repeatedKeys(string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            fn ($t) => is_array($t)
                ? ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                : true,
        ));

        $depth = 0;
        $seen = [];

        foreach ($tokens as $i => $token) {
            if ($token === '[') {
                $depth++;

                continue;
            }

            if ($token === ']') {
                $depth--;

                continue;
            }

            if ($depth !== 1 || ! is_array($token)) {
                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            if (! is_array($next) || $next[0] !== T_DOUBLE_ARROW) {
                continue;
            }

            $key = trim($token[1], "'\"");

            $seen[$key][] = $token[2];
        }

        return array_filter($seen, fn (array $lines) => count($lines) > 1);
    }
}
