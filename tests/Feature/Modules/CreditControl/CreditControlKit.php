<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * বাকি নিয়ন্ত্রণের পরীক্ষাগুলোর ভাগ করা প্রস্তুতি — ডেমো কোম্পানি, মালিক, গ্রাহক, খাতার বকেয়া, পুরনো বিল, চেক।
 *
 * ⓘ ছাঁচটা [[TheCreditReportsTellWhoOwesWhatTest]]-এর — একই খাতা-পথ (PostingEngine), একই সারি।
 */
trait CreditControlKit
{
    private Company $company;

    private User $owner;

    private function bootCreditCompany(): void
    {
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
    }

    /** @param  list<string>  $keys */
    private function staffWith(array $keys, string $role = 'credit-test-role'): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys, $role): void {
            $r = Role::create(['name' => $role.'-'.Str::random(5), 'guard_name' => 'web']);
            $r->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
            $user->assignRole($r);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function customer(string $code, string $limit, ?int $branchId = null): Customer
    {
        return Customer::query()->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true, 'credit_limit' => $limit,
            'branch_id' => $branchId ?? $this->company->defaultBranch()?->id,
        ]);
    }

    private function owes(Customer $c, string $amount): void
    {
        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ]);
    }

    private function unclearedCheque(Customer $c, string $amount): int
    {
        $id = (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'direction' => 'received', 'cheque_date' => now()->toDateString(), 'received_on' => now()->toDateString(),
            'cheque_no' => 'CC-'.random_int(1000, 9999), 'amount' => $amount, 'party_type' => 'customer', 'party_id' => $c->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => $amount],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
        ], 'cheque', $id);

        return $id;
    }

    /** পাকা বিল, মেয়াদ `$daysPastDue` দিন আগে (ঋণাত্মক = সামনে), খাতায় উঠে */
    private function bill(Customer $c, string $no, string $amount, int $daysPastDue): int
    {
        $id = (int) DB::table('sal_invoices')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => $c->branch_id ?? $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => $c->id, 'trx_date' => now()->subDays(max($daysPastDue, 0) + 10)->toDateString(),
            'due_on' => now()->subDays($daysPastDue)->toDateString(), 'subtotal' => $amount, 'total' => $amount,
            'status' => DocumentStatus::CONFIRMED, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ], 'sales_invoice', $id);

        return $id;
    }

    private function postLines(array $lines, string $source = 'test:credit-control', ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    /** গুদামে ১০০টা মাল, ক্রয় থেকে তাকে — বিল করার জন্য ([[ZeroMeansZeroOnTheDayYouSayTest]]-এর ছাঁচ) */
    private function stockedProduct(string $code = 'CC-P1'): Product
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'unit_id' => Product::query()->value('unit_id'), 'purchase_price' => '100', 'sale_price' => '120', 'is_active' => true,
        ]);

        $receipt = app(PurchaseReceiptService::class)->confirm(app(PurchaseReceiptService::class)->create(
            ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '100', 'rate' => '100']],
        ));

        app(StockService::class)->place(product: $product, warehouse: $warehouse, qty: '100',
            sourceType: PurchaseReceipt::STOCK_SOURCE, sourceId: $receipt->id);

        return $product;
    }

    /** খসড়া বিল, ১২০ টাকা দরে */
    private function draftBill(Customer $c, Product $p, string $qty): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => $c->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => $p->id, 'qty' => $qty, 'rate' => '120']],
        );
    }
}
