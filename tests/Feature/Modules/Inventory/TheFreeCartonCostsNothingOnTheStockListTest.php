<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ১৯ (Inventory অডিট, ৪ অক্টোবর ২০২৬): মজুদের তালিকা ফ্রি মালকেও দাম দিয়ে ধরত।
 *
 * ⓘ ১০০টা ৮০ টাকায় কিনে ২০টা ফ্রি পেলে খাতায় মূল্য ৮,০০০ — অথচ তালিকা ১২০ × ৮০ = ৯,৬০০ দেখাত। সরবরাহকারীর ফ্রি
 * মালের খরচ শূন্য: খরচের স্তর বসে কেবল কেনা পরিমাণে ([[PurchaseReceiptService]]), ফ্রি যায় আলাদা খোপে, স্তর ছাড়া।
 * ⓘ দাবি: ফ্রিসহ কেনা পণ্যের তালিকার মূল্য (সারি আর সর্বমোট) = স্তরে পড়ে থাকা মূল্য, যা খাতার মজুদ — ফ্রি গোনা হয় না।
 */
final class TheFreeCartonCostsNothingOnTheStockListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lot_bought_with_free_goods_is_worth_only_its_paid_layer(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $template = Product::query()->where('is_active', true)->where('track_batch', false)->firstOrFail();
        $product = $template->replicate(['public_id']);
        $product->forceFill(['code' => 'G19-'.random_int(1000, 9999), 'name_en' => 'Free Audit Item', 'name_bn' => 'ফ্রি অডিট', 'barcode' => null])->save();

        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => Supplier::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'G19-'.random_int(1000, 9999),
            ],
            [['product_id' => $product->id, 'qty' => '100', 'free_qty' => '20', 'rate' => '80', 'sales_price' => '95']],
        );

        $book = app(CostLayerService::class)->valueOnHand($product);
        $this->assertSame(0, bccomp($book, '8000', 4), 'খরচের স্তরে ১০০ × ৮০ বসেনি — দাবির ভিত নড়ে গেছে।');

        $page = $this->get(route('inventory.stock.index', ['q' => $product->code, 'stock' => 'all']))->assertOk();

        $grand = $page->viewData('grand');
        $this->assertArrayHasKey('stock_value', (array) $grand, 'সর্বমোটে মজুদের মূল্য নেই।');
        $this->assertSame(0, bccomp((string) $grand['stock_value'], $book, 2),
            "⛔ তালিকার সর্বমোট মূল্য {$grand['stock_value']}, খাতা {$book} — ফ্রি মালও দামে ধরা হয়েছে।");

        $html = $page->getContent();
        $this->assertStringContainsString(Money::format('8000'), $html, '⛔ সারিতে ৮,০০০ নেই।');
        $this->assertStringNotContainsString(Money::format('9600'), $html, '⛔ সারিতে ৯,৬০০ — ফ্রি ২০টাও ৮০ টাকায় ধরা হয়েছে।');
    }
}
