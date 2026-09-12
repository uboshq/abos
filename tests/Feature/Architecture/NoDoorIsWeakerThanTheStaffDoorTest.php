<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * ⛔ কোনো দরজা কর্মীর দরজার চেয়ে ঢিলা নয় — ১২ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `CustomerPortalController` পাসওয়ার্ড চাইত `['required','string',
 * 'min:8','max:191','confirmed']` — শুধু দৈর্ঘ্য, গড়ন নিয়ে কিছু নয়।
 * ⚠️ ফলে **`12345678` গৃহীত হত**।
 *
 * ⓘ আর ঠিক ঐ নিয়মটার পাশে মন্তব্যে লেখা ছিল *"আট অক্ষর — কর্মীর
 * পাসওয়ার্ডের মতোই"*। ⛔ কথাটা সত্যি ছিল না: কর্মীর দরজা অক্ষর **আর**
 * সংখ্যা দুইটাই চায়। অর্থাৎ মন্তব্যটা নিয়মটাকে পাহারা দেয়নি, **আড়াল
 * করেছে** — আর সেজন্যই ফাঁকটা কোড পড়েও ধরা পড়ত না।
 *
 * ── ⭐ কেন এই পাহারাটা সারাইয়ের চেয়েও দামি ───────────────────────────
 * ⓘ একই ভুল এই রিপোতে **আগে একবার ধরা পড়েছে ও সারানো হয়েছে** — ৩১
 * আগস্ট ২০২৬, কর্মীর দরজায়, যেখানে `min:8` থাকায় `12345678` আর
 * `password` দুইটাই চলত। ⚠️ সারাইটা তখন **ছড়ায়নি**: পোর্টাল পুরনো
 * নিয়মেই থেকে গেল, আর কেউ টের পেল না।
 *
 * ⛔ অর্থাৎ সমস্যাটা নীতির অভাব নয়, **পৌঁছানোর অভাব**। সারাই আজকের
 * দরজাটা বন্ধ করে; পাহারা **চতুর্থ দরজাটা জন্মাতে দেয় না**। ⓘ চতুর্থ
 * দরজার দিন কেউ একটা নতুন পর্দা লিখবেন, `min:8` লিখবেন, আর সেটা
 * পর্যালোচনায় সম্পূর্ণ স্বাভাবিক দেখাবে — কারণ আটটা তো চাওয়া হয়েছেই।
 *
 * ── কেন "কর্মীর দরজা"-ই মাপকাঠি ─────────────────────────────────────
 * ⓘ একটা সংখ্যা হাতে লিখে রাখলে দুইটা জায়গায় সত্য রাখতে হত। এখানে
 * মাপকাঠিটা **`UserController`-এর নিয়মটাই** — অর্থাৎ কেউ কর্মীর দরজা
 * কড়া করলে বাকি সবগুলোকেও সেদিনই কড়া হতে হয়, আর ঢিলা করলে পাহারাটা
 * অন্তত একবার প্রশ্ন তোলে।
 */
class NoDoorIsWeakerThanTheStaffDoorTest extends TestCase
{
    /**
     * ⭐ কর্মীর দরজা — `UserController::validated()`।
     *
     * ⓘ এই তিনটাই সেখানে চাওয়া হয়, আর কারণটাও সেখানে লেখা: বড়-ছোট
     * হরফ বা চিহ্ন চাওয়া হয় না, কারণ ডিপোর কর্মীরা অনেকে বাংলা
     * কিবোর্ডে টাইপ করেন আর জটিল নিয়ম বসালে পাসওয়ার্ড কাগজে লেখা শুরু
     * হয় — তখন নিয়মটা নিরাপত্তা বাড়ায় না, কমায়।
     */
    private const STAFF_MIN = 8;

