<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReportApiController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * তেত্রিশটা রিপোর্ট ওয়েবে ছিল, ফোনে একটাও না — চুক্তি §৯।
 *
 * ⭐ প্রতিটা দরজার দাবি একই মানুষকে দুইবার: চাবি ছাড়া বন্ধ, চাবি দিলে খোলা
 * ([[same-user-key-off-then-on]])। ⓘ ভূমিকাহীন মানুষ — ডেমোর ভূমিকা বদলায়,
 * আর ভূমিকার উপর দাঁড়ানো দাবি কাল ভুল কারণে লাল হত।
 *
 * ── কেন নিজের রিপোর্ট নিবন্ধন করা ──────────────────────────────────────
 * ⓘ আজ কোনো নিবন্ধিত রিপোর্ট নিজের চাবি (`permission`) ঘোষণা করে না, তাই
 * ফোনে সবগুলোই বন্ধ — ঠিক যেটা [[ReportApiController]] চায়। ⚠️ দরজাটা মাপতে
 * তাই এখানে একটা রিপোর্ট বসানো হয়, যার কোয়েরি **আসল টেবিলে** (`customers`)
 * চলে: কোম্পানির দেয়াল আর তারিখের ছাঁকনি তখন সত্যিকারের সারিতে মাপা হয়।
 */
