<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * খতিয়ানে লেখে একটাই হাত।
 *
 * ── ⭐ কেন এই পাহারাটা লেখা হলো, ২২ সেপ্টেম্বর ২০২৬ ─────────────────
 * [[MoneyNeverLandsOnAGroupAccountTest]] নিয়ে একটা তর্কে দাঁড়িয়েছিল:
 * দল-খাতে টাকা বসলে কী হয়? ⓘ মিউটেশন চালিয়ে দেখা গেল
 * [[PostingEngine]] **নিজেই** ফিরিয়ে দেয় — অর্থাৎ ক্ষতিটা নীরব ভুল
 * জের নয়, পোস্ট করার মাঝপথে একটা ছুড়ে ফেলা।
 *
 * ⚠️ কিন্তু সেই উত্তরটা সত্যি **কেবল যদি সবাই ইঞ্জিন দিয়ে লেখে**।
 * কেউ `DB::table('ledger_entries')->insert(...)` লিখলে ইঞ্জিনের কোনো
 * শর্তই চলত না — না দল-বাধা, না ভারসাম্য, না [[LedgerChain]]-এর সিল।
 *
 * ⓘ প্রশ্নটা কাল্পনিক নয়: `Inventory/Reports/StockReports.php` পুরোটাই
 * query-builder দিয়ে লেখা, তাই এই ঘরানাটা এই রিপোতে প্রতিষ্ঠিত।
 *
 * ── ⓘ ২২ সেপ্টেম্বরের সুইপে যা পাওয়া গেছে ───────────────────────────
 * ১,৮৬৫টা ফাইল, কোনো সীমা ছাড়া। অ্যাপের কোডে খতিয়ানে লেখে **কেবল
 * দুইটা জায়গা**, দুইটাই `PostingEngine`-এ। বাকি সব লেখা মাইগ্রেশনে —
 * ঘোষিত, একবারের, পর্যালোচিত বদল।
 *
 * ⭐ এই পাহারাটা সেই উত্তরটাকে **আজকের ছবি থেকে নিয়মে** বদলায়।
 *
 * ── ⚠️ যা এই পাহারাটা ধরতে পারে না ──────────────────────────────────
 * ⓘ কাঁচা `DB::statement("...")`-এর ভিতরে টেবিলের নাম variable হয়ে
 * এলে এটা দেখবে না, আর প্যাকেজের কোডও দেখে না। ⛔ সীমাটা লিখে রাখা
 * হলো যাতে ছয় মাস পরে কেউ এর সবুজকে **পুরো সত্য** বলে না পড়েন।
 */
final class OnlyTheEngineWritesToTheLedgerTest extends TestCase
{
    /**
     * ⭐ ইঞ্জিন নিজে — এটা **ছাড় নয়, নিয়মের সংজ্ঞা**।
     *
     * ── ⓘ কেন এটা আলাদা তালিকা ───────────────────────────
     * [[EveryExcuseWeGrantedIsCountedTest]] ছাড় গোনে, আর হিসাবটা
     * এক দিকে যায়: **নাম মুছলে পাহারা শক্ত হওয়ার কথা**।
     *
     * ⛔ এখানে সেটা উল্টো। `PostingEngine`-এর নাম মুছলে
     * পাহারাটা নিজের নকশার বিরুদ্ধেই লাল হত, আর কারও একজনকে
     * নামটা ফেরত বসাতে হত। ⓘ তাই এটা একটা **চাহিদা**।
     *
     * ⚠️ তার নিজের লেখাগুলো এই পরীক্ষার **নিয়ন্ত্রণ
     * সারি**ও: ওগুলো না পেলে খোঁজাটাই কিছু দেখছে না।
     *
     * @var array<string, string>
     */
    private const THE_ENGINE = [
        'PostingEngine.php' => 'ইঞ্জিন নিজেই — ভারসাম্য, দল-বাধা আর সিল সবই এখানে',
    ];

