<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের আদেশে দামহীন পণ্য শূন্য দরে বসে না — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ বিপজ্জনক ইনপুট: বিক্রয়মূল্য ০, কোনো দর তালিকাও নেই। ফোনের দরজা এটা ফেরায় ([[SalesOrderApiController]]),
 * ওয়েবের ফর্ম `gt:0` চায়; পোর্টাল চুপচাপ শূন্য দরের সারি বানাত — মালিকের *"sales price chara entry nibe na"*।
 */
final class APortalOrderNeedsAPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_without_a_price_is_refused_on_the_portal_order(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => 'customer-pass-1'])->save();
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $product->forceFill(['sale_price' => '0'])->save();
        DB::table('sal_price_list_items')->where('product_id', $product->id)->delete();
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        $this->actingAs($customer->fresh(), 'portal')->post(route('sales.portal.order.store'), [
            'lines' => [['product_id' => $product->id, 'qty' => '12']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, SalesOrder::query()->where('customer_id', $customer->id)->count(),
            '⛔ দামহীন পণ্যে পোর্টালের আদেশ শূন্য দরে বসে গেল।');
    }
}
