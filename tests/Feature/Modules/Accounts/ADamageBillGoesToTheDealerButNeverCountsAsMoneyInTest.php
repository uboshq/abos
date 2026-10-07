<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteAccounts;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CustomerTargetService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ড্যামেজ বিল — কোম্পানি পাস করেছে, ডিলারের খাতায় যায়, কিন্তু টাকা আসা (inflow) হিসেবে গোনা হয় না
 * (মালিক, ৪ অক্টোবর ২০২৬: "Damage Bill compani theke pas hoye eseche tai seta dilar ledger e dite hobe but eta inflow
 * hisebe count hobe na")।
 *
 * ⓘ আন্তর্জাতিক নিয়মে: ডিলারকে ক্রেডিট নোট — Dr ড্যামেজ দাবি (১১৫১) / Cr ডিলার; কোম্পানিকে ডেবিট নোট — Dr কোম্পানি /
 * Cr ড্যামেজ দাবি। দুই নোট মিলে দাবির খাত শূন্য; কোনো দিকেই নগদ বা ব্যাংক নেই।
 */
final class ADamageBillGoesToTheDealerButNeverCountsAsMoneyInTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Supplier $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->firstOrFail();
        $this->company = Supplier::query()->firstOrFail();
    }

    public function test_both_notes_offer_the_damage_claim_and_the_defaults_stay(): void
    {
        $accounts = app(NoteAccounts::class);

        $this->assertSame([StandardChart::SALES_RETURN, StandardChart::DISCOUNT_GIVEN, StandardChart::DAMAGE_CLAIM],
            $accounts->others(Note::KIND_CUSTOMER, Note::CREDIT)->pluck('code')->all(), '⛔ ডিলারের ক্রেডিট নোটে ড্যামেজ দাবি নেই।');
        $this->assertSame([StandardChart::PURCHASE_PRICE_VARIANCE, StandardChart::DAMAGE_CLAIM],
            $accounts->others(Note::KIND_SUPPLIER, Note::DEBIT)->pluck('code')->all(), '⛔ কোম্পানির ডেবিট নোটে ড্যামেজ দাবি নেই।');

        $this->assertSame(StandardChart::SALES_RETURN, StandardChart::find(StandardChart::SALES_RETURN)->code);
        $this->assertSame((int) StandardChart::find(StandardChart::SALES_RETURN)->id,
            $accounts->defaultOther(Note::KIND_CUSTOMER, Note::CREDIT, (int) $this->dealer->id), '⛔ ডিফল্ট বদলে গেছে।');
    }

    public function test_the_dealer_gets_his_credit_the_company_owes_it_and_the_claim_closes_to_zero(): void
    {
        $credit = $this->note(Note::CREDIT, 'customer', (int) $this->dealer->id, '1500');

        $this->assertSame(0, bccomp($this->sum($credit, StandardChart::RECEIVABLE, 'credit', 'customer', (int) $this->dealer->id), '1500', 4),
            '⛔ ড্যামেজের টাকা ডিলারের খাতায় Cr বসেনি।');
        $this->assertSame(0, bccomp($this->sum($credit, StandardChart::DAMAGE_CLAIM, 'debit'), '1500', 4),
            '⛔ কোম্পানির কাছে দাবি (১১৫১) বসেনি।');

        $debit = $this->note(Note::DEBIT, 'supplier', (int) $this->company->id, '1500');
        $this->assertSame(0, bccomp($this->sum($debit, StandardChart::PAYABLE, 'debit', 'supplier', (int) $this->company->id), '1500', 4),
            '⛔ কোম্পানির দেনা ড্যামেজের টাকায় কমেনি।');

        $claim = StandardChart::find(StandardChart::DAMAGE_CLAIM);
        $net = (string) LedgerEntry::query()->where('account_id', $claim->id)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
        $this->assertSame(0, bccomp($net, '0', 4), '⛔ দুই নোটের পরেও দাবির খাত শূন্যে মেলেনি: '.$net);

        $this->get(route('accounts.note.show', $credit))->assertOk()->assertSee(__('accounts::note.reason_damage_claim'));
    }

    public function test_a_damage_bill_never_shows_as_money_in(): void
    {
        $note = $this->note(Note::CREDIT, 'customer', (int) $this->dealer->id, '1500');

        $rows = app(ReportEngine::class)->run('accounts.inflow', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])->rows;
        $this->assertNotContains($note->document_no, array_column(array_map(fn ($r) => (array) $r, $rows), 'document_no'),
            '⛔ ড্যামেজ বিল টাকা আসার রিপোর্টে উঠেছে।');

        $types = LedgerEntry::query()->where('document_no', $note->document_no)->pluck('source_type')->unique()->all();
        $this->assertNotEmpty($types, 'প্রস্তুতিটাই ভুল — নোটের সারি খাতায় নেই।');
        $this->assertSame([], array_values(array_intersect($types, CustomerTargetService::INFLOW)),
            '⛔ ডিলারের লক্ষ্যে ড্যামেজ বিল "আদায়" হিসেবে গোনা হবে।');
        $this->assertSame(0, LedgerEntry::query()->where('document_no', $note->document_no)
            ->whereIn('account_id', \App\Modules\Accounts\Models\Account::query()->whereNotNull('money_kind')->pluck('id'))->count(),
            '⛔ ড্যামেজ নোট নগদ বা ব্যাংকের খাত ছুঁয়েছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function note(string $direction, string $partyType, int $partyId, string $amount): Note
    {
        $service = app(NoteService::class);

        return $service->confirm($service->create([
            'direction' => $direction,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'tax_amount' => '0',
            'reason' => 'damage_claim',
            'narration' => 'কোম্পানির পাস করা ড্যামেজ বিল',
            'other_account_id' => (int) StandardChart::find(StandardChart::DAMAGE_CLAIM)->id,
        ]));
    }

    private function sum(Note $note, string $code, string $side, ?string $partyType = null, ?int $partyId = null): string
    {
        return (string) LedgerEntry::query()
            ->where('source_type', $note->sourceType())->where('source_id', $note->id)
            ->where('account_id', StandardChart::find($code)->id)
            ->when($partyType !== null, fn ($q) => $q->where('party_type', $partyType)->where('party_id', $partyId))
            ->sum($side);
    }
}
