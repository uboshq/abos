<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফ্রি মাল ঢোকার পথ — আর বাতিলে ফেরার পথ।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * ফ্রি পণ্যের নিজস্ব ভাণ্ডার ছিল, বেরোনোর পথ ছিল, **ঢোকার পথ ছিল না**।
 * ক্রয়ের পর্দায় "ফ্রি" ঘরটা ছিল, সেবা পর্যন্ত সংখ্যাটা পৌঁছাতও —
 * তারপর নিঃশব্দে হারাত, কারণ লাইনে কলামই ছিল না।
 *
 * টেস্ট ধরেনি: `DirectSaleTest::receiveFree()` নিজেই হাতে ভাণ্ডারটা ভরে
 * নিত। জিনিসটা না থাকলেও পরীক্ষা পাশ করত।
 *
 * আর একই জায়গায় দ্বিতীয় একটা ফাঁক পাওয়া গেল: সরাসরি ক্রয় বিল বাতিল
 * করলে খতিয়ান ফিরত, **মাল ফিরত না** — মজুদ ও হিসাব আলাদা হয়ে যেত।
 */
class FreeGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function freeQty(): string
    {
        return $this->stock()->freeQty($this->product, $this->warehouse);
    }

    private function floorQty(): string
    {
        return $this->stock()->floorQty($this->product, $this->warehouse);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function bill(array $lines): PurchaseBill
    {
        return app(PurchaseBillService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            $lines,
        );
    }

    private function line(string $qty = '100', string $free = '10', string $rate = '50'): array
    {
        return ['product_id' => $this->product->id, 'qty' => $qty, 'free_qty' => $free, 'rate' => $rate];
    }

    // ── বিল দিয়ে ঢোকা ────────────────────────────────────────────

    /**
     * "১০০ কিনলে ১০ ফ্রি" — দুইটাই গুদামে ঢোকে, আলাদা ভাণ্ডারে।
     *
     * এটাই সেই পরীক্ষা যা আগে লেখা যেত না: ফ্রি মাল ঢোকানোর কোনো পথ
     * ছিল না বলে সংখ্যাটা সবসময় শূন্য থাকত।
     */
    public function test_free_goods_arrive_with_the_bill(): void
    {
        $floorBefore = $this->floorQty();
        $freeBefore = $this->freeQty();

        app(PurchaseBillService::class)->confirm($this->bill([$this->line()]));

        $this->assertSame(0, bccomp($this->floorQty(), bcadd($floorBefore, '100', 4), 4));
        $this->assertSame(0, bccomp($this->freeQty(), bcadd($freeBefore, '10', 4), 4));
    }

    /**
     * ফ্রি মালের কোনো ক্রয়মূল্য নেই।
     *
     * গড় দরে মিশিয়ে দিলে প্রতিটা বিক্রির খরচ একটু করে কমত আর মুনাফা
     * বেশি দেখাত — ভাণ্ডারটা আলাদা রাখার মূল কারণই এটা।
     */
    public function test_free_goods_carry_no_cost(): void
    {
        // ডেমো ডাটায় এই পণ্যের আগে থেকেই স্তর আছে, তাই বাড়তিটা মাপা
        // হয় — মোট নয়। মোট ধরলে পরীক্ষাটা সিডারের সংখ্যার উপর নির্ভর
        // করত, আর সিডার বদলালেই মিথ্যা ভাঙত।
        $before = app(CostLayerService::class)->valueOnHand($this->product);

        app(PurchaseBillService::class)->confirm($this->bill([$this->line(qty: '100', free: '10', rate: '50')]));

        $added = bcsub(app(CostLayerService::class)->valueOnHand($this->product), $before, 4);

        // ১০০ × ৫০ = ৫০০০। ফ্রি ১০টার দাম ধরলে হত ৫৫০০, আর তখন প্রতিটা
        // বিক্রির খরচ একটু করে কমে গিয়ে মুনাফা বেশি দেখাত।
        $this->assertSame(0, bccomp($added, '5000', 4), 'ফ্রি মালের দাম স্তরে বসেছে — বসার কথা নয়');
    }

    /** ফ্রি না দিলে ভাণ্ডার নড়ে না। */
    public function test_a_bill_without_free_goods_leaves_the_pool_alone(): void
    {
        $before = $this->freeQty();

        app(PurchaseBillService::class)->confirm($this->bill([$this->line(free: '0')]));

        $this->assertSame(0, bccomp($this->freeQty(), $before, 4));
    }

    /** ঋণাত্মক ফ্রি পরিমাণ নেওয়া হয় না, আর বার্তাটা পরিমাণের ঘরের কথা বলে। */
    public function test_a_negative_free_quantity_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->bill([$this->line(free: '-5')]);
    }

    // ── চালান দিয়ে ঢোকা ──────────────────────────────────────────

    /**
     * GRN থাকলে ফ্রি মাল সেখানেই ঢোকে।
     *
     * যে ডিপো চালান ব্যবহার করে তাদের ফ্রি মাল বিলের অপেক্ষায় বসে
     * থাকতে পারে না — কার্টনটা গাড়িতে একসাথেই আসে।
     */
    public function test_free_goods_arrive_with_the_receipt(): void
    {
        $freeBefore = $this->freeQty();

        $receipt = app(PurchaseReceiptService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'received_qty' => '50', 'free_qty' => '5', 'rate' => '40']],
        );

        app(PurchaseReceiptService::class)->confirm($receipt);

        $this->assertSame(0, bccomp($this->freeQty(), bcadd($freeBefore, '5', 4), 4));
    }

    // ── বাতিল ────────────────────────────────────────────────────

    /**
     * বিল বাতিল করলে মালটাও ফেরে — খতিয়ানের সাথে সাথেই।
     *
     * আগে কেবল খতিয়ান ফিরত। মাল গুদামে থেকে যেত আর তার ক্রয়মূল্য
     * স্তরে বসে থাকত, অর্থাৎ মজুদের খাতা আর হিসাবের খাতা আলাদা হয়ে
     * যেত — অথচ কোনো ভুল দেখাত না।
     */
    public function test_cancelling_a_bill_takes_the_goods_back(): void
    {
        $floorBefore = $this->floorQty();
        $freeBefore = $this->freeQty();

        $bill = app(PurchaseBillService::class)->confirm($this->bill([$this->line()]));

        app(PurchaseBillService::class)->cancel($bill, 'ভুল সরবরাহকারী');

        $this->assertSame(0, bccomp($this->floorQty(), $floorBefore, 4), 'বিক্রয়ের মাল ফেরেনি');
        $this->assertSame(0, bccomp($this->freeQty(), $freeBefore, 4), 'ফ্রি মাল ফেরেনি');
        $this->assertSame(DocumentStatus::CANCELLED, $bill->fresh()->status);
    }

    /** বাতিলে ক্রয়মূল্যও স্তর থেকে ওঠে। */
    public function test_cancelling_a_bill_withdraws_its_cost(): void
    {
        $valueBefore = app(CostLayerService::class)->valueOnHand($this->product);

        $bill = app(PurchaseBillService::class)->confirm($this->bill([$this->line()]));
        app(PurchaseBillService::class)->cancel($bill, 'ভুল সরবরাহকারী');

        $this->assertSame(0, bccomp(app(CostLayerService::class)->valueOnHand($this->product), $valueBefore, 4));
    }

    /**
     * মাল বেরিয়ে গেলে বিল বাতিল করা যায় না।
     *
     * "তাকে যা নেই তা বের করা যায় না" — StockService নিজেই আটকায়, আর
     * সেটাই ঠিক: আগে বিক্রয়টা ফেরাতে হবে, তারপর বিল।
     */
    public function test_a_bill_whose_free_goods_are_gone_cannot_be_cancelled(): void
    {
        $bill = app(PurchaseBillService::class)->confirm($this->bill([$this->line(free: '10')]));

        // ফ্রি মালটা বেরিয়ে গেল
        $this->stock()->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_giveaway',
            sourceId: 1,
            free: '-10',
        );

        $this->expectException(ValidationException::class);

        app(PurchaseBillService::class)->cancel($bill, 'দেরি হয়ে গেছে');
    }
}
