<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ উল্টো দাখিলা মূলটার হুবহু আয়না — ধরন, কোম্পানি, কাগজ, বিবরণ, লেখক, পাহারার নাম (Infection, ২৮ নভেম্বর ২০২৬, abos-b1)।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * [[PostingEngine::reverse()]]-এ ৩৭টা mutant বেঁচে ছিল: উল্টো সারির `source_type` থেকে উৎসের নাম মুছলে, কোম্পানি বাদ দিলে,
 * কাগজ-নম্বর বা কারণের ক্রম উল্টালে, বন্ধ মাসের তালা সরালে, জেরের স্মৃতি না মুছলে — কোনো পরীক্ষা লাল হত না। পুরনো পরীক্ষা কেবল
 * দেখত "চারটা সারি, ডেবিট = ক্রেডিট"। ⛔ অথচ খতিয়ানে "কোন কাগজ উল্টাল, কেন, কে" — নিরীক্ষার পুরো প্রশ্নটা ঐ ঘরগুলোতেই।
 *
 * ⓘ যা ইচ্ছা করে এখানে নেই: বছরের তালা দ্বিতীয়বার দেখা (`assertYearStillOpen`) আর পাহারার সারি ঢোকানো — দুটোই দুইজন একই
 * মুহূর্তে লিখলে কাজে লাগে; একা চলা পরীক্ষায় আগের ধাপই থামায়। পাহারার **নাম** অবশ্য এখানে দেখা হয়।
 */
final class AReversalMirrorsItsPaperExactlyTest extends TestCase
{
    use RefreshDatabase;

    private PostingEngine $engine;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(PostingEngine::class);
        $this->company = Company::create(['code' => 'REV', 'name_en' => 'Reversal Co']);
        CompanyContext::set($this->company->id);

        FinancialYear::create(['name' => '2026-2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_current' => true]);

