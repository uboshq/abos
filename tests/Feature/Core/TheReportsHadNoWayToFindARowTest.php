<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * চারশো সারির রিপোর্টে একটা সারি খুঁজে পাওয়ার কোনো উপায় ছিল না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"report e search bar diba"*। ⚠️ তালিকার পর্দাগুলোয় ঘরটা ছিল, রিপোর্টে
 * **একটাও** ছিল না — `report/show.blade.php`-এ সোজা `:search="false"`
 * লেখা ছিল, অর্থাৎ ইচ্ছা করে বন্ধ।
 *
 * ── ⛔ আসল ফাঁদটা ঘরটা বসানোয় নয়, কোথায় ছাঁকা হয় তাতে ───────────────
 * সহজ পথ ছিল আনা সারিগুলো PHP-তে ছেঁকে ফেলা। ⚠️ তাতে পর্দায় দশটা সারি
 * দেখা যেত, আর নিচে **চারশো সারির যোগফল** — সংখ্যাটা ভুল, আর ভুল বলে
 * চেনার কোনো উপায় নেই।
 *
 * ⭐ তাই শব্দটা কোয়েরিতে বসে, যোগফল ও গণনার আগে। এই পরীক্ষাটা ঠিক ঐ
 * জিনিসটাই মাপে: খুঁজলে সারিও কমে, **যোগফলও** কমে।
 */
final class TheReportsHadNoWayToFindARowTest extends TestCase
{
    use RefreshDatabase;

    private ReportEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        CompanyContext::set(Company::query()->where('code', 'TDEPOT')->firstOrFail()->id);

        $this->engine = app(ReportEngine::class);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একটা শব্দ খুঁজলে ফলটা সত্যিই ছোট হয়।
     *
     * ⓘ কোন রিপোর্টে পরীক্ষা হবে তা আগে থেকে বাছা হয় না — ডেমো ডেটায়
     * যেটায় সত্যিই কয়েকটা আলাদা সারি আছে, সেটাই নেওয়া হয়। ⚠️ নাম ধরে
     * বাছলে একদিন ঐ রিপোর্টটা বদলে যেত আর পরীক্ষাটা খালি ডেটায় সবুজ
     * থাকত — কিছু না মেপেই।
     */
    public function test_searching_narrows_the_rows_and_the_totals(): void
    {
        [$key, $column, $needle, $before, $window] = $this->aReportWithRowsToFind();

        $after = $this->engine->run($key, [...$window, 'q' => $needle], perPage: 200);

        $this->assertLessThan($before->totalRows, $after->totalRows, implode(PHP_EOL, [
            $key.': "'.$needle.'" খুঁজে সারির সংখ্যা কমেনি।',
            '',
            'অর্থাৎ শব্দটা কোয়েরিতে পৌঁছায়নি — ঘরটা আঁকা হচ্ছে আর কিছুই',
            'করছে না, যেটা ঠিক আগের অবস্থা।',
        ]));

        $this->assertGreaterThan(0, $after->totalRows,
            $key.': "'.$needle.'" খুঁজে একটাও সারি নেই, অথচ শব্দটা একটা সারি থেকেই নেওয়া।');

        foreach ($after->rows as $row) {
            $this->assertStringContainsStringIgnoringCase($needle, (string) ($row[$column] ?? ''),
                $key.': খোঁজার ফলে এমন সারি এসেছে যাতে শব্দটা নেই।');
        }

        /*
         * ⭐ আসল কথাটা এখানে: যোগফলও ছোট হয়েছে।
         *
         * ⛔ সারি ছেঁকে যোগফল না ছাঁকলে পর্দায় দশটা সারির পাশে চারশোর
         * যোগফল বসত — আর কেউ ধরতে পারত না।
         */
        if ($before->totals === []) {
            return;
        }

        /*
         * ⚠️ একটা নির্দিষ্ট কলাম ধরে দাবি করা যায় না।
         *
         * ⛔ প্রথমে প্রথম শূন্য-নয় কলামটা ধরে লেখা হয়েছিল, আর সেটা লাল
         * হলো ডে বুকে — বাদ পড়া সারিগুলোর `debit` শূন্য ছিল, তাই ঐ
         * যোগফলটা সত্যিই বদলায়নি। ⓘ দাবিটা ভুল ছিল, কোডটা নয়।
         *
         * ⭐ সঠিক প্রশ্নটা: যোগফলগুলোর **কোনো একটা** বদলেছে কি না।
         */
        $this->assertNotSame($before->totals, $after->totals, implode(PHP_EOL, [
            $key.': খুঁজে সারি কমেছে ('.$before->totalRows.' → '.$after->totalRows.'),',
            'অথচ একটা যোগফলও বদলায়নি।',
            '',
            'তার মানে যোগফলগুলো খোঁজার আগের কোয়েরি থেকে আসছে — পর্দায়',
            'কয়েকটা সারি দেখা যাবে আর নিচে পুরো তালিকার যোগফল বসবে।',
        ]));
    }

