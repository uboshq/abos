<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * এড়িয়ে যাওয়া দাবি আর সবুজ দাবি রিপোর্টে প্রায় একরকম দেখায়।
 *
 * ── ⛔ কী ঘটেছিল, ২১-২২ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * টাকার চারটা সুট একসাথে চালিয়ে ফল এসেছিল **`"result":"passed"`** —
 * আর একই লাইনে, ছোট করে, `"skipped":3`।
 *
 * ⚠️ ঐ তিনটা কী ছিল সেটাই আসল কথা:
 *   · *"সীমাটা কেবল নগদে, ব্যাংক অচ্ছুত নয়"* — ডেমোতে ব্যাংক খাত আছে,
 *     কিন্তু **পোস্টেবল নয়**, তাই দাবিটা লেখা হওয়ার দিন থেকে একবারও চলেনি
 *   · **অন্য কোম্পানির গ্রাহকের বকেয়া পড়া যায় কি না** — দ্বিতীয় কোম্পানিতে
 *     একজনও গ্রাহক নেই, তাই বহু-টেন্যান্টের দেয়ালের পরীক্ষাটা মাসের পর মাস
 *     চোখ বুজে ছিল
 *
 * ⓘ আর একই দিনে abos-8b দুইবার একই ফাঁদে পড়েছেন — গুদামের দেয়াল, আর
 * ভাউচারের নামকরণ। ⛔ **অর্থাৎ এটা অজ্ঞতা নয়, অভ্যাস:** ডেটা নেই দেখলে
 * হাত আপনা থেকেই `markTestSkipped` লেখে, আর রিপোর্ট সবুজই থাকে।
 *
 * ── ⭐ তাই সংখ্যাটা একটা ছাদে বাঁধা ──────────────────────────────────
 * এই পাহারাটা এড়িয়ে যাওয়ার জায়গাগুলো গোনে আর ছাদের উপরে গেলে লাল হয়।
 * ⓘ ছাদটা নামতে পারে, উঠতে পারে না — উঠাতে হলে এই ফাইলে হাত দিতে হয়,
 * আর তখন সিদ্ধান্তটা একবার অন্তত ভাবতে হয়।
 *
 * ⚠️ এটা `markTestSkipped` নিষিদ্ধ করে না। কখনো সত্যিই দরকার হয় —
 * যেমন যে বৈশিষ্ট্য সেটিংয়ে বন্ধ। ⛔ কিন্তু "ডেমোতে ডেটা নেই" কখনোই
 * সেই কারণ নয়: পরীক্ষা নিজেই ডেটাটা বানাতে পারে, আর
 * [[CashOnlyLandsInYourOwnTillTest]]-এ ঠিক সেটাই করা হয়েছে।
 */
final class ASkippedTestReadsAsAPassingOneTest extends TestCase
{
    /**
     * আজকের গোনা সংখ্যা।
     *
     * ⓘ ২২ সেপ্টেম্বর ২০২৬-এ তিনটা সরানোর পর যা ছিল। ⚠️ এটা লক্ষ্য নয়,
     * **ছাদ** — কমানোই একমাত্র বৈধ দিক।
     */
    private const CEILING = 25;

    /**
     * যে কারণগুলো এড়িয়ে যাওয়ার বৈধ কারণ নয়।
     *
     * ⓘ প্রতিটাই একই আকারের: *"ডেটাটা নেই"*। ⛔ কিন্তু পরীক্ষা ডেটা
     * **বানাতে পারে** — না থাকা তাই কারণ নয়, অজুহাত।
     *
     * @var list<string>
     */
    private const NOT_A_REASON = [
        'ডেমোতে', 'সিডারে', 'ডেমোর', 'demo', 'seeder',
    ];

