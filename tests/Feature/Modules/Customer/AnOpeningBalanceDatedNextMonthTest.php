<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ শুরুর জের ভবিষ্যতের তারিখে বসত (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[OpeningBalanceService::dateFor()]])।
 *
 * ⚠️ পরের মাসের তারিখে বসানো ৫,০০০-এর শুরুর বকেয়া আজকের বকেয়া, সীমার যাচাই আর রেওয়ামিল থেকে লুকাত — গ্রাহকের পাতায় অঙ্ক নেই,
 * অথচ পরের মাসে হঠাৎ এসে বসত। ⭐ এখন কারণসহ ফেরত, আর গ্রাহক বা সরবরাহকারী কিছুই তৈরি হয় না।
 */
final class AnOpeningBalanceDatedNextMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_opening_balance_on_a_future_date_is_refused_for_both_parties(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $later = now()->addMonth()->toDateString();

        try {
            app(CustomerService::class)->create(['name_en' => 'Future Shop', 'opening_balance' => '5000', 'opening_date' => $later]);
            $this->fail('⛔ পরের মাসের তারিখে গ্রাহকের শুরুর বকেয়া বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_date', $e->errors());
        }
        $this->assertFalse(Customer::query()->where('name_en', 'Future Shop')->exists());

        try {
            app(SupplierService::class)->create(['name_en' => 'Future Mills', 'opening_balance' => '5000', 'opening_date' => $later]);
            $this->fail('⛔ পরের মাসের তারিখে সরবরাহকারীর শুরুর দেনা বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_date', $e->errors());
        }
        $this->assertFalse(Supplier::query()->where('name_en', 'Future Mills')->exists());
    }
}
