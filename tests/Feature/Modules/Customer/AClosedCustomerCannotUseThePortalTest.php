<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⛔ নিষ্ক্রিয় বা মুছে ফেলা গ্রাহক পোর্টালে নয়, আর খতিয়ান ১৯৭০ থেকে নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, গ্রাহক ১৫;
 * [[PortalController]], [[EnsurePortalStillOpen]])।
 *
 * ⓘ লগইন দেখত কেবল "পোর্টাল চালু" — গ্রাহক নিষ্ক্রিয় বা মুছে ফেলা হলেও ঢুকে অর্ডার আর জমার দাবি পাঠাতে পারতেন; ঢুকে থাকা
 * সেশনও চলতে থাকত। আর খতিয়ান ডিফল্টে ১৯৭০-এর ১ জানুয়ারি থেকে সব সারি টানত।
 */
final class AClosedCustomerCannotUseThePortalTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'GATE-1',
            'name_en' => 'Gate Customer', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $this->customer->forceFill(['portal_enabled' => true, 'portal_password' => Hash::make('shop-pass-2026')])->save();
    }

    public function test_an_inactive_customer_cannot_sign_in(): void
    {
        $this->customer->forceFill(['is_active' => false])->save();

        $this->signIn()->assertSessionHasErrors();
        $this->assertFalse(Auth::guard('portal')->check(), '⛔ নিষ্ক্রিয় গ্রাহক পোর্টালে ঢুকলেন');
    }

    public function test_a_deleted_customer_cannot_sign_in(): void
    {
        $this->customer->delete();

        $this->signIn()->assertSessionHasErrors();
        $this->assertFalse(Auth::guard('portal')->check(), '⛔ মুছে ফেলা গ্রাহক পোর্টালে ঢুকলেন');
    }

    public function test_a_customer_made_inactive_while_inside_is_sent_out(): void
    {
        $this->signIn()->assertRedirect(route('sales.portal.home'));
        $this->get(route('sales.portal.ledger'))->assertOk();

        Customer::query()->whereKey($this->customer->id)->update(['is_active' => false]);
        // ⓘ পরের অনুরোধ গার্ড নতুন করে পড়ে — সত্যিকারের ব্রাউজারের মতো
        $this->app['auth']->forgetGuards();

        $this->get(route('sales.portal.ledger'))->assertRedirect(route('sales.portal.login'));
        $this->assertFalse(Auth::guard('portal')->check(), '⛔ নিষ্ক্রিয় হওয়ার পরেও সেশন খোলা');
    }

    public function test_the_ledger_opens_on_this_financial_year_not_1970(): void
    {
        $this->signIn();

        $from = $this->get(route('sales.portal.ledger'))->assertOk()->viewData('from');

        $this->assertNotSame('1970-01-01', $from, '⛔ খতিয়ান ১৯৭০ থেকে');
        $this->assertTrue($from <= now()->toDateString() && $from >= now()->subYear()->toDateString(), 'চলতি অর্থবছরের শুরু: '.$from);
    }

    private function signIn(): TestResponse
    {
        return $this->post(route('sales.portal.login.attempt'), ['code' => 'GATE-1', 'password' => 'shop-pass-2026']);
    }
}