    /**
     * ⚠️ যে দরজাগুলো সত্যিই আছে — পাহারাটা অন্তত এগুলো দেখেই থাকবে।
     *
     * ── ⛔ কেন এই তালিকাটা দরকার ─────────────────────────────────────
     * নিচের খোঁজাটা একটা ছাঁচ ধরে চলে। ⓘ কেউ ছাঁচটা বদলে দিলে — বা
     * নিয়মগুলো একটা `FormRequest`-এ সরিয়ে নিলে — খোঁজায় **শূন্যটা ঘর**
     * পড়ত, আর `assertSame([], $weak)` **চিরকাল সবুজ** থাকত।
     *
     * ⭐ যে পাহারা কিছুই খুঁজে পায় না, সে কাউকে দোষী বলে না। তাই এই
     * তিনটা নাম ধরে লেখা: এগুলো খুঁজে না পেলে পাহারাটা নিজেই লাল হয়।
     *
     * @var list<string>
     */
    private const KNOWN_DOORS = [
        'app/Modules/Customer/Http/Controllers/CustomerPortalController.php',
        'app/Modules/SystemAdmin/Http/Controllers/UserController.php',
        'app/Modules/SystemAdmin/Http/Controllers/SetupController.php',
    ];

    /**
     * ⛔ যে নিয়ম নতুন পাসওয়ার্ড বসায়, সে কর্মীর নিয়মের সমান।
     *
     * ── কীভাবে "বসানো" আর "মেলানো" আলাদা করা হয় ─────────────────────
     * ⓘ লগইনের নিয়ম হয় `['required','string']` — সেখানে দৈর্ঘ্য বা গড়ন
     * চাওয়া **উচিতও নয়**: পুরনো পাসওয়ার্ডগুলো আজকের নিয়মের আগের, আর
     * নিয়ম বসালে পুরনো মানুষ নিজের সঠিক পাসওয়ার্ড দিয়েও ঢুকতে পারতেন
     * না।
     *
     * ⭐ তাই প্রশ্নটা সহজ: **নিয়মটা কি দৈর্ঘ্য বা গড়ন নিয়ে কিছু বলে?**
     * বললে সে একটা নতুন পাসওয়ার্ড বসাচ্ছে (`min:`, `confirmed`,
     * `Password::`), আর তখন তাকে পুরো কর্মী-নিয়মটাই মানতে হবে।
     *
     * ⚠️ এই প্রশ্নটা ইচ্ছাকৃতভাবে **অর্ধেক-নিয়মকেও** ধরে: `min:8` লেখা
     * অথচ অক্ষর-সংখ্যা না চাওয়া — অর্থাৎ ঠিক যে ভুলটা দুইবার ঘটেছে।
     */
    public function test_no_password_rule_is_weaker_than_the_staff_rule(): void
    {
        $doors = $this->passwordRules();

        $weak = [];

        foreach ($doors as $door) {
            if (! $this->setsAPassword($door['rule'])) {
                continue;
            }

            foreach ($this->complaintsAbout($door['rule']) as $complaint) {
                $weak[] = "{$door['file']}:{$door['line']} — {$complaint}";
            }
        }

        sort($weak);

        $this->assertSame([], $weak, implode("\n", array_merge(
            ['⛔ এই দরজাগুলো কর্মীর দরজার চেয়ে ঢিলা:',
                ''],
            $weak,
            ['',
                'কর্মীর নিয়মটা `UserController::validated()`-এ:',
                '',
                '    Password::min('.self::STAFF_MIN.')->letters()->numbers()',
                '',
                '⚠️ `min:8` একাই যথেষ্ট নয় — তাতে `12345678` চলে যায়।',
                'একটা দরজা ইচ্ছাকৃতভাবে ঢিলা রাখতে চাইলে সেটা এখানে',
                'কারণসহ লিখতে হবে; কারণটা লেখাই এখানে আসল কাজ।'],
        )));
    }

