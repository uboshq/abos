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

        /*
         * ⭐ পরে আসা ছাঁকনিগুলো — ৬ অক্টোবর ২০২৬ (ec)। ⛔ তালিকা ২১ সেপ্টেম্বরের পাঁচটায় থেমে ছিল, আর প্রথম অচেনা ছাঁকনিতেই
         * পাহারা থামত (চেক রেজিস্টারের `direction`) — তার পরের কোনো রিপোর্টের কোনো ছাঁকনি আর মাপা হত না।
         * ⓘ যে ছাঁকনির মান রিপোর্ট ভেদে আলাদা (`status`, `state`, `group_by`), তার কয়েকটা বিকল্প — যেকোনো একটা কোয়েরি
         * বদলালেই ছাঁকনিটা জীবিত ([[probes()]])।
         */
        'warehouse_id' => ['warehouse_id' => 987650],
        'product_id' => ['product_id' => 987649],
        'category_id' => ['category_id' => 987648],
        'brand_id' => ['brand_id' => 987647],
        'customer_id' => ['customer_id' => 987646],
        'supplier_id' => ['supplier_id' => 987645],
        'person_id' => ['person_id' => 987644],
        'salesman_id' => ['salesman_id' => 987643],
        'rental_contract_id' => ['rental_contract_id' => 987642],
        'location_id' => ['location_id' => 987641],
        'facility_id' => ['facility_id' => 987640],
        'batch_id' => ['batch_id' => 987639],
        'direction' => ['direction' => 'received'],
        'month' => ['month' => '1997-04'],
        'period' => ['period' => 'week'],
        'status' => [['status' => 'bounced'], ['status' => 'slow'], ['status' => 'negative']],
        // ⓘ বীমার দাবির খাতা নিজের অবস্থাই মানে (জমা · অনুমোদিত · আংশিক · নিষ্পন্ন · নাকচ — [[InsuranceClaim::STATES]])
        'state' => [['state' => 'overdue'], ['state' => 'today'], ['state' => 'lodged']],
        'group_by' => [['group_by' => 'brand'], ['group_by' => 'product']],
    ];

    /**
     * ⓘ যে ছাঁকনি কোয়েরিতে নয়, PHP-তে সারি বাছে — খালি পরীক্ষার ডেটাবেসে কোয়েরির আকার তাই বদলায় না, অথচ ছাঁকনি জীবিত।
     * ⛔ এখানে নাম কেবল কারণসহ; কোয়েরিতে বসানো ছাঁকনি এখানে আসে না।
     */
    private const DATA_DRIVEN = [
        'finance.bank_loan_instalments / state' => 'কিস্তিগুলো সেবা থেকে PHP-তে আসে ([[BankFacilityService::instalmentsDue()]]) আর অবস্থা সেখানেই বাছা হয়',
        'supplier.principal_commission / month' => 'প্রতিটা প্রিন্সিপালের চক্র PHP-তে গোনা ([[PrincipalCommission::rows()]]); প্রিন্সিপাল না থাকলে সারি নেই',
        'supplier.principal_commission / branch' => 'শাখার দেয়াল প্রিন্সিপালের তালিকায় বসে, সারি PHP-তে জন্মায় — প্রিন্সিপাল না থাকলে কোয়েরি খালি',
        'finance.bank_loan_instalments / date_range' => 'শেষ তারিখ কিস্তির সেবায় "আজ পর্যন্ত" হিসেবে যায়; সারি PHP-তে, ঋণ না থাকলে খালি',
        'finance.bank_loan_ledger / date_range' => 'খতিয়ান কেবল একটা সত্যিকারের ঋণ বাছলে গড়ে ওঠে ([[LoanLedgerReports::bankLoan()]]) — বানানো আইডিতে খালি খাতা',
        'finance.bank_loan_ledger / facility_id' => 'ঋণটা ডেটাবেসে খোঁজা হয় — বানানো আইডি না মিললে খালি খাতা, ছাঁকনি থাকুক বা না থাকুক',
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

                if (array_key_exists($key.' / '.$filter, self::DATA_DRIVEN)) {
                    continue;
                }

                $plain = $this->shapeOf($report, []);
                $alive = false;

                foreach ($this->probes($filter) as $probe) {
                    $alive = $alive || $plain !== $this->shapeOf($report, $probe);
                }

                if (! $alive) {
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
                foreach (array_keys($this->probes($filter)[0] ?? []) as $asked) {
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

    /**
     * একটা ছাঁকনির মাপার মান — একটা, বা কয়েকটা বিকল্প (মান যখন রিপোর্ট ভেদে আলাদা)।
     *
     * @return list<array<string, mixed>>
     */
    private function probes(string $filter): array
    {
        $probe = self::PROBE[$filter] ?? [];

        return array_is_list($probe) ? $probe : [$probe];
    }

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
