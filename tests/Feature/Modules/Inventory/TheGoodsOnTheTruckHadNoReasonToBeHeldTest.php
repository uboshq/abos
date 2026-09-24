<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ট্রাকের মালটা আটকানো ছিল, কারণ ছাড়াই।
 *
 * ── ⓘ যা আগে থেকেই ঠিক ছিল ──────────────────────────────────────────
 * স্থানান্তর রওনা হলে মালটা উৎসেই **আটকে** যায়, `floor` কমে না — তাই
 * পথে থাকা মাল কখনো "উধাও" হয় না। ⭐ মালিকের স্পেকের `IN_TRANSIT`
 * অবস্থাটা তাই আলাদা বালতি নয়, আটকানোর একটা কারণ।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * ⚠️ কারণের ঘরটা **ফাঁকা** যেত। আর আটকানোর রিপোর্টের একমাত্র কাজই কারণ
 * আলাদা করা: *"৫ ক্ষতিগ্রস্ত, ৩৫ দাম বাড়ার অপেক্ষায়"*। ⛔ কারণহীন একটা
 * বড় সারি ঠিক সেই কাজটাই নষ্ট করত, আর মালিক ভাবতেন তাঁর মালে সমস্যা —
 * অথচ মালটা কেবল অন্য গুদামের পথে।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. রওনার সারিতে *"অন্য গুদামের পথে"* কারণটা বসে
 *   ২. রিপোর্টে কারণ ধরে সংখ্যাটা পড়া যায়
 *   ৩. মাল পৌঁছে গেলে সংখ্যাটা **শূন্যে** ফেরে
 *   ৪. ট্রাক ফিরে এলেও তাই
 *
 * ⓘ (৩) আর (৪) আসল পাহারা। ⛔ ছাড়ার সারিতে একই কারণ না বসালে যোগফল
 * দুই ভাগ হত — `+৪০ পথে` আর `−৪০ (কারণ নেই)` — আর দুইটাই টিকে থাকত,
 * অর্থাৎ পৌঁছে যাওয়া মাল চিরকাল "পথে" দেখাত।
 *
 * ── ⛔ ডেমোর গুদাম বা মজুদ ধার করা হয় না ─────────────────────────────
 * ⚠️ বীজে ঐ পণ্যের মজুদ আগে থেকেই থাকতে পারে, আর তখন প্রতিটা দাবি
 * বীজের সংখ্যার উপর দাঁড়াত। ⓘ নিজের দুইটা গুদাম মানে শুরুর অবস্থা
 * নিশ্চিতভাবে শূন্য — এই শিক্ষাটা
 * [[TheSameGoodsSatInThreeWarehousesAndTheReportSaidOneNumberTest]]-এ
 * একবার দামি হয়ে এসেছিল।
 */
