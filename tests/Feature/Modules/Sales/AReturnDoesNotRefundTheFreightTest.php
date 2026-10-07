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
 * ⛔ মাল ফেরতে বিলের ভাড়ার ভাগও ফেরত যেত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⚠️৯)।
 *
 * ⓘ [[SalesReturnService::shareOfTheBill()]] ফেরতের অঙ্ক গুনত সারির অঙ্ক × বিলের মোট ÷ সারিগুলোর যোগ; বিলের মোটে ভাড়াও আছে, যা খাতায়
 * ভাড়ার আয়ে বসেছিল — ফেরতে সেই ভাগ বিক্রয়-ফেরতে ডেবিট হত আর গ্রাহক ফেরত পেতেন। বিলের ছাড় বিক্রির ভেতরে, তাই সেটা আগের মতো ভাগে।
 */
final class AReturnDoesNotRefundTheFreightTest extends TestCase
{
    use RefreshDatabase;

    public function test_half_the_goods_back_is_half_the_goods_not_half_the_freight(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');

        // ⓘ মাল ১,০০০ (১০টা), বিলের ছাড় ১০০, ভাড়া ২০০ → মোট ১,১০০
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'FRT-1', 'customer_id' => $customer,
            'trx_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 1000, 'bill_discount' => 100, 'tax' => 0,
            'freight_charge' => 200, 'total' => 1100,
        ]);
        $lineId = DB::table('sal_invoice_lines')->insertGetId([
            'sales_invoice_id' => $invoice, 'product_id' => $product, 'qty' => 10, 'rate' => 100, 'tax' => 0, 'amount' => 1000, 'line_no' => 1,
        ]);

        $share = new \ReflectionMethod(SalesReturnService::class, 'shareOfTheBill');
        [$amount, $tax] = $share->invoke(app(SalesReturnService::class), SalesInvoiceLine::query()->findOrFail($lineId), '5');

        // ⓘ অর্ধেক মাল = মালের দামের অর্ধেক, ছাড়ের ভাগসহ: (১,১০০ − ভাড়া ২০০) × ৫০০ ÷ ১,০০০ = ৪৫০
        $this->assertSame(0, bccomp($amount, '450', 2), '⛔ ফেরতে ভাড়ার ভাগও ফেরত গেল — '.$amount);
        $this->assertSame(0, bccomp($tax, '0', 2));
    }
}
