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
     * ⭐ ঘরটা যা লেখে, নিয়ম তাই মানে — দুইটা আলাদা সংখ্যা নয়।
     *
     * ── ⛔ ব্রাউজারে হাতে করে যা ধরা পড়ল (৫ সেপ্টেম্বর ২০২৬) ─────────
     * উপরের নিয়মগুলো সবুজ ছিল, তবু পর্দাটা মিথ্যা বলছিল। ১০০-র আদেশে
     * ৬০-এর বিল হওয়ার পরেও বাছাইয়ের ঘরটা লিখত **"PRD-0001 - 100"**,
     * আর পুরো ১০০ বিল হয়ে যাওয়ার পরেও **তাই**।
     *
     * ⚠️ কারণ ঘরটা `pendingQty()` ডাকত — ওটা মাপে **কত মাল আসেনি**,
     * `receivedQty()` দেখে। কোনো চালান হয়নি, তাই সংখ্যাটা কোনোদিন
     * নড়েনি। ⛔ ফলে পর্দা ডাকত *"১০০ নাও"*, আর নিয়ম বলত *"৪০-এর বেশি
     * নয়"* — **এক প্রশ্নের দুইটা উত্তর**।
     *
     * ⭐ একটা নিয়ম যতই ঠিক হোক, পর্দা যদি উল্টো সংখ্যা দেখায় তাহলে
     * ব্যবহারকারীর কাছে ব্যবস্থাটা **ভাঙা**। ⓘ এই পরীক্ষাটা তাই নিয়ম
     * নয়, **নিয়ম আর পর্দার মিল** মাপে।
     */
    public function test_the_picker_offers_only_what_may_still_be_billed(): void
    {
        $this->bill('60');

        $screen = $this->get(route('purchase.bill.create', ['purchase_order_id' => $this->order->id]));
        $screen->assertOk();

        $code = $this->product->code;
        $html = $screen->getContent();

        /*
         * ⓘ `assertStringContainsString()` নয় — ওটা ব্যর্থ হলে **পুরো
         * পাতাটা** বার্তায় ঢালে (দেড়শো কিলোবাইট), আর তার ভিতরে আসল
         * কথাটা খুঁজে পাওয়া যায় না। ⚠️ যে লাল পড়া যায় না, সেটা পরের
         * মানুষটার কাছে কেবল দেরি।
         */
        $this->assertTrue(str_contains($html, $code.' - 40'),
            'ঘরটা বাকি ৪০-এর কথা বলে না।');

        $this->assertFalse(str_contains($html, $code.' - 100'),
            'ঘরটা এখনো পুরো ১০০ সাধছে, অথচ নিয়ম ৪০-এর বেশি নেবে না।');

        /*
         * ── ⚠️ আর পরিমাণের ঘরটাও, যেটা আরো বিপজ্জনক ──────────────────
         *
         * বাছাইয়ের ঘর ভুল সংখ্যা **দেখায়**; পরিমাণের ঘর ভুল সংখ্যা
         * **বসিয়ে রাখে**। ⛔ ৬০ বিল হওয়ার পরেও পাতাটা খুললে ঘরে ১০০
         * বসানো থাকত, আর যিনি বিশ্বাস করে Save চাপতেন তিনি প্রত্যাখ্যান
         * পেতেন — নিজে একটা অঙ্কও না লিখে।
         *
         * ── ⓘ চেহারা নয়, মান ────────────────────────────────────────
         * প্রথমে আমি HTML-এ `&quot;qty&quot;:&quot;40&quot;` খুঁজেছিলাম,
         * তারপর `\u0022`-এর চেহারাটা। ⛔ দুইবারই পরীক্ষাটা লাল হয়েছে
         * অথচ **কোডটা ঠিকই ছিল** — Alpine-এর বীজ `Js::from()` দিয়ে বসে,
         * আর সে উদ্ধৃতিচিহ্ন কীভাবে লেখে তা Laravel-এর ব্যাপার, আমার নয়।
         *
         * ⚠️ **একটা ভুল দাবিও একটা ভুল পাহারা** — সে আসল জিনিসটা মাপে
         * না, শুধু চেঁচায়, আর পরের মানুষটা তাকে বিশ্বাস করা ছেড়ে দেন।
         *
         * ⭐ তাই এখন চেহারা খোঁজা বাদ: বীজটা বের করে **ডিকোড করে** পড়া
         * হয়। ⓘ কাল Laravel এস্কেপিং বদলালেও এই পরীক্ষা সত্যি বলবে।
         */
        $this->assertSame('40', $this->seededQty($html),
            'ঘরে বাকি ৪০ বসানো নেই — Save চাপলেই প্রত্যাখ্যান।');
    }

    /**
     * পর্দার পরিমাণের ঘরে কী বসানো আছে — Alpine-এর বীজ থেকে পড়া।
     *
     * ⓘ `rows: JSON.parse('…')` অংশটা একটা JSON স্ট্রিং, যার ভিতরের
     * উদ্ধৃতিচিহ্নগুলো `\uXXXX` করে লেখা। ⭐ তাই দুইবার ডিকোড: একবার
     * এস্কেপ খোলার জন্য, একবার সারিগুলো পাওয়ার জন্য।
     */
    private function seededQty(string $html): ?string
    {
        if (! preg_match("/rows: JSON\\.parse\\('(.*?)'\\)/s", $html, $m)) {
            return null;
        }

        $rows = json_decode((string) json_decode('"'.$m[1].'"', false), true);

        return is_array($rows) && isset($rows[0]['qty']) ? (string) $rows[0]['qty'] : null;
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
