<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Http\Requests\PlainNumber;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ `1e5` যাচাইয়ের ভুল, ৫০০ নয় — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[PlainNumber]])।
 *
 * ⓘ বিপজ্জনক ইনপুট: বৈজ্ঞানিক লেখা। `numeric` নিয়ম `is_numeric` ধরে আর `1e5` পাশ করায়; তারপর PHP 8-এর bcmath
 * ValueError ছোড়ে — আদেশ, আদায়, পোর্টালের আদেশ আর লক্ষ্যের পাতা, সব দরজায় ৫০০।
 */
final class AnExponentNumberIsAValidationErrorNotACrashTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $dealer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->dealer = app(CustomerService::class)->create([
            'name_en' => 'Exponent Traders', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();
    }

    public function test_the_rule_tells_plain_decimals_from_exponents(): void
    {
        foreach (['5', '5.25', '.5', '5.', '-0.5', '+5', '0'] as $plain) {
            $this->assertTrue(PlainNumber::is($plain), "সাধারণ দশমিক ফিরল: {$plain}");
        }

        foreach (['1e5', '1E5', '1e-5', ' 5', 'abc', ''] as $odd) {
            $this->assertFalse(PlainNumber::is($odd), "অদ্ভুত লেখা পার হলো: {$odd}");
        }
    }

    public function test_an_exponent_on_the_office_doors_comes_back_as_a_field_error(): void
    {
        $this->post(route('sales.order.store'), [
            'customer_id' => $this->dealer->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'ordered_qty' => '1', 'rate' => '1e5']],
        ])->assertStatus(302)->assertSessionHasErrors('lines.0.rate');

        $this->post(route('sales.collection.store'), [
            'customer_id' => $this->dealer->id,
            'account_id' => (int) Account::query()->postable()->where('name_en', 'like', '%Cash%')->value('id'),
            'trx_date' => now()->toDateString(),
            'amount' => '1e5',
        ])->assertStatus(302)->assertSessionHasErrors('amount');

        $this->post(route('sales.customer_target.store'), [
            'month' => now()->startOfMonth()->toDateString(),
            'target' => [$this->dealer->id => ['amount' => '1e5']],
        ])->assertStatus(302)->assertSessionHasErrors('target.'.$this->dealer->id.'.amount');

        $this->post(route('sales.target.store'), [
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => [$this->owner->id => '1e3'],
        ])->assertStatus(302)->assertSessionHasErrors('amount.'.$this->owner->id);
    }

    public function test_an_exponent_on_the_portal_order_comes_back_as_a_field_error(): void
    {
        $this->dealer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        $this->actingAs($this->dealer->fresh(), 'portal')->post(route('sales.portal.order.store'), [
            'lines' => [['product_id' => $this->product->id, 'qty' => '1e5']],
        ])->assertStatus(302)->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(0, SalesOrder::query()->where('customer_id', $this->dealer->id)->count());
    }
}