final class TheReportsNeverReachedThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private const MONEY = '/^-?\d+\.\d{4}$/';

    /** রিপোর্ট চালানোর চাবি */
    private const REPORT_KEY = 'customer.report';

    /** ঢাকা কলামের চাবি — ক্রয়মূল্যের মতো */
    private const COST_KEY = 'inventory.cost.view';

    private const LIMITS = 'ec2.opening_limits';

    private const COUNTING = 'ec2.one_hundred_fifty';

    private const NOBODY_SAID = 'ec2.nobody_said_who';

    private const MARCH = ['from' => '2031-03-01', 'to' => '2031-03-31'];

    /** মার্চের পাঁচটা সারি — কোড => ধারের সীমা */
    private const IN_MARCH = [
        'EC2-01' => '1000.25',
        'EC2-02' => '2000.50',
        'EC2-03' => '3000.75',
        'EC2-04' => '4000.10',
        'EC2-05' => '5000.20',
    ];

    private Company $company;

    private Company $other;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['is_active' => true]);

        $engine = app(ReportEngine::class);

        $engine->register(new ReportDefinition(
            key: self::LIMITS,
            title: 'Opening limits',
            query: fn (array $f) => DB::table('customers')
                ->where('company_id', $f['company_id'])
                ->whereBetween('opening_date', [$f['from'], $f['to']])
                ->where('code', 'like', 'EC2-%')
                ->orderBy('code')
                ->select([
                    // ⚠️ ভিতরের আইডি — কোনো কলাম এটা দেখায় না, তাই তারেও যাবে না
                    'id as customer_id',
                    'code',
                    'opening_date as opened_on',
                    'credit_limit as limit_amount',
                    'credit_limit as secret_cost',
                ]),
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => ReportColumn::DOCUMENT],
                ['key' => 'opened_on', 'label' => 'Opened', 'type' => ReportColumn::DATE],
                ['key' => 'limit_amount', 'label' => 'Limit', 'type' => ReportColumn::MONEY],
                ['key' => 'secret_cost', 'label' => 'Cost', 'type' => ReportColumn::MONEY, 'permission' => self::COST_KEY],
            ],
            filters: ['date_range'],
            permission: self::REPORT_KEY,
        ));

        /* ⓘ ১৫০টা সারি, কোনো টেবিল ছাড়া — পাতার সীমা মাপতে ১০০-র বেশি লাগে */
        $engine->register(new ReportDefinition(
            key: self::COUNTING,
            title: 'Counting',
            query: function (array $f) {
                $numbers = DB::query()->selectRaw('1 as n');

                for ($i = 2; $i <= 150; $i++) {
                    $numbers->unionAll(DB::query()->selectRaw("{$i} as n"));
                }

                return DB::query()->fromSub($numbers, 'numbers')->orderBy('n')->select('n');
            },
            columns: [['key' => 'n', 'label' => 'N', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: self::REPORT_KEY,
        ));

        /* ⛔ চাবি ঘোষণা করেনি — "জানি না", "সবার জন্য" নয় */
        $engine->register(new ReportDefinition(
            key: self::NOBODY_SAID,
            title: 'Nobody said who',
            query: fn (array $f) => DB::query()->selectRaw("'x' as v"),
            columns: [['key' => 'v', 'label' => 'V']],
            filters: [],
        ));

        foreach (self::IN_MARCH as $code => $limit) {
            $this->customer($code, $this->company->id, '2031-03-'.substr($code, -2), $limit);
        }

        $this->customer('EC2-OUT', $this->company->id, '2031-04-15', '777.00');
        $this->customer('EC2-FMART', $this->other->id, '2031-03-10', '999.00');
    }

    // ── দরজা ─────────────────────────────────────────────────────────────

    /** ⭐ একই মানুষ: চাবি ছাড়া তালিকায় নেই আর পাতা ৪০৩; চাবি দিলে দুইটাই খোলে। */
    public function test_without_the_reports_key_it_is_neither_listed_nor_opened_and_with_it_both(): void
    {
        $this->assertNotContains(self::LIMITS, $this->listedKeys(), '⛔ চাবি ছাড়াই তালিকায় এসেছে।');
        $this->report(self::LIMITS, self::MARCH)->assertForbidden();

        $this->grant(self::REPORT_KEY);

        $entry = collect($this->list()->assertOk()->json())->firstWhere('key', self::LIMITS);
        $this->assertNotNull($entry, '⛔ চাবি দেওয়ার পরেও তালিকায় আসেনি।');
        $this->assertSame(['key' => self::LIMITS, 'module' => null, 'title' => 'Opening limits', 'filters' => ['date_range']], $entry);

        $this->report(self::LIMITS, self::MARCH)->assertOk()->assertJsonPath('key', self::LIMITS);
    }

    /**
     * ⛔ যে রিপোর্ট নিজের চাবি ঘোষণা করেনি, সেটা ফোনে আসেই না — যাঁর সব চাবি
     * আছে তাঁর কাছেও না। ⓘ null মানে "জানি না" ([[ReportDefinition]])।
     */
    public function test_a_report_that_names_no_key_never_reaches_the_phone(): void
    {
        $this->assertContains(self::NOBODY_SAID, app(ReportEngine::class)->keys(), 'রিপোর্টটা নিবন্ধিতই নয় — দাবিটা কিছু মাপছে না।');
        $this->grant(self::REPORT_KEY);
        $this->grant(self::COST_KEY);

        $this->assertContains(self::LIMITS, $this->listedKeys(), 'চাবিওয়ালা রিপোর্টও আসছে না — দাবিটা কিছু মাপছে না।');
        $this->assertNotContains(self::NOBODY_SAID, $this->listedKeys());
        $this->report(self::NOBODY_SAID)->assertForbidden();
    }

    /**
     * ⛔ ক্রয় বন্ধ করা কোম্পানির ক্রয়ের রিপোর্ট ফোনেও নেই — তালিকায় না, পাতায় ৪০৪।
     *
     * ⓘ এই দরজা ওয়েবের রুট নয়, তাই [[RefuseSwitchedOffScreens]] এখানে চলে না;
     * সুইচটা কন্ট্রোলার নিজে দেখে। ⚠️ না দেখলে মালিক ক্রয় বন্ধ করলেও ফোনে
     * অপেক্ষমাণ আদেশের রিপোর্ট দিব্যি খুলত। ⭐ একই মানুষ, একই চাবি, একই
     * রিপোর্ট — কেবল সুইচ বদলায়: চালু → আছে, বন্ধ → নেই, আবার চালু → আছে।
     * ⓘ আসল নিবন্ধিত রিপোর্ট (`purchase.pending_orders`), নিজের বসানো নয় —
     * নিজের বসানোটার কোনো মডিউল নেই, তাই সুইচ ওটাকে ছোঁয়ই না।
     */
    public function test_a_switched_off_module_hides_its_report_from_list_and_page(): void
    {
        $key = 'purchase.pending_orders';
        $switch = fn (bool $on) => CompanyContext::forCompany($this->company->id,
            fn () => app(SettingsService::class)->set('purchase.enabled', $on));

        $this->assertContains($key, app(ReportEngine::class)->keys(), 'রিপোর্টটা নিবন্ধিতই নয় — দাবিটা কিছু মাপছে না।');
        $this->assertSame('purchase.report', app(ReportEngine::class)->get($key)->permission);
        $this->grant('purchase.report');

        $entry = collect($this->list()->assertOk()->json())->firstWhere('key', $key);
        $this->assertNotNull($entry, 'সুইচ চালু, চাবি আছে, তবু তালিকায় নেই — দাবিটা কিছু মাপছে না।');
        $this->assertSame('purchase', $entry['module'], 'রিপোর্টের মডিউল চেনা যায়নি — সুইচ তাহলে কিছুই মাপে না।');
        $this->report($key)->assertOk()->assertJsonPath('key', $key);

        $switch(false);

        $this->assertNotContains($key, $this->listedKeys(), '⛔ ক্রয় বন্ধ, অথচ ফোনের তালিকায় ক্রয়ের রিপোর্ট।');
        $this->report($key)->assertNotFound();

        $switch(true);

        $this->assertContains($key, $this->listedKeys(), '⛔ সুইচ আবার চালু, তবু রিপোর্ট ফেরেনি।');
        $this->report($key)->assertOk()->assertJsonPath('key', $key);
    }

    /** ⓘ টাইপো মানে ৪০৪ — ৫০০ দেখে ফোন ভাবত সার্ভার ভাঙা। */
    public function test_an_unknown_report_is_404_not_500(): void
    {
        $this->grant(self::REPORT_KEY);

        $this->report('ec2.no_such_report')->assertNotFound();
    }

    /** ⛔ চুরি যাওয়া refresh টোকেনে রিপোর্ট খোলে না — তালিকাও না, পাতাও না। */
    public function test_a_refresh_token_opens_neither_door(): void
    {
        $this->grant(self::REPORT_KEY);
        $token = $this->user->createToken('refresh', [AuthController::REFRESH])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/reports')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/reports/'.self::LIMITS.'?'.http_build_query(self::MARCH))->assertForbidden();
    }

    // ── নিয়ম ক · ঢাকা কলাম উত্তরেই নেই ─────────────────────────────────────

    /**
     * ⭐ একই মানুষ: খরচের চাবি ছাড়া কলামটা কলামের তালিকায়, প্রতিটা সারিতে আর
     * যোগফলে — কোথাও নেই (`null` বা ফাঁকা নয়); চাবি দিলে তিন জায়গাতেই আছে।
     */
    public function test_a_guarded_column_is_absent_everywhere_until_its_key_is_granted(): void
    {
        $this->grant(self::REPORT_KEY);

        $body = $this->report(self::LIMITS, self::MARCH)->assertOk()->json();
        $this->assertCount(5, $body['rows'], 'মার্চের সারি নেই — দাবিটা কিছু মাপছে না।');

        $this->assertNotContains('secret_cost', array_column($body['columns'], 'key'));
        foreach ($body['rows'] as $row) {
            $this->assertArrayNotHasKey('secret_cost', $row, '⛔ কলাম ঢাকা, অথচ সারিতে সংখ্যাটা এসেছে।');
        }
        $this->assertArrayNotHasKey('secret_cost', $body['totals'], '⛔ সারি ঢাকা, অথচ যোগফলে মোটটা খোলা।');
        $this->assertArrayHasKey('limit_amount', $body['totals'], 'খোলা কলামের যোগফলই নেই — দাবিটা কিছু মাপছে না।');

        $this->grant(self::COST_KEY);

        $body = $this->report(self::LIMITS, self::MARCH)->assertOk()->json();
        $this->assertContains('secret_cost', array_column($body['columns'], 'key'), '⛔ চাবি দেওয়ার পরেও কলাম আসেনি।');
        foreach ($body['rows'] as $row) {
            $this->assertArrayHasKey('secret_cost', $row);
        }
        $this->assertSame($this->sumOf(self::IN_MARCH), $body['totals']['secret_cost']);
    }

    /**
     * ⛔ সারিতে কেবল কলামের ঘর — কোয়েরির ভিতরের আইডি (`customer_id`) তারে যায়
     * না (§৩ ক), আর প্রতিধ্বনিত ছাঁকনিতেও কোম্পানির আইডি নেই।
     */
    public function test_rows_carry_only_their_columns_and_no_internal_id(): void
    {
        $this->grant(self::REPORT_KEY);

        $body = $this->report(self::LIMITS, self::MARCH)->assertOk()->json();
        $this->assertNotEmpty($body['rows'], 'সারি নেই — দাবিটা কিছু মাপছে না।');

        foreach ($body['rows'] as $row) {
            $this->assertSame(['code', 'opened_on', 'limit_amount'], array_keys($row));
        }

        $this->assertSame(self::MARCH, $body['filters'], '⛔ ছাঁকনির প্রতিধ্বনিতে ভিতরের ঘর গেছে।');
    }

    // ── নিয়ম খ ও গ · যোগফল সার্ভারের, টাকা স্ট্রিং ─────────────────────────

    /**
     * ⭐ দুই সারির পাতায়ও যোগফল পাঁচ সারির — পাতার নয়; আর টাকা চার ঘরের
     * স্ট্রিং। ⓘ যোগফল ওয়েবের একই উৎস থেকে ([[ReportEngine::run()]])।
     */
    public function test_totals_are_the_whole_reports_and_money_is_a_four_decimal_string(): void
    {
        $this->grant(self::REPORT_KEY);

        $body = $this->report(self::LIMITS, [...self::MARCH, 'perPage' => 2])->assertOk()->json();
        $this->assertCount(2, $body['rows']);

        $whole = $this->sumOf(self::IN_MARCH);
        $page = $this->sumOf(array_column($body['rows'], 'limit_amount'));
        $this->assertNotSame($whole, $page, 'পাতার যোগ আর পুরো যোগ সমান — দাবিটা কিছু মাপছে না।');
        $this->assertSame($whole, $body['totals']['limit_amount'], '⛔ "মোট" কেবল এই পাতার।');

        $web = CompanyContext::forCompany($this->company->id,
            fn () => app(ReportEngine::class)->run(self::LIMITS, self::MARCH)->totals['limit_amount']);
        $this->assertSame(bcadd($web, '0', 4), $body['totals']['limit_amount'], '⛔ ওয়েব এক মোট বলে, ফোন আরেক।');

        $this->assertIsString($body['rows'][0]['limit_amount'], '⛔ টাকা সংখ্যা হয়ে গেলে দশমিক হারায়।');
        $this->assertSame('1000.2500', $body['rows'][0]['limit_amount']);
        $this->assertMatchesRegularExpression(self::MONEY, $body['totals']['limit_amount']);
    }

    /** ⓘ ধরনটা ঘোষণা যা বলে তা-ই যায় — ফোন ওটা দেখে সাজায় (নিয়ম গ ও ঙ)। */
    public function test_each_column_says_its_declared_type_and_whether_it_totals(): void
    {
        $this->grant(self::REPORT_KEY);
        $this->grant(self::COST_KEY);

        $this->assertSame([
            ['key' => 'code', 'label' => 'Code', 'type' => 'document', 'total' => false],
            ['key' => 'opened_on', 'label' => 'Opened', 'type' => 'date', 'total' => false],
            ['key' => 'limit_amount', 'label' => 'Limit', 'type' => 'money', 'total' => true],
            ['key' => 'secret_cost', 'label' => 'Cost', 'type' => 'money', 'total' => true],
        ], $this->report(self::LIMITS, self::MARCH)->assertOk()->json('columns'));
    }

    // ── তারিখ ────────────────────────────────────────────────────────────

    /** ⭐ পরিসরের বাইরের সারি সত্যিই সরে যায় — আর পরিসর বাড়ালে ফিরে আসে। */
    public function test_from_and_to_really_filter(): void
    {
        $this->grant(self::REPORT_KEY);

        $march = array_column($this->report(self::LIMITS, self::MARCH)->assertOk()->json('rows'), 'code');
        $this->assertNotContains('EC2-OUT', $march, '⛔ এপ্রিলের সারি মার্চের রিপোর্টে।');

        $spring = array_column($this->report(self::LIMITS, ['from' => '2031-03-01', 'to' => '2031-04-30'])
            ->assertOk()->json('rows'), 'code');
        $this->assertContains('EC2-OUT', $spring, 'পরিসর বাড়িয়েও সারিটা আসে না — দাবিটা কিছু মাপছে না।');
        $this->assertCount(count($march) + 1, $spring);
    }

    /** ⓘ উল্টো পরিসর বা ভাঙা তারিখ ৪২২ — ইঞ্জিনের ব্যতিক্রম ৫০০ হয়ে ফোনে যায় না। */
    public function test_a_backwards_or_broken_range_is_422_not_500(): void
    {
        $this->grant(self::REPORT_KEY);

        $this->report(self::LIMITS, ['from' => '2031-04-01', 'to' => '2031-03-01'])->assertUnprocessable();
        $this->report(self::LIMITS, ['from' => 'yesterday-ish', 'to' => '2031-03-01'])->assertUnprocessable();
    }

    // ── নিয়ম ঘ · পাতা-ভাগ ────────────────────────────────────────────────

    /** ⛔ `perPage=500` চাইলেও পাতা ১০০-র — আর উত্তর নিজেই সেটা বলে। */
    public function test_per_page_is_capped_at_the_webs_hundred(): void
    {
        $this->grant(self::REPORT_KEY);

        $body = $this->report(self::COUNTING, ['perPage' => 500])->assertOk()->json();

        $this->assertSame(ReportApiController::MAX_PER_PAGE, 100);
        $this->assertCount(100, $body['rows'], '⛔ সীমা ছাড়িয়ে গোটা তালিকা এক পাতায়।');
        $this->assertSame(100, $body['perPage']);
        $this->assertSame(150, $body['totalRows']);
        $this->assertSame(2, $body['lastPage'], '⛔ lastPage ছাড়া পর্দা বলতে পারে না "১৫০-এর প্রথম ১০০"।');
    }

    /**
     * ⛔ `perPage=0` বা ঋণাত্মক চাইলেও উত্তর একটা আসল পাতা — এক সারির।
     *
     * ⚠️ তলা না থাকলে শূন্যে `lastPage()` শূন্য দিয়ে ভাগ করত (৫০০), আর ঋণাত্মকে
     * ডাটাবেজ `LIMIT` ফেলে দিয়ে গোটা তালিকা এক পাতায় দিত — ঠিক যেটা ১০০-র
     * ছাদ আটকায়, কেবল উল্টো দরজা দিয়ে। ⓘ ফোনের পাতা-গোনা ঘরে ভুল সংখ্যা
     * আসতেই পারে (খালি ঘর = ০); সার্ভার সেটাকে ১ ধরে।
     */
    public function test_a_zero_or_negative_per_page_still_returns_a_page(): void
    {
        $this->grant(self::REPORT_KEY);

        foreach ([0, -5] as $asked) {
            $body = $this->report(self::COUNTING, ['perPage' => $asked])->assertOk()->json();

            $this->assertSame(1, $body['perPage'], "⛔ perPage={$asked} চাইলে উত্তরের পাতার মাপ ১ নয়।");
            $this->assertCount(1, $body['rows'], "⛔ perPage={$asked} চাইলে পাতায় এক সারি নয়।");
            $this->assertSame('1', (string) $body['rows'][0]['n']);
            $this->assertSame(150, $body['totalRows'], 'মোট সারি ১৫০ নয় — দাবিটা কিছু মাপছে না।');
            $this->assertSame(150, $body['lastPage']);
        }
    }

    /** ⭐ দ্বিতীয় পাতা ঠিক যেখানে প্রথমটা থামল — একটা সারিও হারায় না, দুইবারও আসে না। */
    public function test_page_two_continues_without_losing_a_row(): void
    {
        $this->grant(self::REPORT_KEY);

        $first = $this->report(self::COUNTING, ['page' => 1])->assertOk()->json('rows');
        $second = $this->report(self::COUNTING, ['page' => 2])->assertOk()->assertJsonPath('page', 2)->json('rows');
        $this->assertCount(50, $second);

        $all = array_map('intval', array_column([...$first, ...$second], 'n'));
        $this->assertSame(range(1, 150), $all);

        /* ⓘ আসল টেবিলেও: দুই-দুই করে তিন পাতা = এক পাতায় সবটা */
        $paged = [];
        for ($page = 1; $page <= 3; $page++) {
            $paged = [...$paged, ...$this->report(self::LIMITS, [...self::MARCH, 'perPage' => 2, 'page' => $page])->json('rows')];
        }
        $this->assertSame($this->report(self::LIMITS, self::MARCH)->json('rows'), $paged);
    }

    // ── কোম্পানির দেয়াল ──────────────────────────────────────────────────

    /**
     * ⛔ FMART-এর সারি TDEPOT-এর মানুষের কাছে আসে না — ঠিকানায় `company_id`
     * বসিয়ে চাইলেও না।
     */
    public function test_another_companys_rows_never_appear_even_when_asked_for(): void
    {
        $this->grant(self::REPORT_KEY);

        $theirs = CompanyContext::forCompany($this->other->id,
            fn () => array_column(app(ReportEngine::class)->run(self::LIMITS, self::MARCH)->rows, 'code'));
        $this->assertContains('EC2-FMART', $theirs, 'FMART-এর সারি ওদের নিজেদের রিপোর্টেও নেই — দাবিটা কিছু মাপছে না।');

        $codes = array_column($this->report(self::LIMITS, [...self::MARCH, 'company_id' => $this->other->id])
            ->assertOk()->json('rows'), 'code');

        $this->assertNotEmpty($codes, 'নিজের সারিও নেই — দাবিটা কিছু মাপছে না।');
        $this->assertNotContains('EC2-FMART', $codes, '⛔ অন্য কোম্পানির সারি এই কোম্পানির রিপোর্টে।');
    }

    /**
     * ⛔ যে ছাঁকনি রিপোর্ট ঘোষণা করেনি, ফোন ঠিকানায় বসালেও সেটা ইঞ্জিনে যায় না।
     *
     * ⚠️ ইঞ্জিন `branch_id` নিজে মুছে দেয় না — না এলে কেবল null বসায়
     * ([[ReportEngine::normaliseFilters()]]), আর কোয়েরি ঘরটা পড়লে মানটা খাটে।
     * ⛔ তাই `$request->all()` পাঠালে ফোন এমন ছাঁকনি চালাত যা ওয়েবের পর্দায়
     * আঁকাই নেই — একই রিপোর্টে ফোন আর ওয়েব দুই অঙ্ক বলত। ⭐ ঘরগুলো কেবল
     * `requestKeys()` থেকে, ওয়েবের মতোই।
     */
    public function test_a_filter_the_report_never_declared_is_ignored_even_when_sent(): void
    {
        $key = 'ec2.no_branch_filter';
        /* ⓘ নিজের কোম্পানিরই শাখা — ছাঁকনিটা বৈধ, কেবল এই রিপোর্টের পর্দায় নেই */
        $branch = $this->company->defaultBranch()?->id;
        $this->assertNotNull($branch, 'TDEPOT-এর শাখা নেই — দাবিটা কিছু মাপছে না।');

        app(ReportEngine::class)->register(new ReportDefinition(
            key: $key,
            title: 'No branch filter',
            query: fn (array $f) => DB::table('customers')
                ->where('company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('branch_id', $b))
                ->whereBetween('opening_date', [$f['from'], $f['to']])
                ->where('code', 'like', 'EC2-%')
                ->orderBy('code')
                ->select(['code']),
            columns: [['key' => 'code', 'label' => 'Code', 'type' => ReportColumn::DOCUMENT]],
            filters: ['date_range'],
            permission: self::REPORT_KEY,
        ));
        $this->assertNotContains('branch_id', app(ReportEngine::class)->get($key)->requestKeys());

        /* ⓘ ইঞ্জিনকে সরাসরি ঘরটা দিলে সারি সত্যিই সরে — কোয়েরি ঘরটা পড়ে */
        $engineWithBranch = CompanyContext::forCompany($this->company->id,
            fn () => app(ReportEngine::class)->run($key, [...self::MARCH, 'branch_id' => $branch])->rows);
        $this->assertSame([], $engineWithBranch, 'ঘরটা দিলেও ইঞ্জিনের সারি বদলায় না — দাবিটা কিছু মাপছে না।');

        $this->grant(self::REPORT_KEY);

        $plain = array_column($this->report($key, self::MARCH)->assertOk()->json('rows'), 'code');
        $this->assertSame(array_keys(self::IN_MARCH), $plain);

        $sent = array_column($this->report($key, [...self::MARCH, 'branch_id' => $branch])->assertOk()->json('rows'), 'code');
        $this->assertSame($plain, $sent, '⛔ ঘোষণাহীন ছাঁকনি ফোন থেকে ইঞ্জিনে গেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function list(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/reports');
    }

    /** @return list<string> */
    private function listedKeys(): array
    {
        return array_column($this->list()->assertOk()->json(), 'key');
    }

    /** @param array<string, mixed> $query */
    private function report(string $key, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/reports/'.$key.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<array-key, string> $amounts */
    private function sumOf(array $amounts): string
    {
        return array_reduce(array_values($amounts), fn (string $c, string $a) => bcadd($c, $a, 4), '0.0000');
    }

    private function customer(string $code, int $companyId, string $openedOn, string $limit): void
    {
        $row = [
            'company_id' => $companyId,
            'code' => $code,
            'name_en' => $code,
            'credit_limit' => $limit,
            'opening_date' => $openedOn,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('customers', 'public_id')) {
            $row['public_id'] = (string) Str::uuid7();
        }

        DB::table('customers')->insert($row);
    }
}
