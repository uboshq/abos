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
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ব্যাচ — যে মালের গায়ে তারিখ লেখা।
 *
 * প্রতিটা সংখ্যা পিন করা। "FEFO কাজ করে" লেখা একটা মন্তব্যের কোনো দাম
 * নেই; যেদিন ওটা কাজ করা বন্ধ করবে সেদিন ভাঙবে এমন একটা পরীক্ষার দাম
 * পুরো ফাইলটার সমান।
 */
class BatchTest extends TestCase
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

    private function batch(string $no, ?string $expiry, ?string $mrp = null): Batch
    {
        return Batch::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
            'mrp' => $mrp,
        ]);
    }

    private function receive(Batch $batch, string $qty): void
    {
        StockMovement::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $batch->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => $qty,
            'source_type' => 'test',
            'source_id' => 1,
            'document_no' => 'TEST-0001',
        ]);
    }

    // ── ব্যাচে কত আছে ─────────────────────────────────────────────

    /**
     * সংখ্যাটা চলাচলের যোগফল, কোনো কলাম নয়।
     *
     * ব্যাচে `qty` কলাম রাখলে সেটা একই সত্যের দ্বিতীয় কপি হত। এই
     * পরীক্ষাটা প্রমাণ করে যে যোগফলটাই উত্তর — আর যেদিন কেউ একটা কলাম
     * যোগ করে দুইটা রাখতে চাইবে, সেদিন এটাই প্রথম প্রশ্ন তুলবে।
     */
    public function test_what_is_left_in_a_lot_is_the_sum_of_its_movements(): void
    {
        $batch = $this->batch('A1', '2027-06-30');

        $this->assertSame(0, bccomp($batch->balance(), '0', 4));

        $this->receive($batch, '10');
        $this->receive($batch, '-3');

        $this->assertSame(0, bccomp($batch->fresh()->balance(), '7', 4));
    }

    public function test_two_lots_of_one_product_hold_their_own_numbers(): void
    {
        $old = $this->batch('A1', '2026-09-30');
        $new = $this->batch('B2', '2027-06-30');

        $this->receive($old, '5');
        $this->receive($new, '12');

        $this->assertSame(0, bccomp($old->fresh()->balance(), '5', 4));
        $this->assertSame(0, bccomp($new->fresh()->balance(), '12', 4));
    }

    // ── FEFO ──────────────────────────────────────────────────────

    /**
     * আগে যেটার মেয়াদ শেষ, সেটা আগে — আগে যেটা কেনা সেটা নয়।
     *
     * এটাই FIFO থেকে পার্থক্য, আর এটাই পুরো ব্যবস্থাটার কারণ। ভুল
     * ব্যাচ আগে বেরোলে পুরনোটা তাকে থেকে মেয়াদ পার করে, আর তখন ওটা
     * মাল হিসেবে নয়, ক্ষতি হিসেবে বেরোয়।
     */
    public function test_the_earliest_expiry_goes_out_first_not_the_earliest_purchase(): void
    {
        // আগে কেনা, কিন্তু মেয়াদ পরে
        $boughtFirst = $this->batch('A1', '2027-12-31');
        // পরে কেনা, কিন্তু মেয়াদ আগে
        $boughtLater = $this->batch('B2', '2026-10-31');

        $order = Batch::query()->where('product_id', $this->product->id)->fefo()->pluck('batch_no')->all();

        $this->assertSame(['B2', 'A1'], $order,
            'FEFO মেয়াদের ক্রম মানছে না — পুরনো মাল তাকে থেকে যাবে।');
        $this->assertNotSame($boughtFirst->id, $boughtLater->id);
    }

    /**
     * মেয়াদহীন ব্যাচ সবার শেষে।
     *
     * ওগুলো খারাপ হয় না, তাই তাড়া নেই। আর SQL-এ NULL-এর ক্রম
     * ডাটাবেজভেদে আলাদা, তাই ক্রমটা হাতে বলা — নাহলে একই কোড MySQL আর
     * PostgreSQL-এ দুই রকম মাল বের করত।
     */
    public function test_a_lot_with_no_expiry_sorts_last(): void
    {
        $this->batch('NOEXP', null);
        $this->batch('A1', '2027-12-31');
        $this->batch('B2', '2026-10-31');

        $order = Batch::query()->where('product_id', $this->product->id)->fefo()->pluck('batch_no')->all();

        $this->assertSame(['B2', 'A1', 'NOEXP'], $order);
    }

    // ── মেয়াদ ─────────────────────────────────────────────────────

    public function test_a_lot_knows_whether_it_has_expired(): void
    {
        $on = Carbon::parse('2026-08-12');

        $this->assertTrue($this->batch('OLD', '2026-08-11')->hasExpired($on));
        $this->assertFalse($this->batch('TODAY', '2026-08-12')->hasExpired($on),
            'আজ মেয়াদ শেষ মানে আজও বেচা যায় — গায়ে লেখা তারিখটাই শেষ দিন।');
        $this->assertFalse($this->batch('LATER', '2026-08-13')->hasExpired($on));
    }

    public function test_a_lot_with_no_expiry_never_expires(): void
    {
        $this->assertFalse($this->batch('NOEXP', null)->hasExpired());
    }

    /**
     * মেয়াদহীন ব্যাচে "কত দিন বাকি" শূন্য নয়, `null`।
     *
     * শূন্য মানে "আজ শেষ" — তার ঠিক উল্টো কথা। দুইটা এক করে ফেললে
     * মেয়াদহীন মাল রোজ সতর্কতার তালিকায় উঠত, আর তালিকাটা কেউ পড়া
     * বন্ধ করে দিত।
     */
    public function test_days_left_is_null_when_there_is_no_expiry(): void
    {
        $this->assertNull($this->batch('NOEXP', null)->daysLeft());
    }

    public function test_days_left_goes_negative_once_the_date_has_passed(): void
    {
        $on = Carbon::parse('2026-08-12');

        $this->assertSame(3, $this->batch('SOON', '2026-08-15')->daysLeft($on));
        $this->assertSame(-2, $this->batch('GONE', '2026-08-10')->daysLeft($on));
    }

    public function test_expired_lots_can_be_left_out(): void
    {
        $on = Carbon::parse('2026-08-12');

        $this->batch('GONE', '2026-08-01');
        $this->batch('GOOD', '2027-01-01');
        $this->batch('NOEXP', null);

        $left = Batch::query()->where('product_id', $this->product->id)
            ->unexpired($on)->pluck('batch_no')->all();

        sort($left);

        $this->assertSame(['GOOD', 'NOEXP'], $left);
    }

    // ── ছাপা দাম ─────────────────────────────────────────────────

    /**
     * MRP ব্যাচে বসে, পণ্যে নয়।
     *
     * প্রস্তুতকারক দুই উৎপাদনের মাঝে দাম বদলে ছাপেন, তাই একই ড্রয়ারে
     * দুই দামের পাতা থাকে। পণ্যে একটা দাম রাখলে পুরনো পাতাটা নতুন দামে
     * বেচা যেত — আর সেটা বেআইনি বিক্রয়, যেটা পর্দায় সঠিক দেখাত।
     */
    public function test_two_lots_of_one_product_carry_two_printed_prices(): void
    {
        $old = $this->batch('A1', '2026-12-31', '20.0000');
        $new = $this->batch('B2', '2027-06-30', '22.0000');

        $this->assertSame(0, bccomp((string) $old->mrp, '20', 4));
        $this->assertSame(0, bccomp((string) $new->mrp, '22', 4));
        $this->assertNotSame((string) $old->mrp, (string) $new->mrp);
    }

    public function test_a_lot_may_have_no_printed_price(): void
    {
        $this->assertNull($this->batch('A1', '2027-01-01')->mrp);
    }

    // ── সীমানা ────────────────────────────────────────────────────

    /** একই পণ্যের একই লট নম্বর দুইবার বসে না। */
    public function test_one_product_cannot_hold_the_same_lot_number_twice(): void
    {
        $this->batch('A1', '2027-01-01');

        $this->expectException(QueryException::class);

        $this->batch('A1', '2027-06-30');
    }

    /** এক কোম্পানির ব্যাচ অন্য কোম্পানি দেখে না। */
    public function test_one_company_never_sees_anothers_lots(): void
    {
        $this->batch('A1', '2027-01-01');

        $other = Company::query()->where('code', '!=', 'TDEPOT')->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় কোম্পানি নেই।');
        }

        CompanyContext::set($other->id, null);

        $this->assertSame(0, Batch::query()->count());
    }
}
