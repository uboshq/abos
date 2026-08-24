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

    /** @return list<string> */
    private function files(): array
    {
        return glob(lang_path('*/*.php')) ?: [];
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
