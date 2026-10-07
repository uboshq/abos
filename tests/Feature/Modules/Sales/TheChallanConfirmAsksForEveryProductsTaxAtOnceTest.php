<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\MarginGuard;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুই সারির চালানে মার্জিনের পাহারা — পণ্যের কর একসাথে আসে, সারি ধরে নয় (৬ অক্টোবর ২০২৬-এর বিক্রয় ধারার পরীক্ষা)।
 *
 * ⓘ মার্জিনের পাহারা ([[MarginGuard::challanRows()]]) প্রতিটা সারির পণ্যের কর পড়ে, অথচ আনত কেবল পণ্য। লাইভে
 * (production) সেটা প্রতিটা সারিতে একটা বাড়তি কোয়েরি মাত্র; local-এ লেজি লোডিং বন্ধ, তাই চালান নিশ্চিত করলেই ৫০০ —
 * "Attempted to lazy load [tax] on model [Product]"। ⓘ টেস্টের পরিবেশে লেজি লোডিং খোলা, তাই দাবি নিজেই বন্ধ করে।
 */
final class TheChallanConfirmAsksForEveryProductsTaxAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_the_margin_guard_reads_a_two_line_challan_with_lazy_loading_shut(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['credit_limit' => '1000000'])->save();
        $products = Product::query()->whereIn('name_en', ['Cosmos Biscuit 40gm', 'Soyabean Oil 5 ltr'])->get();
        $this->assertCount(2, $products, 'দুই পণ্য নেই — দাবি এক সারিতে কিছু মাপবে না।');

        $service = app(DeliveryChallanService::class);
        $challan = $service->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            $products->map(fn (Product $p) => ['product_id' => $p->id, 'delivered_qty' => '1', 'rate' => (string) $p->sale_price])->all(),
        );

        // ⛔ local-এর মতো — লেজি লোডিং বন্ধ; মার্জিনের পাহারার দুই পথ: সইয়ের অনুরোধ, আর চালানের সারি
        $guard = app(MarginGuard::class);
        $rows = new \ReflectionMethod(MarginGuard::class, 'challanRows');
        Model::preventLazyLoading(true);
        try {
            $guard->requestFor($challan->fresh());
            $read = $rows->invoke($guard, $challan->fresh());
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertCount(2, $read, 'দুই সারির হিসাব আসেনি।');
        // ⓘ কর ছাড়া পণ্যে নিট = দর × পরিমাণ — হিসাবটা সত্যিই পণ্যের কর পড়ে দেখেছে
        $this->assertSame(0, bccomp($read[0]['net'], (string) $products->firstWhere('id', $read[0]['product']->id)->sale_price, 4));
    }
}
