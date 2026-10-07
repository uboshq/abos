<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের আদেশ আর DO শূন্য দরে বসতে পারত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৫ ("দুই দরজা দুই নিয়ম": ওয়েব `gt:0` চায়)।
 *
 * শূন্য দরের কাগজ বাকির যাচাই আর অনুমোদনের সীমার নিচ দিয়ে যেত। এখন দাম শূন্য হলে আদেশ আর DO দুটোই ফেরে, পণ্যের নামসহ
 * ([[SalesOrderApiController::lines()]], [[DeliveryOrderService::writeLines()]] — DO-র তিন দরজাই)।
 */
final class AZeroPriceSlippedThroughThePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_and_a_delivery_order_with_no_price_are_refused_and_with_a_price_they_land(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $shop = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        DB::table('sal_price_list_items')->where('product_id', $product->id)->delete();
        $product->forceFill(['sale_price' => '0'])->save();

        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $sr->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $sr->givePermissionTo(array_map(
            fn (string $k) => Permission::findOrCreate($k, 'web'),
            ['sales.order.create', 'sales.order.view', 'sales.do.create', 'sales.do.view'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);

        $body = ['customer' => (string) $shop->public_id, 'lines' => [['product' => (string) $product->public_id, 'qty' => '5']]];
        $orders = SalesOrder::query()->count();
        $dos = DeliveryOrder::query()->count();
        $name = (string) ($product->name_bn ?: $product->name_en);

        $this->assertStringContainsString($name, (string) $this->postJson('/api/v1/sales/orders', $body)->assertStatus(422)->json('message'));
        $this->assertStringContainsString($name, (string) $this->postJson('/api/v1/sales/delivery-orders', $body)->assertStatus(422)->json('message'));
        $this->assertSame([$orders, $dos], [SalesOrder::query()->count(), DeliveryOrder::query()->count()], '⛔ শূন্য দরে কাগজ বসল।');

        $product->forceFill(['sale_price' => '40'])->save();
        $this->postJson('/api/v1/sales/orders', $body)->assertCreated();
        $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated();
    }
}
