<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দাম ভুল হতে পারত, আর সেটা বলার কোনো কাগজ ছিল না — মানচিত্র §৭।
 *
 * ── ⚠️ কেন ফেরতের কাগজে কাজ চলত না ──────────────────────────────────
 * ফেরত মানে **মাল নড়ে**। ⛔ কিন্তু দাম ভুল বসা, মাল নষ্ট হয়ে ছাড় দেওয়া,
 * বা সরবরাহকারীর বেশি দাম — এগুলোতে গুদামে কিছুই বদলায় না। ⓘ ফেরতের
 * কাগজ কেটে শোধরালে স্টক **মিথ্যা** বলত: গুদামে যে মাল নেই সেটা ফিরে
 * এসেছে বলে দেখাত।
 *
 * ⭐ তাই নোট — টাকার সংশোধন, মাল ছাড়া। আর এই ফাইলের সবচেয়ে জরুরি
 * পরীক্ষাটা ঠিক সেটাই পাহারা দেয়: **স্টকে কিছুই নড়ে না**।
 */
final class APriceCouldBeWrongAndNothingCouldSaySoTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->customerId = (int) \App\Modules\Customer\Models\Customer::query()->value('id');
        $this->supplierId = (int) \App\Modules\Supplier\Models\Supplier::query()->value('id');
    }

    /**
     * ⭐ ক্রেডিট নোট — গ্রাহকের কাছে আমাদের পাওনা কমে।
     *
     * Dr বিক্রয় ফেরত ৪১১০ · Dr ভ্যাট ২১২০ / Cr প্রাপ্য ১১১০ (গ্রাহক)
     */
    public function test_a_credit_note_lowers_what_the_customer_owes(): void
    {
        $note = $this->note(Note::CREDIT, $this->customerId, amount: '1200', tax: '180');

        $this->assertSame('1380.0000', $note->total);

        app(NoteService::class)->confirm($note);

        $entries = LedgerEntry::query()->where('source_type', 'credit_note')->get();

        $this->assertCount(3, $entries, 'তিনটা সারি বসার কথা — ফেরত, ভ্যাট, আর প্রাপ্য।');

        $receivable = $this->accountId(StandardChart::RECEIVABLE);
        $ours = $entries->firstWhere('account_id', $receivable);

        $this->assertNotNull($ours, 'প্রাপ্য খাতে কোনো সারিই বসেনি।');

        // ⭐ প্রাপ্য **ক্রেডিট** হয় — গ্রাহকের কাছে আমাদের পাওনা কমল
        $this->assertSame('1380.0000', $ours->credit);
        $this->assertSame('0.0000', $ours->debit);

        // ⓘ আর সারিটা গ্রাহকের নামে বসে, নাহলে তাঁর খাতায় ছাড়টা দেখাত না
        $this->assertSame('customer', $ours->party_type);
        $this->assertSame($this->customerId, (int) $ours->party_id);

        $this->assertSame('1200.0000',
            $entries->firstWhere('account_id', $this->accountId(StandardChart::SALES_RETURN))->debit);
    }

    /**
     * ⭐ ডেবিট নোট — সরবরাহকারীকে আমাদের দেনা কমে।
     *
     * Dr প্রদেয় ২১১১ (সরবরাহকারী) / Cr ক্রয়ের দামের ফারাক ৫১৫০
     */
    public function test_a_debit_note_lowers_what_we_owe_the_supplier(): void
    {
        $note = $this->note(Note::DEBIT, $this->supplierId, amount: '900', tax: '0');

        app(NoteService::class)->confirm($note);

        $entries = LedgerEntry::query()->where('source_type', 'debit_note')->get();

        $this->assertCount(2, $entries, 'ভ্যাট ছাড়া দুইটা সারিই বসার কথা।');

        $payable = $entries->firstWhere('account_id', $this->accountId(StandardChart::PAYABLE));

        $this->assertNotNull($payable, 'প্রদেয় খাতে কোনো সারিই বসেনি।');

        // ⭐ প্রদেয় **ডেবিট** হয় — আমাদের দেনা কমল
        $this->assertSame('900.0000', $payable->debit);
        $this->assertSame('supplier', $payable->party_type);
    }

    /**
     * ⛔⛔ সবচেয়ে জরুরি পরীক্ষা: স্টকে কিছুই নড়ে না।
     *
     * ⚠️ এটাই নোট আর ফেরতের কাগজের গোটা পার্থক্য। ⓘ এখানে ফাটল ধরলে
     * নোট কেটে শোধরানোর প্রতিটা ভুল গুদামের হিসাবকেও মিথ্যা বানাত, আর
     * সেটা কেউ মাস শেষে গোনার আগে ধরতে পারত না।
     */
    public function test_nothing_moves_in_the_warehouse(): void
    {
        $before = \App\Modules\Inventory\Models\StockMovement::query()->count();

        app(NoteService::class)->confirm(
            $this->note(Note::CREDIT, $this->customerId, amount: '1200', tax: '0'),
        );

        $this->assertSame($before, \App\Modules\Inventory\Models\StockMovement::query()->count(),
            'নোট কাটায় স্টক নড়েছে — তাহলে ওটা নোট নয়, ফেরতের কাগজ।');
    }

    /**
     * ⭐ খসড়া অবস্থায় বইয়ে কিছুই যায় না।
     *
     * ⓘ কাগজটা লেখা আর কাগজটা কার্যকর হওয়া দুইটা আলাদা মুহূর্ত — নাহলে
     * ভুল করে খোলা একটা ফর্মও বই বদলে দিত।
     */
    public function test_a_draft_touches_nothing(): void
    {
        $note = $this->note(Note::CREDIT, $this->customerId, amount: '500', tax: '0');

        $this->assertSame(DocumentStatus::DRAFT, $note->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'credit_note')->count());
    }

    /**
     * ⭐ বাতিল করলে দাখিলা ফিরিয়ে নেওয়া হয় — মুছে ফেলা হয় না।
     */
    public function test_cancelling_reverses_the_entries_instead_of_erasing_them(): void
    {
        $notes = app(NoteService::class);
        $note = $notes->confirm($this->note(Note::CREDIT, $this->customerId, amount: '700', tax: '0'));

        $notes->cancel($note, 'ভুল গ্রাহকের নামে কাটা হয়েছিল');

        $this->assertTrue($note->fresh()->isCancelled());

        $receivable = $this->accountId(StandardChart::RECEIVABLE);

        $rows = LedgerEntry::query()
            ->where('account_id', $receivable)
            ->get();

        // ⭐ দুই দিকের দুইটা সারি — মূলটা আর ফেরানোটা; কোনোটাই মোছা হয়নি
        $this->assertSame(2, $rows->count(),
            'ফেরানোর সারিটা বসেনি, নয়তো মূল সারিটা মুছে ফেলা হয়েছে — বই মোছা যায় না।');

        $this->assertSame(700.0, round($rows->sum(fn ($r) => (float) $r->credit), 4),
            'মূল ক্রেডিটের সারিটা আর নেই।');

        $this->assertSame(0.0,
            round($rows->sum(fn ($r) => (float) $r->debit - (float) $r->credit), 4),
            'ফেরানোর পরেও প্রাপ্য খাতে একটা জের পড়ে আছে।');
    }

    /**
     * ⛔ পক্ষের ধরন ফর্ম থেকে আসে না — দিক থেকেই আসে।
     *
     * ⚠️ নাহলে গ্রাহকের নামে ডেবিট নোট পাঠানো যেত, আর সেটা বইয়ে প্রদেয়তে
     * গিয়ে বসত — যেখানে গ্রাহকের কোনো জায়গা নেই।
     */
    public function test_the_party_kind_comes_from_the_direction_not_the_form(): void
    {
        $this->post(route('accounts.note.store'), [
            'direction' => Note::DEBIT,
            'party_type' => 'customer',   // ⛔ পাঠানো হলো, কিন্তু মানা হবে না
            'party_id' => $this->supplierId,
            'trx_date' => now()->toDateString(),
            'amount' => '250',
            'reason' => 'price_correction',
        ])->assertRedirect();

        $this->assertSame('supplier', Note::query()->latest('id')->firstOrFail()->party_type);
    }

    /**
     * ⛔ শূন্য বা ঋণাত্মক অঙ্কে নোট হয় না।
     */
    public function test_a_note_needs_real_money_on_it(): void
    {
        $this->post(route('accounts.note.store'), [
            'direction' => Note::CREDIT,
            'party_id' => $this->customerId,
            'trx_date' => now()->toDateString(),
            'amount' => '0',
            'reason' => 'other',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Note::query()->count());
    }

    private function note(string $direction, int $partyId, string $amount, string $tax): Note
    {
        return app(NoteService::class)->create([
            'direction' => $direction,
            'party_type' => $direction === Note::CREDIT ? 'customer' : 'supplier',
            'party_id' => $partyId,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'tax_amount' => $tax,
            'reason' => 'price_correction',
            'against_no' => 'INV-TEST-1',
            'narration' => 'দাম ভুল বসেছিল',
        ]);
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->postable()->where('code', $code)->value('id');
    }
}
