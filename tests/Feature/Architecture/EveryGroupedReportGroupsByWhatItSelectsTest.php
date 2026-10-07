<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * যে ঘর বাছা হয়, সেটা দলেও থাকে — নাহলে পাতাটা ৫০০ দেয়।
 *
 * ── ⛔ কীভাবে এটা লাগল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * `inventory.stock_by_batch` বাংলায় **৫০০** দিত। ⓘ [[StockReports::
 * warehouseName()]] বাংলায় `COALESCE(NULLIF(w.name_bn, ''), w.name_en)`
 * বাছে, অথচ `groupBy`-তে ছিল কেবল `w.name_en`। ⚠️ `ONLY_FULL_GROUP_BY`
 * তখন গোটা প্রশ্নটাই বাতিল করে।
 *
 * ── ⚠️ আর দুইটা পর্দা মিলে ওটা অদৃশ্য রেখেছিল ───────────────────────
 * ⓘ **ভাষা:** ইংরেজিতে `name_bn` ছোঁয়াই হয় না, তাই ভুলটা ঘটেই না —
 * আর মালিকের লাইভ অ্যাকাউন্ট ইংরেজিতে পড়ে।
 * ⓘ **সুইচ:** ব্যাচের সুইচ বন্ধ, তাই সারিটা মেনুতেও আসত না।
 *
 * ⛔ দুইটা নিরীহ কারণ একসাথে একটা ভাঙা পাতাকে **মাসের পর মাস** লুকিয়ে
 * রাখতে পারে, আর কেউ কিছু টের পায় না।
 *
 * ── ⭐ কেন ঘটনাটা নয়, আকৃতিটা পাহারা দেওয়া ──────────────────────────
 * ঐ একটা সারি সারানো সহজ ছিল। ⚠️ কিন্তু আজ রাতে বারবার দেখা গেছে
 * একটা **নির্দিষ্ট ঘটনার** বিরুদ্ধে লেখা পাহারা চিরকাল সবুজ থাকে আর
 * ভুলটা নতুন একটা জায়গা দিয়ে হেঁটে ফিরে আসে। ⓘ তাই এখানে প্রতিটা
 * দলবদ্ধ রিপোর্ট মাপা হয়, আর নতুন রিপোর্ট লেখার দিনেই ধরা পড়বে।
 */
final class EveryGroupedReportGroupsByWhatItSelectsTest extends TestCase
{
    /**
     * ⓘ যে সহায়কগুলো ভাষা ধরে ঘর বদলায় — নাম → যে ঘরগুলো সে ছুঁতে পারে।
     *
     * ⚠️ তালিকাটা হাতে লেখা, আর সেটা একটা দুর্বলতা: নতুন একটা
     * ভাষা-নির্ভর সহায়ক লিখে এখানে বসাতে ভুললে পাহারাটা ওটাকে দেখবে
     * না। ⭐ তাই নিচের শেষ দাবিটা গোনে সহায়কগুলো সত্যিই এতগুলোই আছে।
     *
     * @var array<string, list<string>>
     */
    private const BILINGUAL = [
        'productName()' => ['p.name_en', 'p.name_bn'],
        'warehouseName()' => ['w.name_en', 'w.name_bn'],
        'reasonName()' => ['r.name_en', 'r.name_bn'],
        'partyName()' => ['pt.name_en', 'pt.name_bn'],
    ];

    /**
     * ⚠️ `app_path()` নয় — ডেটা-প্রোভাইডার **অ্যাপ চালু হওয়ার আগে** চলে।
     *
     * ⛔ প্রথম চালে `app_path()` লেখা ছিল, আর ফল হলো *"Call to undefined
     * method Container::path()"* — প্রোভাইডারটা অচল, তাই **আসল দাবিটা
     * একবারও চলেনি**।
     *
     * ⓘ আর সারাংশ তবু লিখল *"১০ দাবি, ১০ সবুজ"* — যে দাবি চলেই না সে
     * ব্যর্থও হয় না। ⚠️ আজ সারারাত যে আকৃতিটা ধরা হয়েছে, এবার সেটা
     * পাহারার নিজের কাঠামোয় ধরা পড়ল।
     */
    private const ROOT = __DIR__.'/../../../app/Modules';

