<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * লট ধরা পণ্য ক্রয়ের ঘর — কোথায় থাকে, আর কোথায় থাকে না।
 *
 * ── কী ভাঙা ছিল, ৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * লট জন্মানোর কোড ছিল, কলাম ছিল, টেস্ট ছিল — **কিন্তু কোনো ক্রয়-পর্দা
 * মানুষটাকে লট নম্বরটা জিজ্ঞেসই করত না**। ফল: ফার্মেসি ও
 * ফার্মাসিউটিক্যালসের প্রতিটা পণ্য লট ধরা, তাই ওই দুই শিল্পের ক্রেতা
 * ABOS দিয়ে **একটা পাতাও কিনতে পারতেন না** — `BatchService` ব্যতিক্রম
 * ছুঁড়ত, আর ঘরটা কোথাও ছিল না।
 *
 * ── ⛔ আর এখানকার সবচেয়ে জরুরি পরীক্ষাটা উল্টো দিকের ────────────────
 * ঘর তিনটা প্রথমে শর্ত ছাড়া বসানো হয়েছিল, আর তাতে **ক্রয় আদেশের**
 * পর্দাতেও বসে গিয়েছিল — যেখানে `pur_order_lines`-এ ওই কলামগুলোই নেই।
 * অর্থাৎ তিনটা ঘর দেখা যেত যেগুলো কখনো সেভ হত না।
 *
 * ⭐ ধরা পড়েছে ব্রাউজারে খুলে, কোড পড়ে নয় — কোডটা কাজ করছিল, কেবল ভুল
 * পর্দায়। তাই [[test_the_order_screen_does_not_ask_for_a_lot]] এখানকার
 * কেন্দ্রীয় পাহারা, আর ওটাই ভেঙে দেখা হয়েছে।
 */
class ThePharmacyCouldNotBuyASingleStripTest extends TestCase
{
    use RefreshDatabase;