    /**
     * ⚠️ পাহারাটা সত্যিই দরজাগুলো দেখছে তো?
     *
     * ⓘ উপরের দাবিটা খালি তালিকাতেও সবুজ। এই পরীক্ষাটা তাই আলাদা:
     * পরিচিত তিনটা দরজা খোঁজায় ধরা পড়েছে কি না, আর তার অন্তত একটা
     * সত্যিই `Password::` ব্যবহার করছে কি না।
     */
    public function test_the_guard_is_actually_looking_at_the_doors(): void
    {
        $found = array_unique(array_column($this->passwordRules(), 'file'));

        foreach (self::KNOWN_DOORS as $door) {
            $this->assertContains($door, $found, implode("\n", [
                "⛔ এই দরজাটার পাসওয়ার্ড-নিয়ম খুঁজে পাওয়া যায়নি: {$door}",
                '',
                'ফাইলটা সরে গেছে, নাকি নিয়মটা অন্য ছাঁচে লেখা হয়েছে?',
                '⚠️ যেটাই হোক, এই পাহারাটা এখন কম দেখছে — খোঁজার',
                'ছাঁচটা (`passwordRules()`) মিলিয়ে নিন।',
            ]));
        }
    }

    /**
     * ⛔ প্রত্যাখ্যানটা দুই ভাষাতেই মানুষের ভাষায়।
     *
     * ── কী ভাঙা ছিল, মেপে দেখা ──────────────────────────────────────
     * `validation.password.*` চাবিগুলো **কোনো ভাষাতেই ছিল না**। ⓘ
     * Laravel তখন ফ্রেমওয়ার্কের ইংরেজিতে নেমে যেত, অথচ
     * `attributes.password` অনুবাদটা পেত — ফলে বাক্যটা আধা-বাংলা
     * আধা-ইংরেজি হয়ে বেরোত:
     *
     *     bn →  "The পাসওয়ার্ড field must contain at least one letter."
     *
     * ⚠️ আর এটা কেবল পোর্টালের কথা নয় — `UserController` ও
     * `SetupController` একই নিয়ম চায়, অর্থাৎ **কর্মীরাও** ঐ ভাঙা
     * বাক্যটাই দেখতেন।
     *
     * ⓘ [[ARefusalSpeaksTheUsersLanguageTest]] এটা ধরত না: সে সাধারণ
     * নিয়মগুলো (`required`, `email`) আর ভাগ করা ঘরের নাম দেখে,
     * `Password` নিয়মের নিজের বার্তাগুলো নয়। ⭐ তাই দাবিটা এখানে,
     * যে ফাইলটা ঐ নিয়মটার মালিক।
     */
    public function test_the_refusal_speaks_both_languages(): void
    {
        $broken = [];

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (['12345678' => 'letters', 'abcdefgh' => 'numbers'] as $password => $expected) {
                $message = Validator::make(
                    ['password' => (string) $password],
                    ['password' => [Password::min(self::STAFF_MIN)->letters()->numbers()]],
                )->errors()->first('password');

                /*
                 * ⚠️ কাঁচা চাবিটাই ছাপা হচ্ছে — অর্থাৎ কোনো ভাষাতেই
                 * শব্দ নেই। পর্দায় তখন `validation.password.letters`
                 * বসত, আর যিনি পড়েন তিনি ভাবতেন ব্যবস্থাটাই ভাঙা।
                 */
                if (str_contains($message, 'validation.password')) {
                    $broken[] = "{$locale}/{$expected}: কাঁচা চাবি — \"{$message}\"";

                    continue;
                }

                /*
                 * ⓘ ইংরেজির ছাঁচটা — "The … field …"। ইংরেজিতে ওটাই
                 * কাম্য, বাংলায় ওটা থাকা মানে অনুবাদটা পাওয়া যায়নি।
                 */
                $looksEnglish = str_contains($message, ' field ') || str_starts_with($message, 'The ');

                if ($locale === 'bn' && $looksEnglish) {
                    $broken[] = "bn/{$expected}: এখনো ইংরেজি — \"{$message}\"";
                }

                if ($locale === 'en' && ! $looksEnglish) {
                    $broken[] = "en/{$expected}: ইংরেজি বার্তাটা নেই — \"{$message}\"";
                }
            }
        }

