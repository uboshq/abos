<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রেতা টাকা আনতে গেছেন, আর পেছনে লাইন।
 *
 * এটা না থাকলে ক্যাশিয়ার যা করেন সেটাই সমস্যা: বিলটা বাতিল করেন, আর
 * ক্রেতা ফিরলে আবার গোড়া থেকে টাইপ করেন। **তখন বাতিলের সংখ্যা দিয়ে আর
 * কিছু বোঝা যায় না** — দিনে ত্রিশটা বাতিল দেখে বলার উপায় থাকে না কোনটা
 * ভুল, কোনটা চুরির চেষ্টা, আর কোনটা কেবল একজন ক্রেতা টাকা আনতে
 * গিয়েছিলেন।
 */
class PosParkedBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function pos(): PosService
    {
        return app(PosService::class);
    }

    private function park(string $qty = '2'): SalesInvoice
    {
        return $this->pos()->park(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100']],
        );
    }

    // ── ধরে রাখা ─────────────────────────────────────────────────

    /**
     * ধরে রাখা বিল খসড়াই থাকে — খাতায় কিছু বসে না।
     *
     * টাকা ওঠে না, আয় বসে না, খতিয়ানে কোনো চিহ্ন নেই।
     *
     * ⚠️ ── "মাল নড়ে না" কথাটা এখানে লেখা ছিল, আর ওটা আর সত্যি নয় ────
     * মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬): **হোল্ড করা বিল মাল আটকে
     * রাখবে**। নাহলে দুইটা কাউন্টার একই শেষ কার্টনটা একই সাথে বেচতে
     * পারত। মাল এখন `Reserved`-এ যায় ([[ParkedStockReservation]]) —
     * তাকে থেকে সরে না, কিন্তু "বিক্রয়যোগ্য" থেকে বাদ পড়ে।
     *
     * ⓘ খতিয়ান আর মজুদ দুইটা আলাদা প্রশ্ন: খাতায় কিছু বসেনি, তবু
     * মালটা অন্য কারো জন্য নয়।
     */
    public function test_a_parked_bill_stays_a_draft(): void
    {
        $invoice = $this->park();

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertNotNull($invoice->parked_at);
    }

    /** ধরে রাখা মানে কাউন্টারের তালিকায় আসা। */
    public function test_a_parked_bill_shows_up_at_the_counter(): void
    {
        $invoice = $this->park();

        $this->assertTrue($this->pos()->parked()->contains('id', $invoice->id));
    }

    /**
     * সাধারণ খসড়া কাউন্টারের তালিকায় আসে না।
     *
     * `parked_at` না থাকলে ওটা কেউ ফেলে রাখা খসড়া — কাউন্টারে
     * অপেক্ষমাণ কেউ নয়। দুইটা এক করে ফেললে টিলের পর্দা সব পুরনো খসড়ায়
     * ভরে যেত, আর কেউ ওটা পড়া বন্ধ করে দিত।
     */
    public function test_an_ordinary_draft_is_not_waiting_at_the_counter(): void
    {
        $draft = $this->park();
        $draft->forceFill(['parked_at' => null])->save();

        $this->assertFalse($this->pos()->parked()->contains('id', $draft->id));
    }

    /** পুরনোটা আগে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই আগে সিদ্ধান্ত চায়। */
    public function test_the_longest_waiting_bill_comes_first(): void
    {
        $first = $this->park();
        $first->forceFill(['parked_at' => now()->subHours(3)])->save();

        $second = $this->park();
        $second->forceFill(['parked_at' => now()->subMinutes(5)])->save();

        $this->assertSame($first->id, $this->pos()->parked()->first()->id);
    }

    // ── আবার তোলা ────────────────────────────────────────────────

    /**
     * তোলার পর ওটা আর অপেক্ষা করছে না।
     *
     * `parked_at` মুছে না দিলে একই বিল দুই কাউন্টারে একসাথে তোলা যেত,
     * আর একজন নিশ্চিত করার পর অন্যজন খালি পর্দা নিয়ে বসে থাকতেন।
     */
    public function test_resuming_takes_it_off_the_waiting_list(): void
    {
        $invoice = $this->pos()->resume($this->park());

        $this->assertNull($invoice->parked_at);
        $this->assertFalse($this->pos()->parked()->contains('id', $invoice->id));
    }

    public function test_the_lines_survive_the_wait(): void
    {
        $resumed = $this->pos()->resume($this->park('7'));

        $this->assertCount(1, $resumed->lines);
        $this->assertSame(0, bccomp((string) $resumed->lines->first()->qty, '7', 4));
    }

    public function test_a_bill_that_is_not_waiting_cannot_be_resumed(): void
    {
        $invoice = $this->park();
        $this->pos()->resume($invoice);

        $this->expectException(ValidationException::class);

        $this->pos()->resume($invoice->fresh());
    }

    /**
     * সম্পূর্ণ হয়ে যাওয়া বিল আর তোলা যায় না।
     *
     * তুললে ক্যাশিয়ার ভাবতেন ওটা এখনো অসমাপ্ত, আর দ্বিতীয়বার টাকা
     * নিতেন — অথচ খাতায় ওটা আগেই বসে গেছে।
     */
    public function test_a_completed_bill_cannot_be_picked_up_again(): void
    {
        $invoice = $this->park();
        $invoice->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        $this->expectException(ValidationException::class);

        $this->pos()->resume($invoice->fresh());
    }

    // ── সীমানা ───────────────────────────────────────────────────

    public function test_parking_an_empty_cart_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->pos()->park(['warehouse_id' => $this->warehouse->id], []);
    }

    /** এক কোম্পানির ঝুলে থাকা বিল অন্য কোম্পানি দেখে না। */
    public function test_one_company_never_sees_anothers_waiting_bills(): void
    {
        $this->park();

        $other = Company::query()->where('code', '!=', 'TDEPOT')->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় কোম্পানি নেই।');
        }

        CompanyContext::set($other->id, null);

        $this->assertCount(0, $this->pos()->parked());
    }

    // ── মাল আটকানো ───────────────────────────────────────────────

    /**
     * ⭐ ধরে রাখা বিল মাল আটকায় — "বিক্রয়যোগ্য" কমে যায়।
     *
     * ⚠️ এটাই মালিকের শর্ত, আর এটাই সবচেয়ে সহজে মিস হয়: বিলটা হোল্ডে
     * গেল, কিন্তু অন্য কাউন্টার এখনো ওই মাল বেচতে পারল — তখন **একই
     * মাল দুইজনের কাছে বিক্রি হয়ে যায়**, আর ধরা পড়ে গুদামে গিয়ে।
     */
    public function test_parking_takes_the_goods_off_the_shelf_for_everyone_else(): void
    {
        $stock = app(StockService::class);
        $before = $stock->availableQty($this->product, $this->warehouse);

        $this->park('2');

        $this->assertSame(
            0,
            bccomp(bcsub($before, '2', 4), $stock->availableQty($this->product, $this->warehouse), 4),
            'ধরে রাখা বিলের মাল এখনো বিক্রয়যোগ্য দেখাচ্ছে।',
        );
    }

    /** তাক থেকে মাল সরে না — কেবল সংরক্ষিত হয়। */
    public function test_the_goods_stay_on_the_shelf_they_are_only_spoken_for(): void
    {
        $stock = app(StockService::class);
        $floor = $stock->statesFor($this->product, $this->warehouse)['floor'];

        $this->park('2');

        $after = $stock->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($floor, $after['floor'], 4), 'তাকের মাল কমে গেছে — বিল তো এখনো হয়নি।');
        $this->assertSame(0, bccomp('2', $after['reserved'], 4));
    }

    /**
     * ফিরিয়ে আনলে আটকানো **বহাল** — মালিকের নিয়ম।
     *
     * ⓘ "যতক্ষণ না cancel করছি" — ফিরিয়ে আনা মানে কেবল বিলটা আবার
     * সম্পাদনাযোগ্য হওয়া; ক্রেতা তখনো কাউন্টারে দাঁড়িয়ে।
     */
    public function test_picking_the_bill_back_up_does_not_free_the_goods(): void
    {
        $stock = app(StockService::class);
        $invoice = $this->park('2');

        $this->pos()->resume($invoice);

        $this->assertSame(0, bccomp('2', $stock->reservedQty($this->product, $this->warehouse), 4));
    }

    /** বাতিল করলে মাল ছাড়া পায় — নিয়মের অন্য অর্ধেক। */
    public function test_cancelling_gives_the_goods_back(): void
    {
        $stock = app(StockService::class);
        $before = $stock->availableQty($this->product, $this->warehouse);

        $invoice = $this->park('2');
        app(SalesInvoiceService::class)->cancel($invoice, 'ক্রেতা ফেরেননি');

        $this->assertSame(
            0,
            bccomp($before, $stock->availableQty($this->product, $this->warehouse), 4),
            'বাতিলের পরেও মাল আটকে আছে।',
        );
    }

    /**
     * ⭐ নিশ্চিত করলে মাল একবারই যায়, দুইবার নয়।
     *
     * ⚠️ এই পরীক্ষাটাই এই ফাইলের সবচেয়ে জরুরি। নিশ্চিত করার সময়
     * `floor` কমে; সংরক্ষণটা তখনো রয়ে গেলে হিসাব দাঁড়াত:
     *
     *   floor −২ · reserved +২ · available −৪
     *
     * অর্থাৎ **মাল বিক্রি হয়ে যাওয়ার পরেও আটকে থাকত, চিরকাল** — আর
     * কোথাও কিছু ভাঙত না; কেবল "বিক্রয়যোগ্য" সংখ্যাটা রোজ একটু করে
     * মিথ্যা হত।
     */
    public function test_confirming_removes_the_goods_once_not_twice(): void
    {
        $stock = app(StockService::class);
        $before = $stock->availableQty($this->product, $this->warehouse);

        $invoice = $this->park('2');
        app(SalesInvoiceService::class)->confirm($invoice->fresh(['lines.product']));

        $after = $stock->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp('0', $after['reserved'], 4), 'বিল হয়ে গেছে, তবু সংরক্ষণ রয়ে গেছে।');
        $this->assertSame(
            0,
            bccomp(bcsub($before, '2', 4), $after['available'], 4),
            'একই মাল দুইবার গোনা হয়েছে।',
        );
    }
}
