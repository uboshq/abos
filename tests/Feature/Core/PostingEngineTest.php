<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের খাতায় লেখার একমাত্র দরজা ঠিকভাবে পাহারা দিচ্ছে কি না।
 *
 * এই টেস্টগুলোর প্রতিটাই একটা বাস্তব ভুল ঠেকায় — অমিল এন্ট্রি, দুইবার
 * পোস্ট, বন্ধ বছরে এন্ট্রি, আর মোছার বদলে উল্টো এন্ট্রি।
 */
class PostingEngineTest extends TestCase
{
    use RefreshDatabase;

    private PostingEngine $engine;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(PostingEngine::class);

        $this->company = Company::create(['code' => 'TEST', 'name_en' => 'Test Company']);
        CompanyContext::set($this->company->id);

        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ]);

        $this->makeAccounts();
    }

    /**
     * এই ফাইলের দাখিলাগুলো যে খাতগুলোতে বসে।
     *
     * ── কেন এগুলো এখন সত্যিই বানাতে হয় ──────────────────────────────
     * আগে পরীক্ষাগুলো ১০ · ১১ · ২০ · ৩০ · ৪০ — এই আইডিগুলো হাতে লিখত,
     * আর কোনো খাত সত্যিই থাকত না। ইঞ্জিন তখন খাতের দিকে তাকাতই না,
     * তাই কিছু ভাঙত না।
     *
     * ৫ সেপ্টেম্বর ২০২৬-এ ইঞ্জিন খাত যাচাই করা শুরু করেছে — আছে কি,
     * এই কোম্পানির কি, দল কি। ⛔ তাতে এই ফাইলের প্রতিটা দাখিলা
     * *"account 10 does not exist"* বলে থেমে যেত।
     *
     * ⭐ পাহারাটাই ঠিক, পরীক্ষাগুলোই অবাস্তব ছিল: কাল্পনিক আইডিতে টাকা
     * বসানো ঠিক সেই জিনিস যেটা লাইভে নীরবে টাকা হারায়। তাই আইডিগুলো
     * রাখা হলো (দাবিগুলো ওগুলো ধরে লেখা), কিন্তু খাতগুলো আসল।
     */
    private function makeAccounts(): void
    {
        foreach ([10, 11, 20, 30, 40] as $id) {
            DB::table('accounts')->insert([
                'id' => $id,
                'company_id' => $this->company->id,
                'code' => (string) (9000 + $id),
                'name_en' => "Test account {$id}",
                'type' => 'asset',
                'nature' => 'debit',
                'is_group' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_balanced_document_is_written_to_the_ledger(): void
    {
        $entries = $this->engine->post('sales_invoice', 1, '2026-08-04', [
            ['account_id' => 10, 'debit' => 1500.50, 'narration' => 'গ্রাহকের পাওনা'],
            ['account_id' => 20, 'credit' => 1500.50, 'narration' => 'বিক্রয়'],
        ], documentNo: 'INV-2026-0001');

        $this->assertCount(2, $entries);
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame('INV-2026-0001', LedgerEntry::query()->first()->document_no);
    }

    public function test_an_unbalanced_document_writes_nothing_at_all(): void
    {
        try {
            $this->engine->post('sales_invoice', 2, '2026-08-04', [
                ['account_id' => 10, 'debit' => 1000],
                ['account_id' => 20, 'credit' => 900],
            ]);
            $this->fail('An unbalanced document should not be accepted.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        }

        // অর্ধেক বসা হিসাব না বসা হিসাবের চেয়ে খারাপ — একটা রোও যেন না থাকে।
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_fractional_amounts_still_balance_exactly(): void
    {
        // float-এ 0.1 + 0.2 !== 0.3 — এই টেস্টটাই bcmath ব্যবহারের কারণ।
        $entries = $this->engine->post('journal_voucher', 3, '2026-08-04', [
            ['account_id' => 10, 'debit' => 0.1],
            ['account_id' => 11, 'debit' => 0.2],
            ['account_id' => 20, 'credit' => 0.3],
        ]);

        $this->assertCount(3, $entries);
    }

    public function test_the_same_document_cannot_be_posted_twice(): void
    {
        $lines = [
            ['account_id' => 10, 'debit' => 500],
            ['account_id' => 20, 'credit' => 500],
        ];

        $this->engine->post('receipt_voucher', 4, '2026-08-04', $lines);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/already in the ledger/');

        // ব্যবহারকারী Save-এ দুইবার ক্লিক করলে ঠিক এটাই ঘটে।
        $this->engine->post('receipt_voucher', 4, '2026-08-04', $lines);
    }

    public function test_a_line_cannot_carry_both_a_debit_and_a_credit(): void
    {
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/both a debit and a credit/');

        $this->engine->post('journal_voucher', 5, '2026-08-04', [
            ['account_id' => 10, 'debit' => 100, 'credit' => 40],
            ['account_id' => 20, 'credit' => 60],
        ]);
    }

    public function test_posting_into_a_closed_year_is_refused(): void
    {
        FinancialYear::query()->update(['is_closed' => true]);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/is closed/');

        $this->engine->post('sales_invoice', 6, '2026-08-04', [
            ['account_id' => 10, 'debit' => 100],
            ['account_id' => 20, 'credit' => 100],
        ]);
    }

    public function test_posting_to_a_date_no_year_covers_is_refused(): void
    {
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/No financial year covers/');

        $this->engine->post('sales_invoice', 7, '2020-01-01', [
            ['account_id' => 10, 'debit' => 100],
            ['account_id' => 20, 'credit' => 100],
        ]);
    }

    public function test_reversing_keeps_the_original_and_adds_the_opposite(): void
    {
        $this->engine->post('sales_invoice', 8, '2026-08-04', [
            ['account_id' => 10, 'debit' => 700],
            ['account_id' => 20, 'credit' => 700],
        ], documentNo: 'INV-2026-0008');

        $reversal = $this->engine->reverse('sales_invoice', 8, '2026-08-05', 'গ্রাহক ফেরত দিয়েছে');

        $this->assertCount(2, $reversal);
        $this->assertSame(4, LedgerEntry::query()->count(), 'The original entries must survive a reversal.');

        // মোট ডেবিট = মোট ক্রেডিট, এবং নিট প্রভাব শূন্য
        $totalDebit = LedgerEntry::query()->sum('debit');
        $totalCredit = LedgerEntry::query()->sum('credit');

        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(1400, $totalDebit);
    }

    public function test_reversing_something_never_posted_is_refused(): void
    {
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/Nothing to reverse/');

        $this->engine->reverse('sales_invoice', 999, '2026-08-05');
    }

    public function test_an_empty_document_is_refused(): void
    {
        $this->expectException(PostingException::class);
        $this->engine->post('sales_invoice', 10, '2026-08-04', []);
    }

    public function test_ledger_rows_carry_the_source_so_a_figure_can_be_traced_back(): void
    {
        $this->engine->post('purchase_invoice', 11, '2026-08-04', [
            ['account_id' => 30, 'debit' => 250],
            ['account_id' => 40, 'credit' => 250],
        ], documentNo: 'PUR-2026-0011');

        $entry = LedgerEntry::query()->first();

        // নিয়ম ১-এর ভিত্তি: এই দুটো ছাড়া কোনো সংখ্যা তার উৎসে ফিরতে পারে না।
        $this->assertSame('purchase_invoice', $entry->source_type);
        $this->assertSame(11, (int) $entry->source_id);
    }
}