    /**
     * ⛔ যে একটা জায়গা সত্যিই ইঞ্জিন এড়িয়ে লেখে — শর্তসহ।
     *
     * ⓘ [[LedgerChain::reseal()]] কেবল `prev_hash`/`row_hash` বসায়,
     * টাকার কোনো ঘর ছোঁয় না। ⚠️ এটা লাগে কারণ একটা
     * মাইগ্রেশন `account_id` বদলালে পরের সব সিল ভেঙে যায়,
     * আর `abos:books-check` তখন একটা **মিথ্যা অভিযোগ** করত।
     *
     * ⭐ এটাই আসল ছাড়, আর এটাই গোনা হওয়া উচিত:
     * শর্তটা ঢিলে হলে — সে যদি একদিন `debit`/`credit`-ও ছোঁয় —
     * তখন এই নামটা একটা গর্ত হয়ে যাবে।
     *
     * @var array<string, string>
     */
    private const WRITES_ONLY_THE_SEAL = [
        'LedgerChain.php' => 'কেবল `prev_hash`/`row_hash` বসায়, টাকার কোনো ঘর ছোঁয় না',
    ];

    /**
     * ⓘ `LedgerEntry::create([...])` তীর দিয়ে শুরু হয় না, তাই
     * নিচের রূপটা ওটা ধরতে পারে না।
     */
    private const MODEL_WRITE = '/LedgerEntry::\s*(create|insert|upsert|updateOrCreate|firstOrCreate)\s*\(/';

    /**
     * যে ক্রিয়াগুলো সারি বদলায়।
     *
     * ⓘ `select`, `where`, `sum` ইত্যাদি বাদ — খতিয়ান পড়া সবার অধিকার,
     * লেখা নয়।
     */
    private const WRITE = '/->\s*(insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\s*\(/';