final class TheGoodsOnTheTruckHadNoReasonToBeHeldTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'HOLD-TRN';

    private User $owner;

    private Product $product;

    private Warehouse $from;

    private Warehouse $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->from = $this->store('TRN-WH-A', 'Transit test store A');
        $this->to = $this->store('TRN-WH-B', 'Transit test store B');

        /*
         * ⓘ লট ছাড়া পণ্য — ⚠️ লট ধরা পণ্যে রওনা দেওয়ার আগে একটা লট
         * বানাতে হত, আর তাতে পরীক্ষাটা লটের নিয়ম মাপত, কারণের নয়।
         */
        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->from,
            sourceType: 'test_seed',
            sourceId: $this->product->id,
            floor: '40',
        );
    }

    // ── ১ · কারণটা সারিতে বসে ────────────────────────────────────────

    public function test_the_dispatch_row_says_why_the_goods_are_held(): void
    {
        $transfer = $this->dispatched();

        $held = StockMovement::query()
            ->where('source_type', StockTransfer::STOCK_SOURCE)
            ->where('source_id', $transfer->id)
            ->where('hold_change', '>', 0)
            ->firstOrFail();

        $this->assertNotNull($held->reason_code_id,
            'ট্রাক ছাড়ল, মালটা আটকাল, অথচ কেন আটকাল সেটা সারিতে নেই — '
            .'আটকানোর রিপোর্টে ওটা কারণহীন একটা সংখ্যা হয়ে বসবে।');

        $this->assertSame(self::CODE,
            ReasonCode::query()->whereKey($held->reason_code_id)->value('code'));
    }

    public function test_the_returning_truck_puts_the_goods_back_on_sale(): void
    {
        /*
         * ⓘ `returns_to_stock` — ⛔ মিথ্যা হলে বাতিল করা স্থানান্তরের
         * মালটা আর বিক্রয়যোগ্য গণ্য হত না, অথচ মালে কোনো দোষ নেই:
         * কেবল ঠিকানা বদলানো থেমে গেছে।
         */
        $this->assertTrue(
            (bool) $this->transitReason()->returns_to_stock,
            'পথের মালটা ফিরে এলে অবিক্রেয় ধরা হচ্ছে।');
    }

    // ── ২ · রিপোর্টে কারণ ধরে পড়া যায় ────────────────────────────────

    public function test_the_hold_report_counts_it_under_its_own_reason(): void
    {
        /*
         * ⛔ পরম সংখ্যা নয়, **পার্থক্য** — ⚠️ বীজে এই পণ্যের আরেকটা
         * স্থানান্তর পথে থাকলে পরম দাবিটা মিথ্যা লাল হত, আর কারণটা
         * খুঁজতে গিয়ে কেউ ভাবতেন কোডে ভুল।
         */
        $before = $this->onTheWayQty();

        $this->dispatched();

        $this->assertSame(0, bccomp(bcsub($this->onTheWayQty(), $before, 4), '10', 4),
            'রওনা হওয়া ১০ কার্টন আটকানোর রিপোর্টে "অন্য গুদামের পথে" '
            .'কারণে যোগ হয়নি — তাহলে "মাল আছে, বেচা যাচ্ছে না কেন" '
            .'প্রশ্নের উত্তরটা অসম্পূর্ণ।');
    }

    // ── ৩ ও ৪ · পৌঁছালে বা ফিরলে শূন্যে ফেরে ─────────────────────────

    public function test_once_the_goods_arrive_they_are_no_longer_on_the_way(): void
    {
        $before = $this->onTheWayQty();

        $transfer = $this->dispatched();

        app(StockTransferService::class)->receive($transfer);

        $this->assertSame(0, bccomp($this->onTheWayQty(), $before, 4),
            'মাল পৌঁছে গেছে, তবু আটকানোর রিপোর্টে সারিটা রয়ে গেছে — '
            .'অর্থাৎ ছাড়ার সারিতে রওনার কারণটা বসেনি, আর দুইটা যোগফল '
            .'কখনো শূন্যে মিলবে না।');
    }

    public function test_a_cancelled_transfer_leaves_nothing_on_the_way(): void
    {
        $before = $this->onTheWayQty();

        $transfer = $this->dispatched();

        app(StockTransferService::class)->cancel($transfer, 'ট্রাক ফিরে এসেছে');

        $this->assertSame(0, bccomp($this->onTheWayQty(), $before, 4),
            'বাতিল হওয়া স্থানান্তরের মালটা এখনো "পথে" দেখাচ্ছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function dispatched(): StockTransfer
    {
        $service = app(StockTransferService::class);

        $transfer = $service->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->to->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '10']],
        );

        return $service->dispatch($transfer);
    }

    private function transitReason(): ReasonCode
    {
        return ReasonCode::query()->where('code', self::CODE)->firstOrFail();
    }

    /**
     * আটকানোর রিপোর্ট অনুযায়ী এই পণ্যের কতটা "পথে"।
     *
     * ⓘ পাতার লেখা নয়, রিপোর্টের সারি পড়া হয় — ⚠️ পাতার হরফে সংখ্যাটা
     * অন্য কোনো কারণেও থাকতে পারত, আর তখন পাহারাটা মিথ্যা সবুজ দেখাত।
     *
     * ⛔ কারণ ধরে ছাঁকা হয়, কেবল পণ্য ধরে নয়: ⚠️ একই পণ্যের কিছু মাল
     * অন্য কারণে আটকানো থাকলে যোগফলটা মিশে যেত, আর দাবিটা ঐ অন্য
     * কারণের সংখ্যার উপর দাঁড়াত।
     */
    private function onTheWayQty(): string
    {
        $label = $this->reasonLabel($this->transitReason());

        $result = app(ReportEngine::class)->run('inventory.hold', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $sum = '0';

        foreach ($result->rows as $row) {
            $row = (array) $row;

            if ((int) ($row['product_id'] ?? 0) === $this->product->id
                && (string) ($row['reason_name'] ?? '') === $label) {
                $sum = bcadd($sum, (string) ($row['held'] ?? '0'), 4);
            }
        }

        return $sum;
    }

    /** কারণের নাম, রিপোর্ট যেভাবে দেখায় ঠিক সেভাবে। */
    private function reasonLabel(ReasonCode $reason): string
    {
        return app()->getLocale() === 'bn' && ($reason->name_bn ?? '') !== ''
            ? (string) $reason->name_bn
            : (string) $reason->name_en;
    }

    private function store(string $code, string $name): Warehouse
    {
        return Warehouse::query()->create([
            'branch_id' => $this->owner->branch_id ?? Branch::query()->firstOrFail()->id,
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);
    }
}
