<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Reversal;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * উল্টো কাগজ — মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬: "পাকা কাগজ বদলায় না; ভুল শোধরায় উল্টো কাগজে" ([[AccountsReversalService]])।
 *
 * পাকা জাবেদা ৫০০ (পাওনা Dr / দেনা Cr) আর পাকা ক্রেডিট নোট ১,২০০ — "বাতিল" চাপলে:
 *   · নিজের নম্বরে (REV-…) উল্টো কাগজ, মূলের সূত্রসহ; মূল "বাতিল" হয়ে থাকে, পাতায় "উল্টো কাগজ: REV-…";
 *   · খাতা পুরো উল্টায়, উল্টো সারিগুলো REV নম্বরে; নিট শূন্য;
 *   · খসড়ার বাতিল আগের মতো — উল্টো কাগজ নয়;
 *   · মাস বন্ধ, ব্যাংক মেলানো, দ্বিতীয়বার — ফেরত, কারণসহ।
 */
final class AReversalUndoesAPostedPaperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_posted_voucher_gets_its_own_reversal_paper_and_the_books_go_to_zero(): void
    {
        $voucher = $this->journal('500');

        $this->post(route('accounts.voucher.cancel', $voucher), ['cancel_reason' => 'ভুল খাতে বসেছিল'])->assertSessionHas('saved');

        $paper = Reversal::of(Reversal::VOUCHER, (int) $voucher->id);
        $this->assertNotNull($paper, '⛔ উল্টো কাগজ হয়নি।');
        $this->assertStringStartsWith('REV-', $paper->document_no, '⛔ নিজের ক্রমে নম্বর নয়।');
        $this->assertSame($voucher->document_no, $paper->reversed_no);

        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status, '⛔ মূল ভাউচার বাতিল হয়নি।');
        $this->assertSame('0.0000', $this->net(Voucher::SOURCE_TYPES[Voucher::JOURNAL], (int) $voucher->id), '⛔ খাতা শূন্যে ফেরেনি।');
        $this->assertTrue(LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::JOURNAL].':reversal')
            ->where('source_id', $voucher->id)->where('document_no', $paper->document_no)->exists(), '⛔ উল্টো সারি REV নম্বরে নয়।');

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertSee($paper->document_no)->assertSee('ভুল খাতে বসেছিল');
    }

    public function test_a_draft_is_simply_cancelled_without_a_reversal_paper(): void
    {
        $draft = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'খসড়া'],
            [['account_id' => $this->receivable(), 'debit' => '100'], ['account_id' => $this->payable(), 'credit' => '100']]);

        $this->post(route('accounts.voucher.cancel', $draft), ['cancel_reason' => 'লাগবে না'])->assertSessionHas('saved');

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertNull(Reversal::of(Reversal::VOUCHER, (int) $draft->id), '⛔ খসড়ার জন্যও উল্টো কাগজ হয়েছে।');
    }

    public function test_a_closed_month_a_reconciled_line_and_a_second_time_are_refused(): void
    {
        // আগের মাসের ভাউচার, সেই মাস বন্ধ — এ মাস খোলা থাকলেও ফেরত
        $this->travel(-1)->months();
        $old = $this->journal('300');
        $this->travelBack();
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) $old->trx_date->year, 'month' => (int) $old->trx_date->month,
            'reason' => 'মাস বন্ধ', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
        $this->post(route('accounts.voucher.cancel', $old), ['cancel_reason' => 'চেষ্টা'])->assertSessionHasErrors('cancel_reason');
        $this->assertSame(DocumentStatus::CONFIRMED, $old->fresh()->status);

        // ব্যাংক বিবরণীর লাইনে মেলানো
        $matched = $this->journal('200');
        DB::table('acc_bank_statement_lines')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid7(), 'company_id' => $this->company->id,
            'bank_account_id' => $this->receivable(), 'trx_date' => now()->toDateString(), 'debit' => '200', 'credit' => '0',
            'fingerprint' => 'rev-'.$matched->id, 'matched_line_id' => $matched->lines()->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('accounts.voucher.cancel', $matched), ['cancel_reason' => 'চেষ্টা'])->assertSessionHasErrors('cancel_reason');
        $this->assertSame(DocumentStatus::CONFIRMED, $matched->fresh()->status);

        $this->assertSame(0, Reversal::query()->count(), '⛔ থামার পরেও উল্টো কাগজ রয়ে গেছে।');
    }

    public function test_a_posted_note_gets_its_reversal_and_the_party_moves_back(): void
    {
        $customer = Customer::query()->firstOrFail();
        $service = app(NoteService::class);
        $note = $service->confirm($service->create([
            'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => $customer->id,
            'trx_date' => now()->toDateString(), 'amount' => '1200', 'tax_amount' => '0',
            'reason' => 'price_correction', 'narration' => 'দাম ভুল',
        ]));
        $before = (string) $customer->fresh()->outstanding();

        $this->post(route('accounts.note.cancel', $note), ['cancel_reason' => 'ভুল পক্ষ'])->assertSessionHas('saved');

        $paper = Reversal::of(Reversal::NOTE, (int) $note->id);
        $this->assertNotNull($paper);
        $this->assertSame(DocumentStatus::CANCELLED, $note->fresh()->status);
        $this->assertSame(0, bccomp(bcadd($before, '1200', 4), (string) $customer->fresh()->outstanding(), 2), '⛔ গ্রাহকের বকেয়া ফেরেনি।');
        $this->assertTrue(LedgerEntry::query()->where('source_type', $note->sourceType().':reversal')
            ->where('source_id', $note->id)->where('document_no', $paper->document_no)->exists(), '⛔ উল্টো সারি REV নম্বরে নয়।');

        $this->get(route('accounts.note.show', $note))->assertOk()->assertSee($paper->document_no);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function journal(string $amount): Voucher
    {
        $service = app(VoucherService::class);

        return $service->post($service->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'আসল'],
            [['account_id' => $this->receivable(), 'debit' => $amount], ['account_id' => $this->payable(), 'credit' => $amount]],
        ));
    }

    private function net(string $type, int $id): string
    {
        return (string) LedgerEntry::query()->whereIn('source_type', [$type, $type.':reversal'])->where('source_id', $id)
            ->get()->reduce(fn ($c, $e) => bcadd($c, bcsub((string) $e->debit, (string) $e->credit, 4), 4), '0');
    }

    private function receivable(): int
    {
        return (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
    }

    private function payable(): int
    {
        return (int) StandardChart::find(StandardChart::PAYABLE)->id;
    }
}
