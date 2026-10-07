<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "ছাড়ো" বোতাম যেকোনো আটকানো মাল ছেড়ে দিত — অডিট গ১, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * সীমা ছিল গুদামে পণ্যের মোট আটকানো। "দাম বাড়ার অপেক্ষা" কারণে ছাড়তে গিয়ে আসলে ছাড়া যেত পথের ট্রাকের মাল
 * (`HOLD-TRN`) বা পরিদর্শনে বাতিল মাল (`HOLD-REJ`) — আর পরদিন সেই মাল কাউন্টারে।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. এক কারণে যতটা আটকানো, তার বেশি সেই কারণে ছাড়া যায় না
 *   ২. পর্দা থেকে পথের আর বাতিলের কারণে ছাড়া যায় না
 *   ৩. শূন্য বা ঋণাত্মক "ছাড়" চলে না (সেটা আসলে যাচাইহীন আটকানো)
 *   ৪. ঠিক কারণে ঠিক পরিমাণে ছাড়া এখনো চলে
 */
final class TheReleaseButtonFreedAnyHeldStockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'G1-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Held for a reason',
            'name_bn' => 'কারণসহ আটকানো',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $stock = app(StockService::class);
        $stock->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test_opening', sourceId: $this->product->id, floor: '100');

        // ⓘ ১০ দামের অপেক্ষায় (পর্দার আটকানো), ২০ পথে (স্থানান্তরের নিজের কাগজে)
        $stock->hold(product: $this->product, warehouse: $this->warehouse, qty: '10', reason: $this->reason('HOLD-PRICE'));
        $stock->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: 'stock_transfer', sourceId: 7701, hold: '20', reason: $this->reason('HOLD-TRN'));
    }

    // ── ১ · কারণের সীমা ──────────────────────────────────────────────

    public function test_a_reason_releases_no_more_than_it_holds(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(StockService::class)->release(
                product: $this->product,
                warehouse: $this->warehouse,
                qty: '25',
                reason: $this->reason('HOLD-PRICE'),
            );
        } finally {
            $this->assertSame(0, bccomp($this->held(), '30', 4),
                'দামের অপেক্ষার ১০-এর নামে ২৫ ছাড়া হয়ে গেছে — বাকি ১৫ ছিল পথের ট্রাকের মাল।');
        }
    }

    // ── ২ · পর্দা থেকে নিষিদ্ধ কারণ ───────────────────────────────────

    public function test_the_screen_cannot_release_goods_on_the_way(): void
    {
        $this->releaseOnScreen('HOLD-TRN', '5')->assertSessionHasErrors('reason_code_id');

        $this->assertSame(0, bccomp($this->held(), '30', 4),
            'পথের মাল পর্দা থেকে ছাড়া হয়ে গেছে — ট্রাক পৌঁছালে উৎসের মজুদ ঋণাত্মক হত।');
    }

    public function test_the_screen_cannot_release_rejected_goods(): void
    {
        app(StockService::class)->hold(product: $this->product, warehouse: $this->warehouse,
            qty: '5', reason: $this->reason('HOLD-REJ'));

        $this->releaseOnScreen('HOLD-REJ', '5')->assertSessionHasErrors('reason_code_id');

        $this->assertSame(0, bccomp($this->held(), '35', 4),
            'পরিদর্শনে বাতিল মাল পর্দা থেকে ছাড়া হয়ে গেছে — পরদিন সেটা কাউন্টারে বিক্রি হত।');
    }

    // ── ৩ · শূন্য বা ঋণাত্মক নয় ──────────────────────────────────────

    public function test_a_negative_release_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(StockService::class)->release(
                product: $this->product,
                warehouse: $this->warehouse,
                qty: '-5',
                reason: $this->reason('HOLD-PRICE'),
            );
        } finally {
            $this->assertSame(0, bccomp($this->held(), '30', 4),
                'ঋণাত্মক "ছাড়" আসলে যাচাই ছাড়া আরও আটকে দিয়েছে।');
        }
    }

    // ── ৪ · ঠিক পথ খোলা ──────────────────────────────────────────────

    public function test_the_right_reason_still_releases_on_screen(): void
    {
        $this->releaseOnScreen('HOLD-PRICE', '10')->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->held(), '20', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function releaseOnScreen(string $code, string $qty): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.release'), [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'reason_code_id' => $this->reason($code)->id,
                'qty' => $qty,
            ]);
    }

    private function held(): string
    {
        return app(StockService::class)->holdQty($this->product, $this->warehouse);
    }

    private function reason(string $code): ReasonCode
    {
        return ReasonCode::query()->where('code', $code)->where('context', ReasonCode::HOLD)->firstOrFail();
    }
}
