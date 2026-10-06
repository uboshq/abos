<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ফিরিয়ে নেওয়া বিল শূন্য থেকে খাবার বানাত, আর লটের মাল লট ছাড়া ফিরত — Inventory অডিট ম১৬, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ বিলের উল্টানো ([[SalesInvoiceService::unpost()]] — সম্পাদনা আর বাতিল-ইনভয়েস, দুই পথেরই) চালান ছাড়া প্রতিটা সারির
 * পণ্যটাকেই পরিমাণ ধরে তাকে ফেরাত, লট ছাড়া:
 *   ১. অর্ডারে-রান্না খাবার (বিরিয়ানি) কোনোদিন গুদামে ঢোকেনি, বিক্রিতে কমেছিল চাল আর মাংস — উল্টানোয় বিরিয়ানির
 *      মজুদ জন্মাত আর চাল-মাংস ফিরত না;
 *   ২. বিক্রি লট ধরে বেরোয় (গ১০), কিন্তু ফেরা লটহীন সারিতে — লট A খালিই থাকত, পণ্যের মোট কেবল মিলত।
 * ⭐ এখন উল্টানো বিক্রিতে **যা বেরিয়েছিল** তা-ই ফেরায় — একই পণ্য, একই গুদাম, একই লট; উপকরণের খরচও তার স্তরে।
 */
final class TheBillTakenBackMadeADishOutOfNothingTest extends TestCase
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
        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
    }

    public function test_taking_back_a_cooked_dish_returns_its_ingredients_not_the_dish(): void
    {
        $rice = $this->product('M16-RICE', false);
        $biryani = $this->product('M16-BIRY', false);
        $this->receive($rice, '100', '60');
        $recipe = Recipe::query()->create(['product_id' => $biryani->id, 'kind' => Recipe::TO_ORDER, 'yield_qty' => '10', 'is_active' => true]);
        RecipeLine::query()->create(['recipe_id' => $recipe->id, 'product_id' => $rice->id, 'qty' => '5', 'waste_pct' => '0', 'sort' => 0]);

        $invoice = $this->sell($biryani, '4');
        $this->assertSame('98.0000', $this->floor($rice), 'প্রস্তুতিটাই ভুল — চার প্লেটে দুই কেজি চাল কমার কথা।');

        DB::transaction(fn () => app(SalesInvoiceService::class)->takeBackForEdit($invoice, Carbon::today(), 'M16'));

        $this->assertSame('0.0000', $this->floor($biryani), '⛔ ফিরিয়ে নেওয়া বিল শূন্য থেকে বিরিয়ানির মজুদ বানাল।');
        $this->assertSame('100.0000', $this->floor($rice), '⛔ চাল ফিরল না।');
        $this->assertSame('100.0000', $this->layerQty($rice), '⛔ চালের খরচ তার স্তরে ফিরল না — তাক আর স্তর আলাদা।');
    }

    public function test_taking_back_a_bill_returns_the_goods_to_the_lot_they_left(): void
    {
        $soap = $this->product('M16-SOAP', true);
        $early = app(BatchService::class)->receive($soap, 'M16-A', Carbon::today()->addMonths(2)->toDateString());
        $late = app(BatchService::class)->receive($soap, 'M16-B', Carbon::today()->addMonths(9)->toDateString());
        $this->receive($soap, '10', '40', $early);
        $this->receive($soap, '10', '40', $late);

        $invoice = $this->sell($soap, '6');
        $this->assertSame('4.0000', $this->lot($early), 'প্রস্তুতিটাই ভুল — আগে-মেয়াদের লট A থেকে ছয়টা বেরোনোর কথা।');

        DB::transaction(fn () => app(SalesInvoiceService::class)->takeBackForEdit($invoice, Carbon::today(), 'M16'));

        $this->assertSame('10.0000', $this->lot($early), '⛔ মাল লট A-তে ফিরল না।');
        $this->assertSame('10.0000', $this->lot($late), 'লট B-তে বাড়তি মাল ঢুকল।');
        $this->assertSame('0.0000', $this->lot(null, $soap), '⛔ মাল লটহীন সারিতে ফিরল।');
        $this->assertSame('20.0000', $this->floor($soap));
    }

    /** ⓘ সম্পাদনা দুইবার: ফেরানো, আবার নিশ্চিত, আবার ফেরানো — প্রথম ফেরাটা গোনায় না থাকলে মাল দুইবার ফিরত */
    public function test_a_bill_taken_back_twice_returns_each_lot_once(): void
    {
        $soap = $this->product('M16-TWICE', true);
        $lot = app(BatchService::class)->receive($soap, 'M16-T', Carbon::today()->addMonths(3)->toDateString());
        $this->receive($soap, '10', '40', $lot);

        $service = app(SalesInvoiceService::class);
        $invoice = $this->sell($soap, '6');
        DB::transaction(fn () => $service->takeBackForEdit($invoice, Carbon::today(), 'M16 first'));
        $invoice = $service->confirm($invoice->fresh())->fresh(['lines.product', 'lines.challanLine', 'warehouse']);
        $this->assertSame('4.0000', $this->lot($lot), 'প্রস্তুতিটাই ভুল — আবার নিশ্চিতে ছয়টা আবার বেরোনোর কথা।');

        DB::transaction(fn () => $service->takeBackForEdit($invoice, Carbon::today(), 'M16 second'));

        $this->assertSame('10.0000', $this->lot($lot), '⛔ দ্বিতীয় ফেরানোয় মাল দুইবার ফিরল।');
        $this->assertSame('10.0000', $this->layerQty($soap), '⛔ দ্বিতীয় ফেরানোয় খরচ স্তরে দুইবার ফিরল।');
    }

    private function sell(Product $product, string $qty): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => '250.00']],
        ))->fresh(['lines.product', 'lines.challanLine', 'warehouse']);
    }

    private function product(string $code, bool $lots): Product
    {
        return Product::query()->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code, 'sale_price' => '250',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => $lots,
        ]);
    }

    private function receive(Product $product, string $qty, string $cost, ?Batch $batch = null): void
    {
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening',
            sourceId: $product->id, floor: $qty, batch: $batch);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: $cost, sourceType: 'test.opening',
            sourceId: $product->id, batch: $batch);
    }

    private function floor(Product $product): string
    {
        return app(StockService::class)->floorQty($product->fresh(), $this->warehouse);
    }

    private function lot(?Batch $batch, ?Product $product = null): string
    {
        $q = StockMovement::query()->where('warehouse_id', $this->warehouse->id);
        $q = $batch === null ? $q->whereNull('batch_id')->where('product_id', $product?->id) : $q->where('batch_id', $batch->id);

        return bcadd((string) $q->sum('floor_change'), '0', 4);
    }

    private function layerQty(Product $product): string
    {
        return bcadd((string) CostLayer::query()->where('product_id', $product->id)->sum('qty_remaining'), '0', 4);
    }
}