    public function test_the_number_of_skipped_claims_only_goes_down(): void
    {
        $found = $this->skips();

        $this->assertNotSame([], $found, implode(PHP_EOL, [
            'একটাও এড়িয়ে যাওয়ার জায়গা পাওয়া গেল না।',
            '',
            'সম্ভবত খোঁজাটাই ভেঙেছে — কারণ শূন্য হলে নিচের ছাদটা',
            'নামিয়ে আনা উচিত ছিল, আর সেটা কেউ করেনি।',
        ]));

        $this->assertLessThanOrEqual(self::CEILING, count($found), implode(PHP_EOL, [
            'এড়িয়ে যাওয়া দাবির সংখ্যা বেড়েছে: '.count($found).', ছাদ '.self::CEILING.'।',
            '',
            ...array_slice($found, 0, 40),
            '',
            '⛔ এড়িয়ে যাওয়া দাবি রিপোর্টে সবুজের মতোই দেখায় — "passed"',
            'লেখাটাই চোখে পড়ে, "skipped" নয়।',
            '',
            'ডেটা নেই বলে এড়িয়ে যাবেন না — পরীক্ষা ডেটাটা নিজেই বানাক।',
            'সত্যিই দরকার হলে এই ফাইলের ছাদটা বাড়ান, আর কেন বাড়ালেন',
            'সেটা কমিটের বার্তায় লিখুন।',
        ]));
    }

    /**
     * ⭐ "ডেটা নেই" কখনো এড়িয়ে যাওয়ার কারণ নয়।
     *
     * ⚠️ এটাই আসল নিয়মটা — উপরের গোনাটা কেবল সংখ্যা ধরে রাখে। ⓘ একটা
     * বৈশিষ্ট্য বন্ধ থাকায় এড়ানো আর ডেটা না থাকায় এড়ানো এক জিনিস নয়:
     * প্রথমটায় মাপার মতো কিছু নেই, দ্বিতীয়টায় মাপার মতো জিনিসটা
     * **বানানো যেত**।
     *
     * ── ⓘ কেন তালিকা, কেন সোজা নিষেধ নয় ────────────────────────────
     * প্রথম চালেই **১৫টা** পাওয়া গেছে। ⛔ একসাথে সারানো যায় না, আর
     * পাহারাটা লাল রেখে দিলে চারজনের কাজ আটকে যেত।
     *
     * ⭐ তাই নামসহ ছাদ: **নতুন একটা যোগ হলে সাথে সাথে লাল**, আর পুরনোটা
     * কেবল কমতে পারে। ⓘ সারানোর পর নামটা এখান থেকে তুলে দিতে হয় —
     * নাহলে নিচের দাবিটাই ধরিয়ে দেবে তালিকাটা বাসি।
     *
     * @var array<string, int>
     */
    private const STEPPING_ASIDE_FOR_NOW = [
        'tests/Feature/Core/TheChartCountedEveryAccountOneAtATimeTest.php' => 1,
        'tests/Feature/Modules/Accounts/TheBooksWorkedOutOfSightTest.php' => 1,
        'tests/Feature/Modules/BatchOnPaperTest.php' => 1,
        'tests/Feature/Modules/BatchRecallTest.php' => 1,
        'tests/Feature/Modules/BatchTest.php' => 1,
        'tests/Feature/Modules/Governance/NobodyCouldSayHowLongAPaperIsKeptTest.php' => 1,
        'tests/Feature/Modules/LabelsComeOutOfThePrinterTest.php' => 1,
        'tests/Feature/Modules/PackedDocumentTest.php' => 1,
        'tests/Feature/Modules/PosIdempotencyTest.php' => 1,
        'tests/Feature/Modules/PosParkedBillTest.php' => 1,
        'tests/Feature/Modules/Purchase/TheSecondPurchaseRemembersTheFirstTest.php' => 1,
        'tests/Feature/Modules/SixSpecialLedgersAreOneFilterTest.php' => 2,
        'tests/Feature/Modules/TheSameSlipWentInTwiceAndTheMoneyDoubledTest.php' => 1,
        'tests/Feature/Modules/WhatTheMonthWasSupposedToBeTest.php' => 1,
    ];

