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
use Tests\TestCase;

/**
 * রিপোর্ট — প্ল্যান সেকশন ২.২, ষষ্ঠ engine।
 *
 * সবচেয়ে জরুরি টেস্ট দুটো: যোগফল পুরো ফলের উপর (পাতার উপর নয়), আর
 * প্রতিটা সারি তার ডকুমেন্টে ফিরতে পারে (নিয়ম ১)।
 */
class ReportEngineTest extends TestCase
{
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
                ['account_id' => 1101, 'debit' => 1000 * $i],
                ['account_id' => 4001, 'credit' => 1000 * $i],
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

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => 1101];

        $page1 = $this->reports->run('accounts.ledger', $filters, page: 1, perPage: 4);
        $page2 = $this->reports->run('accounts.ledger', $filters, page: 2, perPage: 4);

        // end() রেফারেন্স নেয়, আর readonly প্রপার্টি বদলানো যায় না — তাই
        // অ্যারেটা আগে নিজের ভেরিয়েবলে।
        $rowsOfPage1 = $page1->rows;

        $lastOfPage1 = (float) $rowsOfPage1[array_key_last($rowsOfPage1)]['balance'];
        $firstOfPage2 = (float) $page2->rows[0]['balance'];

        // দ্বিতীয় পাতা শূন্য থেকে শুরু হলে ব্যালেন্স কলামটা মিথ্যা বলত।
        $this->assertGreaterThan($lastOfPage1, $firstOfPage2);
        $this->assertSame(1000.0 + 2000 + 3000 + 4000, $lastOfPage1);
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
        $this->assertCount(2, $result->rows, 'Two accounts were touched.');
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
