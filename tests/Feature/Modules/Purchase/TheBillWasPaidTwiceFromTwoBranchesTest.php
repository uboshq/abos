<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলটা দুই শাখা থেকে দুবার পরিশোধ হতে পারত — পুরো ERP অডিট, ক্রয় ⚠️৯ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ পরিশোধ বসে লেখকের শাখায়, বিল গুদামের শাখায়; বিলের "দেওয়া হয়েছে" যোগ শাখার দেয়াল মানত। শাখা B থেকে দেওয়া টাকা A-তে
 * সীমিত মানুষের কাছে অদৃশ্য — বিল পুরো বাকি দেখাত, আবার দেওয়া যেত। ⭐ এখন পরিশোধ, পরিশোধ-ভাউচার আর ফেরত — তিনটাই
 * গোটা কোম্পানি থেকে গোনা ([[PurchaseBill::paidAmount()]], [[PurchaseBill::scopeWithPaid()]])।
 */
final class TheBillWasPaidTwiceFromTwoBranchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_paid_in_another_branch_comes_off_the_bill_for_someone_who_cannot_see_that_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TH-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->value('id'),
            'qty' => '10', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']])['bill']->fresh();

        $total = (string) $bill->total;
        $this->assertSame(0, bccomp($bill->dueAmount(), $total, 4), 'দৃশ্যটাই বানানো যায়নি — বিলটা পুরো বাকি থাকার কথা।');

        // ── শাখা B-তে: পরিশোধ ১০০, পরিশোধ-ভাউচার ২০০, ফেরত ৩০০ ──
        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $money = Account::query()->postable()->where('money_kind', Account::CASH)->orderBy('id')->firstOrFail();

        $payment = Payment::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $b->id, 'financial_year_id' => $year->id, 'document_no' => 'SP-B-1',
            'supplier_id' => $bill->supplier_id, 'account_id' => $money->id, 'trx_date' => now()->toDateString(), 'amount' => '100',
            'status' => DocumentStatus::CONFIRMED, 'created_by' => $owner->id,
        ]);
        PaymentLine::query()->create(['company_id' => $company->id, 'payment_id' => $payment->id, 'purchase_bill_id' => $bill->id, 'amount' => '100', 'line_no' => 1]);

        Voucher::query()->withoutGlobalScopes()->forceCreate([
            'company_id' => $company->id, 'branch_id' => $b->id, 'financial_year_id' => $year->id, 'type' => Voucher::PAYMENT,
            'document_no' => 'PV-B-1', 'trx_date' => now()->toDateString(), 'amount' => '200', 'status' => DocumentStatus::CONFIRMED,
            'against_type' => PurchaseBill::drillSourceType(), 'against_id' => $bill->id, 'created_by' => $owner->id,
        ]);

        PurchaseReturn::query()->withoutGlobalScopes()->forceCreate([
            'company_id' => $company->id, 'branch_id' => $b->id, 'financial_year_id' => $year->id, 'document_no' => 'PR-B-1',
            'purchase_bill_id' => $bill->id, 'supplier_id' => $bill->supplier_id, 'warehouse_id' => $bill->warehouse_id,
            'trx_date' => now()->toDateString(), 'total' => '300', 'status' => DocumentStatus::CONFIRMED, 'created_by' => $owner->id,
        ]);

        // ── A-তে সীমিত হিসাবরক্ষক ──
        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $a->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        $this->actingAs($clerk);
        CompanyContext::set($company->id, $a->id);

        // ⓘ দাবিটা সত্যিই দেয়ালের ওপারে তাকায় — B-র কাগজ তাঁর চোখে নেই
        $this->assertFalse(Payment::query()->whereKey($payment->id)->exists(), 'দৃশ্যটাই বানানো যায়নি — হিসাবরক্ষক B-র পরিশোধ দেখছেন।');

        $expected = bcsub($total, '600', 4);
        $seen = PurchaseBill::query()->findOrFail($bill->id);
        $this->assertSame(0, bccomp($seen->paidAmount(), '300', 4), '⛔ অন্য শাখার পরিশোধ বা ভাউচার বিলের "দেওয়া"-তে নেই।');
        $this->assertSame(0, bccomp($seen->returnedAmount(), '300', 4), '⛔ অন্য শাখার ফেরত বিলের বাকি থেকে কাটেনি।');
        $this->assertSame(0, bccomp($seen->dueAmount(), $expected, 4), '⛔ বিলটা অন্য শাখার টাকা না দেখে বেশি বাকি দেখায় — আবার দেওয়া যেত।');

        // ⓘ তালিকার পথও ([[PurchaseBill::scopeWithPaid()]]) — একই অঙ্ক
        $listed = PurchaseBill::query()->withPaid()->findOrFail($bill->id);
        $this->assertSame(0, bccomp($listed->dueAmount(), $expected, 4), '⛔ তালিকার বাকি অন্য শাখার টাকা বাদ দেয় না।');
    }
}
