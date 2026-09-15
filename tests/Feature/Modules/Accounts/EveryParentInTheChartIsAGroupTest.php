<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Services\StandardChart;
use Tests\TestCase;

/**
 * নম্বরটা আত্মীয়তার মিথ্যা ইঙ্গিত দেয়।
 *
 * ── ⛔ একই ভুল, একদিনে দুইবার, দুই দিক থেকে ─────────────────────────
 * ১৫ সেপ্টেম্বর ২০২৬-এ ছকে তেরোটা নতুন খাত বসানো হয়, আর দুইবার
 * প্যারেন্ট ভুল ধরা হয় — দুইবারই **নম্বর দেখে আন্দাজ করে**:
 *
 *   ⛔ `1121` → `1120` (মজুদ)   — আসলে `1100`, কারণ ছকে 11xx সবাই
 *      সেকশনের মাথার সরাসরি সন্তান (`1131` বসে `1100`-এ, `1130` নয়)
 *
 *   ⛔ `3210` → `3200` (উত্তোলন) — হিসাবের যুক্তিতে ঠিক, কিন্তু
 *      **`3200` গ্রুপ নয়**, ওতে সরাসরি দাখিলা বসে
 *
 * ── ⚠️ দ্বিতীয়টার দাম ───────────────────────────────────────────────
 * `Account`-এর পাহারা `install()`-কেই থামিয়ে দেয়: *"যে খাতে সরাসরি
 * এন্ট্রি বসে তার নিচে আরেকটা খাত রাখা যায় না"*। ⓘ ফলে **ছক বসায়
 * এমন প্রতিটা টেস্ট মরে** — একবারে ৫৭টা, আর সবকটার বার্তা একই, তাই
 * আসল কারণটা খুঁজে বের করতে সময় লাগে।
 *
 * ── ⭐ কেন এই পরীক্ষাটা ─────────────────────────────────────────────
 * নিয়মটা সহজ আর গোনার যোগ্য: **প্রতিটা খাতের প্যারেন্ট গ্রুপ হতে হবে।**
 * ⓘ এটা থাকলে আজকের দুইটা ভুলের একটাও ঘটত না, আর ৫৭টা লাল দেখে
 * শিকড় খোঁজার দরকার হত না — এই একটা দাবি সরাসরি সারিটার নাম বলত।
 *
 * ⚠️ পরীক্ষাটা ডাটাবেজ ছোঁয় না, ইচ্ছাকৃতভাবে: ছকটা কোডে লেখা, আর
 * ভুলটা কোডেই। ⓘ ডাটাবেজ ধরে দেখলে `install()` আগে চলতে হত, আর
 * সেটা এই ভুলেই থেমে যেত — অর্থাৎ পরীক্ষাটা কারণ না বলে ব্যতিক্রম
 * ছুড়ত, ঠিক যা আজ ঘটেছে।
 */
