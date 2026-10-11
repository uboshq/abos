<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে উদ্ধৃতি — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬; [[SalesQuotationApiController]])।
 *
 * ⭐ দাবি:
 *   একই মানুষ — চাবি ছাড়া ৪০৩, `sales.quotation.create` দিলে লেখেন; দর না দিলে পণ্যের দাম, শূন্য দর ফেরে (ওয়েবের যাচাই);
 *   জমা → অনুমোদিত (ছক নেই) → পাঠানো → গৃহীত → আদেশ; প্রতিটা বোতাম কেবল নিজের ধাপে (`can`);
 *   আদেশে রূপান্তরে আদেশ লেখার চাবিও লাগে — না থাকলে ৪০৩, আর আদেশ হয় না;
 *   দোকানি রাজি না হলে কারণ লাগে।
 */
final class TheFieldQuotesAPriceOnThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->customer = Customer::query()->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '40'])->save();
    }

    public function test_a_quotation_goes_from_the_phone_to_an_order_and_converting_needs_the_order_key(): void
    {
        $sr = $this->staff([]);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $this->postJson('/api/v1/sales/quotations', $this->body())->assertForbidden();

        $sr = $this->staff(['sales.quotation.view', 'sales.quotation.create', 'sales.quotation.update', 'sales.quotation.convert'], $sr);
        Sanctum::actingAs($sr, [AuthController::APP]);

        // ⛔ ফোনের দর গোনা হয় না — সার্ভারের দাম বসে, ফোন ১ টাকা বা ০ পাঠালেও (পুরো ERP অডিট, ৯ অক্টোবর ২০২৬)
        foreach (['1', '0'] as $phoneRate) {
            $this->assertSame('40.00', $this->postJson('/api/v1/sales/quotations', $this->body(rate: $phoneRate))->assertCreated()->json('lines.0.rate'),
                "⛔ ফোনের দর {$phoneRate} দরপত্রে বসল।");
        }
        // ⓘ পণ্যের নিজের দাম শূন্য হলে দরপত্রই নয়
        \Illuminate\Support\Facades\DB::table('sal_price_list_items')->where('product_id', $this->product->id)->delete();
        $this->product->forceFill(['sale_price' => '0'])->save();
        $this->postJson('/api/v1/sales/quotations', $this->body())->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->product->forceFill(['sale_price' => '40'])->save();

        $made = $this->postJson('/api/v1/sales/quotations', $this->body(submit: true))->assertCreated()->json();
        $this->assertSame(SalesQuotation::APPROVED, $made['status'], 'ছক নেই — জমাতেই অনুমোদিত');
        $this->assertSame('40.00', $made['lines'][0]['rate'], 'দর না দিলে পণ্যের দাম');
        $this->assertTrue($made['can']['send']);
        $this->assertFalse($made['can']['answer'], 'পাঠানোর আগে দোকানির উত্তর নয়');

        $sent = $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/send')->assertOk()->json();
        $this->assertSame(SalesQuotation::SENT, $sent['status']);
        $this->assertFalse($sent['can']['send'], 'একবার পাঠানোর পরে আবার "পাঠান" নয়');
        $this->assertTrue($sent['can']['answer']);
        $accepted = $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/accept')->assertOk()->json();
        $this->assertSame(SalesQuotation::ACCEPTED, $accepted['status']);
        $this->assertFalse($accepted['can']['convert'], 'আদেশ লেখার চাবি নেই — বোতামও নেই');

        $orders = SalesOrder::query()->count();
        $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/convert')->assertForbidden();
        $this->assertSame($orders, SalesOrder::query()->count(), '⛔ আদেশ লেখার চাবি ছাড়া উদ্ধৃতির পথে আদেশ হয়ে গেল।');

        $sr = $this->staff(['sales.order.create'], $sr);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $done = $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/convert')->assertOk()->json();
        $this->assertSame(SalesQuotation::CONVERTED, $done['status']);
        $this->assertNotNull($done['order']);
        $this->assertSame((int) $this->customer->id, (int) SalesOrder::query()->where('public_id', $done['order']['id'])->value('customer_id'));
        $this->assertSame([$made['id']], array_column($this->getJson('/api/v1/sales/quotations?status=converted')->assertOk()->json('quotations'), 'id'));
    }

    public function test_a_refusal_needs_its_reason(): void
    {
        $sr = $this->staff(['sales.quotation.view', 'sales.quotation.create', 'sales.quotation.update']);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $made = $this->postJson('/api/v1/sales/quotations', $this->body(submit: true))->assertCreated()->json();
        $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/send')->assertOk();

        $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/reject')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame(SalesQuotation::REJECTED,
            $this->postJson('/api/v1/sales/quotations/'.$made['id'].'/reject', ['note' => 'দাম বেশি'])->assertOk()->json('status'));
    }

    /** @return array<string, mixed> */
    private function body(?string $rate = null, bool $submit = false): array
    {
        return [
            'customer' => (string) $this->customer->public_id,
            'lines' => [['product' => (string) $this->product->public_id, 'qty' => '10'] + ($rate === null ? [] : ['rate' => $rate])],
            'submit' => $submit,
        ];
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
