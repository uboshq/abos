<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খালি সংখ্যার ঘরে গ্রাহকের ফর্ম ৫০০ দিত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerRequest::prepareForValidation()]])।
 *
 * ⚠️ ব্রাউজার খালি ঘর পাঠায় "", Laravel সেটাকে null করে, আর `credit_limit`, `credit_days`, `opening_balance` তিনটাই
 * `NOT NULL default 0` — ফলে "শুরুর বাকি" খালি রেখে নতুন গ্রাহক, বা সীমা মুছে সংরক্ষণ, দুটোই "Column cannot be null"।
 * ⭐ এখন খালি মানে ০ (মালিক, ১ অক্টোবর ২০২৬: "০ বা খালি মানে বাকি নেই")।
 */
final class ABlankNumberBrokeTheCustomerFormTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
    }

    public function test_a_new_customer_with_the_number_boxes_left_blank_is_saved_at_zero(): void
    {
        $this->actingAs($this->owner)->post(route('customer.store'), [
            'name_en' => 'Blank Box Store',
            'credit_limit' => '',
            'credit_days' => '',
            'opening_balance' => '',
            'opening_side' => 'dr',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $customer = Customer::query()->where('name_en', 'Blank Box Store')->first();
        $this->assertNotNull($customer, '⛔ খালি ঘরসহ নতুন গ্রাহক সংরক্ষণ হল না।');
        $this->assertSame(0, bccomp((string) $customer->credit_limit, '0', 4));
        $this->assertSame(0, (int) $customer->credit_days);
        $this->assertSame(0, bccomp((string) $customer->opening_balance, '0', 4));
    }

    public function test_clearing_the_limit_and_days_on_edit_saves_zero(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $customer->forceFill(['credit_limit' => '5000', 'credit_days' => 30])->save();

        $this->actingAs($this->owner)->put(route('customer.update', $customer), [
            'name_en' => $customer->name_en,
            'name_bn' => $customer->name_bn,
            'credit_limit' => '',
            'credit_days' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $customer->refresh();
        $this->assertSame(0, bccomp((string) $customer->credit_limit, '0', 4), '⛔ মুছে দেওয়া সীমা ০ হল না।');
        $this->assertSame(0, (int) $customer->credit_days);
    }
}
