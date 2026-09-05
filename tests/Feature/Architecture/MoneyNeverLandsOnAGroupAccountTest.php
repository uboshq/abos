<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * টাকা কখনো একটা দল-খাতে গিয়ে পড়ে না।
 *
 * ── ⛔ কী ভাঙা ছিল, আর কেন কেউ টের পেত না ───────────────────────────
 * `Account::balanceOn()` একটা **দলের** ক্ষেত্রে কেবল সন্তানদের যোগ করে,
 * দলের নিজের সারিগুলো নয়। তাই একটা দলে বসে যাওয়া টাকা —
 *
 *     খতিয়ানে সারিটা থাকে ✅   ·   কোনো যোগফলে আসে না ⛔
 *
 * ⚠️ **আর পাহারার একটা স্তরও এটা ধরত না।** `EveryVoucherBalances` সবুজ
 * থাকে, কারণ **ডেবিট আর ক্রেডিট তো মিলছেই** — কেবল টাকাটা কোনো রিপোর্টে
 * নেই। ⓘ ৪ সেপ্টেম্বর ২০২৬-এ dev ডাটাবেসে ঠিক এমন একটা সারি পাওয়া
 * গেছে: `1101 হাতে নগদ` (একটা দল) ১,২০০ টাকা ধরে বসে ছিল।
 *
 * ── ⭐ কেন দুই স্তরের পাহারা, আর দুইটা আলাদা জিনিস দেখে ──────────────
 *
 *     PostingEngine-এর ব্যতিক্রম   →  **ক্রেতার লেখা** — যেকোনো পথ, চিরকাল
 *     এই টেস্ট                      →  **আমাদের কোড** — কোন নির্বাচক ছাঁকতে ভুলেছে
 *
 * ⓘ টেস্ট ক্রেতার চার্ট পাহারা দিতে পারে না: তিনি নিজের ছকে একটা খাতকে
 * দল বানিয়ে ফেললে আমাদের কোনো টেস্ট জানবে না। ⛔ উল্টোদিকে ব্যতিক্রমটা
 * বলবে না **কোন পর্দাটা** ভুল তালিকা দেখাচ্ছে — সে কেবল বলবে "এখানে
 * পোস্ট করা যায় না", আর ব্যবহারকারী ততক্ষণে ফর্ম ভরে ফেলেছেন।
 *
 * ── ⚠️ কেন কোড পড়া হয়, ডেটা নয় ─────────────────────────────────────
 * ডাটাবেসে আজ কোনো দল-ব্যাংক নেই, তাই ডেটা ধরে পরীক্ষা করলে এটা চিরকাল
 * সবুজ থাকত — **আজকের ছবির পাহারা, নিয়মের নয়।** তাই কন্ট্রোলারের সোর্স
 * পড়া হয়, ঠিক যেভাবে [[EveryRouteIsGuardedTest]] রুট পড়ে।
 */
final class MoneyNeverLandsOnAGroupAccountTest extends TestCase
{
    /**
     * যে খোঁজাগুলো একটা খাতের **তালিকা** বানায়।
     *
     * ⓘ `Account::query()` দিয়ে শুরু হওয়া প্রতিটা চেইন, যেটা শেষে
     * `get()` বা `pluck()` করে — অর্থাৎ ব্যবহারকারীকে বাছতে দেওয়া হয়,
     * বা আইডিগুলো যোগফলে ব্যবহার হয়।
     */
    /*
     * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — আগের রূপটা একটাও চেইন ধরত না।
     *
     * সে লিখত `(?:\s*(?:->|মন্তব্য))*?` — অর্থাৎ কেবল তীরগুলো, তীরের
     * পরের **নামগুলো নয়**। ⛔ তাই `Account::query()->money()->…` -এর
     * প্রথম তীরের পরেই মিল ভেঙে যেত, আর ৫১টা ফাইলের একটাতেও কিছু
     * পাওয়া যেত না।
     *
     * ⭐ ধরা পড়েছে নিচের "শূন্য সংগ্রহে চালানো assertion" দিয়েই — যে
     * পাহারাটা এই ফাইলের লেখক নিজের অন্ধ হওয়া ঠেকাতে বসিয়ে গিয়েছিলেন।
     * ⓘ ওটা না থাকলে এই গার্ড চিরকাল সবুজ থাকত, কিছুই না দেখে।
     *
     * ⓘ এখন সরল নিয়ম: `Account::query()` থেকে শুরু করে একই বাক্যের
     * ভেতরে (`;` পার না করে) প্রথম `get`/`pluck`/`first` পর্যন্ত।
     */
    private const PICKER = '/Account::query\(\)[^;]*?->(?:get|pluck|first)\(/s';

