<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Sync\SalesOrderSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⛔ ফোন বন্ধ করা দোকানের অর্ডারও নিত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SalesOrderSync::apply()]])।
 *
 * ⚠️ অফিসে গ্রাহক নিষ্ক্রিয় করার আগে ফোনের অফলাইন সারিতে লেখা অর্ডার পরে পৌঁছালে সোজা বসে যেত — সম্পর্ক শেষ, তবু নতুন অর্ডার।
 * ⭐ এখন কারণসহ ফেরত, অর্ডার বসে না।
 */
final class ThePhoneTookAnOrderForAClosedShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_for_an_inactive_customer_is_refused(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $customer = Customer::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $customer->forceFill(['is_active' => false])->save();
        $product = Product::query()->whereNotNull('sale_price')->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        $before = SalesOrder::query()->withoutGlobalScopes()->count();

        $change = PushedChange::fromArray([
            'changeId' => (string) Str::uuid(), 'entityType' => 'SalesOrder', 'operation' => 'CREATE',
            'entityId' => (string) Str::uuid(),
            'payloadJson' => json_encode(['customerId' => (string) $customer->public_id, 'lines' => [['productId' => (string) $product->public_id, 'qty' => '2']]]),
        ]);

        try {
            app(SalesOrderSync::class)->apply($owner, $change);
            $this->fail('⛔ বন্ধ করা দোকানের অর্ডার বসে গেল');
        } catch (SyncRejection $e) {
            $this->assertSame(__('sales::sync.customer_inactive'), $e->getMessage());
        }

        $this->assertSame($before, SalesOrder::query()->withoutGlobalScopes()->count(), '⛔ বন্ধ দোকানের অর্ডার খাতায় উঠল');
    }
}
