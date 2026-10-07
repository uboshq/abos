<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\RealAccounts;
use Tests\TestCase;

/**
 * রিপোর্ট — প্ল্যান সেকশন ২.২, ষষ্ঠ engine।
 *
 * সবচেয়ে জরুরি টেস্ট দুটো: যোগফল পুরো ফলের উপর (পাতার উপর নয়), আর
 * প্রতিটা সারি তার ডকুমেন্টে ফিরতে পারে (নিয়ম ১)।
 */
class ReportEngineTest extends TestCase
{
    use RealAccounts;
    use RefreshDatabase;

    private ReportEngine $reports;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);

        $this->reports = app(ReportEngine::class);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** কয়েকটা লেনদেন বসাও — প্রতিটা আলাদা ডকুমেন্ট থেকে। */
    private function postInvoices(int $count = 5, string $date = '2026-08-04', int $startAt = 1): void
    {
        $posting = app(PostingEngine::class);

        for ($i = $startAt; $i < $startAt + $count; $i++) {
            $posting->post('sales_invoice', $i, $date, [
                ['account_id' => $this->cashAccountId(), 'debit' => 1000 * $i],
                ['account_id' => $this->salesAccountId(), 'credit' => 1000 * $i],
            ], documentNo: sprintf('INV-2026-2027-%04d', $i));
        }
    }

    public function test_reports_are_registered_from_module_files(): void
    {
        // কোর ফাইলে মডিউলের নাম লেখা নেই — module.php-তে ঘোষিত (সেকশন ১৯.৩)।
        $this->assertContains('accounts.day_book', $this->reports->keys());
        $this->assertContains('accounts.ledger', $this->reports->keys());
        $this->assertContains('accounts.trial_balance', $this->reports->keys());
    }

    public function test_an_unregistered_report_lists_what_is_available(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No report registered.*Registered:/s');

        $this->reports->run('accounts.does_not_exist');
    }

    public function test_a_report_returns_rows_and_totals(): void
    {
        $this->postInvoices(5);

        $result = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(10, $result->totalRows, 'Five invoices, two ledger lines each.');
        $this->assertCount(10, $result->rows);

        // ১০০০ + ২০০০ + ৩০০০ + ৪০০০ + ৫০০০
        $this->assertSame('15000.00', $result->totals['debit']);
        $this->assertSame('15000.00', $result->totals['credit']);
    }

    public function test_totals_cover_every_row_not_just_the_visible_page(): void
    {
        $this->postInvoices(30);

        $page1 = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31'], page: 1, perPage: 10);
        $page3 = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31'], page: 3, perPage: 10);

        $this->assertCount(10, $page1->rows);
        $this->assertCount(10, $page3->rows);

        // এটাই সবচেয়ে জরুরি: "মোট" মানে পুরো রিপোর্টের মোট। পাতাভিত্তিক
        // যোগফল দেখালে প্রতিটা পাতায় আলাদা মোট আসত, আর কেউ বুঝত না
        // কোনটা আসল।
        $this->assertSame($page1->totals['debit'], $page3->totals['debit']);
        $this->assertSame('465000.00', $page1->totals['debit']);
    }

    public function test_paging_reports_where_it_is_in_the_list(): void
    {
        $this->postInvoices(12);

        $result = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31'], page: 2, perPage: 10);

        $this->assertSame(24, $result->totalRows);
        $this->assertSame(3, $result->lastPage());
        $this->assertTrue($result->hasMorePages());
        $this->assertSame(['from' => 11, 'to' => 20, 'of' => 24], $result->showing());
    }

    public function test_every_row_can_find_its_way_back_to_a_document(): void
    {
        $this->postInvoices(3);

        $result = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $row = $result->rows[0];

        // নিয়ম ১ — এই দুটো ছাড়া কোনো সংখ্যা তার উৎসে ফিরতে পারে না।
        $this->assertSame('sales_invoice', $row['source_type']);
        $this->assertNotEmpty($row['source_id']);

        $documentColumn = collect($result->report->columns)->firstWhere('key', 'document_no');
        $this->assertTrue($documentColumn->isDrillable());
    }

    public function test_the_running_balance_continues_across_pages(): void
    {
        $this->postInvoices(10);

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => $this->cashAccountId()];

        $page1 = $this->reports->run('accounts.ledger', $filters, page: 1, perPage: 4);
        $page2 = $this->reports->run('accounts.ledger', $filters, page: 2, perPage: 4);

        // end() রেফারেন্স নেয়, আর readonly প্রপার্টি বদলানো যায় না — তাই
        // অ্যারেটা আগে নিজের ভেরিয়েবলে।
        $rowsOfPage1 = $page1->rows;

        $lastOfPage1 = (float) $rowsOfPage1[array_key_last($rowsOfPage1)]['balance'];
        $firstOfPage2 = (float) $page2->rows[0]['balance'];

        /*
         * ⛔ এখানে আগে ছিল কেবল `assertGreaterThan($lastOfPage1, …)` —
         * আর সেটা কিছুই মাপত না, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ ফিক্সচারের প্রতিটা অঙ্ক ধনাত্মক আর ক্রমবর্ধমান, তাই আগের
         * পাতার **যেকোনো** চারটা সারি নিলেও যোগফল বড়ই হত। ⓘ ইঞ্জিন
         * ঠিক ওই ভুলটাই করত: `reorder()` ক্রম মুছে দিত, আর "প্রথম চারটা"
         * মানে দাঁড়াত "যেকোনো চারটা"। ⛔ প্রশ্নটা "বড় কি না" নয়,
         * **"ঠিক কত"** — তাই সংখ্যাটাই দাবি করা হয়।
         *
         * চালান ১,০০০ · ২,০০০ · ৩,০০০ … তাই প্রথম পাতার শেষে ১০,০০০,
         * আর দ্বিতীয় পাতার প্রথম সারিতে ৫,০০০ যোগ হয়ে ১৫,০০০।
         */
        $this->assertSame(1000.0 + 2000 + 3000 + 4000, $lastOfPage1);
        $this->assertSame(15000.0, $firstOfPage2,
            'দ্বিতীয় পাতার শুরুর ব্যালেন্স ভুল — আগের পাতার ঠিক সারিগুলো যোগ হয়নি।');

        /*
         * ⭐ আর শেষ পাতাটাও মিলিয়ে দেখা: দশটা চালানের যোগ ৫৫,০০০, আর
         * চলমান ব্যালেন্সের শেষ সংখ্যাটা ঠিক সেটাই হওয়ার কথা। ⓘ এক-দুই
         * পাতা মিললেও মাঝপথে সারি হারালে এই দাবিটা ধরে ফেলে।
         */
        $last = $this->reports->run('accounts.ledger', $filters, page: 3, perPage: 4);
        $lastRows = $last->rows;

        $this->assertSame(
            55000.0,
            (float) $lastRows[array_key_last($lastRows)]['balance'],
            'শেষ সারির ব্যালেন্স দশটা চালানের যোগফলের সমান নয় — কোথাও সারি বাদ পড়েছে বা দুইবার গোনা হয়েছে।',
        );
    }

    /**
     * ⭐⭐ আগের পাতার যোগফল **রিপোর্টের নিজের ক্রমে** — ঢোকার ক্রমে নয়।
     *
     * ── ⛔ কেন উপরের পরীক্ষাটা যথেষ্ট ছিল না ───────────────────────────
     * সেখানে সারিগুলো যে ক্রমে ঢোকে, তারিখের ক্রমও ঠিক সেটাই। ⓘ তাই
     * `orderBy` মুছে দিলেও MySQL একই সারিগুলোই ফেরত দিত, আর পরীক্ষাটা
     * সবুজ থাকত — মিউটেশনে ঠিক সেটাই ধরা পড়েছে (২০ সেপ্টেম্বর ২০২৬)।
     *
     * ⭐ এখানে তারিখ **উল্টো**: প্রথমে ঢোকা সারিটার তারিখ সবচেয়ে পরে।
     * ⚠️ তাই "প্রথম চারটা সারি" দুই রকম হয় — তারিখ ধরে ৮+৭+৬+৫ = ২৬,০০০,
     * আর ঢোকার ক্রমে ১+২+৩+৪ = ১০,০০০। ⓘ ক্রম হারালে দ্বিতীয় পাতার
     * শুরুর ব্যালেন্স ওই দ্বিতীয় সংখ্যাটা দেখাত, আর কেউ বুঝত না কেন।
     */
    public function test_the_opening_of_a_later_page_follows_the_reports_own_order(): void
    {
        // ঢোকার ক্রম ১…৮ (অঙ্ক ১,০০০…৮,০০০), তারিখ ১০ তারিখ থেকে পিছিয়ে ৩
        foreach (range(1, 8) as $i) {
            $this->postInvoices(1, sprintf('2026-08-%02d', 11 - $i), $i);
        }

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => $this->cashAccountId()];

        $page1 = $this->reports->run('accounts.ledger', $filters, page: 1, perPage: 4);
        $page2 = $this->reports->run('accounts.ledger', $filters, page: 2, perPage: 4);

        $rowsOfPage1 = $page1->rows;

        $this->assertSame(26000.0, (float) $rowsOfPage1[array_key_last($rowsOfPage1)]['balance'],
            'প্রথম পাতাই তারিখের ক্রমে সাজেনি — ফিক্সচারটাই ভুল হয়েছে।');

        $this->assertSame(30000.0, (float) $page2->rows[0]['balance'],
            'দ্বিতীয় পাতার শুরুর ব্যালেন্স ঢোকার ক্রমে গোনা হয়েছে, তারিখের ক্রমে নয়।');
    }

    /**
     * ⭐ আগের পাতার যোগফলের কোয়েরিতে ক্রমটা **থাকতেই হবে**।
     *
     * ── ⚠️ কেন এই দাবিটা আচরণ দিয়ে করা গেল না ──────────────────────────
     * `reorder()` ফিরিয়ে দিয়ে উপরের পরীক্ষাগুলো চালালে সেগুলো **সবুজই
     * থাকে** (মিউটেশনে দেখা, ২০ সেপ্টেম্বর ২০২৬)। ⓘ কারণ যোগফল নির্ভর
     * করে **কোন** সারিগুলো গোনা হলো তার উপর, তাদের ক্রমের উপর নয় — আর
     * MySQL তারিখের সূচক ধরে স্ক্যান করায় ORDER BY ছাড়াও প্রায়ই একই
     * সারিগুলোই ফেরত দেয়।
     *
     * ⛔ "প্রায়ই" মানেই ভরসা নয়। ⚠️ SQL-এ ORDER BY ছাড়া LIMIT-এর মানে
     * সংজ্ঞা অনুযায়ীই অনির্দিষ্ট: সূচক বদলালে, তথ্য বাড়লে, বা অপটিমাইজার
     * অন্য পথ নিলে সেদিন থেকে চলমান ব্যালেন্স ভুল হবে — আর ভুলটা নীরব।
     *
     * ⭐ তাই দাবিটা ফলাফলের নয়, **চুক্তির**: কোয়েরিটা ক্রম নিয়েই যায়।
     */
    public function test_the_query_behind_a_later_page_keeps_its_order(): void
    {
        $this->postInvoices(8);

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => $this->cashAccountId()];

        DB::enableQueryLog();

        $this->reports->run('accounts.ledger', $filters, page: 2, perPage: 4);

        $log = collect(DB::getQueryLog())->pluck('query');

        DB::disableQueryLog();

        // ⓘ আগের পাতার যোগফলটা একটা উপ-কোয়েরির ভিতরে বসে — ওটাই খোঁজা
        /*
         * ⓘ উপ-কোয়েরিটার নাম ধরেই খোঁজা — `earlier`। ⚠️ কেবল "sum আছে
         * এমন উপ-কোয়েরি" খুঁজলে মোট-যোগফলের কোয়েরিটা আগে মিলে যেত, আর
         * পরীক্ষাটা ভুল জিনিস মাপত।
         *
         * ⓘ ছোট-বড় হাতও মেলানো হয় না: Laravel কীওয়ার্ড ছোটহাতে লেখে,
         * আর আমাদের `COALESCE(SUM(...))` বড়হাতে।
         */
        $opening = $log->map(fn (string $sql) => strtolower($sql))
            ->first(fn (string $sql) => str_contains($sql, 'as `earlier`'));

        $this->assertNotNull($opening, 'আগের পাতার যোগফলের কোয়েরিটাই চলেনি — নামটা বদলে গেছে?');

        $this->assertStringContainsString('order by', $opening,
            'আগের পাতার সারি বাছার কোয়েরিতে ক্রম নেই — তখন "প্রথম N সারি" মানে "যেকোনো N সারি"।');

        /*
         * ⭐ আর সারিগুলো PHP-তে টানাও হয় না — যোগটা SQL-এ। ⓘ ৫০ নম্বর
         * পাতায় আগে ৪,৯০০টা সারি মেমোরিতে উঠত, কেবল দুইটা সংখ্যার জন্য।
         */
        $this->assertStringContainsString('sum(', $opening,
            'যোগফলটা আর SQL-এ হচ্ছে না — সারিগুলো আবার PHP-তে উঠছে।');
    }

    public function test_the_running_balance_is_not_totalled(): void
    {
        $ledger = $this->reports->get('accounts.ledger');
        $balance = collect($ledger->columns)->firstWhere('key', 'balance');

        // চলমান ব্যালেন্সের যোগফল একটা অর্থহীন সংখ্যা — শেষ সারির মানটাই
        // আসল ব্যালেন্স।
        $this->assertFalse($balance->total);
    }

    public function test_the_trial_balance_balances(): void
    {
        $this->postInvoices(7);

        $result = $this->reports->run('accounts.trial_balance', ['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame($result->totals['debit'], $result->totals['credit']);

        /*
         * এই চালানগুলোর দুইটা খাত, আর ডেমো ডেটার খোলা জেরগুলো আরও কিছু।
         *
         * সংখ্যাটা আগে ২ ছিল, কারণ রেওয়ামিল ভুল করে "from" তারিখটা
         * ব্যালেন্সেও লাগাত আর তার আগের সব দাখিলা বাদ দিত। রেওয়ামিল
         * জেরের রিপোর্ট — আগের সব কিছু এতে থাকতেই হবে।
         */
        $this->assertGreaterThanOrEqual(2, count($result->rows));

        $names = array_column($result->rows, 'account_name');
        $this->assertNotEmpty(array_filter($names), 'প্রতিটা সারির খাতের নাম থাকতে হবে।');
    }

    public function test_a_report_only_sees_the_company_in_context(): void
    {
        $this->postInvoices(4);

        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany($beta->id, function () {
            $result = app(ReportEngine::class)->run(
                'accounts.day_book',
                ['from' => '2026-08-01', 'to' => '2026-08-31'],
            );

            $this->assertSame(0, $result->totalRows, "Beta must not see Alpha's ledger.");
            $this->assertTrue($result->isEmpty());
        });
    }

    public function test_a_date_range_is_applied_even_when_nobody_asked_for_one(): void
    {
        // আগের অর্থবছর — পোস্টিং engine বন্ধ বা অনুপস্থিত বছরে এন্ট্রি নেয় না,
        // তাই পুরনো তারিখের ডাটা বসাতে হলে বছরটাও থাকতে হবে।
        FinancialYear::create([
            'name' => '2024-2025',
            'starts_on' => '2024-07-01',
            'ends_on' => '2025-06-30',
        ]);

        $this->postInvoices(2, '2025-01-15');

        // আজকের তারিখেই — ডিফল্ট রেঞ্জ মাসের ১ থেকে আজ পর্যন্ত, তাই
        // মাসের ১৫ তারিখে বসালে আজ ৪ তারিখ হলে সেটা রেঞ্জের বাইরে পড়ত।
        $this->postInvoices(2, date('Y-m-d'), startAt: 100);

        // ডিফল্ট রেঞ্জ চলতি মাস — সেকশন ৯: প্রতিটা তালিকায় ডেট ফিল্টার
        // বাধ্যতামূলক, নাহলে প্রথম খোলাতেই পুরো ইতিহাস টানার চেষ্টা হয়।
        $result = $this->reports->run('accounts.day_book');

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(date('Y-m-01'), $result->filters['from']);
    }

    public function test_a_backwards_date_range_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/start date is after the end date/');

        $this->reports->run('accounts.day_book', ['from' => '2026-08-31', 'to' => '2026-08-01']);
    }

    public function test_an_empty_report_still_reports_zero_rather_than_nothing(): void
    {
        $result = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->totalRows);

        // ফাঁকা ঘর নয়, "0.00" — ফাঁকা দেখলে ব্যবহারকারী ভাবে হিসাব হয়নি।
        $this->assertSame('0.00', $result->totals['debit']);
        $this->assertSame(['from' => 0, 'to' => 0, 'of' => 0], $result->showing());
    }

    public function test_export_streams_in_chunks_so_a_large_report_fits_in_memory(): void
    {
        $this->postInvoices(60);

        $rows = [];

        // শেয়ার্ড হোস্টে ১ লাখ রো একবারে মেমরিতে তুললে PHP-র সীমা ছাড়ায়
        // (সেকশন ৯)। Generator হওয়ায় মেমরি স্থির থাকে।
        foreach ($this->reports->stream('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31'], chunk: 25) as $row) {
            $rows[] = $row;
        }

        $this->assertCount(120, $rows);
    }

    public function test_money_and_quantity_are_formatted_the_same_everywhere(): void
    {
        $this->postInvoices(1);

        $result = $this->reports->run('accounts.day_book', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $debit = collect($result->report->columns)->firstWhere('key', 'debit');

        // ফরম্যাটিং ফলের ভেতরে, ভিউতে নয় — একই সংখ্যা স্ক্রিনে ও PDF-এ
        // আলাদা দেখালে ব্যবহারকারী ধরে নেয় দুটো আলাদা হিসাব।
        $this->assertSame('1,000.00', $result->format($result->rows[0], $debit));
        $this->assertSame('1,000.00', $result->formatTotal($debit));
    }

    public function test_a_column_without_a_label_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no label/');

        new ReportDefinition(
            key: 'test',
            title: 'Test',
            query: fn () => DB::table('ledger_entries'),
            columns: [['key' => 'debit']],
        );
    }

    public function test_two_reports_cannot_claim_the_same_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Two reports claim the key/');

        $this->reports->register($this->reports->get('accounts.day_book'));
    }
}
