<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বাতিল ফেরত লট আর আটকানো ঘর শূন্যের নিচে নামাত — Inventory অডিট ম১৭, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ উল্টো কাগজ (ফেরত বাতিল) তাক থেকে মাল তোলার আগে কেবল **পণ্যের মোট** দেখত ([[StockService::move()]]):
 *   ১. ফেরত-আসা মাল আটকে রাখা হলো, পরে ছাড়া হলো, তারপর ফেরতটা বাতিল — আটকানো ঘর −২, আর "পাওয়া যায়" তাকের চেয়ে বেশি;
 *   ২. লট A শেষ, লট B-তে মাল আছে — A থেকে তোলা চলত, A শূন্যের নিচে, মোটটা মিলত বলে কেউ টের পেত না।
 * ⭐ এখন লটের নিজের তাক আর আটকানো ঘর দুটোই পাহারায়, তালাসহ; থামলে কারণটা বলে।
 */
final class TheCancelledReturnLeftTheLotBelowZeroTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_a_return_whose_held_goods_were_released_cannot_be_cancelled(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        $sales = app(SalesInvoiceService::class);
        $invoice = $sales->confirm($sales->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '200']],
        ))->load('lines');

        $returns = app(SalesReturnService::class);
        $return = $returns->confirm($returns->create(
            ['customer_id' => $invoice->customer_id, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $invoice->id,
                'trx_date' => now()->toDateString(), 'reason_code_id' => (int) ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id')],
            [['product_id' => $product->id, 'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '2', 'to_hold' => true]],
        ));
        $heldBefore = $this->held($product);

        // ⓘ আটকানো মাল ছাড়া হলো (যেমন পরীক্ষায় ভালো পাওয়া গেল)
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.release',
            sourceId: 91701, hold: bcmul($heldBefore, '-1', 4));
        $this->assertSame('0.0000', $this->held($product), 'প্রস্তুতিটাই ভুল — আটকানো ঘর শূন্য হওয়ার কথা।');

        try {
            $returns->cancel($return, 'M17');
            $this->fail('⛔ ছাড়া হয়ে যাওয়া আটকানো মালের ফেরত বাতিল হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($product->name(), implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame('0.0000', $this->held($product), '⛔ আটকানো ঘর শূন্যের নিচে নামল।');
    }

    public function test_a_lot_cannot_give_more_than_it_holds_even_when_the_product_has_more(): void
    {
        $product = Product::query()->create([
            'code' => 'M17-LOT', 'name_en' => 'Lot probe', 'name_bn' => 'লট-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true,
        ]);
        $a = app(BatchService::class)->receive($product, 'M17-A', Carbon::today()->addMonths(2)->toDateString());
        $b = app(BatchService::class)->receive($product, 'M17-B', Carbon::today()->addMonths(6)->toDateString());
        $stock = app(StockService::class);
        $stock->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.in', sourceId: 1, floor: '2', batch: $a);
        $stock->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.in', sourceId: 2, floor: '10', batch: $b);

        try {
            $stock->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.out', sourceId: 3, floor: '-3', batch: $a);
            $this->fail('⛔ দুইটা আছে এমন লট থেকে তিনটা বেরোল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('M17-A', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame('2.0000', bcadd((string) StockMovement::query()->where('batch_id', $a->id)->sum('floor_change'), '0', 4));

        // ⓘ লটে যতটা আছে ঠিক ততটা বেরোয়
        $stock->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.out', sourceId: 4, floor: '-2', batch: $a);
        $this->assertSame('0.0000', bcadd((string) StockMovement::query()->where('batch_id', $a->id)->sum('floor_change'), '0', 4));
    }

    private function held(Product $product): string
    {
        return bcadd((string) StockMovement::query()->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)->sum('hold_change'), '0', 4);
    }
}
