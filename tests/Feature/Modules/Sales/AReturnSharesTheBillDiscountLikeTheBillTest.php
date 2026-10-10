<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ ফেরতে বিলের ছাড় সারিগুলোর মধ্যে ভাগ হয় বিল যেভাবে ভাগ করে — ভ্যাটের আগের দামে (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️৭)।
 *
 * ⓘ মালিকের ১০ অক্টোবরের নিয়ম: ছাড়ের পরের দামে ভ্যাট। বিল ([[SalesInvoiceService::vatAfterBillDiscount()]]) ছাড়টা ভ্যাটের আগের
 * দামে ভাগ করে আর সারির ভ্যাট কমায়; ফেরত ([[SalesReturnService::shareOfTheBill()]]) ভাগ করত সারির ভ্যাটসহ অঙ্কে। দুই সারির বিলে
 * আংশিক ফেরতের জমা কয়েক টাকা ভুল — পুরো বিল ফেরতে যোগফল মিলত, তাই কেউ টের পেত না।
 */
final class AReturnSharesTheBillDiscountLikeTheBillTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_line_takes_its_share_by_the_price_before_vat(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');

        /*
         * ⓘ বিলটা নতুন নিয়মে যেমন বসে: A ১০ × ১০০ + ১৫% ভ্যাট, B ১০ × ১০০ ভ্যাট ছাড়া, বিলের ছাড় ২০০ (ভাগ ১০০ + ১০০)।
         * A-র ভ্যাট ১৫০ − ১৫ = ১৩৫ → অঙ্ক ১১৩৫; মোট ১১৩৫ + ১০০০ − ২০০ = ১৯৩৫।
         */
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'VSH-1', 'customer_id' => $customer,
            'trx_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 2000, 'bill_discount' => 200, 'tax' => 135, 'total' => 1935,
        ]);
        $a = DB::table('sal_invoice_lines')->insertGetId([
            'sales_invoice_id' => $invoice, 'product_id' => $product, 'qty' => 10, 'rate' => 100, 'tax' => 135, 'amount' => 1135, 'line_no' => 1,
        ]);
        $b = DB::table('sal_invoice_lines')->insertGetId([
            'sales_invoice_id' => $invoice, 'product_id' => $product, 'qty' => 10, 'rate' => 100, 'tax' => 0, 'amount' => 1000, 'line_no' => 2,
        ]);

        $share = new \ReflectionMethod(SalesReturnService::class, 'shareOfTheBill');
        [$aAmount, $aTax] = $share->invoke(app(SalesReturnService::class), SalesInvoiceLine::query()->findOrFail($a), '10');
        [$bAmount, $bTax] = $share->invoke(app(SalesReturnService::class), SalesInvoiceLine::query()->findOrFail($b), '10');

        $this->assertSame(0, bccomp(bcadd($aAmount, $aTax, 4), '1035', 2), '⛔ A ফেরতের জমা বিলের ভাগের সাথে মেলে না — '.bcadd($aAmount, $aTax, 4));
        $this->assertSame(0, bccomp($aTax, '135', 2), 'A-র ভ্যাট বিলে যা বসেছিল তাই ফেরে');
        $this->assertSame(0, bccomp(bcadd($bAmount, $bTax, 4), '900', 2), '⛔ B ফেরতের জমা বিলের ভাগের সাথে মেলে না — '.bcadd($bAmount, $bTax, 4));

        // ⓘ আর পুরো বিল ফেরতে যোগফল হুবহু মোট
        $this->assertSame(0, bccomp(bcadd(bcadd($aAmount, $aTax, 4), bcadd($bAmount, $bTax, 4), 4), '1935', 2));

        // ⓘ অর্ধেক A = অর্ধেক জমা
        [$halfAmount, $halfTax] = $share->invoke(app(SalesReturnService::class), SalesInvoiceLine::query()->findOrFail($a), '5');
        $this->assertSame(0, bccomp(bcadd($halfAmount, $halfTax, 4), '517.5', 2));
    }
}
