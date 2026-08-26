<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ঘড়ি না বললে Carbon UTC ধরে নেয়।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `Carbon::createFromTimestamp($ts)` — দ্বিতীয় যুক্তি ছাড়া — ফল দেয়
 * **UTC-তে**, অ্যাপের ঘড়িতে নয়। ফাইলের `filemtime()` বা সেশনের
 * `last_activity` এভাবে পড়লে পর্দায় সময়টা ছয় ঘণ্টা আগে দেখায়।
 *
 * ২৬ আগস্ট ২০২৬-এ ব্যাকআপের পর্দায় ধরা পড়েছে: ফাইলের নাম
 * `abos-2026-08-26-023012`, অথচ পর্দায় লেখা **"২৫/০৮/২০২৬ ০৮:৩০ PM"**।
 * নাম আর সময় একে অপরের সাথেই মিলছিল না।
 *
 * ── এটা আজকের বড় ভুলটারই আরেক চেহারা ─────────────────────────────────
 * সকালে পাওয়া গিয়েছিল `config/app.php`-এ হাতে বসানো `'UTC'`
 * ([[TheClockRanSixHoursBehindTest]]), আর দুপুরে MySQL-এর `CURDATE()`
 * ([[NobodyAsksTheDatabaseWhatDayItIsTest]])। এটা তৃতীয় উৎস: একটা
 * লাইব্রেরির ডিফল্ট।
 *
 * তিনটারই আকার এক — **ঘড়ির উৎস একাধিক, আর একটা চুপচাপ UTC**।
 *
 * ── কেন তুলনার জায়গাতেও নিয়মটা মানা হয় ───────────────────────────────
 * `->lt($cutoff)` ধরনের তুলনা মুহূর্ত ধরে হয়, তাই ওখানে ঘড়ি না বললেও
 * ফল একই। তবু নিয়মটা সবখানে এক রাখা হয়েছে: একই ফাইলে দুই রকম লেখা
 * থাকলে পরেরজন ভুলটাই কপি করেন, আর বসান এমন জায়গায় যেখানে ফলটা
 * **দেখানো** হয়।
 */
class ATimestampWithNoClockMeansUtcTest extends TestCase
{
    /**
     * প্রতিটা `createFromTimestamp` ঘড়িটা বলে দেয়।
     */
    public function test_every_timestamp_names_its_clock(): void
    {
        $bare = [];

        foreach ($this->sources() as $file) {
            /*
             * মন্তব্য বাদ দিয়ে কেবল কোডটা।
             *
             * ── কেন, আর এটা আজ দ্বিতীয়বার ─────────────────────────────
             * এই ফাইলের ব্যাখ্যাতেই `createFromTimestamp()` লেখা আছে —
             * খালি বন্ধনী, কোনো কমা নেই। কাঁচা লেখা পড়লে পাহারাটা
             * **নিজের ব্যাখ্যাকেই** ভুল বলত।
             *
             * আজ সকালে SQL-ঘড়ির পাহারাতেও একই সমস্যা হয়েছিল, আর
             * সমাধান একই: যে পাহারা নিজের কারণ লিখতে দেয় না, সেটা
             * সবুজ করার একমাত্র উপায় হয় কারণটা মুছে ফেলা।
             */
            $src = $this->codeOnly((string) file_get_contents($file));

            foreach (['createFromTimestamp', 'createFromTimestampMs'] as $call) {
                foreach ($this->argumentsOf($src, $call) as $args) {
                    if (! str_contains($args, ',')) {
                        $bare[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' — '.$call;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bare)), implode("\n", [
            'এই জায়গাগুলোয় টাইমস্ট্যাম্প পড়া হচ্ছে ঘড়ি না বলে:',
            ...array_unique($bare),
            '',
            'Carbon তখন UTC ধরে, অ্যাপের ঘড়ি নয় — পর্দায় সময়টা ছয় ঘণ্টা',
            "আগে দেখাবে। দ্বিতীয় যুক্তি দিন: config('app.timezone')।",
        ]));
    }

    /**
     * ডিফল্টটা সত্যিই UTC — অনুমান নয়, মেপে দেখা।
     *
     * ── কেন এটা লাগে ────────────────────────────────────────────────
     * উপরের নিয়মটা কেবল তখনই দরকারি যদি ডিফল্ট সত্যিই অ্যাপের ঘড়ি
     * থেকে আলাদা হয়। Carbon একদিন আচরণ বদলালে নিয়মটা অকারণ হয়ে যেত,
     * আর তখন এই পরীক্ষাটাই আগে ভাঙবে — অর্থাৎ কথাটা জানা যাবে।
     */
    public function test_the_default_really_is_utc(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.timezone'));

        $ts = 1787689812;   // ২৬ আগস্ট ২০২৬, ০২:৩০:১২ ঢাকা

        $this->assertSame(
            '2026-08-25 20:30:12',
            Carbon::createFromTimestamp($ts)->toDateTimeString(),
            'Carbon আর UTC ধরছে না — উপরের নিয়মটা আবার ভেবে দেখুন।',
        );

        $this->assertSame(
            '2026-08-26 02:30:12',
            Carbon::createFromTimestamp($ts, config('app.timezone'))->toDateTimeString(),
        );
    }

    /** মন্তব্য ছাড়া কেবল কোডের লেখা। */
    private function codeOnly(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * একটা ডাকের ভিতরের যুক্তিগুলো — বন্ধনী গুনে।
     *
     * ── কেন রেগেক্স নয় ───────────────────────────────────────────────
     * প্রথম লেখায় এটা একটা রেগেক্স ছিল, আর সে বাসা-বাঁধা বন্ধনী গুনতে
     * পারত না। `createFromTimestamp(filemtime($p), config('app.timezone'))`
     * পড়ে সে থামত প্রথম `)`-তে, হাতে পেত `filemtime($p` — কোনো কমা নেই,
     * তাই **সঠিক কোডটাকেই ভুল বলত**।
     *
     * একটা পাহারা যদি সঠিক জিনিসকে ভুল বলে, সেটা কয়েক দিনেই বন্ধ করে
     * দেওয়া হয় — আর তখন আসল ভুলগুলোও আর ধরা পড়ে না। এই প্রকল্পে ঠিক
     * ওই কারণেই আগে দুইবার পাহারা শোধরাতে হয়েছে।
     *
     * @return list<string>
     */
    private function argumentsOf(string $src, string $call): array
    {
        $found = [];
        $needle = $call.'(';
        $at = 0;

        while (($at = strpos($src, $needle, $at)) !== false) {
            $i = $at + strlen($needle);
            $depth = 1;
            $start = $i;

            while ($i < strlen($src) && $depth > 0) {
                if ($src[$i] === '(') {
                    $depth++;
                } elseif ($src[$i] === ')') {
                    $depth--;
                }

                $i++;
            }

            $found[] = substr($src, $start, $i - $start - 1);
            $at = $i;
        }

        return $found;
    }

    /** @return list<string> */
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

        return $files;
    }
}
