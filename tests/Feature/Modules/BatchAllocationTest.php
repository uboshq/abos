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
use App\Modules\Inventory\Services\BatchAllocator;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কোন লট থেকে কতটা — FEFO, নিজে থেকে।
 *
 * ব্যাচ থাকা আর ব্যাচ **কাজে লাগা** এক জিনিস নয়। ক্যাশিয়ারকে ড্রপডাউন
 * দিলে তিনি প্রতিবার প্রথমটাই বাছেন, আর তখন পুরনো পাতাগুলো তাকেই মেয়াদ
 * পার করে। এই ফাইলটা প্রমাণ করে যে বাছাইটা কোড করে, আর ঠিক ক্রমে করে।
 */
class BatchAllocationTest extends TestCase
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

    private function lot(string $no, ?string $expiry, string $qty): Batch
    {
        $batch = Batch::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);

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

        return $batch;
    }

    private function allocator(): BatchAllocator
    {
        return app(BatchAllocator::class);
    }

    private function take(string $qty, ?Carbon $on = null): array
    {
        return $this->allocator()->allocate($this->product, $this->warehouse, $qty, $on);
    }

    // ── ক্রম ─────────────────────────────────────────────────────

    /**
     * আগে-মেয়াদ-শেষটা আগে বেরোয়, আগে-কেনাটা নয়।
     *
     * এটাই FIFO থেকে পার্থক্য, আর ভুল হলে ফল দেখা যায় ছয় মাস পরে —
     * তাকের পিছনে মেয়াদ পেরোনো মাল হিসেবে।
     */
    public function test_the_earliest_expiry_is_taken_first(): void
    {
        $this->lot('OLD-BUY', '2027-12-31', '100');   // আগে কেনা, মেয়াদ পরে
        $this->lot('SOON', '2026-10-31', '100');      // পরে কেনা, মেয়াদ আগে

        $taken = $this->take('10');

        $this->assertCount(1, $taken);
        $this->assertSame('SOON', $taken[0]['batch']->batch_no);
    }

    /**
     * একটা লটে না কুলালে পরেরটা থেকে বাকিটা।
     *
     * পাঁচটা চাইলেন, পুরনো লটে তিনটা — তিনটা ওখান থেকে, দুইটা পরেরটা
     * থেকে। পুরনোটা খালি করাই তো পুরো উদ্দেশ্য; "যথেষ্ট নেই" বলে
     * ফিরিয়ে দিলে ক্যাশিয়ার হাতে বাছতেন, আর আবার প্রথমটাই।
     */
    public function test_it_spills_into_the_next_lot_when_one_is_not_enough(): void
    {
        $this->lot('FIRST', '2026-10-31', '3');
        $this->lot('SECOND', '2027-01-31', '10');

        $taken = $this->take('5');

        $this->assertCount(2, $taken);

        $this->assertSame('FIRST', $taken[0]['batch']->batch_no);
        $this->assertSame(0, bccomp($taken[0]['qty'], '3', 4));

        $this->assertSame('SECOND', $taken[1]['batch']->batch_no);
        $this->assertSame(0, bccomp($taken[1]['qty'], '2', 4));
    }

    public function test_the_pieces_add_up_to_what_was_asked_for(): void
    {
        $this->lot('A', '2026-10-31', '2');
        $this->lot('B', '2026-11-30', '2');
        $this->lot('C', '2026-12-31', '2');

        $total = array_reduce(
            $this->take('5'),
            fn (string $sum, array $row) => bcadd($sum, $row['qty'], 4),
            '0',
        );

        $this->assertSame(0, bccomp($total, '5', 4));
    }

    /** খালি লট এড়িয়ে যায় — শূন্যের সারি ফেরত দেয় না। */
    public function test_an_empty_lot_is_skipped(): void
    {
        $this->lot('EMPTY', '2026-09-30', '0');
        $this->lot('FULL', '2027-01-31', '10');

        $taken = $this->take('4');

        $this->assertCount(1, $taken);
        $this->assertSame('FULL', $taken[0]['batch']->batch_no);
    }

    // ── মেয়াদ ─────────────────────────────────────────────────────

    /**
     * মেয়াদোত্তীর্ণ লট বাছাইয়েই আসে না।
     *
     * সতর্কতা নয়, বাধা — আর সেটা এখানেই, কারণ পর্দায় সতর্ক করলে
     * ব্যস্ত কাউন্টারে কেউ পড়ে না।
     */
    public function test_an_expired_lot_is_never_taken(): void
    {
        $on = Carbon::parse('2026-08-12');

        $this->lot('GONE', '2026-08-01', '100');
        $this->lot('GOOD', '2027-01-31', '10');

        $taken = $this->take('4', $on);

        $this->assertCount(1, $taken);
        $this->assertSame('GOOD', $taken[0]['batch']->batch_no);
    }

    /**
     * মেয়াদোত্তীর্ণ মাল গুদামে থাকলেও "যথেষ্ট নেই"।
     *
     * সংখ্যাটা তাকে আছে, কিন্তু বেচার মতো নেই — আর বার্তাটা সেটাই বলে,
     * যাতে দোকানদার গুদামে গিয়ে অবাক না হন।
     */
    public function test_expired_stock_does_not_count_towards_what_is_available(): void
    {
        $on = Carbon::parse('2026-08-12');

        $this->lot('GONE', '2026-08-01', '100');
        $this->lot('GOOD', '2027-01-31', '2');

        $this->expectException(ValidationException::class);

        $this->take('5', $on);
    }

    /** মেয়াদহীন লট সবার শেষে, কিন্তু আসে। */
    public function test_a_lot_with_no_expiry_is_used_last_but_is_used(): void
    {
        $this->lot('NOEXP', null, '10');
        $this->lot('DATED', '2026-10-31', '3');

        $taken = $this->take('5');

        $this->assertSame('DATED', $taken[0]['batch']->batch_no);
        $this->assertSame('NOEXP', $taken[1]['batch']->batch_no);
    }

    // ── যখন দেওয়া যায় না ─────────────────────────────────────────

    /**
     * পুরোটা না পেলে কিছুই দেওয়া হয় না।
     *
     * আংশিক ফেরত দিলে ডাকা কোড ভাবত কাজ হয়ে গেছে, আর বাকিটা নীরবে
     * হারাত — নয়তো লট ছাড়া বেরোত, যেটা ঠিক ওই সুতোটাই ছিঁড়ত যার জন্য
     * ব্যাচ আছে।
     */
    public function test_nothing_is_allocated_when_the_whole_amount_is_not_there(): void
    {
        $this->lot('A', '2026-10-31', '3');

        try {
            $this->take('10');
            $this->fail('যথেষ্ট না থাকলেও বাছাই হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }
    }

    public function test_asking_for_nothing_is_refused(): void
    {
        $this->lot('A', '2026-10-31', '10');

        $this->expectException(ValidationException::class);

        $this->take('0');
    }

    // ── দেখা বনাম নেওয়া ──────────────────────────────────────────

    /**
     * দেখা (preview) যথেষ্ট না থাকলেও ব্যতিক্রম ছোড়ে না।
     *
     * পর্দায় "কোন ব্যাচ যাবে" দেখানো একটা প্রশ্ন, আদেশ নয়। বসানোর
     * সময় `allocate()` আবার চলে — তালাসহ — কারণ দেখা আর বসা এক মুহূর্ত
     * নয়, আর মাঝখানে অন্য কাউন্টার পুরোটা নিয়ে যেতে পারে।
     */
    public function test_preview_shows_what_it_can_without_refusing(): void
    {
        $this->lot('A', '2026-10-31', '3');

        $shown = $this->allocator()->preview($this->product, $this->warehouse, '10');

        $this->assertCount(1, $shown);
        $this->assertSame(0, bccomp($shown[0]['qty'], '3', 4));
    }

    /** দেখা আর নেওয়া একই ক্রম দেয়। */
    public function test_preview_and_allocate_agree_on_the_order(): void
    {
        $this->lot('SECOND', '2027-01-31', '10');
        $this->lot('FIRST', '2026-10-31', '3');

        $shown = array_map(
            fn ($row) => $row['batch']->batch_no,
            $this->allocator()->preview($this->product, $this->warehouse, '5'),
        );
        $taken = array_map(fn ($row) => $row['batch']->batch_no, $this->take('5'));

        $this->assertSame($shown, $taken);
    }

    // ── গুদাম ────────────────────────────────────────────────────

    /**
     * অন্য গুদামের মাল এই গুদামের বিক্রয়ে আসে না।
     *
     * লট এক, কিন্তু মাল দুই জায়গায় — আর ঢাকার কাউন্টার চট্টগ্রামের
     * তাক থেকে বেচতে পারে না।
     */
    public function test_stock_in_another_warehouse_is_not_offered(): void
    {
        $other = Warehouse::query()->where('is_default', false)->first()
            ?? Warehouse::query()->create([
                'company_id' => $this->company->id,
                'code' => 'WH2',
                'name_en' => 'Second store',
                'is_default' => false,
            ]);

        $batch = $this->lot('A', '2026-10-31', '10');

        StockMovement::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $other->id,
            'batch_id' => $batch->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => '50',
            'source_type' => 'test',
            'source_id' => 2,
            'document_no' => 'TEST-0002',
        ]);

        // এই গুদামে ১০, অন্যটায় ৫০ — ২০ চাইলে কম পড়বে।
        $this->expectException(ValidationException::class);

        $this->take('20');
    }
}
