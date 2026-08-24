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
            foreach ($this->sqlStrings($file) as $line => $sql) {
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
        file_put_contents($file, <<<'PHP'
            <?php
            // CURDATE() এই মন্তব্যে আছে, আর ধরা পড়ার কথা নয়
            $q = DB::raw('DATEDIFF(a.b, CURDATE()) as c');
            PHP);

        $hits = [];

        foreach ($this->sqlStrings($file) as $sql) {
            if (preg_match('/\bCURDATE\s*\(/i', $sql)) {
                $hits[] = $sql;
            }
        }

        unlink($file);

        $this->assertCount(1, $hits, 'মন্তব্যের ভিতরেরটা গোনা হয়েছে, অথবা আসলটা ধরা পড়েনি।');
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
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * ফাইলের কেবল স্ট্রিং টোকেনগুলো — মন্তব্য ও কোড বাদ।
     *
     * @return array<int, string> লাইন => লেখা
     */
    private function sqlStrings(string $file): array
    {
        $out = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[$token[2]] = $token[1];
            }
        }

        return $out;
    }
}