    private Product $medicine;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ডেমোতে লট ধরা কোনো পণ্য নেই, তাই একটা বানিয়ে নেওয়া হয়।
         *
         * ⚠️ খুঁজে না পেলে skip করা হত না — একটা এড়িয়ে যাওয়া টেস্ট সবুজ
         * তালিকায় বসে অথচ কিছুই প্রমাণ করে না, আর এই পাহারাটার পুরো
         * বিষয়ই হলো "লট ধরা পণ্যে কী হয়"।
         */
        $this->medicine = Product::query()->firstOrFail();
        $this->medicine->forceFill(['track_batch' => true])->save();
    }

    /** এই পর্দায় লটের ঘরটা বাঁধা আছে কি না। */
    private function asksForALot(string $route): bool
    {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        return str_contains($html, ':name="`lines[${i}][batch_no]`"');
    }

    public function test_the_bill_and_the_receipt_ask_for_a_lot(): void
    {
        $this->assertTrue($this->asksForALot('purchase.bill.create'),
            'ক্রয় বিলের পর্দায় লট নম্বরের ঘর নেই — লট ধরা পণ্য তাহলে কেনাই যাবে না।');

        $this->assertTrue($this->asksForALot('purchase.receipt.create'),
            'ক্রয় চালানের (GRN) পর্দায় লট নম্বরের ঘর নেই।');
    }

    /**
     * ⛔ আদেশের পর্দা লট চায় না — আর চাওয়ার কথাও নয়।
     *
     * আদেশ একটা **অনুরোধ**, মাল তখনো আসেনি; লট নম্বর তখন কেউ জানেন না।
     * আর `pur_order_lines`-এ কলাম তিনটাই নেই, তাই ঘরটা দেখালে সেটা
     * **কখনো সেভ হত না** — একটা মৃত ঘর, যা দেখতে জীবন্ত।
     *
     * ⚠️ এই টেস্টটাই সেই সিদ্ধান্তের পাহারা যে `lots` প্রপটা opt-in।
     * ⓘ ভেঙে দেখা হয়েছে: আদেশের ফর্মে `lots` বসিয়ে এটা লাল হয়।
     */
    public function test_the_order_screen_does_not_ask_for_a_lot(): void
    {
        $this->assertFalse($this->asksForALot('purchase.order.create'),
            'ক্রয় আদেশের পর্দায় লটের ঘর বসে গেছে — pur_order_lines-এ ওই কলামগুলো নেই, তাই লেখাটা হারাবে।');
    }

    /**
     * ঘরটা থাকা যথেষ্ট নয় — সংখ্যাটা সার্ভারে পৌঁছাতে হবে।
     *
     * ⚠️ পর্দায় ঘর, request-এ নিয়ম, টেবিলে কলাম — তিনটা থাকা সত্ত্বেও
     * সার্ভিসের একটা `array_map` লাইনের অভাবে মানটা নীরবে হারাতে পারে।
     */
    public function test_the_lot_reaches_the_books_with_its_expiry_and_printed_price(): void
    {
        $result = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'MEG-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [[
                'product_id' => $this->medicine->id,
                'qty' => '100',
                'rate' => '12',
                'batch_no' => 'NAPA-7781',
                'expiry_date' => '2027-06-30',
                'mrp' => '18.50',
            ]],
        );

        $line = $result['bill']->fresh(['lines'])->lines->first();

        $this->assertSame('NAPA-7781', $line->batch_no,
            'লট নম্বরটা বিলের সারিতে পৌঁছায়নি।');
        $this->assertSame('2027-06-30', $line->expiry_date?->toDateString(),
            'মেয়াদটা পৌঁছায়নি — আর ওটা ছাড়া FEFO আর মেয়াদ আটকানো দুইটাই অচল।');
        $this->assertSame(0, bccomp((string) $line->mrp, '18.50', 2),
            'ছাপা দামটা পৌঁছায়নি।');

        /*
         * ⭐ আর লটটা সত্যিই জন্মেছে — সারিতে লেখা থাকাই যথেষ্ট নয়।
         *
         * `Batch` না জন্মালে মজুদে "কোন লট" প্রশ্নের উত্তর থাকত না, আর
         * বিক্রির সময় ব্যবস্থা বলত লটে যথেষ্ট নেই।
         */
        $batch = Batch::query()->where('batch_no', 'NAPA-7781')->first();

        $this->assertNotNull($batch, 'লটটা জন্মায়নি — সারিতে নম্বর আছে, ভাণ্ডারে লট নেই।');
        $this->assertSame($this->medicine->id, (int) $batch->product_id);
    }

    /**
     * ⛔ লট ধরা পণ্য লট নম্বর ছাড়া ঢোকে না।
     *
     * ⓘ শর্তটা সেবা স্তরে, পর্দায় নয় — পর্দার `required` কেবল ব্রাউজারের
     * ভদ্রতা, আর API বা ইমপোর্ট ওই পথে আসেই না।
     */
    public function test_a_lot_tracked_product_cannot_arrive_without_a_number(): void
    {
        $this->expectException(ValidationException::class);

        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'MEG-'.fake()->unique()->numberBetween(1000, 9999),
            ],
            [[
                'product_id' => $this->medicine->id,
                'qty' => '10',
                'rate' => '12',
                'batch_no' => '',
            ]],
        );
    }

    /**
     * ⭐ প্রত্যাখ্যানের বার্তা **কারণ** বলে, ঠিকানা নয়।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া হয় ───────────────────────────
     * এই বার্তাটা একদিনে দুইবার মিথ্যা হয়েছে:
     *
     *   "GRN-এর পর্দা দিয়ে নিন"        → ওখানে ঘর ছিল না
     *   "কোনো ক্রয়-পর্দাতেই ঘর নেই"     → ঘর বসতেই মিথ্যা হয়ে গেল
     *
     * ⓘ দুইবারই বার্তাটা ব্যবস্থার **অবস্থা** বলছিল, আর অবস্থা বদলায়।
     * এখন সে বলে উপহারের সারির কথা — যেটা এই বাধার আসল কারণ।
     */
    public function test_the_refusal_names_the_reason_not_a_screen(): void
    {
        $message = __('purchase::validation.gift_needs_a_lot', ['product' => 'X']);

        $this->assertStringContainsString(__('purchase::field.gift'), $message,
            'বার্তাটা বলে না কেন আটকাল — উপহারের সারির কথাটাই তো কারণ।');

        foreach (['GRN', 'goods receipt'] as $address) {
            $this->assertStringNotContainsString($address, $message,
                "বার্তাটা আবার একটা পর্দার নাম বলছে ({$address}) — ঠিকানা বদলায়, কারণ বদলায় না।");
        }
    }
}
