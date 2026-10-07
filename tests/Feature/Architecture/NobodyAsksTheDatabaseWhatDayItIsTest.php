<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * "আজ" কোনটা — প্রশ্নটা ডাটাবেজকে করা হয় না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * মেয়াদ-উত্তীর্ণ লটের রিপোর্টে লেখা ছিল
 * `DATEDIFF(b.expiry_date, CURDATE())`। MySQL-এর `CURDATE()` উত্তর দেয়
 * **ডাটাবেজ সার্ভারের** ঘড়ি ধরে; অ্যাপ উত্তর দেয় `config('app.timezone')`
 * ধরে। দুইটা এক হওয়ার কোনো নিশ্চয়তা কোথাও লেখা নেই।
 *
 * ২৫/৮/২০২৬-এ লাইভে দুইটা সত্যিই আলাদা ছিল: অ্যাপ চলত UTC-তে
 * (২৪ তারিখ), MySQL চলত মেশিনের নিজের ঘড়িতে (২৫)। ফলে গোটা অ্যাপের
 * মধ্যে ঠিক ওই একটা কলাম এক দিন এগিয়ে থাকত — আজ মেয়াদ শেষ হওয়া লট
 * দেখাত "১ দিন বাকি", আর ফেরত পাঠানোর শেষ দিনটা ওভাবেই হাতছাড়া হয়।
 *
 * ── কেন আজ মিলে যাওয়াটা যথেষ্ট নয় ────────────────────────────────────
 * অ্যাপের ঘড়ি ঢাকায় সরানোয় আজ দুইটা মিলে গেছে। কিন্তু মিলেছে
 * **কাকতালীয়ভাবে**: MySQL এই একই মেশিনে চলে, আর তার `time_zone` বসানো
 * `SYSTEM`-এ। ডাটাবেজ একদিন ম্যানেজড হোস্টে গেলে — যেখানে ডিফল্ট UTC —
 * ফাঁকটা নীরবে ফিরে আসত, আর কোনো পরীক্ষা ভাঙত না।
 *
 * নীরবে ঠিক থাকা আর যাচাই করে ঠিক থাকা এক জিনিস নয়। এই ফাইলটা
 * দ্বিতীয়টা।
 *
 * ── কেবল SQL-এর লেখাগুলো দেখা হয়, মন্তব্য নয় ─────────────────────────
 * `token_get_all()` দিয়ে ফাইল ভেঙে কেবল **স্ট্রিং টোকেন** দেখা হয়।
 * নাহলে ঠিক উপরের এই মন্তব্যটাই — যেখানে `CURDATE()` লেখা আছে —
 * পরীক্ষাটাকে ভাঙাত, আর তখন ব্যাখ্যাটা মুছে ফেলা ছাড়া উপায় থাকত না।
 * একটা পাহারা যদি নিজের ব্যাখ্যা লিখতে না দেয়, তবে ব্যাখ্যাটাই হারায়।
 */
class NobodyAsksTheDatabaseWhatDayItIsTest extends TestCase
{
    /**
     * যে SQL ফাংশনগুলো ডাটাবেজের নিজের ঘড়ি পড়ে।
     *
     * @var list<string>
     */
    private const CLOCKS = [
        'CURDATE', 'CURTIME', 'CURRENT_DATE', 'CURRENT_TIME',
        'CURRENT_TIMESTAMP', 'NOW', 'SYSDATE', 'UTC_DATE',
        'UTC_TIME', 'UTC_TIMESTAMP', 'LOCALTIME', 'LOCALTIMESTAMP',

        /*
         * ⛔ এটা তালিকায় ছিল না — ২১ সেপ্টেম্বর ২০২৬, অডিটে ধরা।
         *
         * ⓘ `UNIX_TIMESTAMP()` আর্গুমেন্ট ছাড়া ডাকলে সে-ও ডাটাবেজের
         * নিজের ঘড়িই পড়ে, কেবল উত্তরটা সেকেন্ডে দেয়। ⚠️ বাকি বারোটার
         * সাথে তার কোনো পার্থক্য নেই — সার্ভারের সময় অ্যাপের সময় নয়।
         */
        'UNIX_TIMESTAMP',
    ];

    /**
     * যে জায়গাগুলোয় এটা সত্যিই ঠিক — কারণসহ।
     *
     * ── কেন মাইগ্রেশন আলাদা ─────────────────────────────────────────
     * `DEFAULT CURRENT_TIMESTAMP` একটা **ঘরের সংজ্ঞা**, কোনো হিসাব নয়।
     * ওটা কেবল তখনই বসে যখন অ্যাপ নিজে কিছু বসায়নি, আর ABOS-এ প্রতিটা
     * সারি Eloquent-এর হাত দিয়ে যায় — তাই ওটা একটা জাল, চলার পথ নয়।
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // এখানে নাম => কারণ। খালি থাকাই স্বাভাবিক অবস্থা।
    ];

    public function test_no_query_reads_the_database_clock(): void
    {
        $found = [];

        foreach ($this->sources() as $file) {
            foreach ($this->sqlStrings($file) as ['line' => $line, 'sql' => $sql]) {
                foreach (self::CLOCKS as $clock) {
                    if (preg_match('/\b'.$clock.'\s*\(/i', $sql)
                        || preg_match('/\b'.$clock.'\b(?!\s*\()/i', $sql) && str_contains($clock, '_')) {
                        $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

                        if (isset(self::ALLOWED[$short])) {
                            continue;
                        }

                        $found[] = "{$short}:{$line} — {$clock}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($found)), implode("\n", [
            'এই SQL-গুলো ডাটাবেজকে জিজ্ঞেস করছে এখন কখন:',
            ...array_unique($found),
            '',
            'ডাটাবেজের ঘড়ি অ্যাপের ঘড়ি নয়। তারিখটা PHP থেকে বেঁধে দিন —',
            "যেমন: ->selectRaw('DATEDIFF(x, ?) as d', [Carbon::today()->toDateString()])",
        ]));
    }

    /**
     * পাহারাটা সত্যিই ধরে।
     *
     * উপরের পরীক্ষাটা আজকের অবস্থা মাপে; এটা মাপে খোঁজার কাজটা কাজ করে
     * কি না। `sqlStrings()` সবসময় খালি ফেরালে উপরেরটাও দিব্যি সবুজ থাকত,
     * আর পাহারাটা থাকত নামে মাত্র।
     */
    public function test_the_guard_actually_finds_one(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'clock').'.php';

