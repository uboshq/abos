<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের "বানাও আর জমা দাও" এক কাজ — জমা ফিরলে খসড়াও থাকে না — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬
 * ([[SalesOrderApiController::store()]]; [[DeliveryOrderApiController::store()]])।
 *
 * ⓘ বানানো আগে পাকা হত, জমা পরে; জমা ফিরলে (৪২২) ফোন বলত "রাখা গেল না" আর চাবি মুছত, অথচ সার্ভারে একটা অনাথ খসড়া থেকে যেত —
 * আবার চাপায় দ্বিতীয় খসড়া। এখানে জমার মুহূর্তটা নিজেই ফেরানো হয় (যে কারণেই ফিরুক, ফল একই হওয়ার কথা)।
 */
final class ARefusedSubmitLeavesNoDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_whose_submit_is_refused_leaves_no_draft_behind(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        $shop = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $sr->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $sr->givePermissionTo(array_map(
            fn (string $k) => Permission::findOrCreate($k, 'web'), ['sales.order.create', 'sales.order.view'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);

        // ⓘ জমার মুহূর্তে ফেরানো — বানানো চলে, জমা নয়
        SalesOrder::saving(function (SalesOrder $order): void {
            if ($order->status === SalesOrderStatus::SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'জমা ফিরল']);
            }
        });

        $before = SalesOrder::query()->count();
        $this->postJson('/api/v1/sales/orders', ['customer' => (string) $shop->public_id, 'submit' => true,
            'lines' => [['product' => (string) $product->public_id, 'qty' => '5']]])->assertStatus(422);

        $this->assertSame($before, SalesOrder::query()->count(), '⛔ জমা ফিরল, অথচ অনাথ খসড়া রয়ে গেল — আবার চাপায় দ্বিতীয়টা');
        SalesOrder::flushEventListeners();
    }
}
