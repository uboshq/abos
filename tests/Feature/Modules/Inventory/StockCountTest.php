<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাল গোনা — খাতায় যা লেখা, তাকে যা সত্যিই আছে।
 *
 * এই উইন্ডোতে কেবল গণনা লেখা ও পার্থক্য বের করা (record) — অনুমোদন-সমন্বয়
 * (টাকার পথ) পরের উইন্ডোতে। তাই এখানকার পরীক্ষাগুলো একটাই প্রশ্ন করে:
 * পার্থক্যটা সৎভাবে গোনা হলো কি না, আর গোনা-হয়নি পণ্যকে ভুল করে শূন্য
 * ধরা হলো কি না।
 */
class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function service(): StockCountService
    {
        return app(StockCountService::class);
    }

    /**
     * একটা পণ্য, গুদামে তার পরিমাণ ও দর।
     *
     * `move()` কেবল পরিমাণ নাড়ে; দরটা FIFO স্তরে বসে, যাতে পার্থক্যের
     * টাকা গোনা যায়।
     */
    private function stocked(string $name, string $onHand, string $unitCost = '10.00'): Product
    {
        $product = Product::query()->create([
            'code' => 'SC-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        if (bccomp($onHand, '0', 4) > 0) {
            app(StockService::class)->move(
                product: $product,
                warehouse: $this->warehouse,
                sourceType: 'test.opening',
                sourceId: $product->id,
                floor: $onHand,
            );

            app(CostLayerService::class)->receive(
                product: $product,
                qty: $onHand,
                unitCost: $unitCost,
                sourceType: 'test.opening',
                sourceId: $product->id,
            );
        }

        return $product;
    }

    public function test_a_matching_count_records_no_variance(): void
    {
        $product = $this->stocked('Rice', '50');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '50']],
        );

        $this->assertSame(DocumentStatus::DRAFT, $count->status);
        $this->assertTrue($count->matches(), 'সব মিলেও পার্থক্য দেখাচ্ছে।');
        $this->assertSame(0, bccomp((string) $count->lines->first()->difference, '0', 4));
    }

    public function test_a_shortage_is_a_negative_difference(): void
    {
        $product = $this->stocked('Sugar', '50');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '47']],
        );

        $line = $count->lines->first();

        $this->assertSame(0, bccomp((string) $line->book_qty, '50', 4));
        $this->assertSame(0, bccomp((string) $line->difference, '-3', 4), 'ঘাটতি ঋণাত্মক পার্থক্য নয়।');
        $this->assertFalse($line->isSurplus());
        $this->assertFalse($count->matches());
    }

    public function test_a_surplus_is_a_positive_difference(): void
    {
        $product = $this->stocked('Salt', '50');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '53']],
        );

        $line = $count->lines->first();

        $this->assertSame(0, bccomp((string) $line->difference, '3', 4));
        $this->assertTrue($line->isSurplus());
    }

    /**
     * ★ সবচেয়ে বিপজ্জনক নিয়ম: গোনা-হয়নি ≠ শূন্য।
     *
     * দুইটা পণ্য গুদামে, গোনা হলো একটা। গণনায় শুধু একটাই লাইন বসবে —
     * না-গোনা পণ্যটার কোনো লাইন নেই, তার মজুদও অক্ষত। এই একটা পরীক্ষা
     * ভাঙলে অনুমোদনে গোটা গুদাম শূন্য হয়ে যেত।
     */
    public function test_an_uncounted_product_gets_no_line_and_stays_untouched(): void
    {
        $counted = $this->stocked('Counted', '10');
        $skipped = $this->stocked('Skipped', '20');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $counted->id, 'counted_qty' => '8']],
        );

        $this->assertCount(1, $count->lines, 'গোনা-হয়নি পণ্যও একটা লাইন পেয়ে গেছে।');
        $this->assertSame($counted->id, $count->lines->first()->product_id);

        // না-গোনা পণ্যের মজুদ record কখনোই ছোঁয় না
        $this->assertSame(
            0,
            bccomp(app(StockService::class)->floorQty($skipped, $this->warehouse), '20', 4),
            'না-গোনা পণ্যের মজুদ বদলে গেছে।',
        );
    }

    public function test_book_qty_is_captured_at_record_time(): void
    {
        $product = $this->stocked('Flour', '75');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '75']],
        );

        // record-এর মুহূর্তের floor লাইনে জমা — চলতি হিসাব নয়
        $this->assertSame(0, bccomp((string) $count->lines->first()->book_qty, '75', 4));
    }

    public function test_variance_value_uses_the_average_cost(): void
    {
        $product = $this->stocked('Oil', '50', unitCost: '10.00');

        $count = $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '45']],
        );

        $line = $count->lines->first();

        // ৫ ইউনিট কম × ৳১০ = ৳৫০, সবসময় ধনাত্মক
        $this->assertNotNull($line->varianceValue());
        $this->assertSame(0, bccomp((string) $line->varianceValue(), '50', 4));
    }

    public function test_the_same_product_twice_is_rejected(): void
    {
        $product = $this->stocked('Tea', '10');

        $this->expectException(ValidationException::class);

        $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [
                ['product_id' => $product->id, 'counted_qty' => '10'],
                ['product_id' => $product->id, 'counted_qty' => '9'],
            ],
        );
    }

    public function test_an_empty_count_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [],
        );
    }

    public function test_a_count_needs_a_warehouse(): void
    {
        $product = $this->stocked('Soap', '10');

        $this->expectException(ValidationException::class);

        $this->service()->record(
            [],
            [['product_id' => $product->id, 'counted_qty' => '10']],
        );
    }

    public function test_a_negative_counted_quantity_is_rejected(): void
    {
        $product = $this->stocked('Rope', '10');

        $this->expectException(ValidationException::class);

        $this->service()->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '-1']],
        );
    }
}
