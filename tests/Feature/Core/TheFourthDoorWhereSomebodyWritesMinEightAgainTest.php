<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * চতুর্থ দরজা, যেখানে কেউ আবার `min:8` লিখে ফেলবেন।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৩, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"ধাপ ০-তে পোর্টালের নিয়ম ঠিক হয়েছে। এখন একটি স্থাপত্য-পরীক্ষা লিখুন —
 * যা প্রতিটি পাসওয়ার্ড ভ্যালিডেশন খুঁজে বের করে নিশ্চিত করে কোনোটিই
 * কর্মী-নিয়মের চেয়ে ঢিলা নয়। **নাহলে চতুর্থ দরজার দিন কেউ আবার `min:8`
 * লিখবেন।**"*
 *
 * ── ⚠️ কেন একবার সারালেই হয় না ────────────────────────────────────────
 * পোর্টালের নিয়মটা ঢিলা ছিল (`min:8`, অর্থাৎ `12345678` চলত), আর সেটা
 * সারানো হয়েছে। ⓘ কিন্তু সারানোটা ছিল **একটা ফাইলে একটা লাইন** — আর
 * পাসওয়ার্ড বসানোর দরজা এখন চারটা: কর্মী, প্রোফাইল, রিসেট, গ্রাহক-পোর্টাল।
 *
 * ⛔ পঞ্চম দরজাটা যেদিন বসবে (সরবরাহকারীর পোর্টাল? ফিল্ড অ্যাপ?), সেদিন
 * কেউ পাশের ফাইল থেকে নকল করবেন — আর নকলটা সবসময় **সবচেয়ে সহজ লাইনটার**
 * হয়, নিরাপদটার নয়।
 *
 * ── ⭐ কেন নিয়মটা "ঢিলা নয়" আকারে লেখা ───────────────────────────────
 * *"সবাই হুবহু এক নিয়ম মানুক"* বললে কড়া নিয়মও ভাঙত — সেটআপের পর্দায়
 * ইচ্ছাকৃতভাবে `min(10)`, কারণ ওটা প্রতিষ্ঠানের প্রথম মালিক-অ্যাকাউন্ট।
 * ⓘ তাই দাবিটা একমুখী: **কর্মীর নিয়মের চেয়ে কম নয়।**
 */
final class TheFourthDoorWhereSomebodyWritesMinEightAgainTest extends TestCase
{
    /** কর্মী-অ্যাকাউন্টের নিয়ম — সবার মেঝে, ছাদ নয়। */
    private const FLOOR = 8;

    /**
     * যে জায়গাগুলো পাসওয়ার্ড **যাচাই** করে, বসায় না — আর কেন।
     *
     * ⚠️ এখানে নাম বসানোর আগে নিশ্চিত হোন ঘরটা সত্যিই একটা **পুরনো**
     * পাসওয়ার্ড মেলাচ্ছে। ⛔ নতুন পাসওয়ার্ড বসানোর কোনো দরজা এখানে
     * ঢুকলে এই পুরো পাহারাটাই অর্থহীন হয়ে যায়।
     *
     * @var array<string, string>
     */
    private const CHECKS_AN_OLD_ONE = [
        'app/Http/Controllers/Auth/LoginController.php' => 'লগইন — যা আছে তাই মেলায়।',
        'app/Http/Controllers/Api/AuthController.php' => 'API লগইন — একই কাজ।',
        'app/Http/Controllers/MfaController.php' => 'দুই-ধাপ বন্ধ করার আগে পরিচয় নিশ্চিত করা।',
        'app/Http/Controllers/ProfileController.php' => 'ইমেইল/পাসওয়ার্ড বদলের আগে `current_password` মেলানো — নতুনটার নিয়ম একই ফাইলে আলাদা করে আছে।',
        'app/Modules/Sales/Http/Controllers/PortalController.php' => 'পোর্টালে ঢোকা — যা আছে তাই মেলায়।',
        'app/Modules/SystemAdmin/Http/Controllers/OwnershipController.php' => 'মালিকানা হস্তান্তরের আগে পরিচয় নিশ্চিত করা।',
    ];

    /**
     * ⭐ যেখানেই নতুন পাসওয়ার্ড বসে, নিয়মটা কর্মীর চেয়ে ঢিলা নয়।
     */
    public function test_no_door_asks_for_less_than_a_staff_password(): void
    {
        $loose = [];

        foreach ($this->passwordRules() as $file => $rules) {
            foreach ($rules as $rule) {
                /*
                 * ⓘ `Password::min(n)` থাকলে সংখ্যাটাই আসল প্রশ্ন —
                 * আর সাথে অক্ষর ও অঙ্ক দুইটাই চাওয়া হয়েছে কি না।
                 */
                if (preg_match('/Password::min\((\d+)\)/', $rule, $m)) {
                    if ((int) $m[1] < self::FLOOR) {
                        $loose[] = "{$file} — `Password::min({$m[1]})`, মেঝে ".self::FLOOR.'।';
                    }

                    if (! str_contains($rule, '->letters()')) {
                        $loose[] = "{$file} — অক্ষর চাওয়া হয়নি (`->letters()`)।";
                    }

                    if (! str_contains($rule, '->numbers()')) {
                        $loose[] = "{$file} — অঙ্ক চাওয়া হয়নি (`->numbers()`)।";
                    }

                    continue;
                }

                /*
                 * ⛔ `confirmed` মানে ঘরটা দুইবার লেখা হচ্ছে — অর্থাৎ
                 * এটা **নতুন** পাসওয়ার্ড বসানোর দরজা। ⚠️ তবু নিয়মটা
                 * নেই, তার মানে কেউ আবার হাতে লিখেছেন।
                 */
                if (str_contains($rule, 'confirmed')) {
                    $loose[] = "{$file} — নতুন পাসওয়ার্ড বসছে, অথচ `Password::min()` নেই।";

                    continue;
                }

                if (! isset(self::CHECKS_AN_OLD_ONE[$file])) {
                    $loose[] = "{$file} — নিয়মও নেই, ছাড়ের তালিকাতেও নেই।";
                }
            }
        }

        $this->assertSame([], $loose, sprintf(
            "পাসওয়ার্ডের দরজাগুলো এক নিয়ম মানছে না:\n  %s\n\n".
            "⭐ নতুন পাসওয়ার্ড বসানোর প্রতিটা জায়গায়:\n".
            "      Password::min(%d)->letters()->numbers()\n\n".
            '⚠️ পুরনো পাসওয়ার্ড কেবল মেলানো হলে ফাইলটা CHECKS_AN_OLD_ONE-এ কারণসহ লিখুন।',
            implode("\n  ", $loose),
            self::FLOOR,
        ));
    }