final class EveryParentInTheChartIsAGroupTest extends TestCase
{
    /**
     * ⛔ প্রতিটা খাতের প্যারেন্ট গ্রুপ।
     */
    public function test_every_account_hangs_under_a_group(): void
    {
        $rows = $this->chartRows();

        $isGroup = [];
        foreach ($rows as $row) {
            $isGroup[$row[0]] = (bool) $row[5];
        }

        $offenders = [];

        foreach ($rows as $row) {
            [$code, $nameEn, , , $parent] = $row;

            if ($parent === null) {
                continue;
            }

            if (! array_key_exists($parent, $isGroup)) {
                $offenders[] = $code.' ('.$nameEn.') → '.$parent.' — এমন কোনো খাতই নেই';

                continue;
            }

            if (! $isGroup[$parent]) {
                $offenders[] = $code.' ('.$nameEn.') → '.$parent.' — ওটা গ্রুপ নয়, ওতে দাখিলা বসে';
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'ছকের এই খাতগুলোর প্যারেন্ট গ্রুপ নয়:',
            '',
            '⛔ StandardChart::install() এখানেই থামবে, আর ছক বসায় এমন',
            'প্রতিটা টেস্ট মরবে — সবকটার বার্তা একই, তাই শিকড় খুঁজতে',
            'সময় যাবে।',
            '',
            'ⓘ নিয়মটা নম্বরে নয়, is_group-এ: 3200 (উত্তোলন) দেখতে',
            'গ্রুপের মতো, কিন্তু ওতে সরাসরি দাখিলা বসে।',
            '',
            ...$offenders,
        ]));
    }

    /*
     * ⛔ এখানে দ্বিতীয় একটা দাবি ছিল, আর সেটা মিথ্যা অভিযোগ করত।
     *
     * লেখা ছিল: "গ্রুপ খাত কোড ধরে টাকার ভূমিকায় খোঁজা হবে না"।
     * ⚠️ কিন্তু এই ছকে `1101` (হাতে নগদ) **সত্যিই গ্রুপ** — তার
     * নিচে `1101-01 অফিসের নগদ`, `1101-02 কাউন্টার টিল` বসে, আর
     * কোডটা খোঁজা হয় **প্যারেন্ট হিসেবে**, পাতা হিসেবে নয়।
     *
     * ⓘ ছয়টা খাত অভিযুক্ত হয়েছিল (1101 · 1102 · 1105 · 1200 · 4000
     * · 5200), আর ছয়টাই ঠিক ছিল। ⭐ মিথ্যা অভিযোগ করা পাহারা
     * কিছুদিনের মধ্যেই বন্ধ করে দেওয়া হয়, তাই দাবিটা তুলে দেওয়া হলো।
     *
     * ⓘ দায়িত্বটা [[MoneyNeverLandsOnAGroupAccountTest]]-এর — সে চলার
     * সময় দেখে টাকা কোথায় বসল, আর সেটাই সঠিক জায়গা।
     */

    /**
     * ⓘ তালিকাটা খালি নয় — শূন্য সংগ্রহে দাবি সবসময় সবুজ।
     */
    public function test_the_chart_itself_is_not_empty(): void
    {
        $this->assertGreaterThan(60, count($this->chartRows()),
            'ছকটাই ছোট হয়ে গেছে — খোঁজাটা কি আর কাজ করছে?');
    }

    /**
     * ছকের সারিগুলো — ডাটাবেজ নয়, কোড থেকে।
     *
     * ⚠️ `StandardChart::definition()` ব্যক্তিগত, আর সেটা ঠিকই আছে: বাইরের
     * কেউ ছকটা হাতে নিয়ে ঘাঁটবে না। ⓘ কেবল এই পাহারাটার জন্য
     * প্রতিফলন — আর সেটাই সবচেয়ে সৎ, কারণ যা যাচাই হচ্ছে সেটা
     * **হুবহু যা চলবে**, তার কোনো নকল নয়।
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: bool, 6: array<string, mixed>}>
     */
    private function chartRows(): array
    {
        $method = new \ReflectionMethod(StandardChart::class, 'definition');
        $method->setAccessible(true);

        return $method->invoke(app(StandardChart::class));
    }

    /**
     * যে কোডগুলো অন্য মডিউল **ধরে ধরে** খোঁজে — ধ্রুবক হিসেবে ঘোষিত।
     *
     * ⓘ হাতে লেখা নয়, প্রতিফলনে তোলা: কেউ নতুন ধ্রুবক যোগ করলে
     * পাহারাটা নিজে থেকেই সেটাকেও দেখবে।
     *
     * @return list<string>
     */
    private function systemMoneyCodes(): array
    {
        $codes = [];

        foreach ((new \ReflectionClass(StandardChart::class))->getConstants() as $name => $value) {
            if (is_string($value) && preg_match('/^\d{4}$/', $value) && ! str_contains($name, 'GROUP')) {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }
}
