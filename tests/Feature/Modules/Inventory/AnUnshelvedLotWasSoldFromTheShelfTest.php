<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * তাকে-না-তোলা লটের মাল তাক থেকে বিক্রি হয় না — ২৯ সেপ্টেম্বর ২০২৬ (অডিট)।
 *
 * ── ⛔ সন্দেহ ─────────────────────────────────────────────────────────────
 * `Batch::balance()` যোগ করে তাকের মাল আর তোলার-অপেক্ষার মাল একসাথে
 * (`floor_change + unplaced_change`)। ⚠️ বিক্রি তাক থেকে কাটে, আর পুরো পণ্যের
 * পাহারা দেখে সব লটের মোট তাক — অন্য লটের মাল থাকলে সেটা পার হয়ে যায়। ফলে যে
 * লটের সব মাল এখনো অপেক্ষায়, তার তাক ঋণাত্মক হয়: মজুদ এমন মাল দেখায় যা
 * তাকে নেই, আর পরের বাছাই ভুল লট দেয়।
 */
class AnUnshelvedLotWasSoldFromTheShelfTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $waiting;

    private Batch $shelved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'UNS-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Unshelved probe',
            'name_bn' => 'তাকে-না-তোলা নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => true,
        ]);

        // ⓘ লট A আগে মেয়াদ-শেষ (পুরনোটা আগে নিয়মে সে-ই প্রথম), কিন্তু তার সব মাল এখনো তোলার অপেক্ষায়
        $this->waiting = Batch::query()->create([
            'product_id' => $this->product->id, 'batch_no' => 'LOT-WAIT', 'expiry_date' => now()->addMonths(2)->toDateString(),
        ]);
        $this->shelved = Batch::query()->create([
            'product_id' => $this->product->id, 'batch_no' => 'LOT-SHELF', 'expiry_date' => now()->addMonths(8)->toDateString(),
        ]);

        $stock = app(StockService::class);
        $stock->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test.receipt', sourceId: 1, unplaced: '10', batch: $this->waiting);
        $stock->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: 2, floor: '10', batch: $this->shelved);

        $this->assertSame(0, bccomp($this->lotFloor($this->waiting), '0', 4), 'প্রস্তুতিটাই ভুল — অপেক্ষার লট তাকে আছে।');
    }

    /** বাছা লট — তাকে ০, তাই ৫ বিক্রি ফেরানো উচিত। */
    public function test_a_chosen_lot_with_nothing_on_the_shelf_is_refused(): void
    {
        $refused = null;

        try {
            app(StockService::class)->issue(
                product: $this->product, warehouse: $this->warehouse,
                sourceType: 'test.sale', sourceId: 10, qty: '5', batch: $this->waiting,
            );
        } catch (ValidationException $e) {
            $refused = $e->errors()['qty'][0] ?? '';
        }

        $this->assertNotNull($refused, '⛔ তাকে-না-তোলা লট থেকে ৫ বিক্রি হয়ে গেল।');
        // ⓘ কারণটাও ঠিক — "তাকে তোলা হয়নি", "লট খালি" নয়; করণীয় আলাদা
        $this->assertSame(__('inventory::validation.chosen_lot_not_shelved', ['lot' => 'LOT-WAIT', 'available' => '0', 'waiting' => '10']), $refused);
        $this->assertSame(0, bccomp($this->lotFloor($this->waiting), '0', 4),
            '⛔ অপেক্ষার লটের তাক '.$this->lotFloor($this->waiting).' — ঋণাত্মক হওয়ার কথা নয়।');
    }

    /** লট না বলে — পুরনোটা আগে নিয়মেও তাকের মালই যায়, অপেক্ষার লট নয়। */
    public function test_the_automatic_pick_takes_shelved_goods_not_waiting_ones(): void
    {
        app(StockService::class)->issue(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test.sale', sourceId: 11, qty: '5',
        );

        $this->assertSame(0, bccomp($this->lotFloor($this->waiting), '0', 4),
            '⛔ অপেক্ষার লটের তাক '.$this->lotFloor($this->waiting).' — বাছাই তাকে-না-তোলা মাল দিয়েছে।');
        $this->assertSame(0, bccomp($this->lotFloor($this->shelved), '5', 4),
            '⛔ তাকের লটে ৫ থাকার কথা, আছে '.$this->lotFloor($this->shelved).'।');
    }

    private function lotFloor(Batch $batch): string
    {
        return (string) StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');
    }
}