    /**
     * ⛔ ছাড়ের তালিকাটা যেন পচে না যায়।
     *
     * ⚠️ ফাইলটা মুছে গেলে বা তাতে পাসওয়ার্ডের নিয়মই না থাকলে নামটা এখানে
     * পড়ে থাকত, আর পরের পাঠক ভাবতেন ওখানে একটা ছাড় দরকার। ⓘ আর ঐভাবেই
     * একটা পাহারা ধীরে ধীরে একটা রূপকথা হয়ে যায়।
     */
    public function test_the_excuse_list_does_not_rot(): void
    {
        $found = $this->passwordRules();
        $stale = [];

        foreach (self::CHECKS_AN_OLD_ONE as $file => $why) {
            if (! is_file(base_path($file))) {
                $stale[] = "{$file} — ফাইলটাই আর নেই।";

                continue;
            }

            if (! isset($found[$file])) {
                $stale[] = "{$file} — এখানে আর কোনো পাসওয়ার্ডের নিয়ম নেই; ছাড়ের দরকার ফুরিয়েছে।";
            }

            if (trim($why) === '') {
                $stale[] = "{$file} — কারণটা খালি।";
            }
        }

        $this->assertSame([], $stale, "ছাড়ের তালিকাটা বাস্তবের সাথে মেলে না:\n  ".implode("\n  ", $stale));
    }

    /**
     * ⓘ সেটআপের দাবি — স্ক্যানারটা সত্যিই দরজাগুলো খুঁজে পাচ্ছে তো?
     *
     * ⚠️ `'password' => [` লেখার ধরন একদিন বদলালে (ফর্ম রিকোয়েস্টে সরে
     * গেলে) স্ক্যানার শূন্য ফেরাত, আর উপরের দাবিটা **চিরকাল সবুজ** থাকত।
     * ⛔ নীরব সবুজই সবচেয়ে বিপজ্জনক — আজকের পুরো দিনটা তাই শিখিয়েছে।
     */
    public function test_the_scanner_actually_finds_the_doors(): void
    {
        $found = $this->passwordRules();

        $this->assertGreaterThan(6, count($found),
            'পাসওয়ার্ডের দরজা এত কম হতে পারে না — স্ক্যানারটাই কিছু খুঁজে পাচ্ছে না।');

        $withPolicy = array_filter($found, fn (array $rules) => str_contains(implode(' ', $rules), 'Password::min('));

        $this->assertNotEmpty($withPolicy,
            'একটাও `Password::min()` পাওয়া গেল না — স্ক্যানার নিয়মটাই চিনছে না।');
    }

    /**
     * প্রতিটা ফাইলে পাসওয়ার্ডের নিয়মের লাইনগুলো।
     *
     * @return array<string, list<string>>
     */
    private function passwordRules(): array
    {
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /*
             * ⓘ `password` বা `new_password` — কিন্তু `current_password`
             * নয়, কারণ ওটা সবসময় পুরনোটা মেলায়।
             *
             * ── ⛔ কেন বন্ধনী বন্ধ হওয়া পর্যন্ত পড়া হয় ────────────────
             * প্রথমে লেখা ছিল "নিচের চার লাইন" — আর সেটা সাথে সাথেই
             * একটা **মিথ্যা অভিযোগ** তুলেছে: [[UserController]]-এর
             * নিয়মটা ছয় লাইনে ছড়ানো, তাই `Password::min()` পঞ্চম লাইনে
             * পড়েছিল আর স্ক্যানার ওটা দেখেনি।
             *
             * ⚠️ একটা স্ক্যানার যত সহজে মিথ্যা অভিযোগ তোলে, তত সহজেই
             * **নীরবে পাশ কাটায়** — দুইটাই একই অসাবধানতার ফল।
             */
            $lines = explode("\n", $source);

            foreach ($lines as $i => $line) {
                if (! preg_match("/'(?:new_)?password' => \[/", $line)) {
                    continue;
                }

                $rule = '';

                for ($j = $i; $j < min($i + 20, count($lines)); $j++) {
                    $rule .= ' '.$lines[$j];

                    // নিয়মের অ্যারেটা যেখানে শেষ — `],` বা `]` একা লাইনে
                    if ($j > $i && preg_match('/^\s*\],?\s*$/', $lines[$j])) {
                        break;
                    }
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                $found[$relative][] = preg_replace('/\s+/', ' ', $rule) ?? $rule;
            }
        }

        ksort($found);

        return $found;
    }
}
