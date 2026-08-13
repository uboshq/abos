<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিক্রয় লট ধরে বেরোয় — ইঞ্জিন ১, ২ ও ৫ একসাথে।
 *
 * ── কেন তিনটা এক টেস্টে ──────────────────────────────────────────────
 * আলাদা করা যায় না। ব্যাচ স্টকে না বসলে FEFO-র বাছার কিছু নেই, আর
 * FEFO না ডাকলে মেয়াদ আটকানোরও কিছু নেই। তিনটার কোড আলাদা, কিন্তু
 * তিনটা একসাথে না চললে একটাও কাজ করে না।
 *
 * ── এর আগে যা ছিল ────────────────────────────────────────────────────
 * BatchAllocator আর Batch দুইটারই কোড ছিল, তাদের নিজেদের টেস্টও ছিল,
 * সব সবুজ। অথচ `BatchAllocator` গ্রেপ করলে নিজের ফাইল ছাড়া কেউ তার নাম
 * নিত না — অর্থাৎ একটা মেয়াদোত্তীর্ণ ওষুধ বেচলে ব্যবস্থা কিছুই বলত না।
 * এই টেস্টটা ঠিক সেই ফাঁকটা পাহারা দেয়।
 */
class BatchOnSaleTest extends TestCase
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

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();
    }

    /** একটা লট, আর তাতে কিছু মাল — খোলা মজুদের মতো করে। */
    private function lot(string $batchNo, ?string $expiry, string $qty): Batch
    {
        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
            'mrp' => '120',
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $batch->id,
            floor: $qty,
            batch: $batch,
        );

        return $batch;
    }

    private function sell(string $qty): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => $qty, 'rate' => '100']],
        );

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /** এই চালানে কোন লট থেকে কতটা গেল। */
    private function issued(DeliveryChallan $challan): array
    {
        return StockMovement::query()
            ->where('source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('source_id', $challan->id)
            ->with('batch')
            ->get()
            ->mapWithKeys(fn (StockMovement $m) => [
                $m->batch?->batch_no ?? '(লট নেই)' => (string) $m->floor_change,
            ])
            ->all();
    }

    // ── FEFO ─────────────────────────────────────────────────────

    /**
     * তিন মেয়াদের তিন লট — আগে-মেয়াদ-শেষটা নিজে থেকে বেরোয়।
     *
     * ক্যাশিয়ারকে বাছতে বলা হয় না। বললে তিনি প্রতিবার প্রথমটাই বাছতেন,
     * আর পুরনো পাতাগুলো তাকেই মেয়াদ পার করত।
     */
    public function test_the_lot_that_expires_first_leaves_first(): void
    {
        $this->lot('LATE', now()->addMonths(12)->toDateString(), '50');
        $this->lot('SOON', now()->addMonths(2)->toDateString(), '50');
        $this->lot('MID', now()->addMonths(6)->toDateString(), '50');

        $challan = $this->sell('10');

        $this->assertSame(['SOON' => '-10.0000'], $this->issued($challan));
    }

    /**
     * এক লটে না কুলালে পরেরটা থেকে বাকিটা।
     *
     * একটা লট বেছে "যথেষ্ট নেই" বললে ক্যাশিয়ার হাতে অন্য লট বাছতেন —
     * আর আবার সেই প্রথমটাই।
     */
    public function test_a_short_lot_is_topped_up_from_the_next(): void
    {
        $this->lot('SOON', now()->addMonths(2)->toDateString(), '3');
        $this->lot('LATE', now()->addMonths(9)->toDateString(), '20');

        $challan = $this->sell('5');

        $this->assertSame(
            ['SOON' => '-3.0000', 'LATE' => '-2.0000'],
            $this->issued($challan),
        );
    }

    /** মেয়াদহীন লট সবার শেষে — ওগুলোর তাড়া নেই। */
    public function test_a_lot_without_an_expiry_waits_its_turn(): void
    {
        $this->lot('NONE', null, '50');
        $this->lot('DATED', now()->addMonths(10)->toDateString(), '50');

        $challan = $this->sell('4');

        $this->assertSame(['DATED' => '-4.0000'], $this->issued($challan));
    }

    // ── মেয়াদ ────────────────────────────────────────────────────

    /**
     * মেয়াদোত্তীর্ণ লট বিক্রয়ে আসেই না।
     *
     * এটাই ইঞ্জিন ৫-এর পুরো চুক্তি। না থাকলে আজকের ABOS একটা মেয়াদ
     * পেরোনো ওষুধ চুপচাপ বেচে দিত।
     */
    public function test_an_expired_lot_is_not_sold(): void
    {
        $this->lot('DEAD', now()->subDay()->toDateString(), '50');
        $this->lot('GOOD', now()->addMonths(6)->toDateString(), '50');

        $challan = $this->sell('10');

        $this->assertSame(['GOOD' => '-10.0000'], $this->issued($challan));
    }

    /**
     * সব লট মেয়াদোত্তীর্ণ হলে বিক্রয় হয় না।
     *
     * তাকে মাল আছে, খাতাতেও আছে — তবু বেচা যায় না, আর সেটাই ঠিক।
     */
    public function test_a_sale_of_only_expired_stock_is_refused(): void
    {
        $this->lot('DEAD', now()->subDay()->toDateString(), '50');

        $this->expectException(ValidationException::class);

        $this->sell('1');
    }

    // ── যোগফল ────────────────────────────────────────────────────

    /**
     * প্রতিটা লটের যোগফল = পণ্যের মোট মজুদ।
     *
     * ইঞ্জিন ১-এর চুক্তি। দুইটা আলাদা হলে রিকলের খাতা মিথ্যা।
     */
    public function test_the_lots_add_up_to_the_products_stock(): void
    {
        $a = $this->lot('A', now()->addMonths(3)->toDateString(), '30');
        $b = $this->lot('B', now()->addMonths(8)->toDateString(), '20');

        $this->sell('35');

        $lots = bcadd($a->fresh()->balance($this->warehouse), $b->fresh()->balance($this->warehouse), 4);

        /*
         * তুলনাটা লট-ধরা চলাচলের সাথে, পণ্যের মোটের সাথে নয়।
         *
         * প্রথমবার মোটের সাথে মিলিয়েছিলাম আর ১২০ ইউনিটের পার্থক্য
         * পেয়েছিলাম — ডেমো সিডারের পুরনো মাল, যেগুলো লট চালু হওয়ার
         * আগে তাকে উঠেছিল। ওই ১২০ ভুল নয়, আর যোগফলের চুক্তিটাও ভাঙেনি:
         * চুক্তিটা লট-ধরা মালের, আর লট ছাড়া মাল লট ছাড়াই থাকে।
         *
         * ওই পুরনো মালের কী হয়, সেটা নিচের আলাদা টেস্টে।
         */
        $batched = (string) StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->whereNotNull('batch_id')
            ->sum('floor_change');

        $this->assertSame(0, bccomp($lots, $batched, 4), "লটের যোগফল {$lots}, ব্যাচ-ধরা মোট {$batched}");
        $this->assertSame(0, bccomp('15', $lots, 4));
    }

    /**
     * লট চালু করার আগের মাল লট ছাড়াই তাকে থেকে যায় — আর বিক্রি হয় না।
     *
     * ── এটা বাগ নয়, কিন্তু ফাঁদ ─────────────────────────────────────
     * ব্যবস্থার পক্ষে জানার উপায় নেই তাকের ওই ১২০ পিস কোন লটের। ধরে
     * নিয়ে যেকোনো লটে বসিয়ে দিলে রিকলের খাতা মিথ্যা হত — আর রিকলের
     * খাতা মিথ্যা মানে ভুল ক্রেতার কাছে ফোন যাওয়া, বা ঠিক ক্রেতার কাছে
     * ফোন না যাওয়া।
     *
     * তাই ব্যবস্থা বেচতে অস্বীকার করে, আর সেটাই নিরাপদ পথ। কিন্তু
     * দোকানির চোখে মজুদ ১২০ দেখাচ্ছে অথচ বিক্রি হচ্ছে না — তাই বার্তাটা
     * আলাদা, নাহলে তিনি "লটে যথেষ্ট নেই" পড়ে ভাবতেন হিসাব ভুল।
     *
     * ঠিক করার পথ: খোলা মজুদের পর্দায় ওই মালটা লট ধরে আবার বসানো।
     */
    public function test_stock_that_predates_lot_tracking_says_so_plainly(): void
    {
        $bare = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();
        $bare->forceFill(['track_batch' => true])->save();

        $onShelf = (string) StockMovement::query()
            ->where('product_id', $bare->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');

        try {
            app(DeliveryChallanService::class)->confirm(
                app(DeliveryChallanService::class)->create(
                    [
                        'customer_id' => $this->customer->id,
                        'warehouse_id' => $this->warehouse->id,
                        'trx_date' => now()->toDateString(),
                    ],
                    [['product_id' => $bare->id, 'delivered_qty' => '1', 'rate' => '100']],
                ),
            );

            $this->fail('লট ছাড়া মাল বিক্রি হয়ে গেল');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('inventory::validation.batch_untracked_stock', [
                    'product' => $bare->name(),
                    'qty' => rtrim(rtrim($onShelf, '0'), '.'),
                ]),
                $e->validator->errors()->first(),
            );
        }
    }

    // ── বাতিল ────────────────────────────────────────────────────

    /**
     * বাতিলে মাল ফেরে যে লট থেকে বেরিয়েছিল সেই লটেই।
     *
     * লাইন ধরে নতুন করে গুনলে FEFO আজকের অবস্থা ধরে অন্য লট বাছত, আর
     * মাল ফিরত এমন বাক্সে যেখান থেকে কখনো বেরোয়ইনি।
     */
    public function test_cancelling_returns_the_goods_to_the_same_lots(): void
    {
        $soon = $this->lot('SOON', now()->addMonths(2)->toDateString(), '3');
        $late = $this->lot('LATE', now()->addMonths(9)->toDateString(), '20');

        $challan = $this->sell('5');

        app(DeliveryChallanService::class)->cancel($challan, 'পরীক্ষা');

        $this->assertSame(0, bccomp('3', $soon->fresh()->balance($this->warehouse), 4));
        $this->assertSame(0, bccomp('20', $late->fresh()->balance($this->warehouse), 4));
    }

    // ── লট ছাড়া পণ্য ─────────────────────────────────────────────

    /**
     * লট ধরা নেই এমন পণ্যে সবকিছু আগের মতোই।
     *
     * ডিপোর চাল-ডাল-সাবানে লট নেই আর হবেও না। সবাইকে লট দিয়ে বের করতে
     * বললে ওই পণ্যগুলোর প্রতিটা বিক্রয় "লটে যথেষ্ট নেই" বলে ফিরে যেত —
     * একটা ফার্মেসি-সুবিধা চালু করলে গোটা ডিপো বন্ধ।
     */
    public function test_a_product_without_lots_sells_exactly_as_before(): void
    {
        $plain = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();
        $plain->forceFill(['track_batch' => false])->save();

        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $plain->id, 'delivered_qty' => '2', 'rate' => '100']],
        );

        app(DeliveryChallanService::class)->confirm($challan);

        $rows = StockMovement::query()
            ->where('source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('source_id', $challan->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->batch_id);
    }
}
