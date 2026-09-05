<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ষাট কার্টন আজ, বাকি চল্লিশ পরের সপ্তাহে।
 *
 * ── মালিকের নির্দেশ (৫ সেপ্টেম্বর ২০২৬) ──────────────────────────────
 * *"Parsial Bill hole setaw dite hobe"* — এক আদেশের বিরুদ্ধে একাধিক
 * বিল, আর প্রতিটা বিল জানে কতটা বাকি রইল।
 *
 * ── ⛔ যা আজ পর্যন্ত ভাঙা ছিল ────────────────────────────────────────
 * `resolveOrderLine()` চারটা জিনিস দেখত — কোম্পানি · সরবরাহকারী ·
 * আদেশের অবস্থা · পণ্য — কিন্তু **পরিমাণ দেখত না**। তাই ১০০ কার্টনের
 * আদেশে ৬০ + ৫০ = ১১০ বিল করে ফেলা যেত, আর কেউ আটকাত না।
 *
 * ⚠️ কথাটা কোডে ছিল না, কিন্তু **মন্তব্যে লেখা ছিল**: *"আদেশে মাল তো
 * এখনো আসেইনি, তাই ওখানে মাপকাঠি আদেশের পরিমাণ"*। ⓘ আজকের ভ্যাটের
 * বাগটার হুবহু আকৃতি — নিয়মটা লেখা, অঙ্কটা মানে না।
 *
 * ── ⭐ আর সংখ্যাটা জমা রাখা হয় না ────────────────────────────────────
 * *"কত বিল হয়েছে"* একটা **চলমান সংখ্যা**, আর চলমান সংখ্যা জমা রাখলে
 * বাড়ানোর কোড লেখা হয় আর কমানোরটা ভুলে যাওয়া হয়। ⓘ এই রিপো আগেই
 * উত্তরটা দিয়েছে (`PurchaseOrderLine::receivedQty()`): **গোনা হয়, জমা
 * নয়** — তাই বাতিল হলে সংখ্যাটা নিজে থেকেই ফিরে আসে।
 */
