<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\CreditExposure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ আংশিক বিল হওয়া চালান-সারির বাকিটা বাকির সীমায় গোনা হত না — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⚠️৮)।
 *
 * ⓘ [[CreditExposure]] একটা বিল-সারি পেলেই পুরো চালান-সারি বাদ দিত: ১০-এর ৬ বিল হলে বাকি ৪-এর দাম শূন্য — মাল গ্রাহকের হাতে,
 * সীমায় নেই। মালিকের নিয়ম: সীমা পরম। এখন অঙ্ক × (পাঠানো − বিল হওয়া) ÷ পাঠানো, দুই পথেই (এক গ্রাহক আর অনেক গ্রাহক একসাথে)।
 */
final class APartlyBilledChallanStillCountsTowardsTheLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_unbilled_part_of_a_line_still_holds_the_limit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->create(['code' => 'PART-1', 'name_en' => 'Part Traders', 'name_bn' => 'Part Traders', 'is_active' => true]);
        $product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');

        // ⓘ পাকা চালান: সারি ক — ১০টা, ১,০০০ (৬টা বিল হয়েছে); সারি খ — ৫টা, ৫০০ (কিছুই বিল হয়নি)
        $challan = DB::table('sal_challans')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'PART-DC', 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString(), 'status' => DocumentStatus::CONFIRMED,
        ]);
        $line = fn (int $no, int $qty, int $amount) => DB::table('sal_challan_lines')->insertGetId([
            'delivery_challan_id' => $challan, 'product_id' => $product, 'delivered_qty' => $qty, 'rate' => 100, 'amount' => $amount, 'line_no' => $no,
        ]);
        $a = $line(1, 10, 1000);
        $line(2, 5, 500);
        // ⓘ সারি গ — ২টা পাঠানো, ৩টা বিল (পুরনো ভুল ডেটা): সীমা থেকে কিছু কমায় না, ঋণাত্মক নয়
        $over = $line(3, 2, 200);

        $bill = fn (string $no, string $status) => DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => $no, 'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(), 'status' => $status, 'subtotal' => 600, 'total' => 600,
        ]);
        DB::table('sal_invoice_lines')->insert([
            'sales_invoice_id' => $bill('PART-INV', DocumentStatus::CONFIRMED), 'product_id' => $product, 'delivery_challan_line_id' => $a,
            'qty' => 6, 'rate' => 100, 'amount' => 600, 'line_no' => 1,
        ]);
        // ⓘ বাতিল বিলের সারি গোনা নয় — বাতিল হলে মাল আবার "বিল না হওয়া"
        DB::table('sal_invoice_lines')->insert([
            'sales_invoice_id' => $bill('PART-CXL', DocumentStatus::CANCELLED), 'product_id' => $product, 'delivery_challan_line_id' => $a,
            'qty' => 4, 'rate' => 100, 'amount' => 400, 'line_no' => 1,
        ]);

        DB::table('sal_invoice_lines')->insert([
            'sales_invoice_id' => $bill('PART-OVR', DocumentStatus::CONFIRMED), 'product_id' => $product, 'delivery_challan_line_id' => $over,
            'qty' => 3, 'rate' => 100, 'amount' => 300, 'line_no' => 1,
        ]);

        // ⓘ বিল না হওয়া: ক-এর ৪টা (৪০০) + খ-এর পুরো ৫০০ + গ-এর শূন্য = ৯০০
        $exposure = app(CreditExposure::class);
        $this->assertSame(0, bccomp($exposure->pending($customer->fresh()), '900', 2), '⛔ আংশিক বিল হওয়া সারির বাকিটা সীমায় নেই — '.$exposure->pending($customer->fresh()));
        $this->assertSame(0, bccomp((string) ($exposure->pendingFor([(int) $customer->id])[(int) $customer->id] ?? '0'), '900', 2), '⛔ অনেক গ্রাহকের পথে আলাদা অঙ্ক');
    }
}
