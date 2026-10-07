<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কাউন্টার এমন মাল বেচত যা অন্যের জন্য রাখা — অডিট গ১১, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * চালান নিশ্চিত হওয়ার সময় [[StockService::move()]] দেখত কেবল তাক (`floor`)। অন্য ডিলারের DO-র জন্য
 * সংরক্ষিত মাল, পরিদর্শনে বাতিল হয়ে আটকানো মাল, স্থানান্তরের ট্রাকে ওঠা মাল — সবই তাকে, তাই সবই বিক্রি।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. অন্যের সংরক্ষিত মাল চালানে বেরোয় না
 *   ২. আটকানো মাল চালানে বেরোয় না
 *   ৩. এই বিক্রিরই নিজের সংরক্ষণ (DO-র আটকানো) নিজের চালানকে থামায় না
 *   ৪. পাওয়া যায় যতটা, ততটা দিব্যি বেরোয় — পাহারা বেশি কড়া নয়
 */
final class TheCounterSoldWhatWasPromisedElsewhereTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'G11-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Promised elsewhere',
            'name_bn' => 'অন্যের জন্য রাখা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        // তাকে ১০
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $this->product->id,
            floor: '10',
        );
    }

    // ── ১ · অন্যের সংরক্ষণ ───────────────────────────────────────────

    public function test_goods_reserved_for_another_order_do_not_leave_on_a_challan(): void
    {
        // ⓘ অন্য ডিলারের DO ৮ ধরে রেখেছে — পাওয়া যায় ২
        $this->reserveFor('delivery_order', 991, '8');

        $this->assertRefused('5');

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($this->product, $this->warehouse), '10', 4),
            'অন্যের জন্য রাখা মাল চালানে বেরিয়ে গেছে — DO-র ডিলার পরে এসে খালি তাক পাবেন।');
    }

    // ── ২ · আটকানো মাল ───────────────────────────────────────────────

    public function test_held_goods_do_not_leave_on_a_challan(): void
    {
        app(StockService::class)->hold(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '7',
            reason: ReasonCode::query()->where('code', 'HOLD-REJ')->firstOrFail(),
        );

        $this->assertRefused('4');
    }

    // ── ৩ · নিজের সংরক্ষণ ────────────────────────────────────────────

    public function test_the_sales_own_reservation_does_not_stop_its_own_challan(): void
    {
        // ⓘ এই DO-ই ৮ ধরে রেখেছে, আর চালানটা সেই DO-র — নিজের আটকানো নিজেকে থামায় না
        $this->reserveFor('delivery_order', 992, '8');

        $challan = $this->draft('8');

        app(DeliveryChallanService::class)->confirm($challan, '0', [['delivery_order', 992]]);

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($this->product, $this->warehouse), '2', 4));
    }

    // ── ৪ · যা পাওয়া যায় তা যায় ─────────────────────────────────────

    public function test_what_is_available_still_leaves(): void
    {
        $this->reserveFor('delivery_order', 993, '8');

        app(DeliveryChallanService::class)->confirm($this->draft('2'));

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($this->product, $this->warehouse), '8', 4),
            'পাওয়া যায় ঠিক ২, অথচ ২-এর চালানও আটকে গেছে — পাহারা বেশি কড়া।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function reserveFor(string $sourceType, int $sourceId, string $qty): void
    {
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: $sourceType,
            sourceId: $sourceId,
            reserved: $qty,
        );
    }

    private function draft(string $qty): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => $qty, 'rate' => '100']],
        );
    }

    private function assertRefused(string $qty): void
    {
        $challan = $this->draft($qty);

        try {
            app(DeliveryChallanService::class)->confirm($challan);
            $this->fail("তাকে আছে কিন্তু পাওয়া যায় না এমন মাল চালানে বেরিয়ে গেছে ({$qty})।");
        } catch (ValidationException) {
            // আশা করাই হচ্ছিল
        }

        $this->assertSame(DeliveryChallan::query()->find($challan->id)?->status, 'draft');
    }
}
