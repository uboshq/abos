<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * গ্রাহক আর সরবরাহকারীর তালিকার নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   গ্রাহক         সারি · বকেয়া (খাতায় ডেবিট − ক্রেডিট, পক্ষ ধরে)
 *   সরবরাহকারী     সারি · দেনা (ক্রেডিট − ডেবিট)
 *
 * ⓘ ৫১ জন (দুই পাতা) আর ৩ জন — দুই খোঁজাতেই পট্টি ডাটাবেজের সরাসরি গোনা ও খাতার সরাসরি যোগ বলে।
 * ⓘ খাতার প্রতিটা পক্ষে দুইটা সারি (১০০ আর −৩০), যাতে যোগটা কেবল প্রথম সারির হলে ধরা পড়ে।
 */
final class PartyListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_customer_list_sums_the_outstanding_of_every_page(): void
    {
        foreach ([['ZQG', 51], ['ZQH', 3]] as [$prefix, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $customer = Customer::query()->create([
                    'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'code' => sprintf('%s-%03d', $prefix, $i), 'name_en' => "{$prefix} Party {$i}",
                    'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
                ]);
                $this->ledger('customer', (int) $customer->id, '100', '0');
                $this->ledger('customer', (int) $customer->id, '0', '30');
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $ids = DB::table('customers')->where('company_id', CompanyContext::id())->where('code', 'like', $prefix.'-%')->pluck('id');

            $this->assertBar('customer.index', ['q' => $prefix], $ids->count(), [
                __('customer::field.outstanding') => $this->net('customer', $ids->all(), 'debit', 'credit'),
            ]);
        }
    }

    public function test_the_supplier_list_sums_the_payable_of_every_page(): void
    {
        foreach ([['ZQG', 51], ['ZQH', 3]] as [$prefix, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $supplier = Supplier::query()->create([
                    'code' => sprintf('%s-%03d', $prefix, $i), 'name_en' => "{$prefix} Maker {$i}",
                    'name_bn' => "{$prefix} Maker {$i}", 'is_active' => true,
                ]);
                $this->ledger('supplier', (int) $supplier->id, '0', '100');
                $this->ledger('supplier', (int) $supplier->id, '30', '0');
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $ids = DB::table('suppliers')->where('company_id', CompanyContext::id())->where('code', 'like', $prefix.'-%')->pluck('id');

            $this->assertBar('supplier.index', ['q' => $prefix], $ids->count(), [
                __('supplier::field.payable') => $this->net('supplier', $ids->all(), 'credit', 'debit'),
            ]);
        }
    }

    public function test_the_party_ledger_says_how_many_entries(): void
    {
        $customer = Customer::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
            'code' => 'ZQL-001', 'name_en' => 'ZQL Ledger', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $this->ledger('customer', (int) $customer->id, '100', '0');
        $this->ledger('customer', (int) $customer->id, '0', '30');

        $html = (string) $this->get(route('customer.show', $customer))->assertOk()->getContent();
        $this->assertStringContainsString($this->rowsText(DB::table('ledger_entries')->where('party_type', 'customer')
            ->where('party_id', $customer->id)->count()), $this->bar($html, 'customer.show'));
    }

    private function ledger(string $party, int $id, string $debit, string $credit): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => DB::table('financial_years')->where('company_id', CompanyContext::id())->orderByDesc('id')->value('id'),
            'account_id' => (int) DB::table('accounts')->where('company_id', CompanyContext::id())->where('is_group', false)->value('id'),
            'trx_date' => now()->toDateString(), 'debit' => $debit, 'credit' => $credit,
            'party_type' => $party, 'party_id' => $id,
            'source_type' => 'zq_test', 'source_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<int>  $ids */
    private function net(string $party, array $ids, string $plus, string $minus): string
    {
        return (string) DB::table('ledger_entries')->where('company_id', CompanyContext::id())
            ->where('party_type', $party)->whereIn('party_id', $ids)
            ->selectRaw("COALESCE(SUM({$plus}) - SUM({$minus}), 0) AS n")->value('n');
    }
}
