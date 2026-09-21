<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * ছাঁকনির ঘরটা আঁকা হয়, অথচ কিছুই বদলায় না।
 *
 * ── ⛔ মালিকের অভিযোগ, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Filter Fanctional korba full"*। ⓘ মেপে দেখা গেল কথাটা সত্যি, আর
 * ভুলটা দুই জায়গায় ছিল — দুইটাই **নীরব**:
 *
 * ১. তিনটা রিপোর্ট `branch` ঘোষণা করত, পর্দা ঘরটা আঁকত, আর কোয়েরি ওটা
 *    পড়তই না।
 * ২. আটটা রিপোর্ট কন্ট্রোলারে `$request->only([...])`-র আটটা **হাতে
 *    লেখা** তালিকা ছিল, আর ছয়টা `party_type_id` পাঠাত না। ⚠️ অর্থাৎ
 *    কোয়েরি ছাঁকনিটা মানত, কিন্তু মানটা কোনোদিন পৌঁছাত না।
 *
 * ⛔ দুইটার একটাতেও কিছু ভাঙত না: ব্যবহারকারী বেছে দিতেন, পাতা আবার
 * আসত, আর ফল অবিকল আগের মতোই। কোনো ত্রুটি নেই, কোনো পরীক্ষা লাল নেই।
 *
 * ── ⭐ কীভাবে মাপা হয় ─────────────────────────────────────────────────
 * ঘোষণা পড়ে নয় — **কোয়েরি দুইবার বানিয়ে**। একবার ছাঁকনির মানটা এমন
 * একটা সংখ্যায় যেটা আর কোথাও আসতে পারে না, একবার ছাড়া। SQL আর তার
 * বাঁধা মানগুলো অবিকল এক থাকলে ঘরটা কিছুই করছে না।
 *
 * ⓘ প্রথমে ক্লোজারের উৎস grep করে মাপা হয়েছিল, আর সেটা **ভুল উত্তর**
 * দিয়েছিল: বেশিরভাগ ক্লোজার `fn ($f) => self::query($f)`, তাই ভিতরে
 * কিছুই দেখা যেত না আর সব ছাঁকনি মৃত মনে হত। কোয়েরিকে জিজ্ঞেস করলে
 * উত্তরটা অনুমান নয়।
 */
final class ADrawnFilterBoxThatChangesNothingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ছাঁকনির নাম → ঠিকানার ঘর, আর এমন একটা মান যা আর কোথাও আসে না।
     *
     * @var array<string, array<string, mixed>>
     */
    private const PROBE = [
        'date_range' => ['from' => '1997-03-11', 'to' => '1997-04-17'],
        'branch' => ['branch_id' => 987654],
        'party_type' => ['party_type_id' => 987653],
        'cost_centre' => ['cost_center_id' => 987652],
        'account' => ['account_id' => 987651],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** ঘোষিত প্রতিটা ছাঁকনি সত্যিই কোয়েরিতে পৌঁছায়। */
    public function test_every_declared_filter_changes_the_query(): void
    {
        $dead = [];
        $measured = 0;

        foreach ($this->reports() as $key => $report) {
            foreach ($report->filters as $filter) {
                $this->assertArrayHasKey($filter, self::PROBE, implode(PHP_EOL, [
                    $key.' একটা অচেনা ছাঁকনি ঘোষণা করেছে: '.$filter,
                    '',
                    'এই ফাইলের PROBE তালিকায় ওটার জন্য একটা মান লিখুন,',
                    'নাহলে নতুন ছাঁকনিটা কেউ মাপবে না — আর ঠিক সেভাবেই',
                    'আগেরগুলো মরে পড়ে ছিল।',
                ]));

                $measured++;

                if ($this->shapeOf($report, []) === $this->shapeOf($report, self::PROBE[$filter])) {
                    $dead[] = $key.' / '.$filter;
                }
            }
        }

        $this->assertGreaterThan(50, $measured, implode(PHP_EOL, [
            'মাপার মতো যথেষ্ট ছাঁকনি পাওয়া গেল না ('.$measured.'টা)।',
            '',
            'রিপোর্টগুলো সম্ভবত নিবন্ধিতই হয়নি, আর তখন এই পাহারাটা',
            'সবুজ থাকে ঠিক সেই কারণে যেটা সবচেয়ে খারাপ: দেখার মতো',
            'কিছু নেই বলে।',
        ]));

        $this->assertSame([], $dead, implode(PHP_EOL, [
            'এই ছাঁকনিগুলো ঘোষণা করা আছে, পর্দায় ঘর আঁকা হয়, অথচ কোয়েরি',
            'ওগুলো পড়ে না:',
            '',
            implode(PHP_EOL, $dead),
            '',
            '⛔ ব্যবহারকারী বেছে দেবেন আর কিছুই বদলাবে না — কোনো ত্রুটি',
            'ছাড়াই। হয় কোয়েরিতে ছাঁকনিটা বসান, নয় ঘোষণা থেকে নামটা',
            'তুলে দিন যাতে ঘরটা আর আঁকা না হয়।',
        ]));
    }

    /**
     * ঠিকানার ঘরগুলো আর হাতে লেখা হয় না।
     *
     * ── ⚠️ কেন এটা আলাদা করে পাহারা দেওয়া দরকার ──────────────────────
     * উপরের পরীক্ষাটা কোয়েরি মাপে, কন্ট্রোলার নয়। ⛔ একটা কোয়েরি
     * ছাঁকনিটা নিখুঁতভাবে মানতে পারে, আর কন্ট্রোলার মানটা না পাঠালে
     * পর্দায় ঘরটা তবু মরা — আর উপরের পরীক্ষাটা সবুজ থাকত।
     *
     * ⓘ এখন ঘরগুলো [[ReportDefinition::requestKeys()]] থেকে আসে, তাই
     * প্রশ্নটা একটাই: কেউ আবার হাতে লিখেছে কি না।
     */
    public function test_no_controller_hand_writes_its_own_filter_list(): void
    {
        $handWritten = [];

        foreach ($this->reportControllers() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match("/only\(\[\s*'from'/", $source) === 1) {
                $handWritten[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $handWritten, implode(PHP_EOL, [
            'এই কন্ট্রোলারগুলো ঠিকানার ঘরের তালিকা নিজে লিখছে:',
            '',
            implode(PHP_EOL, $handWritten),
            '',
            '⚠️ আটটা তালিকা আটবার হাতে লিখলে ওগুলো এক থাকে না — ঠিক',
            'সেভাবেই ছয়টা কন্ট্রোলার party_type_id পাঠানো বন্ধ করেছিল,',
            'আর পর্দার ঘরটা মরে পড়ে ছিল।',
            '',
            '$request->only($definition->requestKeys()) ব্যবহার করুন।',
        ]));
    }

    /**
     * ⭐ ঘোষিত প্রতিটা ছাঁকনির ঘর সত্যিই ঠিকানা থেকে নেওয়া হয়।
     *
     * ⓘ উপরের দুইটা মিলিয়ে ফাঁকটা বন্ধ হয়: কোয়েরি ছাঁকনিটা পড়ে, আর
     * কন্ট্রোলার মানটা পাঠায়। এটা দ্বিতীয়টার সরাসরি প্রমাণ।
     */
    public function test_the_request_keys_cover_every_declared_filter(): void
    {
        $missing = [];

        foreach ($this->reports() as $key => $report) {
            $keys = $report->requestKeys();

            foreach ($report->filters as $filter) {
                foreach (array_keys(self::PROBE[$filter] ?? []) as $asked) {
                    if (! in_array($asked, $keys, true)) {
                        $missing[] = $key.' / '.$filter.' → '.$asked;
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode(PHP_EOL, [
            'এই ঘরগুলো ঘোষিত, অথচ ঠিকানা থেকে নেওয়াই হয় না:',
            '',
            implode(PHP_EOL, $missing),
            '',
            'ReportDefinition::ASKED_AS তালিকায় নামটা যোগ করুন।',
        ]));

        // ⓘ খোঁজার ঘরটা প্রতিটা রিপোর্টের সাথেই যায় — কেউ ঘোষণা করে না।
        foreach ($this->reports() as $key => $report) {
            $this->assertContains('q', $report->requestKeys(),
                $key.' খোঁজার শব্দটা ঠিকানা থেকে নেয় না — ঘরটা আঁকা হবে আর কাজ করবে না।');
        }
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /** @return array<string, ReportDefinition> */
    private function reports(): array
    {
        $engine = app(ReportEngine::class);

        $property = new ReflectionProperty($engine, 'reports');
        $property->setAccessible(true);

        /** @var array<string, ReportDefinition> $reports */
        $reports = $property->getValue($engine);

        return $reports;
    }

    /**
     * কোয়েরিটার আকার — SQL আর তার বাঁধা মান একসাথে।
     *
     * ⚠️ কেবল SQL মিলিয়ে দেখলে যথেষ্ট হত না: `where branch_id = ?`
     * দুইটা আলাদা মানেও অবিকল একই SQL, আর তখন একটা **কাজ করা** ছাঁকনিকে
     * মৃত মনে হত।
     *
     * @param  array<string, mixed>  $extra
     */
    private function shapeOf(ReportDefinition $report, array $extra): string
    {
        $filters = [
            'company_id' => $this->company->id,
            'from' => '2000-01-01',
            'to' => '2030-12-31',
            'branch_id' => null,
            'party_type_id' => null,
            'cost_center_id' => null,
            'account_id' => null,
            ...$extra,
        ];

        $query = ($report->query)($filters);

        return $query->toSql().'||'.json_encode(array_map(
            fn ($b) => is_scalar($b) ? (string) $b : gettype($b),
            $query->getBindings(),
        ));
    }

    /** @return list<string> */
    private function reportControllers(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, 'Controller.php')
                && str_contains((string) file_get_contents($path), 'ReportEngine')) {
                $found[] = $path;
            }
        }

        $this->assertNotSame([], $found, 'একটাও রিপোর্ট কন্ট্রোলার পাওয়া গেল না — পাহারাটা কিছুই দেখছে না।');

        return $found;
    }
}
