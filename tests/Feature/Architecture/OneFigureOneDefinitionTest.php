<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Metrics\Metric;
use App\Core\Support\DocumentStatus;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * একটা সংখ্যার একটাই সংজ্ঞা, একটাই জায়গা।
 *
 * ── ABOS-এর নিজের প্রমাণ ────────────────────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই চার জায়গায় হিসাব হত: ড্যাশবোর্ড, POS,
 * রিপোর্ট, শিফট। একবার তারা দুইটা আলাদা উত্তর দিয়েছিল — একজন খসড়াও
 * গুনত, অন্যজন নয়। ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের শিফটে দেখা
 * যেত, শিফট মেলানোর সময় হাতের নগদ কম পড়ত, আর কেউ বুঝত না কেন।
 *
 * ওই নির্দিষ্ট ভুলটা সারানো। এই পরীক্ষাটা পরেরটা আটকায়।
 */
class OneFigureOneDefinitionTest extends TestCase
{
    /**
     * ⭐ "গোনা হয় এমন অবস্থা" তালিকার যত চেহারা — লুপ আর ভুল নমুনা, দুইজনই এটাই পড়ে।
     *
     * ⓘ প্রথম দুইটা আগের (২১ সেপ্টেম্বর)। ⚠️ শেষ দুইটা যোগ হলো অডিট §৬-এর
     * দিনে (২৭ সেপ্টেম্বর ২০২৬): **দুই-উদ্ধৃতির** লেখা (`"confirmed"`) আর
     * **উল্টো ক্রম** (`closed, confirmed`) — দুইটাই একই নিয়ম, আর পুরনো
     * খোঁজা কোনোটাই দেখত না। ⓘ যোগ করার আগে মাপা: গোটা `app/`-এ নতুন
     * কোনো ধরা পড়া নেই।
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '/CONFIRMED,(?:self|DocumentStatus)::CLOSED,?\]/',
        '/CLOSED,(?:self|DocumentStatus)::CONFIRMED,?\]/',
        '/[\'"]confirmed[\'"],[\'"]closed[\'"]/',
        '/[\'"]closed[\'"],[\'"]confirmed[\'"]/',
    ];

    /**
     * "কোন কাগজ গোনা হয়" কেবল এক জায়গায় লেখা।
     *
     * ── কেন গ্রেপ, কেন আচরণ নয় ──────────────────────────────────────
     * আচরণ দিয়ে ধরা যায় না: দুইটা জায়গায় একই তালিকা হাতে লিখলে আজ
     * দুইটাই ঠিক উত্তর দেয়। ভুলটা ঘটে ছয় মাস পরে, যখন কেউ একটা
     * বদলায় আর অন্যটা খুঁজে পায় না। তাই প্রশ্নটা "উত্তর কি এক" নয়,
     * "নিয়মটা কি এক জায়গায়" — আর সেটা কেবল কোড পড়েই দেখা যায়।
     */
    public function test_the_counted_statuses_are_written_in_exactly_one_place(): void
    {
        /*
         * ফাঁকা জায়গা মুছে তারপর খোঁজা।
         *
         * ── কেন, আর কীভাবে ধরা পড়ল ──────────────────────────────────
         * প্রথম রূপে নিডলগুলো হুবহু লেখা খুঁজত, তাই এক লাইনের নকল ধরা
         * পড়ত আর কয়েক লাইনে ভাঙা নকল পড়ত না। ঠিক তেমন একটা নকল
         * `SalesReturnService`-এ বসে ছিল — পাহারাটা সবুজ দেখাচ্ছিল
         * অথচ নিয়মটা দুই জায়গায় লেখা ছিল।
         *
         * একটা পাহারা যা অর্ধেক ধরে, তার বিপদ ধরতে না পারার চেয়ে বেশি:
         * সবুজ দেখে সবাই ধরে নেয় জিনিসটা এক জায়গায় আছে।
         */
        /*
         * ⭐ দুইটা চেহারা, আর দ্বিতীয়টা ২১ সেপ্টেম্বর ২০২৬-এ যোগ হলো।
         *
         * ⛔ পাহারাটা কেবল ধ্রুবকের রূপ খুঁজত (`DocumentStatus::CONFIRMED`),
         * তাই **হুবহু লেখা স্ট্রিং** (`['confirmed', 'closed']`) কোনোদিন
         * দেখত না। ⚠️ আর নিয়মটা ঠিক ওভাবেই আরও **ছয় জায়গায়** লেখা ছিল —
         * PurchaseWidgets, ReturnOnCapitalReport (দুইবার), SettlementReport
         * (দুইবার), আর PaymentScheduleController-এ **কাঁচা SQL-এর ভিতরে**।
         *
         * ⓘ অর্থাৎ পাহারাটা দাবি করত "সংজ্ঞাটা এক জায়গায়", অথচ সাত
         * জায়গায় ছিল — আর সবুজ দেখে সবাই ধরে নিত জিনিসটা এক জায়গায় আছে।
         */
        $home = 'app/Core/Support/DocumentStatus.php';

        $offenders = [];
        $looked = 0;
        $homeSeen = false;

        foreach ($this->phpFiles() as $path) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(base_path()) + 1));
            $looked++;

            if (! $this->spellsTheCountedList((string) file_get_contents($path))) {
                continue;
            }

            if ($relative === $home) {
                $homeSeen = true;

                continue;
            }

            $offenders[] = $relative;
        }

        /*
         * ⭐ "কিছু পেয়েছি" — অডিট §৬, ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ অডিটের কথা: *"অবস্থার পাহারাদার নিজে কিছু পেয়েছে কি না, তা
         * যাচাই করে না।"* ⚠️ খোঁজার ছাঁচ একদিন অন্ধ হলে — কোড ফরম্যাটার
         * লেখা বদলালে, ধ্রুবকের নাম বদলালে — `$offenders` খালি থাকত আর
         * পাহারাটা সবুজ দিত, ঠিক যেমন ২০ সেপ্টেম্বরের "খালি তালিকা আর
         * খালি তালিকা এক" পাহারাগুলো দিত।
         *
         * ⭐ তাই দুইটা গণনা, আর দ্বিতীয়টাই আসল: **একমাত্র বৈধ সংজ্ঞাটা**
         * (`DocumentStatus::POSTED`) এই খোঁজায় ধরা পড়তেই হবে। ⓘ ওটা আসল
         * কোড, পরীক্ষায় হাতে লেখা নাম নয় — তাই খোঁজা অন্ধ হলে এখানেই লাল।
         */
        $this->assertGreaterThan(1000, $looked,
            "মাত্র {$looked}টা PHP ফাইল দেখা হলো (২৭ সেপ্টেম্বর ছিল ২১৯১) — হাঁটাটাই ভেঙেছে।");

        $this->assertTrue($homeSeen,
            "খোঁজাটা {$home}-এর নিজের সংজ্ঞাটাই চিনতে পারছে না — পাহারাটা অন্ধ, "
            .'আর অন্ধ পাহারার খালি তালিকার কোনো মানে নেই।');

        $this->assertSame([], $offenders,
            '"কোন কাগজ গোনা হয়" নিয়মটা একাধিক জায়গায় লেখা হয়েছে। কোয়েরিতে `->posted()`, '
            ."সংজ্ঞা বলতে `DocumentStatus::POSTED` — তালিকাটা {$home}-এ:\n".implode("\n", $offenders));
    }

    /**
     * ⛔ ইচ্ছাকৃত ভুল নমুনা — খোঁজাটা সত্যিই কামড়ায় (অডিট §৬, ২৭ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ প্রতিটা নমুনা যায় **সেই একই** [[spellsTheCountedList()]] দিয়ে, যা
     * উপরের লুপ ডাকে — আলাদা করে লেখা নকল দিয়ে নয়। ⚠️ আর উল্টো দিকও
     * মাপা: মন্তব্যের ভেতরের তালিকা আর `->posted()` ছাড়া পেতে হবে —
     * নাহলে "সবকিছু ধরে" এমন পাহারাও এখানে সবুজ থাকত, আর তার মিথ্যা
     * অভিযোগে মানুষ পাহারাটাই বন্ধ করে দিতেন।
     */
    public function test_the_detector_bites_a_deliberately_bad_sample(): void
    {
        $bad = [
            'ধ্রুবক দিয়ে' => <<<'PHP'
                <?php $q->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED]);
                PHP,
            'কয়েক লাইনে ভাঙা' => <<<'PHP'
                <?php $q->whereIn('status', [
                    self::CONFIRMED,
                    self::CLOSED,
                ]);
                PHP,
            'এক-উদ্ধৃতির লেখা' => <<<'PHP'
                <?php $q->whereIn('status', ['confirmed', 'closed']);
                PHP,
            'দুই-উদ্ধৃতির লেখা' => <<<'PHP'
                <?php $q->whereIn('status', ["confirmed", "closed"]);
                PHP,
            'কাঁচা SQL-এর ভেতরে, উল্টো ক্রমে' => <<<'PHP'
                <?php DB::select("select * from sal_invoices where status in ('closed', 'confirmed')");
                PHP,
        ];

        foreach ($bad as $shape => $source) {
            $this->assertTrue($this->spellsTheCountedList($source),
                "ইচ্ছাকৃত ভুল নমুনাটা ({$shape}) পাহারা পেরিয়ে গেছে — খোঁজাটা এই চেহারা দেখে না।");
        }

        $innocent = [
            'লাইন-মন্তব্যে' => <<<'PHP'
                <?php // আগে লেখা ছিল ['confirmed', 'closed'] — এখন ->posted()
                PHP,
            'ব্লক-মন্তব্যে' => <<<'PHP'
                <?php /* [self::CONFIRMED, self::CLOSED] — DocumentStatus::POSTED দেখুন */
                PHP,
            'আসল পথ' => <<<'PHP'
                <?php $q->posted()->whereIn('status', DocumentStatus::POSTED);
                PHP,
        ];

        foreach ($innocent as $shape => $source) {
            $this->assertFalse($this->spellsTheCountedList($source),
                "নির্দোষ লেখাকে ({$shape}) অপরাধী বলছে — মিথ্যা অভিযোগের পাহারা মানুষ বন্ধ করে দেয়।");
        }
    }

    /**
     * সংজ্ঞাটা তার নিজের চারটা প্রশ্নের উত্তর দেয়।
     *
     * এই চারটাতেই দুইজন মানুষ দুই রকম ধরে নেয়, আর কোনোটাই সংখ্যাটা
     * দেখে বোঝা যায় না।
     */
    public function test_a_metric_can_say_how_it_is_counted(): void
    {
        $metric = new Metric(
            key: 'sales.today',
            label: 'আজকের বিক্রয়',
            statuses: [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED],
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.view',
            value: fn () => '1000.00',
        );

        $definition = $metric->definition();

        // কোন status — খসড়া নয়, সেটাই সবচেয়ে বড় প্রশ্ন
        $this->assertStringContainsString(__('core.status.confirmed'), $definition);
        $this->assertStringNotContainsString(__('core.status.draft'), $definition);

        // কোন তারিখ — লেনদেনের, এন্ট্রির নয়
        $this->assertStringContainsString(__('core.metric.by_transaction_date'), $definition);

        // রাউন্ডিং কোন ধাপে
        $this->assertStringContainsString(__('core.metric.round_at_total'), $definition);

        $this->assertSame('1000.00', $metric->value());
    }

    /**
     * একটাও status না গুনলে সংখ্যাটা চিরকাল শূন্য — নির্মাণেই আটকানো।
     */
    public function test_a_metric_that_counts_nothing_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('sales.today', 'x', [], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /**
     * অজানা status টাইপো — সংখ্যাটা নিঃশব্দে শূন্য হত।
     */
    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('sales.today', 'x', ['confirmd'], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /**
     * নামের আগে মডিউল — নাহলে দুই মডিউলের "today" ঠুকে যেত।
     */
    public function test_a_metric_key_names_its_module(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('today', 'x', [DocumentStatus::CONFIRMED], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /**
     * ⭐ একটা উৎস "গোনা হয় এমন অবস্থা"-র তালিকা নিজে লেখে কি না।
     *
     * ⓘ মন্তব্য বাদ, তারপর সব ফাঁকা জায়গা বাদ, তারপর [[PATTERNS]]। ⚠️ লুপ
     * আর ভুল নমুনা **এই এক পদ্ধতিই** ডাকে — দুই জায়গায় দুই রকম খোঁজা
     * থাকলে নমুনাটা একটা পরীক্ষা করত, আর পাহারা আরেকটা চালাত।
     */
    private function spellsTheCountedList(string $source): bool
    {
        $code = preg_replace('/\s+/', '', $this->withoutComments($source));

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /** মন্তব্যে নিয়মটার কথা লেখা থাকতেই পারে — সেটা ব্যাখ্যা, অপরাধ নয়। */
    private function withoutComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!su', '', $code);

        return preg_replace('!//.*!', '', $code);
    }
}
