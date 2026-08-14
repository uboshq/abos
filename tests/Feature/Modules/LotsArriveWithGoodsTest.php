<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * লট মালের সাথে জন্মায়।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * `Batch::create` গোটা প্রকল্পে কোথাও ছিল না। লট **খরচ** করার আটটা
 * ইঞ্জিন লেখা ছিল — FEFO, মেয়াদ আটকানো, MRP সীমা, রিকল — আর তাদের
 * খাওয়ানোর কেউ ছিল না। ক্রয়ের কোনো পথ `batch:` পাঠাত না।
 *
 * পরীক্ষাগুলো পাশ করত কারণ প্রতিটা পরীক্ষা নিজে হাতে একটা `Batch`
 * বানিয়ে নিত। জিনিসটা না থাকলেও।
 *
 * নিচের প্রথম পরীক্ষাটা সেই ফাঁকটাই হাঁটে: একটা ক্রয় করো, তারপর
 * জিজ্ঞেস করো লটটা আছে কি না।
 */
class LotsArriveWithGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Product $tracked;

    private Product $plain;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();

        $products = Product::query()->orderBy('id')->take(2)->get();

        $this->tracked = $products[0];
        $this->tracked->forceFill(['track_batch' => true])->save();

        $this->plain = $products[1];
        $this->plain->forceFill(['track_batch' => false])->save();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function buy(array $line): PurchaseBill
    {
        $bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [$line],
        );

        return app(PurchaseBillService::class)->confirm($bill);
    }

    private function trackedLine(array $extra = []): array
    {
        return [
            'product_id' => $this->tracked->id,
            'qty' => '100',
            'rate' => '20',
            'batch_no' => 'B-2026-01',
            'expiry_date' => now()->addYear()->toDateString(),
            ...$extra,
        ];
    }

    // ── জন্ম ─────────────────────────────────────────────────────

    /** ক্রয় নিশ্চিত হলে লটটা তৈরি হয়, মেয়াদ ও ছাপা দাম নিয়ে। */
    public function test_a_lot_is_born_when_the_goods_are_billed(): void
    {
        $this->buy($this->trackedLine(['mrp' => '25']));

        $batch = Batch::query()->where('product_id', $this->tracked->id)->firstOrFail();

        $this->assertSame('B-2026-01', $batch->batch_no);
        $this->assertSame(now()->addYear()->toDateString(), $batch->expiry_date?->toDateString());
        $this->assertSame(0, bccomp((string) $batch->mrp, '25', 4));
    }

    /** চলাচলের সারিটা ওই লটকেই দেখায় — নাহলে রিকল কিছুই খুঁজে পেত না। */
    public function test_the_movement_points_at_the_lot(): void
    {
        $bill = $this->buy($this->trackedLine());

        $batch = Batch::query()->where('product_id', $this->tracked->id)->firstOrFail();

        $movement = StockMovement::query()
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->firstOrFail();

        $this->assertSame($batch->id, $movement->batch_id);
    }

    /** লটে যা ঢুকল, লটের হিসাবেও তাই। */
    public function test_the_lot_holds_what_came_in(): void
    {
        $this->buy($this->trackedLine(['qty' => '60']));

        $batch = Batch::query()->where('product_id', $this->tracked->id)->firstOrFail();

        $this->assertSame(0, bccomp($batch->balance(), '60', 4));
    }

    /** চালান দিয়ে ঢুকলেও একই — মাল যে পথেই আসুক, লট জন্মায়। */
    public function test_a_lot_is_born_on_the_receipt_too(): void
    {
        $receipt = app(PurchaseReceiptService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->tracked->id,
                'received_qty' => '40',
                'rate' => '20',
                'batch_no' => 'G-77',
                'expiry_date' => now()->addMonths(6)->toDateString(),
            ]],
        );

        app(PurchaseReceiptService::class)->confirm($receipt);

        $batch = Batch::query()->where('batch_no', 'G-77')->firstOrFail();

        $this->assertSame(0, bccomp($batch->balance(), '40', 4));
    }

    // ── একই লট দুইবার ────────────────────────────────────────────

    /**
     * একই লট নম্বরে দ্বিতীয়বার মাল এলে নতুন লট খোলে না।
     *
     * খুললে "এই ব্যাচে কত আছে" প্রশ্নের দুইটা উত্তর হত, আর রিকলে
     * একটা অর্ধেক তালিকা।
     */
    public function test_the_same_lot_number_twice_is_one_lot(): void
    {
        $this->buy($this->trackedLine(['qty' => '50']));
        $this->buy($this->trackedLine(['qty' => '30']));

        $this->assertSame(1, Batch::query()->where('product_id', $this->tracked->id)->count());
        $this->assertSame(
            0,
            bccomp(Batch::query()->where('product_id', $this->tracked->id)->firstOrFail()->balance(), '80', 4),
        );
    }

    /**
     * দ্বিতীয়বার মেয়াদ বা ছাপা দাম নিঃশব্দে বদলায় না।
     *
     * মেয়াদ পিছিয়ে দিলে মেয়াদোত্তীর্ণ মাল আবার বিক্রয়যোগ্য হয়ে যেত,
     * আর MRP বাড়ালে পুরনো প্যাকেট বেআইনি দামে বেচা যেত। দুইটাই
     * বদলানোর নিজস্ব পথ আছে, আর দুইটাতেই কারণ ও অডিট লাগে।
     */
    public function test_a_second_arrival_does_not_quietly_move_the_expiry(): void
    {
        $first = now()->addYear()->toDateString();

        $this->buy($this->trackedLine(['expiry_date' => $first, 'mrp' => '25']));
        $this->buy($this->trackedLine(['expiry_date' => now()->addYears(3)->toDateString(), 'mrp' => '40']));

        $batch = Batch::query()->where('product_id', $this->tracked->id)->firstOrFail();

        $this->assertSame($first, $batch->expiry_date?->toDateString());
        $this->assertSame(0, bccomp((string) $batch->mrp, '25', 4));
    }

    // ── যে পণ্যে লট নেই ───────────────────────────────────────────

    /** লট ধরা নয় এমন পণ্যে কোনো লট তৈরি হয় না — চাল-ডাল-সাবান। */
    public function test_an_untracked_product_gets_no_lot(): void
    {
        $this->buy(['product_id' => $this->plain->id, 'qty' => '10', 'rate' => '5']);

        $this->assertSame(0, Batch::query()->where('product_id', $this->plain->id)->count());
    }

    // ── নম্বর ছাড়া ────────────────────────────────────────────────

    /**
     * লট ধরা পণ্যে নম্বর ছাড়া মাল ঢোকানো যায় না।
     *
     * ঢুকতে দিলে মালটা "কোন লট জানা নেই" অবস্থায় বসত, আর পরে বেচতে
     * গেলে ব্যবস্থা বলত লটে যথেষ্ট নেই — আজ ঠিক সেটাই ঘটত, কারণ
     * কোনো পথই লট বানাত না।
     */
    public function test_a_tracked_product_cannot_arrive_without_a_lot_number(): void
    {
        $this->expectException(ValidationException::class);

        $this->buy($this->trackedLine(['batch_no' => '']));
    }
}