        app()->setLocale('bn');

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['⛔ দুর্বল পাসওয়ার্ডের প্রত্যাখ্যান ব্যবহারকারীর ভাষায় আসছে না:',
                ''],
            $broken,
            ['',
                '`lang/bn/validation.php` ও `lang/en/validation.php`-এর',
                "`'password'` অ্যারেতে সারিগুলো আছে তো? (নিয়ম ৯ — দুই ফাইলেই)"],
        )));
    }

    /* ── খোঁজার কাজটা ──────────────────────────────────────────────── */

    /**
     * কোডের প্রতিটা `'password' => [ … ]` নিয়ম, ফাইল ও লাইনসহ।
     *
     * ⚠️ লাইন ধরে খোঁজা হয় না — `UserController`-এর নিয়মটা **পাঁচ
     * লাইনে** লেখা। ⓘ তাই `[` থেকে শুরু করে বন্ধনী গুনে গুনে মিলটা
     * বের করা হয়, আর পুরো টুকরোটা একসাথে দেখা হয়।
     *
     * @return list<array{file: string, line: int, rule: string}>
     */
    private function passwordRules(): array
    {
        $rules = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            $src = (string) file_get_contents($path);
            $offset = 0;

            while (preg_match('/([\'"])password\1\s*=>\s*\[/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = (int) $m[0][1] + strlen($m[0][0]) - 1;
                $end = $this->matchingBracket($src, $start);

                $offset = (int) $m[0][1] + strlen($m[0][0]);

                if ($end === null) {
                    continue;
                }

                $rules[] = [
                    'file' => str_replace('\\', '/', substr($path, strlen(base_path()) + 1)),
                    'line' => substr_count(substr($src, 0, (int) $m[0][1]), "\n") + 1,
                    'rule' => substr($src, $start, $end - $start + 1),
                ];
            }
        }

        return $rules;
    }

    /**
     * ⓘ নিয়মটা কি একটা **নতুন** পাসওয়ার্ড বসাচ্ছে?
     *
     * দৈর্ঘ্য বা গড়ন নিয়ে কিছু বললে হ্যাঁ। লগইন ও পরিচয় যাচাইয়ের
     * নিয়মগুলো (`['required','string']`) কিছুই বলে না — আর বলাও উচিত
     * নয়, কারণ পুরনো পাসওয়ার্ড আজকের নিয়মের আগের।
     */
    private function setsAPassword(string $rule): bool
    {
        return str_contains($rule, 'Password::')
            || str_contains($rule, 'confirmed')
            || preg_match('/[\'"]min:\d+[\'"]/', $rule) === 1;
    }

    /**
     * এই নিয়মটা কর্মীর নিয়ম থেকে কোথায় কম — প্রতিটা অভিযোগ আলাদা করে।
     *
     * @return list<string>
     */
    private function complaintsAbout(string $rule): array
    {
        $complaints = [];

        if (preg_match('/Password::min\(\s*(\d+)\s*\)/', $rule, $m) !== 1) {
            return ['`Password::min()` ব্যবহার করে না — `min:8` একা `12345678` আটকায় না'];
        }

        if ((int) $m[1] < self::STAFF_MIN) {
            $complaints[] = "`Password::min({$m[1]})` — কর্মীর দরজা চায় অন্তত ".self::STAFF_MIN;
        }

        if (! str_contains($rule, '->letters()')) {
            $complaints[] = '`->letters()` নেই — কেবল সংখ্যার পাসওয়ার্ড চলে যায়';
        }

        if (! str_contains($rule, '->numbers()')) {
            $complaints[] = '`->numbers()` নেই — কেবল অক্ষরের পাসওয়ার্ড চলে যায়';
        }

        return $complaints;
    }

    /**
     * `[` থেকে তার জোড়া `]` — ভেতরের বন্ধনী গুনে।
     *
     * ⓘ নিয়মের ভেতরে বাসা-বাঁধা অ্যারে থাকতে পারে, তাই প্রথম `]`-টাই
     * শেষ নয়।
     */
    private function matchingBracket(string $src, int $start): ?int
    {
        $depth = 0;

        for ($i = $start, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '[') {
                $depth++;
            } elseif ($src[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