    /** ⛔ `%` টাইপ করলে সব সারি মেলে না — ওটা অক্ষর, নিয়ম নয়। */
    public function test_a_wildcard_typed_by_hand_is_just_a_character(): void
    {
        [$key, , , $before, $window] = $this->aReportWithRowsToFind();

        $after = $this->engine->run($key, [...$window, 'q' => '%'], perPage: 200);

        $this->assertLessThan($before->totalRows, $after->totalRows, implode(PHP_EOL, [
            $key.': `%` লিখলে প্রতিটা সারি মিলে যাচ্ছে।',
            '',
            'অর্থাৎ শব্দটা LIKE-এর নিয়ম হিসেবে যাচ্ছে, অক্ষর হিসেবে নয় —',
            'আর তখন খোঁজার ঘরটা কিছুই ছাঁকে না।',
        ]));
    }

    /** ফাঁকা ঘর মানে কোনো ছাঁকনি নেই — সব সারিই থাকে। */
    public function test_an_empty_box_hides_nothing(): void
    {
        [$key, , , $before, $window] = $this->aReportWithRowsToFind();

        foreach (['', '   '] as $nothing) {
            $this->assertSame(
                $before->totalRows,
                $this->engine->run($key, [...$window, 'q' => $nothing], perPage: 200)->totalRows,
                $key.': ফাঁকা খোঁজার ঘর সারি লুকাচ্ছে।',
            );
        }
    }

