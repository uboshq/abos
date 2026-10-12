<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ বছর বন্ধ হয় কেবল যখন কোনো কাগজ মাঝপথে নেই — অডিট ম১৭/স১২/ছ৮, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ আগে বছর বন্ধের পাহারা কেবল খসড়া ভাউচার গুনত, আর সেটাও লেনদেনের বাইরে। তিনটা ফাঁক:
 * - সইয়ের অপেক্ষার কাগজ (জমা, বিল) আর নিশ্চিত হয়েও খাতায় না-ওঠা কাগজ বন্ধের পর আর কখনো ঐ বছরে বসতে পারত না;
 * - পাহারা আর বন্ধের মাঝখানে বসা খসড়া পাহারা পেরিয়ে যেত — বছরের সারিতে তালা ছিল না;
 * - ইঞ্জিন বছরটা লেনদেনের বাইরে পড়ত, তাই ঠিক তখন বন্ধ হওয়া বছরে দাখিলা বসে যেত।
 */
final class TheYearWaitsForEveryOpenPaperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    public function test_a_paper_awaiting_signature_stops_the_year_close(): void
    {
        $this->assertClosable();

        $bill = $this->bill('PB-YEAR-WAIT', DocumentStatus::DRAFT);
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => PurchaseBill::class, 'approvable_id' => $bill->id,
            'module' => 'purchase', 'action' => 'bill', 'amount' => '1500', 'status' => Approval::PENDING,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);

        $this->assertRefused(['awaiting' => 1, 'unposted' => 0], 'সইয়ের অপেক্ষার বিল থাকতে বছর বন্ধের ছাড় মিলল');
    }

    public function test_a_confirmed_paper_outside_the_books_stops_the_year_close(): void
    {
        $this->assertClosable();

        $this->bill('PB-YEAR-UNPOSTED', DocumentStatus::CONFIRMED);

        $this->assertRefused(['awaiting' => 0, 'unposted' => 1], 'খাতায় না-ওঠা নিশ্চিত বিল থাকতে বছর বন্ধের ছাড় মিলল');
    }

    public function test_a_draft_that_lands_after_the_first_look_still_stops_the_close(): void
    {
        $this->assertClosable();

        // ⓘ প্রথম খসড়া-গোনার ঠিক পরে, লেনদেন শুরুর আগে, আরেকজন একটা খসড়া বসান
        $done = false;
        DB::listen(function (QueryExecuted $q) use (&$done): void {
            if ($done || ! str_contains($q->sql, 'vouchers')) {
                return;
            }

            $done = true;
            $this->draft();
        });

        try {
            app(YearEndService::class)->close($this->year);
            $this->fail('⛔ পাহারার পরে বসা খসড়া রেখেই বছর বন্ধ হল — বছরের সারিতে তালা নেই, পাহারা লেনদেনের বাইরে');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.year_has_drafts', ['count' => 1]), $e->errors()['year'][0] ?? null);
        }

        $this->assertTrue($done, 'দৃশ্যটাই বানানো যায়নি — খসড়া বসেনি');
        $this->assertFalse((bool) FinancialYear::query()->whereKey($this->year->id)->value('is_closed'), 'বছরটা বন্ধ হয়ে গেছে');
    }

    public function test_the_engine_reads_the_year_again_inside_its_transaction(): void
    {
        $sourceId = random_int(1, 9_999_999);

        // ⓘ ইঞ্জিন বছর পড়ার পরে, লেনদেন শুরুর আগে, আরেকজন বছরটা বন্ধ করেন
        $done = false;
        DB::listen(function (QueryExecuted $q) use (&$done): void {
            if ($done || ! str_contains($q->sql, 'ledger_entries')) {
                return;
            }

            $done = true;
            DB::table('financial_years')->where('id', $this->year->id)->update(['is_closed' => true]);
        });

        try {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $sourceId, trxDate: $this->inTheYear(), lines: [
                ['account_id' => Account::query()->where('code', '5202')->value('id'), 'debit' => '700'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '700'],
            ]);
            $this->fail('⛔ বন্ধ হয়ে যাওয়া বছরে দাখিলা বসল — ইঞ্জিন বছরটা লেনদেনের ভিতরে আবার পড়েনি');
        } catch (PostingException $e) {
            $this->assertStringContainsString('is closed', $e->getMessage());
        }

        $this->assertTrue($done, 'দৃশ্যটাই বানানো যায়নি — বছরটা মাঝপথে বন্ধ হয়নি');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'journal_voucher')->where('source_id', $sourceId)->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** প্রস্তুতি — ডেমোর বছর নিজে থেকে বন্ধযোগ্য, নাহলে মাপটা অর্থহীন। */
    private function assertClosable(): void
    {
        app(YearEndService::class)->assertCanClose($this->year);
        $this->addToAssertionCount(1);
    }

    /** @param array{awaiting: int, unposted: int} $counts */
    private function assertRefused(array $counts, string $why): void
    {
        try {
            app(YearEndService::class)->assertCanClose($this->year);
            $this->fail('⛔ '.$why);
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.year_has_open_papers', $counts), $e->errors()['year'][0] ?? null);
        }
    }

    private function inTheYear(): string
    {
        $today = CarbonImmutable::today();
        $ends = CarbonImmutable::parse($this->year->ends_on);

        return ($today->greaterThan($ends) ? $ends : $today)->toDateString();
    }

    private function bill(string $no, string $status): PurchaseBill
    {
        return PurchaseBill::query()->create([
            'company_id' => $this->company->id,
            'document_no' => $no,
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'trx_date' => $this->inTheYear(),
            'status' => $status,
            'total' => '1500',
        ]);
    }

    private function draft(): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $this->inTheYear(), 'narration' => 'খসড়া'],
            [
                ['account_id' => Account::query()->where('code', '5202')->value('id'), 'debit' => '700'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '700'],
            ],
        );
    }
}
