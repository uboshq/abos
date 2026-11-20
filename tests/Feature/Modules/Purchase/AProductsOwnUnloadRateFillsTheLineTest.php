<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পণ্যের নিজের আনলোড দর সারিতে নিজে বসে — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: বড় কার্টন নামাতে ১ টাকা, খুব বড়টায় ৫ — প্রতিবার হাতে না লিখে পণ্যের ফর্মে একবার। সারিতে খালি বা না পাঠালে
 *   পণ্যের দর; হাতে লেখা দর থাকলে সেটাই জেতে ([[ChargesUnloading::unloadRateFor()]])।
 */
final class AProductsOwnUnloadRateFillsTheLineTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $this->product->forceFill(['unload_rate' => '1.00'])->save();
    }

    public function test_a_receipt_line_takes_the_products_rate_unless_one_is_typed(): void
    {
        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [
                ['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100'],
                ['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100', 'unload_rate' => ''],
                ['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100', 'unload_rate' => '2.5'],
            ],
        );

        $rates = $receipt->lines()->orderBy('line_no')->pluck('unload_rate')->map(fn ($r) => $r === null ? null : bcadd((string) $r, '0', 4))->all();

        $this->assertSame('1.0000', $rates[0], '⛔ দর না পাঠানো সারিতে পণ্যের নিজের দর বসেনি।');
        $this->assertSame('1.0000', $rates[1], '⛔ খালি দরের সারিতে পণ্যের নিজের দর বসেনি।');
        $this->assertSame('2.5000', $rates[2], '⛔ হাতে লেখা দর হারাল — পণ্যের দর জিতে গেল।');
    }

    public function test_a_product_without_its_own_rate_leaves_the_line_to_the_equal_share(): void
    {
        $this->product->forceFill(['unload_rate' => null])->save();

        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );

        $this->assertNull($receipt->lines()->first()->unload_rate, '⛔ দর নেই, তবু সারিতে কোনো দর বসল — সমান ভাগ হারাল।');
    }

    public function test_the_direct_purchase_line_takes_the_products_rate_too(): void
    {
        $this->post(route('purchase.direct.store'), [
            'supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'UNL-RATE-1',
            // ⓘ বিক্রয়দর ছাড়া সরাসরি ক্রয়ের সারি নয় — মালিক, ৩ অক্টোবর ২০২৬ (DirectPurchaseRules); আনলোডিংয়ের দাবি কেবল সেই নিয়ম মেনে
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '75']],
            'unload_mode' => 'supplier',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $line = PurchaseBill::query()->latest('id')->firstOrFail()->lines()->firstOrFail();

        $this->assertSame('1.0000', bcadd((string) $line->unload_rate, '0', 4), '⛔ সরাসরি ক্রয়ের সারিতে পণ্যের নিজের দর বসেনি।');
    }
}
