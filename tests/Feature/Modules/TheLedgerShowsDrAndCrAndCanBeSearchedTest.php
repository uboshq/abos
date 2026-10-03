<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * পার্টির খতিয়ান — "(Dr)/(Cr)" জের, খোঁজা আর ছাঁকনি (মালিক, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ জেরে কখনো +/− নয়: "(Dr) 250.79" / "(Cr) 22,958.21"; শূন্যে কেবল 0.00।
 *   গ্রাহক — (Dr) = পাওনা, (Cr) = অগ্রিম; সরবরাহকারী ও সেবাদাতা — (Cr) = দেনা, (Dr) = অগ্রিম দেওয়া।
 * ⭐ খোঁজা (নম্বর, বিবরণ, অঙ্ক) আর ছাঁকনি (তারিখ, ধরন, কেবল ডেবিট/ক্রেডিট) — আর ছাঁকনিতেও প্রতিটা সারির জের খাতার
 *   সব সারি থেকে, শূন্য থেকে নয়।
 * ⭐ পর্দা, রপ্তানি আর গ্রাহকের পোর্টাল — তিন জায়গায় একই লেখা।
 */
final class TheLedgerShowsDrAndCrAndCanBeSearchedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_a_customer_owes_as_dr_holds_an_advance_as_cr_and_settles_to_a_bare_zero(): void
    {
        $customer = $this->customer('DRCR-C');
        $page = fn () => (string) $this->actingAs($this->owner)->get(route('customer.show', $customer))->assertOk()->getContent();

        $this->entry('customer', $customer->id, '2026-08-01', '250.79', '0', 'sales_invoice', 'INV-DRCR-1');
        $this->assertStringContainsString('(Dr) '.Money::format('250.79'), $page(), 'গ্রাহকের পাওনা "(Dr)" নয়।');

        $this->entry('customer', $customer->id, '2026-08-02', '0', '250.79', 'collection', 'COL-DRCR-1');
        $this->assertBareZero($page(), 'গ্রাহক');

        $this->entry('customer', $customer->id, '2026-08-03', '0', '22958.21', 'collection', 'COL-DRCR-2');
        $html = $page();
        $this->assertStringContainsString('(Cr) '.Money::format('22958.21'), $html, 'গ্রাহকের অগ্রিম "(Cr)" নয়।');
        // ⓘ লেনদেনের টেবিলটুকু — মাথার বকেয়ার কার্ড 👁 পপআপের ধাপে আলাদা করে দেখা হবে
        $table = substr($html, (int) strpos($html, 'id="transactions"'));
        $this->assertStringNotContainsString('-'.Money::format('22958.21'), $table, '⛔ লেনদেনের জেরে বিয়োগ চিহ্ন রয়ে গেছে।');
    }

    public function test_a_supplier_and_a_service_provider_are_owed_as_cr_and_paid_ahead_as_dr(): void
    {
        $vendor = Supplier::query()->onlySuppliers()->firstOrFail();
        $provider = Supplier::query()->onlyServiceProviders()->first() ?? $this->serviceProvider();

        foreach (['সরবরাহকারী' => $vendor, 'সেবাদাতা' => $provider] as $who => $party) {
            $page = fn () => (string) $this->actingAs($this->owner)->get(route('supplier.show', $party))->assertOk()->getContent();
            $start = $this->netOf('supplier', $party->id);
            $this->entry('supplier', $party->id, '2026-08-01', '0', bcadd('500', bcmul($start, '1', 4), 4), 'purchase_bill', 'BILL-'.$party->id);
            $this->assertStringContainsString('(Cr) '.Money::format('500'), $page(), "{$who}: দেনা \"(Cr)\" নয়।");

            $this->entry('supplier', $party->id, '2026-08-02', '500', '0', 'purchase_payment', 'PAY-'.$party->id);
            $this->assertBareZero($page(), $who);

            $this->entry('supplier', $party->id, '2026-08-03', '120', '0', 'purchase_payment', 'ADV-'.$party->id);
            $this->assertStringContainsString('(Dr) '.Money::format('120'), $page(), "{$who}: অগ্রিম দেওয়া \"(Dr)\" নয়।");
        }
    }

    public function test_search_and_filters_narrow_the_rows_and_the_balance_still_counts_every_entry(): void
    {
        $customer = $this->customer('DRCR-F');
        $this->entry('customer', $customer->id, '2026-07-01', '1000', '0', 'sales_invoice', 'INV-F-1', 'July bill');
        $this->entry('customer', $customer->id, '2026-07-10', '0', '300', 'collection', 'COL-F-1', 'cash in');
        $this->entry('customer', $customer->id, '2026-08-01', '500', '0', 'sales_invoice', 'INV-F-2', 'August bill');
        $this->entry('customer', $customer->id, '2026-08-05', '0', '200', 'sales_return', 'RET-F-1', 'came back');

        $rows = function (array $filter) use ($customer): array {
            $entries = $this->actingAs($this->owner)->get(route('customer.show', [$customer] + $filter))->assertOk()->viewData('entries');

            return collect($entries->items())->mapWithKeys(fn ($e) => [$e->document_no => Money::drCr($e->net_balance)])->all();
        };

        // ⭐ কেবল ক্রেডিট — আদায়ের পাশে জের ৭০০ (১,০০০ − ৩০০), ফেরতের পাশে ১,০০০ (৭০০ + ৫০০ − ২০০); শূন্য থেকে নয়
        $this->assertSame(['RET-F-1' => '(Dr) '.Money::format('1000'), 'COL-F-1' => '(Dr) '.Money::format('700')], $rows(['side' => 'credit']),
            '⛔ ছাঁকনিতে জের ভুল — ছাঁকা সারিগুলোই যোগ হয়েছে, খাতার সব সারি নয়।');

        // ⭐ তারিখ — আগস্টের প্রথম সারির জের জুলাইয়ের জের (৭০০) থেকে শুরু
        $this->assertSame(['RET-F-1' => '(Dr) '.Money::format('1000'), 'INV-F-2' => '(Dr) '.Money::format('1200')], $rows(['from' => '2026-08-01']),
            '⛔ তারিখের ছাঁকনিতে জের শূন্য থেকে শুরু।');

        $this->assertSame(['COL-F-1'], array_keys($rows(['kind' => 'money'])), 'ধরনের ছাঁকনি (আদায়) ভুল সারি দিল।');
        $this->assertSame(['RET-F-1'], array_keys($rows(['kind' => 'return'])), 'ধরনের ছাঁকনি (ফেরত) ভুল সারি দিল।');
        $this->assertSame(['INV-F-2', 'INV-F-1'], array_keys($rows(['side' => 'debit'])), '"কেবল ডেবিট" ভুল সারি দিল।');
        $this->assertSame(['INV-F-2'], array_keys($rows(['q' => 'August'])), 'বিবরণে খোঁজা কাজ করেনি।');
        $this->assertSame(['COL-F-1'], array_keys($rows(['q' => 'COL-F'])), 'নম্বরে খোঁজা কাজ করেনি।');
        $this->assertSame(['RET-F-1'], array_keys($rows(['q' => '200'])), 'অঙ্কে খোঁজা কাজ করেনি।');
    }

    public function test_the_export_and_the_dealer_portal_print_the_same_dr_and_cr(): void
    {
        $customer = $this->customer('DRCR-P');
        $customer->forceFill(['portal_password' => Hash::make('customer-pass'), 'portal_enabled' => true])->save();
        $this->entry('customer', $customer->id, '2026-08-01', '250.79', '0', 'sales_invoice', 'INV-P-1');

        $csv = (string) $this->actingAs($this->owner)->get(route('customer.show', [$customer, 'export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('(Dr) '.Money::format('250.79'), $csv, 'রপ্তানিতে "(Dr)" নেই।');

        $portal = (string) $this->actingAs($customer, 'portal')->get(route('sales.portal.ledger', ['from' => '2026-07-01', 'to' => '2026-08-31']))->assertOk()->getContent();
        $this->assertStringContainsString('(Dr) '.Money::format('250.79'), $portal, 'ডিলারের পোর্টালে "(Dr)" নেই।');
    }

    private function assertBareZero(string $html, string $who): void
    {
        $this->assertMatchesRegularExpression('/>\s*'.preg_quote(Money::format('0'), '/').'\s*</', $html, "{$who}: শোধের পরে জের 0.00 নয়।");
        $this->assertStringNotContainsString('(Dr) '.Money::format('0'), $html, "⛔ {$who}: শূন্যের পাশে \"(Dr)\" লেবেল।");
        $this->assertStringNotContainsString('(Cr) '.Money::format('0'), $html, "⛔ {$who}: শূন্যের পাশে \"(Cr)\" লেবেল।");
    }

    private function customer(string $code): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => $code, 'name_en' => 'Customer '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
    }

    private function serviceProvider(): Supplier
    {
        $type = \App\Modules\MasterData\Models\PartyType::query()->where('code', '<>', Supplier::VENDOR_CODE)->firstOrFail();

        return Supplier::query()->create([
            'company_id' => $this->company->id, 'code' => 'SP-DRCR', 'name_en' => 'Transport Provider',
            'party_type_id' => $type->id, 'is_active' => true,
        ]);
    }

    /** সরবরাহকারীর আগের জের (ক্রেডিট − ডেবিট) — ডেমোর নিজের দেনা থাকলে দাবিটা তার উপরে গোনে */
    private function netOf(string $type, int $id): string
    {
        $net = LedgerEntry::query()->where('party_type', $type)->where('party_id', $id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net') ?? '0';

        return bcmul((string) $net, '1', 4);
    }

    private function entry(string $type, int $id, string $on, string $debit, string $credit, string $source, string $no, ?string $narration = null): void
    {
        LedgerEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'account_id' => StandardChart::find($type === 'customer' ? StandardChart::RECEIVABLE : StandardChart::PAYABLE)->id,
            'party_type' => $type, 'party_id' => $id, 'trx_date' => $on,
            'debit' => $debit, 'credit' => $credit, 'source_type' => $source, 'source_id' => 1,
            'document_no' => $no, 'narration' => $narration,
        ]);
    }
}
