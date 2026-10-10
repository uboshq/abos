<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরিশোধের সূচি সরবরাহকারীর খাতার সাথে মিলত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⛔ সূচি বিলের বাকি গুনত কেবল পরিশোধের সারি বাদ দিয়ে — পাকা ফেরত (আর বিলের বিপরীতে ভাউচারে দেওয়া টাকা)
 * সূচিতে বাকি থাকত, অথচ খাতায় দেনা কমিয়েছে। ⓘ এখানে ১,০০০-এর বিলে ২০০-র ফেরত: খাতা ৮০০, সূচিও ৮০০।
 */
final class ThePaymentScheduleDisagreedWithTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_posted_return_lowers_the_schedule_as_it_lowers_the_ledger(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();

        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'SCHED-1', 'due_on' => now()->addDays(7)->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '10', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']])['bill'];

        $returns = app(PurchaseReturnService::class);
        $returns->confirm($returns->create([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->fresh('lines')->lines->first()->id]]));

        $payable = Account::query()->where('code', StandardChart::PAYABLE)->value('id');
        $ledger = bcsub(
            (string) LedgerEntry::query()->where('account_id', $payable)->where('party_type', 'supplier')->where('party_id', $supplier->id)->sum('credit'),
            (string) LedgerEntry::query()->where('account_id', $payable)->where('party_type', 'supplier')->where('party_id', $supplier->id)->sum('debit'),
            4,
        );
        $this->assertSame(0, bccomp($ledger, '800', 4), 'দাবির ভিত্তি: খাতায় দেনা ৮০০ নয়।');

        $row = collect(app(ReportEngine::class)->run('supplier.payment_schedule', [
            'from' => now()->subMonth()->toDateString(), 'to' => now()->addMonth()->toDateString(),
        ])->rows)->map(fn ($r) => (array) $r)->firstWhere('document_no', $bill->document_no);

        $this->assertNotNull($row, 'বিলটা সূচিতে নেই — দাবি অন্ধ।');
        $this->assertSame(0, bccomp((string) $row['due_amount'], '800', 4),
            "⛔ সূচিতে বাকি {$row['due_amount']}, খাতায় ৮০০ — ফেরতটা সূচিতে গোনা হয়নি।");
    }
}
