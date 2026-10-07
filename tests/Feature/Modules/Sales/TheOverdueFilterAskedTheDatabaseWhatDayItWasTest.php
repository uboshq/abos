<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesCustomerFilters;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "মেয়াদোত্তীর্ণ বিল নেই" ছাঁকনি ডেটাবেসের ঘড়ি দেখত — ৬ অক্টোবর ২০২৬ (NobodyAsksTheDatabaseWhatDayItIsTest-এর লাল)।
 *
 * ⛔ UTC ডেটাবেসে রাত ১২টা থেকে ভোর ৬টা পর্যন্ত "আজ" ছিল গতকাল — ধারের শেষ দিনে দাঁড়ানো গ্রাহক ঐ ছয় ঘণ্টা ভুল দলে।
 * ⭐ এখন "আজ" অ্যাপের ([[SalesCustomerFilters::noOverdueBill()]])। ⓘ দাবিটা অ্যাপের ঘড়ি সরিয়ে দেখে।
 */
final class TheOverdueFilterAskedTheDatabaseWhatDayItWasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_bill_past_its_credit_days_by_the_apps_today_counts_as_overdue(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $customer->forceFill(['credit_days' => 30])->save();
        SalesInvoice::query()->create([
            'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'INV-OVD-1', 'customer_id' => $customer->id,
            'trx_date' => '2029-11-01', 'due_on' => '2029-12-01', 'subtotal' => '100', 'discount' => '0', 'tax' => '0',
            'total' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);

        // ⓘ অ্যাপের "আজ" ৫ ডিসেম্বর ২০২৯ — ১ নভেম্বরের বিল ৩০ দিন পেরিয়েছে
        Carbon::setTestNow('2029-12-05 03:00:00');

        $clean = app(SalesCustomerFilters::class)->noOverdueBill(Customer::query())->pluck('id')->all();

        $this->assertNotContains($customer->id, $clean, '⛔ অ্যাপের "আজ"-এ মেয়াদ পেরোনো বিলের গ্রাহক "বকেয়া নেই" দলে।');
    }
}
