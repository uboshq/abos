<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ হাতে লেখা বিলের নম্বর গোটা কোম্পানিতে মেলানো — নিজের শাখায় নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ৫;
 * [[SalesInvoiceService::create()]])।
 *
 * ⓘ নম্বর অনন্য গোটা কোম্পানিতে (ইউনিক ইনডেক্স), অথচ যাচাই দেখত কেবল শাখার দেয়ালের ভিতর। এক শাখায় সীমিত কর্মী অন্য শাখার একটা নম্বর
 * লিখলে বাংলা বার্তার বদলে ডাটাবেজের ধাক্কা — পাতা ভেঙে ৫০০।
 */
final class AManualBillNumberIsCheckedAcrossTheCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_branchs_number_is_refused_with_a_reason(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($home->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($company->id, $home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->orderBy('id')->firstOrFail();
        SalesInvoice::query()->create(['branch_id' => $elsewhere->id, 'document_no' => 'HAND-77', 'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(), 'subtotal' => '10', 'discount' => '0', 'tax' => '0', 'total' => '10', 'status' => DocumentStatus::CONFIRMED]);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        try {
            app(SalesInvoiceService::class)->create(['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(), 'document_no' => 'HAND-77'],
                [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '10']]);
            $this->fail('⛔ অন্য শাখার নম্বর আবার বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_no', $e->errors(), '⛔ বাংলা কারণের বদলে অন্য কিছু');
        }
    }
}
