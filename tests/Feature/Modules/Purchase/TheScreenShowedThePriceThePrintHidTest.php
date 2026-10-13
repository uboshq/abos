<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ক্রয়ের পর্দা সেই দাম দেখাত যা ছাপা লুকায় (fe, ১৩ অক্টোবর ২০২৬; [[PurchasePrintController::showsPurchasePrice()]])।
 *
 * ⚠️ ছাপা আর ফোন ক্রয়মূল্য দেখায় কেবল খরচ দেখার চাবিতে (`inventory.cost.view`); অথচ আদেশ, বিল, রসিদ আর ফেরতের পর্দা যে কেউ খুলতে
 * পারতেন তাঁকেই দর, মূল্য আর মোট দেখাত। ⭐ এখন চার পর্দাতেই ছাপার নিয়ম — একই মানুষ: চাবি ছাড়া দাম নেই, চাবি দিলে আছে।
 */
final class TheScreenShowedThePriceThePrintHidTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = '777.77';

    public function test_the_four_purchase_screens_hide_the_price_without_the_cost_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $order = app(PurchaseOrderService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '1', 'rate' => self::RATE]],
        );
        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '1', 'rate' => self::RATE]],
        );
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'PRICE-1'],
            [['product_id' => $product->id, 'qty' => '2', 'rate' => self::RATE]],
        ));
        $return = app(PurchaseReturnService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '1', 'purchase_bill_line_id' => $bill->lines()->firstOrFail()->id]],
        );

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $grant = function (string ...$keys) use ($company, $clerk): void {
            CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $grant('purchase.order.view', 'purchase.receipt.view', 'purchase.bill.view', 'purchase.return.view');

        $pages = [
            'আদেশ' => route('purchase.order.show', $order),
            'রসিদ' => route('purchase.receipt.show', $receipt),
            'বিল' => route('purchase.bill.show', $bill),
            'ফেরত' => route('purchase.return.show', $return),
        ];

        foreach ($pages as $what => $url) {
            $this->actingAs($clerk->fresh())->get($url)->assertOk()->assertDontSee('777.77', false);
        }

        $grant('inventory.cost.view');

        foreach ($pages as $what => $url) {
            $this->assertStringContainsString('777.77', (string) $this->actingAs($clerk->fresh())->get($url)->assertOk()->getContent(),
                "দাবির ভিত্তি: চাবি দিলেও {$what}-এর পর্দায় দাম নেই।");
        }
    }
}
