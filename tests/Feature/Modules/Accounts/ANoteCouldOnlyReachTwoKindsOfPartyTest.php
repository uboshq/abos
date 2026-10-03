<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\Person;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নোট কেবল দুই রকম পক্ষে যেত — মালিক, ৩ অক্টোবর ২০২৬: *"সব পক্ষেই ডেবিট ক্রেডিট হয়, দুই পক্ষেরই লাগে"*।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * ক্রেডিট নোট কেবল গ্রাহকের, ডেবিট নোট কেবল সরবরাহকারীর; সেবাদাতা সরবরাহকারীর খাতে, ব্যক্তি কোথাও না।
 *
 * ── ⭐ এখন ([[NoteAccounts]], সমন্বয়কের ঠিক করা হিসাব) ────────────────
 * চার ধরন, দুই দিক। ক্রেডিট নোটে পক্ষের খাত Cr, ডেবিটে Dr; অন্য পাশ ধরন অনুযায়ী। সেবাদাতার খাত যেখানে তাঁর বকেয়া,
 * ব্যক্তির খাত যেখানে তাঁর খাতা; অন্য পাশ ব্যবহারকারীর বাছা। ⛔ তালিকার বাইরের খাত সার্ভারেও ফেরে।
 */
final class ANoteCouldOnlyReachTwoKindsOfPartyTest extends TestCase
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

    public function test_a_debit_note_reaches_a_customer(): void
    {
        $customer = (int) Customer::query()->value('id');

        $lines = $this->confirm(['direction' => Note::DEBIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => $customer], tax: '15');

        $this->assertLine($lines, StandardChart::RECEIVABLE, debit: '115', party: ['customer', $customer]);
        $this->assertLine($lines, StandardChart::SALES, credit: '100');
        $this->assertLine($lines, StandardChart::VAT_PAYABLE, credit: '15');
    }

    public function test_a_credit_note_reaches_a_supplier(): void
    {
        $supplier = $this->vendor();

        $lines = $this->confirm(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SUPPLIER, 'party_id' => $supplier->id]);

        $this->assertLine($lines, StandardChart::PURCHASE_PRICE_VARIANCE, debit: '100');
        $this->assertLine($lines, StandardChart::PAYABLE, credit: '100', party: ['supplier', (int) $supplier->id]);
    }

    /** ⭐ সেবাদাতা — বকেয়া যে খাতে (২১১৬) সেখানেই, অন্য পাশ তাঁর শেষ বিলের খরচের খাত আগে থেকে বাছা */
    public function test_a_service_provider_note_adjusts_the_payable_where_the_balance_sits(): void
    {
        $provider = $this->provider();
        $this->owe($provider, StandardChart::TRANSPORT_PAYABLE, '900');

        $page = $this->get(route('accounts.note.create', ['direction' => Note::CREDIT, 'kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id]))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="'.$this->id(StandardChart::TRANSPORT_PAYABLE).'"\s+selected/', $page, '⛔ বকেয়ার খাত আগে থেকে বাছা নেই।');
        $this->assertMatchesRegularExpression('/value="'.$this->id(StandardChart::RENT).'"\s+selected/', $page, '⛔ শেষ বিলের খরচের খাত আগে থেকে বাছা নেই।');

        $lines = $this->confirm(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id,
            'other_account_id' => $this->id(StandardChart::RENT)]);

        $this->assertLine($lines, StandardChart::RENT, debit: '100');
        $this->assertLine($lines, StandardChart::TRANSPORT_PAYABLE, credit: '100', party: ['supplier', (int) $provider->id]);
    }

    /** ⓘ বকেয়া দুই খাতে হলে ফর্ম বাছতে বলে; কোথাও না থাকলে ২১১৮ */
    public function test_a_service_provider_with_two_payables_must_choose_and_one_with_none_uses_2118(): void
    {
        $provider = $this->provider();
        $this->owe($provider, StandardChart::TRANSPORT_PAYABLE, '500');
        $this->owe($provider, StandardChart::VENDOR_PAYABLE, '300');

        $this->assertRefused(['direction' => Note::DEBIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id,
            'other_account_id' => $this->id(StandardChart::RENT)], 'control_account_id');

        $lines = $this->confirm(['direction' => Note::DEBIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id,
            'control_account_id' => $this->id(StandardChart::VENDOR_PAYABLE), 'other_account_id' => $this->id(StandardChart::RENT)]);
        $this->assertLine($lines, StandardChart::VENDOR_PAYABLE, debit: '100', party: ['supplier', (int) $provider->id]);

        $fresh = $this->provider('TRANS2');
        $lines = $this->confirm(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $fresh->id,
            'other_account_id' => $this->id(StandardChart::RENT)]);
        $this->assertLine($lines, StandardChart::VENDOR_PAYABLE, credit: '100', party: ['supplier', (int) $fresh->id]);
    }

    /** ⭐ ব্যক্তি — তাঁর খাতা যে খাতে (হাওলাত ১১৭০), অন্য পাশ আয় বা খরচ; খাতা না থাকলে নোট নয় */
    public function test_a_person_note_uses_the_account_the_person_already_has(): void
    {
        $person = $this->person('P-1');
        $this->post_(StandardChart::HAND_LOAN, '0', '1000', ['person', (int) $person->id], StandardChart::INTEREST_INCOME, '1000', '0');

        $lines = $this->confirm(['direction' => Note::DEBIT, 'party_kind' => Note::KIND_PERSON, 'party_id' => $person->id,
            'other_account_id' => $this->id(StandardChart::INTEREST_INCOME)]);

        $this->assertLine($lines, StandardChart::HAND_LOAN, debit: '100', party: ['person', (int) $person->id]);
        $this->assertLine($lines, StandardChart::INTEREST_INCOME, credit: '100');

        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_PERSON, 'party_id' => $this->person('P-2')->id,
            'other_account_id' => $this->id(StandardChart::INTEREST_INCOME)], 'control_account_id');
    }

    /** ⛔ দেয়াল — ভুল ধরন, তালিকার বাইরের খাত, সেবাদাতায় ভ্যাট; ফর্ম এড়িয়ে পাঠালেও */
    public function test_the_wall_refuses_what_the_lists_do_not_offer(): void
    {
        $customer = (int) Customer::query()->value('id');
        $vendor = $this->vendor();
        $provider = $this->provider();

        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SUPPLIER, 'party_id' => $provider->id], 'party_id');
        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $vendor->id,
            'other_account_id' => $this->id(StandardChart::RENT)], 'party_id');
        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => $customer,
            'other_account_id' => $this->id(StandardChart::PURCHASE_PRICE_VARIANCE)], 'other_account_id');
        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id,
            'control_account_id' => $this->id(StandardChart::RECEIVABLE), 'other_account_id' => $this->id(StandardChart::RENT)], 'control_account_id');
        $this->assertRefused(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_SERVICE_PROVIDER, 'party_id' => $provider->id,
            'other_account_id' => $this->id(StandardChart::RENT), 'tax_amount' => '10'], 'tax_amount');

        $this->assertSame(0, Note::query()->count(), '⛔ ফেরানো নোট বসে গেছে।');
    }

    /** ⓘ ধরন বাছলে তালিকায় কেবল ঐ ধরনের পক্ষ */
    public function test_the_party_list_follows_the_chosen_kind(): void
    {
        $vendor = $this->vendor();
        $provider = $this->provider();

        $page = fn (string $kind) => $this->get(route('accounts.note.create', ['direction' => Note::CREDIT, 'kind' => $kind]))->assertOk()->viewData('parties');
        $ids = fn (array $options) => array_map(fn ($o) => (int) $o['id'], $options);

        $this->assertContains((int) $provider->id, $ids($page(Note::KIND_SERVICE_PROVIDER)));
        $this->assertNotContains((int) $vendor->id, $ids($page(Note::KIND_SERVICE_PROVIDER)), '⛔ সেবাদাতার তালিকায় পণ্যের সরবরাহকারী।');
        $this->assertContains((int) $vendor->id, $ids($page(Note::KIND_SUPPLIER)));
        $this->assertNotContains((int) $provider->id, $ids($page(Note::KIND_SUPPLIER)), '⛔ সরবরাহকারীর তালিকায় সেবাদাতা।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int, LedgerEntry> */
    private function confirm(array $data, string $tax = '0')
    {
        $service = app(NoteService::class);
        $note = $service->confirm($service->create($data + [
            'trx_date' => now()->toDateString(), 'amount' => '100', 'tax_amount' => $tax, 'reason' => 'price_correction',
        ]));

        return LedgerEntry::query()->where('source_type', 'note')->where('source_id', $note->id)->get();
    }

    private function assertRefused(array $data, string $key): void
    {
        try {
            app(NoteService::class)->create($data + ['trx_date' => now()->toDateString(), 'amount' => '100', 'reason' => 'price_correction']);
            $this->fail("⛔ নোট বসে গেল, অথচ {$key}-এ ফেরার কথা।");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    private function assertLine($lines, string $code, ?string $debit = null, ?string $credit = null, ?array $party = null): void
    {
        $line = $lines->firstWhere('account_id', $this->id($code));

        $this->assertNotNull($line, "⛔ {$code} খাতে কোনো সারি নেই।");

        if ($debit !== null) {
            $this->assertSame(0, bccomp($debit, (string) $line->debit, 4), "⛔ {$code}-এ ডেবিট {$line->debit}, হওয়ার কথা {$debit}।");
        }

        if ($credit !== null) {
            $this->assertSame(0, bccomp($credit, (string) $line->credit, 4), "⛔ {$code}-এ ক্রেডিট {$line->credit}, হওয়ার কথা {$credit}।");
        }

        if ($party !== null) {
            $this->assertSame($party, [(string) $line->party_type, (int) $line->party_id], "⛔ {$code}-এর সারি পক্ষের নামে নয়।");
        }
    }

    private function id(string $code): int
    {
        return (int) Account::query()->postable()->where('code', $code)->value('id');
    }

    private function vendor(): Supplier
    {
        $vendor = Supplier::query()->orderBy('id')->firstOrFail();
        $vendor->forceFill(['party_type_id' => null])->save();

        return $vendor;
    }

    private function provider(string $code = 'TRANS'): Supplier
    {
        $type = PartyType::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => $code], [
            'name_en' => $code, 'name_bn' => $code, 'applies_to' => PartyType::SUPPLIER, 'is_active' => true,
        ]);

        $provider = Supplier::query()->orderByDesc('id')->where('id', '!=', Supplier::query()->orderBy('id')->value('id'))
            ->whereNull('party_type_id')->firstOrFail();
        $provider->forceFill(['party_type_id' => $type->id])->save();

        return $provider;
    }

    private function person(string $code): Person
    {
        return Person::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => 'Person '.$code, 'name_bn' => 'ব্যক্তি '.$code, 'is_active' => true,
        ]);
    }

    /** সেবাদাতার বকেয়া — খরচ (ভাড়া) Dr / প্রদেয় খাত Cr, পক্ষের নামে */
    private function owe(Supplier $provider, string $payable, string $amount): void
    {
        $this->post_($payable, '0', $amount, ['supplier', (int) $provider->id], StandardChart::RENT, $amount, '0');
    }

    private function post_(string $partyCode, string $partyDebit, string $partyCredit, array $party, string $otherCode, string $otherDebit, string $otherCredit): void
    {
        static $n = 0;

        app(PostingEngine::class)->post(
            sourceType: 'test.note_party',
            sourceId: ++$n,
            trxDate: now()->toDateString(),
            lines: [
                ['account_id' => $this->id($partyCode), 'debit' => $partyDebit, 'credit' => $partyCredit, 'party_type' => $party[0], 'party_id' => $party[1]],
                ['account_id' => $this->id($otherCode), 'debit' => $otherDebit, 'credit' => $otherCredit],
            ],
            documentNo: 'TEST-NP-'.$n,
        );
    }
}