    /** @return iterable<string, array{string}> */
    public static function reportFiles(): iterable
    {
        foreach (glob(self::ROOT.'/*/Reports/*.php') ?: [] as $file) {
            /*
             * ⚠️ মডিউলের নামসহ — কেবল ফাইলের নাম দিলে সংঘর্ষ হয়।
             *
             * ⛔ `PartyReports.php` দুই মডিউলে আছে, আর PHPUnit তখন
             * *"key has already been defined"* বলে **গোটা প্রোভাইডারটাই
             * বাতিল করে** — অর্থাৎ দাবিটা আবার একবারও চলত না।
             */
            yield basename(dirname($file, 2)).'/'.basename($file) => [$file];
        }
    }

    /**
     * ⭐ প্রতিটা দলবদ্ধ রিপোর্ট তার বাছা প্রতিটা ঘর দলেও রাখে।
     */
    #[DataProvider('reportFiles')]
    public function test_a_grouped_report_groups_by_every_column_it_selects(string $file): void
    {
        $source = (string) file_get_contents($file);
        $broken = [];

        /* ⓘ প্রতিটা রিপোর্ট শুরু হয় `key: '…'` দিয়ে, তাই ওখানেই কাটা */
        foreach (preg_split("/(?=\n\s+key: ')/", $source) ?: [] as $block) {
            if (! preg_match("/key: '([^']+)'/", $block, $name)) {
                continue;
            }

            /*
             * ⛔ সহায়কগুলোর **সংজ্ঞা** ব্লক থেকে বাদ — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ ফাইলের **শেষ** রিপোর্টের ব্লকটা ক্লাসের বাকিটাও গিলে
             * ফেলত, আর সেখানে `private static function productName()`
             * লেখা আছে। ⓘ ফলে পাহারাটা দুইটা নিরীহ রিপোর্টকে
             * (`sales.by_brand`, `purchase.by_supplier`) ভুল করে
             * লাল বলত — ওরা `brandName()` ব্যবহার করে, `productName()` নয়।
             *
             * ⛔ আর এটা কেবল বিরক্তির প্রশ্ন নয়: যে পাহারা মিথ্যা লাল
             * দেয়, মানুষ একদিন তার তালিকা দেখে বলে *"ওগুলো তো সবসময়ই
             * লাল"* — আর তখন আসল লালটাও ঢাকা পড়ে।
             */
            $block = preg_split('/\n\s+(?:private|protected|public) static function /', $block)[0];

            if (! preg_match('/->groupBy\(([^)]*)\)/s', $block, $group)) {
                continue;
            }

            foreach (self::BILINGUAL as $helper => $columns) {
                if (! str_contains($block, $helper)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! str_contains($group[1], "'".$column."'")) {
                        $broken[] = $name[1].' — '.$helper.' '.$column.' বাছে, কিন্তু groupBy-তে সেটা নেই';
                    }
                }
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            '⛔ এই রিপোর্টগুলো এমন ঘর বাছে যা দলে নেই:',
            '',
            ...$broken,
            '',
            '⚠️ `ONLY_FULL_GROUP_BY` তখন গোটা প্রশ্নটাই বাতিল করে, আর পাতাটা',
            '৫০০ দেয়। ⓘ আর প্রায়ই সেটা **কেবল এক ভাষায়** — ইংরেজিতে',
            '`name_bn` ছোঁয়াই হয় না, তাই পরীক্ষা করে দেখলে সব ঠিক লাগে।',
            '',
            'ⓘ groupBy-তে ঘরটা বসান — বাংলা ও ইংরেজি দুইটাই।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⛔ উপরের দাবিটা সবুজ থাকত যদি একটাও রিপোর্ট ফাইল না মিলত, বা
     * ভাষা-নির্ভর সহায়কের তালিকাটা খালি হত — দুইটাই নীরব।
     */
    public function test_there_really_are_reports_and_helpers_to_check(): void
    {
        $this->assertGreaterThan(2, count(iterator_to_array(self::reportFiles())),
            'রিপোর্টের ফাইল প্রায় কিছুই পাওয়া গেল না — পথটা পড়া হচ্ছে না।');

        $found = 0;

        foreach (glob(app_path('Modules/*/Reports/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            foreach (array_keys(self::BILINGUAL) as $helper) {
                if (str_contains($source, 'function '.rtrim($helper, '()'))) {
                    $found++;
                }
            }
        }

        $this->assertGreaterThan(1, $found, implode("\n", [
            '⛔ ভাষা-নির্ভর সহায়কের তালিকাটা আর কোডের সাথে মেলে না।',
            '',
            '⚠️ নাম বদলে গেলে `str_contains` কিছুই মেলাত না, আর উপরের',
            'দাবিটা **প্রতিটা রিপোর্ট নীরবে বাদ দিয়ে** সবুজ থাকত।',
        ]));
    }
}