    public function test_no_new_claim_steps_aside_merely_because_the_demo_is_thin(): void
    {
        $lazy = [];

        foreach ($this->skips() as $skip) {
            foreach (self::NOT_A_REASON as $word) {
                if (mb_stripos($skip, $word) !== false) {
                    $lazy[] = $skip;
                    break;
                }
            }
        }

        $counted = [];

        foreach ($lazy as $one) {
            $file = (string) strstr($one, ':', true);
            $counted[$file] = ($counted[$file] ?? 0) + 1;
        }

        $newOnes = [];

        foreach ($counted as $file => $n) {
            $allowed = self::STEPPING_ASIDE_FOR_NOW[$file] ?? 0;

            if ($n > $allowed) {
                $newOnes[] = $file.' — এখন '.$n.'টা, ছাদ '.$allowed.'টা';
            }
        }

        $this->assertSame([], $newOnes, implode(PHP_EOL, [
            'নতুন দাবি এড়িয়ে যাচ্ছে কেবল ডেমোতে ডেটা নেই বলে:',
            '',
            ...$newOnes,
            '',
            '⛔ ডেটা না থাকা কারণ নয়, অজুহাত — পরীক্ষা ডেটাটা বানাতে পারে।',
            'CashOnlyLandsInYourOwnTillTest::bankAccount() একটা নমুনা:',
            'একটা বৈধ ভাইকে নকল করে কেবল পরিচয়টুকু বদলে দেওয়া হয়।',
            '',
            '⚠️ সত্যিই উপায় না থাকলে উপরের তালিকায় নামটা যোগ করুন —',
            'কিন্তু জেনে রাখুন ওটা যোগ করা মানে একটা দাবি চোখ বুজল।',
        ]));
    }

    /**
     * ⭐ তালিকাটা নিজেই বাসি হতে পারে না।
     *
     * ⓘ কেউ একটা এড়ানো সারালে নামটা তালিকায় পড়ে থাকত, আর তখন ছাদটা
     * আবার জায়গা করে দিত — পরেরজন নিঃশব্দে ঐ জায়গাটা ভরে ফেলতে পারতেন।
     */
    public function test_the_list_names_only_files_that_still_step_aside(): void
    {
        $lazy = [];

        foreach ($this->skips() as $skip) {
            foreach (self::NOT_A_REASON as $word) {
                if (mb_stripos($skip, $word) !== false) {
                    $lazy[] = (string) strstr($skip, ':', true);
                    break;
                }
            }
        }

        $stale = [];

        foreach (self::STEPPING_ASIDE_FOR_NOW as $file => $n) {
            $now = count(array_keys($lazy, $file, true));

            if ($now < $n) {
                $stale[] = $file.' — তালিকায় '.$n.'টা, আসলে '.$now.'টা';
            }
        }

        $this->assertSame([], $stale, implode(PHP_EOL, [
            'তালিকাটা বাসি — এগুলো সারানো হয়ে গেছে:',
            '',
            ...$stale,
            '',
            '⭐ সংখ্যাটা নামিয়ে দিন, নয়তো নামটা তুলে দিন। ছাদ কেবল নামে।',
        ]));
    }
    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * প্রতিটা এড়িয়ে যাওয়ার জায়গা, ফাইল ও কারণসহ।
     *
     * ⚠️ নিজের ফাইলটা বাদ — নাহলে উপরের ধ্রুবকগুলোই অপরাধী গণ্য হত।
     *
     * @return list<string>
     */
    private function skips(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            if (str_ends_with($path, '/'.class_basename(self::class).'.php')) {
                continue;
            }

            $short = substr($path, strpos($path, '/tests/') + 1);

            /*
             * ⓘ মন্তব্য বাদ — নাহলে *"আগে এখানে `markTestSkipped` ছিল"*
             * লেখা একটা ব্যাখ্যাও অপরাধী গণ্য হত, আর যিনি ফাঁদটা সারিয়ে
             * কারণ লিখে রেখেছেন তিনিই শাস্তি পেতেন।
             */
            $code = $this->withoutComments((string) file_get_contents($path));

            foreach (explode("\n", $code) as $n => $line) {
                if (! str_contains($line, 'markTestSkipped') && ! str_contains($line, 'markTestIncomplete')) {
                    continue;
                }

                $found[] = $short.':'.($n + 1).' — '.trim($this->reasonNear($code, $n));
            }
        }

        sort($found);

        return $found;
    }

    /** এড়ানোর কারণটা — ঐ লাইনে, নয়তো পরের দুই লাইনে। */
    private function reasonNear(string $code, int $at): string
    {
        $lines = explode("\n", $code);

        for ($i = $at; $i < min($at + 3, count($lines)); $i++) {
            if (preg_match("/'([^']{6,})'/u", $lines[$i], $m) === 1) {
                return $m[1];
            }
        }

        return '(কারণ লেখা নেই)';
    }

    /** ⓘ লাইনের সংখ্যা অটুট রেখে — নাহলে উপরের নম্বরগুলো ভুল হত। */
    private function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $kept .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? (string) preg_replace('/[^\n]/', ' ', $token[1])
                    : $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }
}