        foreach ([10, 20] as $id) {
            DB::table('accounts')->insert([
                'id' => $id, 'company_id' => $this->company->id, 'code' => (string) (9000 + $id), 'name_en' => "Account {$id}",
                'type' => 'asset', 'nature' => 'debit', 'is_group' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $amount = '700'): array
    {
        return [
            ['account_id' => 10, 'debit' => $amount],
            ['account_id' => 20, 'credit' => $amount],
        ];
    }

    public function test_each_reversal_row_names_its_kind_company_paper_and_the_original_document(): void
    {
        $this->engine->post('sales_invoice', 8, '2026-08-04', $this->lines(), documentNo: 'INV-2026-0008');

        $writer = User::factory()->create();
        $someoneElse = User::factory()->create();
        $this->actingAs($someoneElse);

        // ⓘ লগ-ইন অবস্থায় পেছনের তারিখের জানালা (৭ দিন) খাটে — তাই আজকের তারিখে উল্টানো; বছরটা আজকে ঢাকে
        $today = now()->toDateString();
        $rows = $this->engine->reverse('sales_invoice', 8, $today, userId: $writer->id);

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame('sales_invoice:reversal', $row->source_type, '⛔ উল্টো সারি উৎসের নাম হারাল');
            $this->assertSame(8, (int) $row->source_id);
            $this->assertSame($this->company->id, (int) $row->company_id, '⛔ উল্টো সারি কোম্পানি হারাল');
            $this->assertSame('INV-2026-0008', $row->document_no, '⛔ নিজের নম্বর না দিলে মূল কাগজের নম্বরই থাকার কথা');
            $this->assertSame(__('core.posting.reversal_of', ['document' => 'INV-2026-0008']), $row->narration);
            $this->assertStringContainsString('INV-2026-0008', (string) $row->narration, '⛔ বিবরণে কোন কাগজ উল্টাল তা নেই');
            $this->assertSame($writer->id, (int) $row->created_by, '⛔ যিনি উল্টাতে বললেন তাঁর বদলে লগ-ইন করা মানুষ বসল');
            $this->assertSame($today, $row->trx_date instanceof \DateTimeInterface ? $row->trx_date->format('Y-m-d') : (string) $row->trx_date);
        }

        // ডেবিট-ক্রেডিট উল্টানো: খাত ১০-এ মূলটা ডেবিট ৭০০, উল্টোটা ক্রেডিট ৭০০
        $mirror = collect($rows)->first(fn (LedgerEntry $r) => (int) $r->account_id === 10)->refresh();
        $this->assertSame(0, bccomp((string) $mirror->credit, '700', 4));
        $this->assertSame(0, bccomp((string) $mirror->debit, '0', 4));

        $this->assertTrue(
            DB::table('posted_documents')->where('source_type', 'sales_invoice:reversal')->where('source_id', 8)->exists(),
            '⛔ প্রথম উল্টানোর পাহারা `<উৎস>:reversal` নামে বসেনি',
        );
    }

    public function test_a_reversal_under_its_own_number_and_reason_carries_both(): void
    {
        $this->engine->post('sales_invoice', 9, '2026-08-04', $this->lines(), documentNo: 'INV-2026-0009');

        $rows = $this->engine->reverse('sales_invoice', 9, '2026-08-06', 'গ্রাহক ফেরত দিয়েছে', documentNo: 'CXL-2026-0001');

        foreach ($rows as $row) {
            $this->assertSame('CXL-2026-0001', $row->refresh()->document_no, '⛔ বাতিলের নিজের নম্বর হারাল');
            $this->assertSame('গ্রাহক ফেরত দিয়েছে', $row->narration, '⛔ দেওয়া কারণের বদলে ছাঁচের বিবরণ বসল');
        }
    }

    public function test_a_paper_can_be_posted_reversed_posted_again_and_reversed_again(): void
    {
        // নিশ্চিত বিল সম্পাদনার পথ: উল্টানো → আবার বসানো → আবার উল্টানো
        $this->engine->post('purchase_bill', 3, '2026-08-04', $this->lines('500'), documentNo: 'PB-3');
        $this->engine->reverse('purchase_bill', 3, '2026-08-05');
        $this->engine->post('purchase_bill', 3, '2026-08-06', $this->lines('650'), documentNo: 'PB-3');

        // ⭐ যা উল্টানো হয়নি কেবল তা-ই উল্টায় (৬৫০), প্রথম দাখিলা (৫০০) আবার নয় — আর দ্বিতীয়বার "already posted" নয়
        $again = $this->engine->reverse('purchase_bill', 3, '2026-08-07');

        $this->assertCount(2, $again, '⛔ দ্বিতীয় উল্টানো থামল, বা প্রথম দাখিলাটাও আবার উল্টাল');
        $this->assertSame(0, bccomp((string) collect($again)->sum(fn ($r) => (float) $r->refresh()->debit), '650', 4));

        $last = (int) LedgerEntry::query()->where('source_type', 'purchase_bill')->where('source_id', 3)->max('id');
        $this->assertTrue(
            DB::table('posted_documents')->where('source_type', 'purchase_bill:reversal@'.$last)->where('source_id', 3)->exists(),
            '⛔ দ্বিতীয় উল্টানোর পাহারা যে দাখিলা উল্টাল তার শেষ সারির id বহন করে না',
        );

        $this->expectException(PostingException::class);
        $this->engine->reverse('purchase_bill', 3, '2026-08-08');
    }

    public function test_a_reversal_into_a_closed_month_is_refused(): void
    {
        $this->engine->post('sales_invoice', 11, '2026-08-04', $this->lines(), documentNo: 'INV-11');

        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 9, 'reason' => 'audited', 'locked_at' => now()]);

        try {
            $this->engine->reverse('sales_invoice', 11, '2026-09-10');
            $this->fail('⛔ বন্ধ মাসের ভেতরে উল্টো দাখিলা বসল');
        } catch (ValidationException) {
            $this->assertSame(0, LedgerEntry::query()->where('source_type', 'sales_invoice:reversal')->count());
        }
    }

    public function test_a_reversal_wipes_the_remembered_balances(): void
    {
        $this->engine->post('sales_invoice', 12, '2026-08-04', $this->lines(), documentNo: 'INV-12');

        $balances = app(LedgerBalances::class);
        $balances->preload([10]);
        $this->assertNotNull($balances->raw(10), 'প্রস্তুতিটাই ভুল — জের মনে রাখা হয়নি');

        $this->engine->reverse('sales_invoice', 12, '2026-08-05');

        $this->assertNull(app(LedgerBalances::class)->raw(10), '⛔ উল্টানোর পরেও পুরনো জের মনে রইল — পাতা ভুল জের দেখাত');
    }

    public function test_an_amount_written_in_scientific_form_with_spaces_posts_its_value(): void
    {
        $rows = $this->engine->post('sales_invoice', 13, '2026-08-04', [
            ['account_id' => 10, 'debit' => ' 1.5e3 '],
            ['account_id' => 20, 'credit' => '1500'],
        ], documentNo: 'INV-13');

        $this->assertSame(0, bccomp((string) $rows[0]->refresh()->debit, '1500', 4), '⛔ " 1.5e3 " ১৫০০ হয়ে বসল না');
    }
}
