<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Services\BatchTrace;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফ্রি কার্টনও একটা লটের কার্টন।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * ফ্রি ও উপহার সরাসরি `move()` ডাকত, লট ছাড়া। তাই:
 *
 *   ১. মেয়াদোত্তীর্ণ মাল "ফ্রি" হয়ে বেরিয়ে যেত — বিক্রির লাইনে মেয়াদ
 *      আটকাত, ফ্রি-র লাইনে আটকাত না, অথচ কার্টনটা একই।
 *   ২. রিকলে যাঁরা ফ্রি পেয়েছেন তাঁরা তালিকার বাইরে থাকতেন — আর
 *      তালিকাটা দেখে মনে হত সবাই ধরা পড়েছে।
 *
 * দুই নম্বরটা রিকলের সবচেয়ে বিপজ্জনক ভুল: একটা সম্পূর্ণ দেখতে
 * অসম্পূর্ণ তালিকা।
 */
class FreeGoodsCarryLotsTest extends TestCase
{
    use RefreshDatabase;

    private Product $medicine;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->medicine = Product::query()->firstOrFail();
        $this->medicine->forceFill(['track_batch' => true])->save();
    }

    /** একটা ক্রয় — লট, মেয়াদ ও ফ্রি কার্টনসহ। */
    private function buy(string $batchNo, string $expiry, string $qty = '100', string $free = '10'): Batch
    {
        $bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->medicine->id,
                'qty' => $qty,
                'free_qty' => $free,
                'rate' => '20',
                'batch_no' => $batchNo,
                'expiry_date' => $expiry,
            ]],
        );

        app(PurchaseBillService::class)->confirm($bill);

        return Batch::query()->where('batch_no', $batchNo)->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $gifts
     */
    private function sell(string $qty = '5', string $free = '2', array $gifts = []): object
    {
        return (object) app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [[
                'product_id' => $this->medicine->id,
                'qty' => $qty,
                'rate' => '30',
                'free_qty' => $free,
            ]],
            $gifts,
        );
    }

    // ── ঢোকা ─────────────────────────────────────────────────────

    /** ফ্রি কার্টন লটেই ঢোকে — একই ব্যাচ নম্বর, একই মেয়াদ। */
    public function test_free_cartons_arrive_inside_the_lot(): void
    {
        $batch = $this->buy('AB-1', now()->addYear()->toDateString());

        $this->assertSame(0, bccomp($batch->balance($this->warehouse), '100', 4));
        $this->assertSame(0, bccomp($batch->freeBalance($this->warehouse), '10', 4));
    }

    /**
     * লটের দুইটা হিসাব আলাদাই থাকে।
     *
     * একসাথে গুনলে বিক্রয়ের FEFO এমন লট বেছে নিত যেখানে কেবল ফ্রি মাল
     * আছে, আর কাউন্টারে "৫ আছে" দেখিয়ে বেচতে গেলে থামত।
     */
    public function test_the_two_counts_inside_a_lot_stay_apart(): void
    {
        $batch = $this->buy('AB-2', now()->addYear()->toDateString(), qty: '0.0001', free: '50');

        $this->assertSame(0, bccomp($batch->freeBalance($this->warehouse), '50', 4));
        $this->assertSame(-1, bccomp($batch->balance($this->warehouse), '1', 4), 'বিক্রয়যোগ্য অংশটা ফ্রি দিয়ে ভরে গেছে');
    }

    // ── বেরোনো ───────────────────────────────────────────────────

    /** ফ্রি মাল লট ধরে বেরোয় — চলাচলের সারিতে লটটা লেখা থাকে। */
    public function test_free_goods_leave_with_their_lot(): void
    {
        $batch = $this->buy('AB-3', now()->addYear()->toDateString());

        $this->sell(free: '3');

        $this->assertSame(0, bccomp($batch->freeBalance($this->warehouse), '7', 4));
    }

    /**
     * মেয়াদোত্তীর্ণ লট থেকে ফ্রি দেওয়া যায় না।
     *
     * এটাই ছিল আসল বিপদ: বিক্রির লাইনে মেয়াদ আটকাত, ফ্রি-র লাইনে
     * আটকাত না — অথচ প্যাকেটটা একই তারিখে পচে।
     */
    public function test_an_expired_lot_cannot_be_given_away_free(): void
    {
        $batch = $this->buy('AB-4', now()->addYear()->toDateString());

        // লটটা মেয়াদ পার করানো হলো
        $batch->forceFill(['expiry_date' => now()->subDay()->toDateString()])->save();

        $this->expectException(ValidationException::class);

        $this->sell(qty: '0.0001', free: '1');
    }

    /** আগে-মেয়াদ-শেষ লট থেকে ফ্রি আগে বেরোয়। */
    public function test_the_soonest_to_expire_lot_gives_first(): void
    {
        $soon = $this->buy('AB-5', now()->addMonth()->toDateString(), free: '4');
        $later = $this->buy('AB-6', now()->addYear()->toDateString(), free: '9');

        $this->sell(free: '6');

        $this->assertSame(0, bccomp($soon->freeBalance($this->warehouse), '0', 4), 'আগেরটা পুরো খালি হয়নি');
        $this->assertSame(0, bccomp($later->freeBalance($this->warehouse), '7', 4), 'বাকিটা পরেরটা থেকে যায়নি');
    }

    // ── রিকল ─────────────────────────────────────────────────────

    /**
     * যিনি কেবল ফ্রি পেয়েছেন, রিকলে তিনিও আছেন।
     *
     * না থাকলে তালিকাটা সম্পূর্ণ দেখাত অথচ কয়েকজন বাদ — আর ওষুধ ফেরত
     * ডাকার সময় ওটাই সবচেয়ে খারাপ ভুল।
     */
    public function test_someone_who_only_got_free_goods_is_still_recalled(): void
    {
        $batch = $this->buy('AB-7', now()->addYear()->toDateString());

        // বিক্রি শূন্যের কাছাকাছি, ফ্রি-টাই আসল
        $this->sell(qty: '0.0001', free: '5');

        $recipients = app(BatchTrace::class)->recipients($batch);

        $this->assertTrue(
            $recipients->contains(fn ($row) => (int) $row->customer_id === $this->customer->id),
            'ফ্রি পাওয়া ক্রেতা রিকলের তালিকায় নেই',
        );
    }
}
