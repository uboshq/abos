<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ট্রাকের মাল ঘাটতি হিসেবে গোনা হত — পুরো ERP অডিট ⛔৭, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ বদলি পাঠালে উৎসের তাক কমে না (মাল আটকে থাকে, পৌঁছালে তবেই নামে), অথচ মালটা ট্রাকে। তখন উৎসে গুনলে খাতা বলত তাকে আছে,
 * হাতে মিলত না — মিথ্যা ঘাটতি, আর মেনে নিলে সেটা খরচে। ⭐ এখন পথে থাকা পণ্য উৎসে গোনা যায় না, বার্তায় বদলির নম্বর; পৌঁছানোর
 * পরে খাতা ঠিক সংখ্যা দেখায়। ⓘ গন্তব্যেও গ্রহণ পর্যন্ত গোনা থামে (৯ অক্টোবর ২০২৬): মাল নেমে গেলেও খাতায় নেই।
 */
final class TheTruckWasCountedAsAShortageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $from;

    private Warehouse $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->create(['code' => 'TRUCK-TO', 'name_en' => 'Truck to', 'is_active' => true,
            'branch_id' => $this->from->branch_id]);
    }

    public function test_goods_on_the_way_cannot_be_counted_at_the_source_until_they_arrive(): void
    {
        $rice = $this->stocked('Truck rice', '50');
        $other = $this->stocked('Truck sugar', '20');
        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(
            ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $rice->id, 'qty' => '10']],
        );
        $transfers->dispatch($transfer);

        try {
            $this->countIn($this->from, $rice, '40');
            $this->fail('⛔ ট্রাকের মাল উৎসে গোনা গেল — ১০-এর মিথ্যা ঘাটতি বসত।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $transfer->fresh()->document_no, implode(' ', $e->validator->errors()->all()));
        }

        // ⓘ অন্য পণ্য — কোনো বাধা নেই
        $this->assertSame(0, bccomp((string) $this->countIn($this->from, $other, '20')->lines->first()->difference, '0', 4));

        // ⛔ গন্তব্যেও গ্রহণ পর্যন্ত থামা — নেমে আসা মাল খাতায় নেই, গুনলে মিথ্যা বাড়তি, পরে গ্রহণে দ্বিগুণ
        // (পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬; [[ACountWaitsForATransferFromAnyBranchTest]])
        try {
            $this->countIn($this->to, $rice, '10');
            $this->fail('⛔ গ্রহণের আগে গন্তব্যে গোনা গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $transfer->fresh()->document_no, implode(' ', $e->validator->errors()->all()));
        }

        $transfers->receive($transfer->fresh());
        $this->assertSame(StockTransfer::query()->find($transfer->id)->status, 'closed');

        $line = $this->countIn($this->from, $rice, '40')->lines->first();
        $this->assertSame(0, bccomp((string) $line->book_qty, '40', 4), '⛔ পৌঁছানোর পরে খাতা ৪০ নয়।');
        $this->assertSame(0, bccomp((string) $line->difference, '0', 4), '⛔ পৌঁছানোর পরেও পার্থক্য।');
    }

    private function countIn(Warehouse $warehouse, Product $product, string $qty)
    {
        return app(StockCountService::class)->record(['warehouse_id' => $warehouse->id], [['product_id' => $product->id, 'counted_qty' => $qty]]);
    }

    private function stocked(string $name, string $onHand): Product
    {
        $product = Product::query()->create(['code' => 'TR-'.mb_substr(md5($name.microtime()), 0, 8), 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);

        app(StockService::class)->move(product: $product, warehouse: $this->from, sourceType: 'test.opening', sourceId: $product->id, floor: $onHand);
        app(CostLayerService::class)->receive(product: $product, qty: $onHand, unitCost: '10', sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }
}
