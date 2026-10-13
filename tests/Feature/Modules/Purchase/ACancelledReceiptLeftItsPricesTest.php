<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বাতিল রসিদের দাম পণ্যে থেকে যেত (fe, ১৩ অক্টোবর ২০২৬; [[PurchaseReceiptService::restorePrices()]])।
 *
 * ⚠️ পাকা রসিদ পণ্যের ক্রয়মূল্য আর বিক্রয়মূল্য নিজের দরে বসায়; বাতিলে কিছুই ফিরত না। ⭐ এখন আগের দামে ফেরে — কেবল যদি পণ্যে এখনো
 * এই রসিদেরই দাম থাকে।
 */
final class ACancelledReceiptLeftItsPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_a_receipt_puts_the_products_earlier_prices_back(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $product->forceFill(['purchase_price' => '40', 'sale_price' => '55'])->save();

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '5', 'rate' => '90', 'sales_price' => '120']],
        ));
        $this->assertSame(0, bccomp((string) $product->fresh()->purchase_price, '90', 4), 'দৃশ্যটাই বানানো যায়নি — রসিদের দাম পণ্যে বসেনি');

        $receipts->cancel($receipt->fresh(), 'ভুল রসিদ');

        $this->assertSame(0, bccomp((string) $product->fresh()->purchase_price, '40', 4), '⛔ বাতিল রসিদের ক্রয়মূল্য পণ্যে রয়ে গেল।');
        $this->assertSame(0, bccomp((string) $product->fresh()->sale_price, '55', 4), '⛔ বাতিল রসিদের বিক্রয়মূল্য পণ্যে রয়ে গেল।');
    }
}