    /**
     * ⭐ খতিয়ানে লেখে কেবল ইঞ্জিন।
     */
    public function test_nothing_outside_the_engine_writes_a_ledger_row(): void
    {
        $offenders = [];
        $engineSeen = 0;

        foreach ($this->sourceFiles() as $file => $source) {
            $name = basename($file);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            foreach ($this->writeSites($source) as $line) {
                if (isset(self::THE_ENGINE[$name])) {
                    $engineSeen++;

                    continue;
                }

                if (isset(self::WRITES_ONLY_THE_SEAL[$name])) {
                    continue;
                }

                $offenders[] = $relative.':'.$line;
            }
        }

        /*
         * ⛔ শূন্য সংগ্রহে চালানো assertion সবসময় সবুজ।
         *
         * ⓘ রেগেক্সটা কিছু না পেলে নিচের দাবিটা নীরবে পাস করত, আর
         * পাহারাটা অলংকার হয়ে যেত। ⚠️ ইঞ্জিনের নিজের লেখাগুলোই এখানে
         * **must-exist নিয়ন্ত্রণ সারি**: ওগুলো না পেলে খোঁজাটা কিছুই
         * দেখছে না।
         */
        $this->assertGreaterThanOrEqual(2, $engineSeen, implode("\n", [
            'ইঞ্জিনের নিজের লেখাগুলোই পাওয়া গেল না।',
            '',
            '⛔ তাহলে এই খোঁজাটা আর কিছুই দেখছে না, আর নিচের দাবিটা',
            'ফাঁকা সংগ্রহে সবুজ।',
        ]));

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'এই জায়গাগুলো খতিয়ানে সরাসরি লেখে, ইঞ্জিন ছাড়া:',
            '',
            ...$offenders,
            '',
            'ⓘ ইঞ্জিন দিয়ে লিখুন ([[PostingEngine::post()]]) — ওটাই ভারসাম্য',
            'দেখে, দল-খাত আটকায় আর [[LedgerChain]]-এর সিল বসায়।',
            '',
            '⚠️ সরাসরি লেখা সারি তিনটা পাহারার একটাও পায় না, আর',
            '`abos:books-check` পরে বলবে কেউ অ্যাপের বাইরে দিয়ে খাতা',
            'বদলেছে — একটা মিথ্যা অভিযোগ, যার উৎস খুঁজে পাওয়া কঠিন।',
        ]));
    }

    /**
     * ⓘ ছাড়ের তালিকাও বাসি হতে পারে।
     */
    public function test_no_exemption_names_a_file_that_is_gone(): void
    {
        $present = array_map('basename', array_keys($this->sourceFiles()));

        $stale = array_values(array_diff(
            array_merge(array_keys(self::THE_ENGINE), array_keys(self::WRITES_ONLY_THE_SEAL)),
            $present,
        ));

        $this->assertSame([], $stale,
            'ছাড়ের তালিকায় এমন ফাইলের নাম আছে যা আর খতিয়ানে লেখে না — মুছে দিন: '.implode(', ', $stale));
    }

    /**
     * এই সোর্সে খতিয়ানে লেখার জায়গাগুলো।
     *
     * ── ⛔ প্রথম রূপটা মিথ্যা লাল দিয়েছিল ───────────────
     * সে টেবিলের নামের পর **আট লাইন** দেখত। ⚠️
     * `YearEndService.php:310`-এ খতিয়ানে একটা **পড়া**
     * (`exists()`) আছে, আর তিন লাইন পরে অন্য একটা মডেলের
     * (`FinancialYear`) একটা `update()` — জানালাটা দুইটাকে এক
     * করে দিয়েছিল।
     *
     * ⓘ মিথ্যা লাল দুইভাবে ক্ষতি করে: সত্যিকারের ভুলগুলো
     * চাপা পড়ে, আর একদিন কেউ গার্ডটাকেই বন্ধ করে দেয়।
     *
     * ── ⭐ এখন বাক্য ধরে, লাইন গুনে নয় ────────────────
     * টেবিলের নাম থেকে পরবর্তী `;` পর্যন্ত — অর্থাৎ একটাই
     * বাক্য। ⓘ দুইটা আলাদা বাক্য আর কখনো এক হবে না, আর
     * লম্বা চেইনও পুরোটাই দেখা হবে।
     *
     * @return list<int> লাইন নম্বর
     */
    private function writeSites(string $source): array
    {
        $sites = [];

        foreach (['LedgerEntry::', "table('ledger_entries", 'table("ledger_entries'] as $needle) {
            $at = 0;

            while (($at = strpos($source, $needle, $at)) !== false) {
                $stop = strpos($source, ';', $at);
                $statement = $stop === false
                    ? substr($source, $at)
                    : substr($source, $at, $stop - $at);

                if (preg_match(self::WRITE, $statement) === 1
                    || preg_match(self::MODEL_WRITE, $statement) === 1) {
                    $sites[] = substr_count(substr($source, 0, $at), "\n") + 1;
                }

                $at += strlen($needle);
            }
        }

        sort($sites);

        return array_values(array_unique($sites));
    }

    /**
     * @return array<string, string> path => source
     *
     * ── ⓘ মাইগ্রেশন ইচ্ছাকৃতভাবে বাইরে ─────────────────────────────
     * একটা মাইগ্রেশন **ঘোষিত, একবারের, পর্যালোচিত** বদল, আর সিল ভাঙলে
     * [[LedgerChain::reseal()]] আছে ঠিক সেই কারণেই। ⚠️ রোজের কোডের
     * সাথে ওটাকে এক নিয়মে বাঁধলে তালিকাটা এত বড় হত যে কেউ আর পড়ত না।
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path()) as $entry) {
            if ($entry->getExtension() !== 'php') {
                continue;
            }

            if (str_contains($entry->getPathname(), DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = File::get($entry->getPathname());

            if (str_contains($source, 'ledger_entries') || str_contains($source, 'LedgerEntry::')) {
                $files[$entry->getPathname()] = $source;
            }
        }

        return $files;
    }
}