        /*
         * ⭐ তৃতীয় লাইনটা **এক লাইনে দুইটা স্ট্রিং** — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ আগে [[sqlStrings()]] লাইন নম্বরকে চাবি করত, তাই একই লাইনের
         * আগের স্ট্রিংটা চাপা পড়ত। ⚠️ আর ঐ আকারটাই এখানে সবচেয়ে
         * স্বাভাবিক: `->selectRaw('… NOW() …', ['…'])`। তাই নমুনাটাও
         * এখন ঠিক ওভাবেই লেখা, নইলে দাবিটা ফাঁকটা মাপতই না।
         */
        file_put_contents($file, <<<'PHP'
            <?php
            // CURDATE() এই মন্তব্যে আছে, আর ধরা পড়ার কথা নয়
            $q = DB::raw('DATEDIFF(a.b, CURDATE()) as c'); $r = q('x', 'NOW() as n');
            PHP);

        $hits = [];

        foreach ($this->sqlStrings($file) as ['sql' => $sql]) {
            foreach (['CURDATE', 'NOW'] as $clock) {
                if (preg_match('/\b'.$clock.'\s*\(/i', $sql)) {
                    $hits[] = $clock;
                }
            }
        }

        unlink($file);

        sort($hits);

        $this->assertSame(
            ['CURDATE', 'NOW'],
            $hits,
            'এক লাইনের দুইটা স্ট্রিংয়ের একটা হারিয়েছে, অথবা মন্তব্যের ভিতরেরটা গোনা হয়েছে।',
        );
    }

    /**
     * অ্যাপের প্রতিটা PHP ফাইল।
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }

            /*
             * ⛔ ভাষার ফাইল বাদ — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ এই পাহারাটা স্ট্রিং টোকেন পড়ে SQL খোঁজে, আর ভাষার
             * ফাইল পুরোটাই স্ট্রিং। ⓘ ফল: `'Due now (including overdue)'`
             * লেখাটা `/\bNOW\s*\(/i`-এ মিলে যেত, আর পাহারাটা **মানুষের
             * পড়ার একটা বাক্যকে** ডাটাবেসের ঘড়ি বলে অভিযোগ করত।
             *
             * ⭐ আর মিথ্যা অভিযোগ করা পাহারা সবচেয়ে খারাপ ধরনের পাহারা:
             * কয়েক দিনেই কেউ ওটাকে ছাড়ের তালিকায় ফেলে দেয় বা বন্ধ করে
             * দেয়, আর তখন সে **আসল** ভুলটাও আর ধরে না। ⓘ এখানে ঠিক
             * সেটাই হচ্ছিল — লালটা এতদিন বসে ছিল বলে ঐ ফাইলের সত্যিকারের
             * দুইটা অন্ধ জায়গা ঢাকা পড়ে ছিল।
             *
             * ⓘ ভাষার ফাইলে SQL থাকতেই পারে না; ওখানে কেবল পর্দার লেখা।
             */
            if (str_contains(str_replace('\\', '/', $f->getPathname()), '/Resources/lang/')) {
                continue;
            }

            $files[] = $f->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * ফাইলের কেবল স্ট্রিং টোকেনগুলো — মন্তব্য ও কোড বাদ।
     *
     * @return list<array{line: int, sql: string}> লাইন => লেখা
     */
    private function sqlStrings(string $file): array
    {
        $out = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                /*
                 * ⛔ এখানে ছিল `$out[$token[2]] = $token[1];` — অর্থাৎ
                 * চাবিটা **লাইন নম্বর**, আর একই লাইনের আগের স্ট্রিংগুলো
                 * চাপা পড়ে যেত (২১ সেপ্টেম্বর ২০২৬, অডিটে ধরা)।
                 *
                 * ⚠️ ঠিক যে ধরনের লেখা এখানে বেশি হয়, সেখানেই ফাঁকটা:
                 * `->selectRaw('… NOW() …', ['…'])` এক লাইনে লেখা থাকলে
                 * শেষ স্ট্রিংটাই কেবল দেখা হত, আর `NOW()` চুপচাপ পার
                 * হয়ে যেত।
                 *
                 * ⓘ এখন প্রতিটা স্ট্রিং আলাদা সারি, আর লাইন নম্বরটা
                 * সারির ভিতরে — বার্তায় জায়গাটা বলা যায়, অথচ কিছু
                 * হারায় না।
                 */
                $out[] = ['line' => $token[2], 'sql' => $token[1]];
            }
        }

        return $out;
    }
}