    /**
     * ⭐ রিপোর্টের পর্দায় Enter মানে খোঁজা, আর কিছু নয়।
     *
     * ── ⛔ কেন এটা আলাদা করে মাপা হয় ────────────────────────────────
     * abos-8b আজ রাতে ধরেছেন মালিকের *"search box kaj kore na"* আসলে
     * HTML-এর একটা নিয়ম: ঘরে Enter চাপলে ব্রাউজার ফর্মের **প্রথম**
     * সাবমিট বোতামটা চালায়, আর এই ফর্মের প্রথমটা ছিল ঘনত্বের বোতাম।
     *
     * ⚠️ ওঁদের পাহারাটা একটা **তালিকার** পর্দা মাপে। ⛔ রিপোর্টের পর্দা
     * আলাদা: ছাঁকনির ঘরগুলো টুলবারের ভিতরে বসে, আর ওখানে একটা সাবমিট
     * খোঁজার ঘরের আগে পড়ে গেলে বাগটা এখানে ফিরে আসত — অথচ ওঁদের
     * পাহারা সবুজই থাকত।
     */
    public function test_enter_on_a_report_screen_only_searches(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = (string) $this->get(route('accounts.report.show', ['slug' => 'day-book']))
            ->assertOk()
            ->getContent();

        $at = strpos($html, 'name="q"');

        $this->assertNotFalse($at, implode(PHP_EOL, [
            'রিপোর্টের পাতায় খোঁজার ঘরই নেই।',
            '',
            'show.blade.php-তে :search আবার বন্ধ হয়ে গেছে, নয়তো',
            'রিপোর্টটার একটাও খোঁজার মতো কলাম নেই।',
        ]));

        $start = strrpos(substr($html, 0, $at), '<form');
        $end = strpos($html, '</form>', $at);

        $this->assertNotFalse($start, 'খোঁজার ঘরটা কোনো ফর্মের ভিতরে নেই — Enter কিছুই জমা দেবে না।');

        $form = substr($html, (int) $start, (int) $end - (int) $start);

        preg_match_all("/<button[^>]*type=[\"']submit[\"'][^>]*>/i", $form, $submits);

        $this->assertNotSame([], $submits[0], implode(PHP_EOL, [
            'ফর্মে একটাও সাবমিট বোতাম নেই — Enter কিছুই করবে না।',
        ]));

        $first = $submits[0][0];

        $this->assertStringNotContainsString('name=', $first, implode(PHP_EOL, [
            'রিপোর্টের ফর্মে প্রথম সাবমিট বোতামটা সাধারণ খোঁজা নয়:',
            '',
            $first,
            '',
            '⛔ Enter চাপলে ব্যবহারকারী খোঁজার বদলে ওটাই চালাবেন — ঠিক',
            'যে বাগটা তালিকার পর্দায় আজ সারানো হলো।',
        ]));
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * ডেমো ডেটায় খুঁজে বের করার মতো সারি আছে এমন একটা রিপোর্ট।
     *
     * @return array{0: string, 1: string, 2: string, 3: ReportResult, 4: array<string, mixed>}
     */
    private function aReportWithRowsToFind(): array
    {
        static $found = null;

        if ($found !== null) {
            return $found;
        }

        foreach ($this->reports() as $key => $report) {
            foreach ($report->searchableColumns() as $column) {
                /*
                 * ⚠️ পরিসরটা মনে রাখা হয়, আর ফেরতও দেওয়া হয়।
                 *
                 * ⛔ প্রথমে কেবল `['q' => ...]` দিয়ে দ্বিতীয় রানটা চালানো
                 * হয়েছিল, আর ইঞ্জিন তারিখ না পেয়ে **চলতি মাস** ধরে নিত।
                 * ⓘ ফল শূন্য সারি, আর দেখে মনে হচ্ছিল খোঁজাটা ভেঙেছে —
                 * অথচ ভেঙেছিল পরীক্ষাটাই।
                 */
                $window = ['from' => '2000-01-01', 'to' => '2030-12-31'];

                $result = $this->engine->run($key, $window, perPage: 200);

                if ($result->totalRows < 2) {
                    continue 2;
                }

                $values = array_values(array_unique(array_filter(array_map(
                    fn (array $row) => trim((string) ($row[$column] ?? '')),
                    $result->rows,
                ))));

                /*
                 * ⚠️ অন্তত দুইটা **আলাদা** মান লাগে। একটাই মান হলে সেটা
                 * খুঁজলে সব সারিই আসত, আর পরীক্ষাটা "ছোট হয়েছে" দেখতে
                 * না পেয়ে লাল হত — ভুল কারণে।
                 */
                if (count($values) < 2) {
                    continue;
                }

                $needle = $this->wordFrom($values[0], $values);

                if ($needle === null) {
                    continue;
                }

                return $found = [$key, $column, $needle, $result, $window];
            }
        }

        $this->fail(implode(PHP_EOL, [
            'ডেমো ডেটায় খোঁজার মতো সারিওয়ালা একটাও রিপোর্ট নেই।',
            '',
            'তাহলে এই পরীক্ষাটা কিছুই মাপতে পারে না — আর সবুজ থাকা',
            'মানে কেবল ডেটা নেই, কাজ করছে নয়।',
        ]));
    }

    /**
     * এমন একটা টুকরো যা প্রথম মানে আছে অথচ সব মানে নেই।
     *
     * @param  list<string>  $all
     */
    private function wordFrom(string $value, array $all): ?string
    {
        foreach ([8, 6, 4, 3] as $length) {
            if (mb_strlen($value) < $length) {
                continue;
            }

            $piece = mb_substr($value, 0, $length);

            $matches = array_filter($all, fn (string $v) => mb_stripos($v, $piece) !== false);

            if (count($matches) < count($all)) {
                return $piece;
            }
        }

        return null;
    }

    /** @return array<string, ReportDefinition> */
    private function reports(): array
    {
        $property = new ReflectionProperty($this->engine, 'reports');
        $property->setAccessible(true);

        /** @var array<string, ReportDefinition> $reports */
        $reports = $property->getValue($this->engine);

        return $reports;
    }
}
