<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডিলার পোর্টালে ধাপ দেখেন, দাম নয় — মালিক, ৬ অক্টোবর ২০২৬ (খ): ট্র্যাকিং পাতায় (তালিকা আর একটা বিক্রি) কোনো টাকার অঙ্ক নেই;
 * আদেশ দেওয়ার ফর্মে দাম থাকে, যাতে ডিলার জানেন কত টাকার আদেশ দিচ্ছেন।
 */
final class TheDealerTracksStepsNotPricesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000', 'portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->product->forceFill(['sale_price' => '137.45'])->save();
    }

    public function test_the_tracking_pages_carry_no_money_and_the_order_form_carries_the_price(): void
    {
        $challan = $this->challan();
        $total = Money::format((string) $challan->total);
        $this->assertNotSame(Money::format('0'), $total, 'প্রস্তুতিটাই ভুল — চালানের মোট শূন্য।');

        $this->actingAs($this->customer->fresh(), 'portal');

        $this->get(route('sales.portal.tracking'))->assertOk()
            ->assertSee((string) ($challan->sale_no ?: $challan->document_no))
            ->assertDontSee($total, false);
        $this->get(route('sales.portal.tracking.show', ['challan', $challan->public_id]))->assertOk()
            ->assertSee('data-tracking-milestones', false)
            ->assertDontSee($total, false);

        // ⭐ দামটা আদেশের ফর্মে — ডিলার জানেন কত টাকার আদেশ দিচ্ছেন
        $this->get(route('sales.portal.do.create'))->assertOk()->assertSee(Money::format('137.45'), false);
    }

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '7', 'rate' => '137.45']]))->fresh();
    }
}
