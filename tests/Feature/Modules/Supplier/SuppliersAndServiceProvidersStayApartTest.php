<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * সরবরাহকারী আর সেবাদাতা এক নয় — মালিক, ২ অক্টোবর ২০২৬:
 * *"Suppliers tara kebol zader product sales kori, r zader sahazo niye kori tara sarvice provider … duto ki ek?"*
 * আর *"Service Providers & Suppliers sob jaygay alada thakbe"*।
 *
 *   পুঁজির উপর ফেরত   সেবাদাতা (Hira Auto) নেই — তাঁর মজুদ, দাবি বা মার্জিন নেই; সরবরাহকারী আছে
 *   প্রদেয় তালিকা     ধরন বাছা না থাকলে কেবল সরবরাহকারী; সেবাদাতার ধরন বাছলে সেবাদাতা
 *   পক্ষ বাছাই        সেবাদাতার পাশে "(সেবাদাতা)", আর তালিকায় সরবরাহকারীদের পরে
 *   ড্যাশবোর্ড        মোট সংখ্যা কেবল সরবরাহকারীর — লিংক যে তালিকায় যায়, সেই তালিকার মাপে
 */
final class SuppliersAndServiceProvidersStayApartTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $vendor;

    private Supplier $garage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->vendor = app(SupplierService::class)->create(['name_en' => 'ZQ Rice Mill', 'party_type_id' => $this->type('VENDOR')]);
        $this->garage = app(SupplierService::class)->create(['name_en' => 'ZQ Hira Auto', 'party_type_id' => $this->type('SERVICE')]);

        // ⓘ দুজনের নামেই প্রদেয়তে টাকা — দুজনেরই খাতা আছে, যাতে বাদ পড়াটা সত্যিই কিছু বাদ দেয়
        foreach ([$this->vendor, $this->garage] as $party) {
            $this->owe($party, '400');
        }
    }

    public function test_return_on_capital_lists_the_supplier_and_not_the_service_provider(): void
    {
        // ⓘ দুজনকেই অগ্রিম — বাদ না দিলে সেবাদাতার সারি আসত (অগ্রিম > ০)
        $this->advance($this->vendor, '1000');
        $this->advance($this->garage, '1000');

        $ids = collect($this->rows('purchase.return_on_capital'))->pluck('supplier_id')->map(fn ($id) => (int) $id);

        $this->assertContains((int) $this->vendor->id, $ids->all(), 'দৃশ্যটাই বানানো যায়নি — অগ্রিম দেওয়া সরবরাহকারী নেই।');
        $this->assertNotContains((int) $this->garage->id, $ids->all(), '⛔ সেবাদাতা পুঁজির উপর ফেরতে — মালিকের ছবির Hira Auto।');
    }

    public function test_the_payable_list_is_suppliers_unless_a_service_type_is_picked(): void
    {
        $plain = collect($this->rows('supplier.payable_list'))->pluck('party_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $this->vendor->id, $plain, 'দৃশ্যটাই বানানো যায়নি — সরবরাহকারী প্রদেয় তালিকায় নেই।');
        $this->assertNotContains((int) $this->garage->id, $plain, '⛔ ধরন না বাছতেই সেবাদাতা সরবরাহকারীর প্রদেয়তে।');

        $service = collect($this->rows('supplier.payable_list', ['party_type_id' => $this->type('SERVICE')]))
            ->pluck('party_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $this->garage->id, $service, '⛔ সেবাদাতার ধরন বেছেও সেবাদাতা আসেনি — তাঁদের দেনা দেখার পথ বন্ধ।');
    }

    public function test_the_party_picker_marks_service_providers_and_lists_them_after_suppliers(): void
    {
        $group = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', 'supplier');
        $options = collect($group['options']);

        $vendor = $options->firstWhere('id', (int) $this->vendor->id);
        $garage = $options->firstWhere('id', (int) $this->garage->id);

        $this->assertStringNotContainsString((string) __('supplier::menu.service_provider'), $vendor['label']);
        $this->assertStringContainsString('('.__('supplier::menu.service_provider').')', $garage['label'], '⛔ সেবাদাতার পাশে চিহ্ন নেই।');

        $firstService = $options->search(fn ($o) => $o['note'] !== null);
        $lastSupplier = $options->filter(fn ($o) => $o['note'] === null)->keys()->last();
        $this->assertGreaterThan($lastSupplier, $firstService, '⛔ সেবাদাতা সরবরাহকারীদের মাঝে মিশে আছে।');
    }

    public function test_the_dashboard_counts_suppliers_only(): void
    {
        $total = collect(SupplierDashboard::dashboard()->stats)->first()->value;

        $this->assertSame((string) Supplier::query()->inViewedBranch()->onlySuppliers()->count(), $total,
            '⛔ ড্যাশবোর্ডের মোটে সেবাদাতাও — অথচ লিংক যায় সরবরাহকারীর তালিকায়।');
    }

    private function owe(Supplier $party, string $amount): void
    {
        $payable = Account::query()->postable()->where('code', StandardChart::PAYABLE)->firstOrFail();

        DB::table('ledger_entries')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => DB::table('financial_years')->where('company_id', CompanyContext::id())->orderByDesc('id')->value('id'),
            'account_id' => $payable->id, 'party_type' => Supplier::drillSourceType(), 'party_id' => $party->id,
            'trx_date' => now()->toDateString(), 'debit' => '0', 'credit' => $amount,
            'source_type' => 'zq_test', 'source_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

    }

    /** ⓘ অগ্রিম — ডেবিট জের; পুঁজির উপর ফেরত এটাই দেখে */
    private function advance(Supplier $party, string $amount): void
    {
        $payable = Account::query()->postable()->where('code', StandardChart::PAYABLE)->firstOrFail();

        DB::table('ledger_entries')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => DB::table('financial_years')->where('company_id', CompanyContext::id())->orderByDesc('id')->value('id'),
            'account_id' => $payable->id, 'party_type' => Supplier::drillSourceType(), 'party_id' => $party->id,
            'trx_date' => now()->toDateString(), 'debit' => $amount, 'credit' => '0',
            'source_type' => 'zq_test', 'source_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function type(string $code): int
    {
        return (int) PartyType::query()->where('code', $code)->firstOrFail()->id;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key, array $extra = []): array
    {
        return app(ReportEngine::class)->run($key, [
            'from' => now()->subYear()->toDateString(), 'to' => now()->toDateString(), ...$extra,
        ], perPage: 500)->rows;
    }
}