    /**
     * যে জায়গাগুলোয় দল থাকাই স্বাভাবিক — কারণসহ।
     *
     * ⚠️ তালিকাটা ছোট রাখা ইচ্ছাকৃত: প্রতিটা সারি একটা সিদ্ধান্ত।
     *
     * @var array<string, string>
     */
    private const GROUPS_BELONG_HERE = [
        'ChartOfAccountsController.php' => 'ছকের পর্দা — দল দেখানোই তার কাজ, আর বাবা বাছতে দলই লাগে',
        'AccountService.php' => 'খাত তৈরি ও সরানো — বাবা সবসময় একটা দল',
        'StandardChart.php' => 'ছকটা নিজে বসায়, দল ও ঘর দুইটাই',
        'HeadTotals.php' => 'যোগফল গাছ ধরে হাঁটে — দল ছাড়া মাথার সংখ্যাই হত না',
        'AccountsFacts.php' => 'একই কারণে — পরিবারের যোগফল',
        'BalanceSheetService.php' => 'স্থিতিপত্র দল ধরেই সাজানো',
        'FixedAssetController.php' => 'সম্পদের বাবা-খাত বাছা হয়, আর ওটা দল',
    ];

    public function test_every_account_picker_leaves_groups_out(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->sourceFiles() as $file => $source) {
            $name = basename($file);

            if (isset(self::GROUPS_BELONG_HERE[$name])) {
                continue;
            }

            preg_match_all(self::PICKER, $source, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$chain, $at]) {
                $checked++;

                if (str_contains($chain, 'postable()') || str_contains($chain, 'is_group')) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $at), "\n") + 1;
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line;
            }
        }

        /*
         * ⚠️ শূন্য সংগ্রহে চালানো assertion সবসময় সবুজ।
         *
         * রেগেক্সটা কিছু না পেলে নিচের পরীক্ষাটা নীরবে পাস করত, আর
         * পাহারাটা অলংকার হয়ে যেত। ⓘ আজ একবার ঠিক এই ফাঁদে পড়া
         * হয়েছে — চারটা null-এর সাথে চারটা null মিলিয়ে একটা গার্ড
         * পাস করছিল।
         */
        $this->assertGreaterThanOrEqual(10, $checked,
            'একটাও খাত-নির্বাচক পাওয়া গেল না — খোঁজাটা কি আর কাজ করছে?');

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'এই খোঁজাগুলো দল-খাতও তুলে আনে, আর সেগুলো বাছাই করা যায়:',
            '',
            'একটা দলে টাকা বসলে সেটা কোনো রিপোর্টে আসে না — Account::balanceOn()',
            'দলের নিজের সারি গোনে না। খতিয়ানে সারিটা থাকে, যোগফলে থাকে না।',
            '',
            '`->postable()` যোগ করুন, নয় দল থাকা যদি ইচ্ছাকৃত হয় তবে',
            'GROUPS_BELONG_HERE-এ কারণসহ লিখুন।',
            '',
            ...$offenders,
        ]));
    }

    /**
     * ঘোষিত ছাড়গুলোও বাসি হতে পারে।
     *
     * ⓘ ফাইলটা মুছে গেলে বা নাম বদলালে সারিটা এখানে পড়ে থাকত, আর পরের
     * জন ভাবতেন ওখানে দল থাকা এখনো ইচ্ছাকৃত।
     */
    public function test_no_exemption_names_a_file_that_is_gone(): void
    {
        $present = array_map('basename', array_keys($this->sourceFiles()));

        $stale = array_values(array_diff(array_keys(self::GROUPS_BELONG_HERE), $present));

        $this->assertSame([], $stale,
            'ছাড়ের তালিকায় এমন ফাইলের নাম আছে যা আর নেই — মুছে দিন: '.implode(', ', $stale));
    }

    /** @return array<string, string> path => source */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path()) as $entry) {
            if ($entry->getExtension() !== 'php') {
                continue;
            }

            $source = File::get($entry->getPathname());

            if (str_contains($source, 'Account::query()')) {
                $files[$entry->getPathname()] = $source;
            }
        }

        return $files;
    }
}