class SixtyCartonsTodayAndFortyNextWeekTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Product $product;

    private Warehouse $warehouse;

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ১০০ কার্টনের একটা নিশ্চিত আদেশ — বাকি সবকিছু এর উপরে দাঁড়ায়
        $orders = app(PurchaseOrderService::class);

        $this->order = $orders->create(
            [
                'supplier_id' => $this->supplier->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '100', 'rate' => '10']],
        );

        $this->order = $orders->confirm($this->order);
    }

    private function orderLine(): \App\Modules\Purchase\Models\PurchaseOrderLine
    {
        return $this->order->lines()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function bill(string $qty, array $extra = []): \App\Modules\Purchase\Models\PurchaseBill
    {
        return app(PurchaseBillService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ] + $extra,
            [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $this->orderLine()->id,
                'qty' => $qty,
                'rate' => '10',
            ]],
        );
    }

    private function assertQty(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$actual} ≠ {$expected}");
    }

    /**
     * ⭐ আংশিক বিল চলে, আর আদেশ জানে কত বাকি।
     */
    public function test_sixty_today_leaves_forty_for_next_week(): void
    {
        $this->assertQty('0', $this->orderLine()->billedQty());
        $this->assertQty('100', $this->orderLine()->pendingBillQty());

        $this->bill('60');

        $this->assertQty('60', $this->orderLine()->billedQty());
        $this->assertQty('40', $this->orderLine()->pendingBillQty());

        $this->bill('40');

        $this->assertQty('100', $this->orderLine()->billedQty());
        $this->assertQty('0', $this->orderLine()->pendingBillQty());
    }

    /**
     * ⛔ ৬০ + ৫০ = ১১০ চলবে না।
     *
     * ⚠️ বার্তাটায় **সংখ্যা দুইটাই** থাকতে হবে — *"বেশি হয়ে গেছে"* শুনে
     * কেউ কিছু করতে পারেন না; কত ছিল আর কত হয়ে গেছে, দুইটাই লাগে।
     */
    public function test_the_order_refuses_more_than_it_ever_asked_for(): void
    {
        $this->bill('60');

        try {
            $this->bill('50');

            $this->fail('আদেশের চেয়ে বেশি বিল হয়ে গেল, অথচ কেউ আটকাল না।');
        } catch (ValidationException $e) {
            $said = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('100', $said, 'বার্তাটা বলেনি আদেশে কত ছিল।');
            $this->assertStringContainsString('60', $said, 'বার্তাটা বলেনি কত বিল হয়ে গেছে।');
        }

        // ⓘ আর ব্যর্থ চেষ্টাটা যেন কিছু রেখে না যায়
        $this->assertQty('60', $this->orderLine()->billedQty());
    }

    /**
     * ⭐ ঠিক বাকিটুকু চলে — সীমানার গায়ে দাঁড়িয়েও।
     *
     * ⓘ `>` না লিখে `>=` লিখলে এই পরীক্ষাটাই লাল হত, আর তখন **শেষ
     * চালানটার বিলই করা যেত না** — সবচেয়ে সাধারণ কাজটাই।
     */
    public function test_the_last_forty_still_goes_through(): void
    {
        $this->bill('60');

        $bill = $this->bill('40');

        $this->assertNotNull($bill->fresh());
        $this->assertQty('100', $this->orderLine()->billedQty());
    }

    /**
     * ⛔ বাতিল হলে পরিমাণটা ফিরে আসে — কারণ ফেরানোর কোনো কোডই নেই।
     *
     * ── কেন এই পরীক্ষাটা সবচেয়ে জরুরি ───────────────────────────────
     * `nexus-25` আজ ঠিক এই আকৃতির ভুল করেছে জামানতে: **একটা সংখ্যা
     * বাড়ানোর কোড লেখা হয়েছিল, কমানোরটা নয়** — চুক্তি বন্ধ করে টাকা
     * ফেরত দেওয়ার পরেও `depositLeft()` বলছিল ১১,৯০,০০০ পড়ে আছে।
     *
     * ⭐ এখানে ফাঁদটা নেই, কারণ সংখ্যাটা **জমা থাকে না, গোনা হয়** — আর
     * এই পরীক্ষাটা সেটাই পাহারা দেয়। ⚠️ কেউ একদিন "দ্রুততার জন্য" একটা
     * `billed_qty` কলাম বসালে এটাই প্রথম লাল হবে।
     */
    public function test_a_cancelled_bill_gives_the_quantity_back(): void
    {
        $bills = app(PurchaseBillService::class);

        $first = $this->bill('60');
        $this->assertQty('60', $this->orderLine()->billedQty());

        $bills->cancel($first, 'ভুল সরবরাহকারী');

        $this->assertQty('0', $this->orderLine()->billedQty());
        $this->assertQty('100', $this->orderLine()->pendingBillQty());

        // ⓘ আর তাই পুরো ১০০-ই আবার বিল করা যায়
        $this->bill('100');

        $this->assertQty('100', $this->orderLine()->billedQty());
    }

    /**
     * ⚠️ একটা বিল সম্পাদনা করলে সে **নিজেকে গোনে না**।
     *
     * ⛔ যোগফলে নিজের সারিটা রয়ে গেলে ৬০-এর বিলটা দ্বিতীয়বার সেভ করাই
     * যেত না — ৬০ + ৬০ = ১২০ হয়ে আদেশ ছাড়িয়ে যেত, আর ব্যবহারকারী
     * দেখতেন নিজের বিলটাই নিজেকে আটকাচ্ছে।
     */
    public function test_editing_a_bill_does_not_count_itself(): void
    {
        $bills = app(PurchaseBillService::class);

        $bill = $this->bill('60');

        $bills->update(
            $bill,
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $this->orderLine()->id,
                'qty' => '70',
                'rate' => '10',
            ]],
        );

        $this->assertQty('70', $this->orderLine()->billedQty());
    }
}
