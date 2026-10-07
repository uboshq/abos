<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোনের দরজা অদ্ভুত ইনপুটে ৫০০ দিত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১১।
 *
 * "1e3" `numeric` পার হয়ে bcmath-এ ValueError, `?q[]=x` দিলে `(string)` অ্যারে, আর জমার অঙ্কের উচ্চসীমা ছিল না। এখন সংখ্যা এক
 * দশমিক নিয়মে ([[PhoneInput::DECIMAL]]) আর প্রশ্নের ঘর লেখা হিসেবে ([[PhoneInput::text()]]) — ভুল ইনপুটে ৪২২ বা সাধারণ উত্তর,
 * ৫০০ কখনো নয়।
 */
final class ThePhoneDoorsBrokeOnOddInputTest extends TestCase
{
    use RefreshDatabase;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
    }

    public function test_an_array_in_a_query_field_is_no_500(): void
    {
        foreach ([
            '/api/v1/sales/orders?customer[]=x&status[]=y',
            '/api/v1/sales/delivery-orders?customer[]=x',
            '/api/v1/sales/quotations?status[]=y',
            '/api/v1/sales/leads?q[]=x&status[]=y',
            '/api/v1/sales/tracking?q[]=x&customer[]=y&step[]=z&from[]=a',
            '/api/v1/purchase/principals?q[]=x',
            '/api/v1/sales/standing/'.$this->shop->public_id.'?total[]=1',
        ] as $url) {
            $status = $this->getJson($url)->status();
            $this->assertLessThan(500, $status, "⛔ {$url} — {$status}");
        }

        $this->getJson('/api/v1/sales/deposit-requests?customer[]=x')->assertNotFound();
    }

    public function test_scientific_or_oversized_numbers_are_a_422_not_a_500(): void
    {
        $this->getJson('/api/v1/sales/standing/'.$this->shop->public_id.'?total=1e3')->assertOk();

        $line = fn (string $qty) => ['product' => (string) $this->product->public_id, 'qty' => $qty];
        foreach ([
            ['/api/v1/sales/offers', ['customer' => (string) $this->shop->public_id, 'lines' => [$line('1e3')]]],
            ['/api/v1/sales/orders', ['customer' => (string) $this->shop->public_id, 'lines' => [$line('1e3')]]],
            ['/api/v1/sales/delivery-orders', ['customer' => (string) $this->shop->public_id, 'lines' => [$line('2e2')]]],
            ['/api/v1/sales/quotations', ['customer' => (string) $this->shop->public_id, 'lines' => [$line('1e3')]]],
            ['/api/v1/sales/deposit-requests', ['customer' => (string) $this->shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '1e3', 'method' => 'cash']],
            ['/api/v1/sales/deposit-requests', ['customer' => (string) $this->shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '123456789012345678', 'method' => 'cash']],
        ] as [$url, $body]) {
            $this->postJson($url, $body)->assertStatus(422);
        }
    }

    public function test_a_plain_decimal_still_goes_through(): void
    {
        $this->postJson('/api/v1/sales/deposit-requests', [
            'customer' => (string) $this->shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '1500.50', 'method' => 'cash',
        ])->assertCreated();
    }
}
