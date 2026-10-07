<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খোলা মজুদের কার্টের দ্বিতীয় ধাপ — মালিক, ৬ অক্টোবর ২০২৬: *"Quantity * Free"*, *"Rate, Markup, Margin, Sales price"*,
 * *"লট Opening দেবে"*।
 *
 * ⭐ ফ্রি একই লটে বসে, খরচের স্তরে বা খাতায় নয়; বিক্রয়মূল্য লিখলে পণ্যের দাম ও নীতি বদলায় ক্রয়ের নিয়মে, না লিখলে
 * পুরনো দাম অক্ষত; খালি লট "Opening", আর একই পণ্যে আবার দিলে সেই লটেই যোগ হয় — হাতে লেখা লট দুইবার নয়।
 */
final class TheOpeningCartHadNoFreeNoPriceAndNoOpeningLotTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->store = Warehouse::query()->create(['code' => 'CART2', 'name_en' => 'Cart store 2', 'is_active' => true,
            'branch_id' => $company->defaultBranch()?->id]);
    }

    public function test_free_goes_into_the_same_lot_with_no_cost(): void
    {
        $p = $this->product('FREE-A');

        $this->cart([['product' => 'FREE-A', 'qty' => '10', 'free_qty' => '2', 'unit_cost' => '50', 'batch_no' => 'F-1']])
            ->assertSessionHasNoErrors();

        $moves = StockMovement::query()->where('product_id', $p->id)->where('warehouse_id', $this->store->id)->get();
        $this->assertSame(0, bccomp((string) $moves->sum('floor_change'), '10', 4), '⛔ বিক্রয়যোগ্য পরিমাণ ১০ নয়।');
        $this->assertSame(0, bccomp((string) $moves->sum('free_change'), '2', 4), '⛔ ফ্রি ২ বসেনি।');
        $this->assertSame(1, $moves->pluck('batch_id')->unique()->count(), '⛔ ফ্রি আলাদা লটে গেল।');

        $this->assertSame(0, bccomp((string) CostLayer::query()->where('product_id', $p->id)->sum('qty_in'), '10', 4), '⛔ ফ্রি খরচের স্তরে ঢুকল।');
        $debit = LedgerEntry::query()->where('source_type', 'opening_stock')->whereIn('source_id', $moves->pluck('id'))->sum('debit');
        $this->assertSame(0, bccomp((string) $debit, '500', 2), '⛔ খাতায় ফ্রির দাম উঠল — মূল্য ১০ × ৫০ = ৫০০ হওয়ার কথা।');
    }

    public function test_a_sales_price_with_markup_sets_the_product_price_and_policy(): void
    {
        $p = $this->product('PRICE-A', ['sale_price' => '70', 'purchase_price' => '40']);

        $this->cart([['product' => 'PRICE-A', 'qty' => '5', 'unit_cost' => '50', 'batch_no' => 'P-1',
            'sales_price' => '60', 'pricing_anchor' => 'markup', 'pricing_pct' => '20']])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame(0, bccomp((string) $p->purchase_price, '50', 4), '⛔ কেনা দর পণ্যে বসেনি।');
        $this->assertSame(0, bccomp((string) $p->sale_price, '60', 4), '⛔ বিক্রয়মূল্য পণ্যে বসেনি।');
        $this->assertSame('markup', (string) $p->pricing_anchor, '⛔ নীতি বসেনি।');
        $this->assertSame(0, bccomp((string) $p->pricing_pct, '20', 4), '⛔ নীতির শতাংশ বসেনি।');
    }

    public function test_a_sales_price_anchor_keeps_no_percent(): void
    {
        $p = $this->product('PRICE-S', ['pricing_anchor' => 'markup', 'pricing_pct' => '15']);

        $this->cart([['product' => 'PRICE-S', 'qty' => '1', 'unit_cost' => '80', 'batch_no' => 'S-1',
            'sales_price' => '100', 'pricing_anchor' => 'sales_price', 'pricing_pct' => '25']])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('sales_price', (string) $p->pricing_anchor);
        $this->assertNull($p->pricing_pct, '⛔ দাম নোঙর হলেও শতাংশ বসল — ক্রয়ের নিয়মে কেবল markup/margin-এ শতাংশ।');
    }

    public function test_a_blank_sales_price_leaves_the_old_price_and_policy(): void
    {
        $p = $this->product('PRICE-B', ['sale_price' => '70', 'purchase_price' => '40', 'pricing_anchor' => 'margin', 'pricing_pct' => '30']);

        $this->cart([['product' => 'PRICE-B', 'qty' => '5', 'unit_cost' => '45', 'batch_no' => 'B-1', 'sales_price' => '',
            'pricing_anchor' => 'markup', 'pricing_pct' => '99']])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame(0, bccomp((string) $p->purchase_price, '45', 4), '⛔ কেনা দর পণ্যে বসেনি।');
        $this->assertSame(0, bccomp((string) $p->sale_price, '70', 4), '⛔ বিক্রয়মূল্য না লিখেও দাম বদলে গেল।');
        $this->assertSame('margin', (string) $p->pricing_anchor, '⛔ বিক্রয়মূল্য না লিখেও নীতি বদলে গেল।');
        $this->assertSame(0, bccomp((string) $p->pricing_pct, '30', 4));
    }

    public function test_a_blank_lot_is_opening_and_a_second_time_adds_into_it(): void
    {
        $p = $this->product('OPEN-LOT');

        $this->cart([['product' => 'OPEN-LOT', 'qty' => '4', 'unit_cost' => '10', 'batch_no' => '']])->assertSessionHasNoErrors();
        $this->cart([['product' => 'OPEN-LOT', 'qty' => '6', 'unit_cost' => '10', 'batch_no' => '']])->assertSessionHasNoErrors();

        $lots = Batch::query()->where('product_id', $p->id)->pluck('batch_no')->all();
        $this->assertSame(['Opening'], $lots, '⛔ খালি লট একটাই "Opening" লট নয়।');
        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('floor_change'), '10', 4),
            '⛔ দ্বিতীয়বার "Opening" লটে যোগ হয়নি।');
    }

    public function test_a_hand_written_lot_still_goes_in_only_once(): void
    {
        $p = $this->product('HAND-LOT');

        $this->cart([['product' => 'HAND-LOT', 'qty' => '4', 'unit_cost' => '10', 'batch_no' => 'H-1']])->assertSessionHasNoErrors();
        $this->cart([['product' => 'HAND-LOT', 'qty' => '6', 'unit_cost' => '10', 'batch_no' => 'H-1']])->assertSessionHasErrors();

        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('floor_change'), '4', 4),
            '⛔ হাতে লেখা একই লট দুইবার বসল — যোগ করা কেবল "Opening"-এর জন্য।');
    }

    public function test_the_picked_product_id_wins_over_the_typed_text(): void
    {
        $picked = $this->product('PICK-A');
        $this->product('PICK-B');

        $this->cart([['product' => 'PICK-B — anything', 'product_id' => (string) $picked->id, 'qty' => '3', 'unit_cost' => '10', 'batch_no' => 'K-1']])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, StockMovement::query()->where('product_id', $picked->id)->count(), '⛔ সার্চ থেকে বাছা পণ্য বসেনি।');
    }

    /**
     * ⭐ প্রিন্সিপালের তালিকায় কেবল এই শাখার কারখানা — মালিক, ৭ অক্টোবর ২০২৬ (ছবি: লায়নের সারিতে "Star Line (Super)" আর
     * পরিবহনের "Hirra Miya")। ⛔ অন্য শাখার প্রিন্সিপাল আর সেবাদাতা তালিকায় নেই, আর সার্ভারও সেবাদাতাকে প্রিন্সিপাল নেয় না।
     */
    public function test_the_principal_list_keeps_to_the_branch_and_leaves_out_service_providers(): void
    {
        $company = CompanyContext::id();
        $here = (int) CompanyContext::branchId();
        $there = \App\Models\Branch::create(['company_id' => $company, 'code' => 'ZLION', 'name_en' => 'Lion'])->id;
        $vendor = \App\Modules\MasterData\Models\PartyType::query()->firstOrCreate(['company_id' => $company, 'code' => 'VENDOR'], ['name_en' => 'Principal', 'applies_to' => 'supplier', 'is_active' => true]);
        $transport = \App\Modules\MasterData\Models\PartyType::query()->firstOrCreate(['company_id' => $company, 'code' => 'TRANSPORT'], ['name_en' => 'Transport', 'applies_to' => 'supplier', 'is_active' => true]);

        $row = fn (string $code, int $branch, int $type) => \Illuminate\Support\Facades\DB::table('suppliers')->insertGetId([
            'company_id' => $company, 'branch_id' => $branch, 'party_type_id' => $type, 'code' => $code, 'name_en' => $code,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mine = $row('ZP-HERE', $here, $vendor->id);
        $theirs = $row('ZP-THERE', $there, $vendor->id);
        $lorry = $row('ZT-LORRY', $here, $transport->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner)->post(route('branch.switch'), ['branch_id' => (string) $here]);
        $listed = array_keys($this->actingAs($owner->fresh())->get(route('inventory.stock.opening'))->assertOk()->viewData('suppliers'));

        $this->assertContains($mine, $listed, 'প্রস্তুতিটাই ভুল — নিজের শাখার প্রিন্সিপাল নেই।');
        $this->assertNotContains($theirs, $listed, '⛔ অন্য শাখার প্রিন্সিপাল তালিকায়।');
        $this->assertNotContains($lorry, $listed, '⛔ পরিবহন (সেবাদাতা) প্রিন্সিপালের তালিকায়।');

        $p = $this->product('PRIN-T');
        $this->cart([['product' => 'PRIN-T', 'qty' => '1', 'unit_cost' => '10', 'batch_no' => 'PT-1', 'supplier_id' => (string) $lorry]])
            ->assertSessionHasErrors();
        $this->assertSame(0, StockMovement::query()->where('product_id', $p->id)->count(), '⛔ সেবাদাতাকে প্রিন্সিপাল ধরে মজুদ বসেছে।');
    }

    /**
     * ⭐ কেবল পরের দিনের ক্রয় ঢুকেছে, কিছু বের হয়নি — খোলা মজুদ তার আগের তারিখে বসে (মালিক, ৭ অক্টোবর ২০২৬: SL Lion WH-এ ১৪টা
     * পণ্য "ইতিমধ্যেই নড়াচড়া করেছে", আসলে কেবল ৬ অক্টোবরের ক্রয়-বিল)। ⛔ পরের তারিখে নয় (বার্তা দিন বলে), আর কিছু বের হলে একেবারেই নয়।
     */
    public function test_opening_goes_in_before_a_later_purchase_but_not_after_goods_went_out(): void
    {
        $p = $this->product('LATE-A');
        $stock = app(\App\Modules\Inventory\Services\StockService::class);
        $bought = now()->subDays(1)->toDateString();
        $stock->move(product: $p, warehouse: $this->store, sourceType: 'purchase_bill', sourceId: 7777, floor: '50', date: $bought, documentNo: 'PBL-7777');

        $row = [['product' => 'LATE-A', 'qty' => '10', 'unit_cost' => '20', 'batch_no' => '']];

        // ⛔ ক্রয়ের পরের দিনে — বার্তা ক্রয়ের তারিখটা বলে দেয়
        $this->post(route('inventory.stock.opening.cart'), ['warehouse_id' => $this->store->id, 'trx_date' => now()->toDateString(), 'rows' => $row])
            ->assertSessionHasErrors();
        $this->assertSame(0, StockMovement::query()->where('product_id', $p->id)->where('source_type', 'opening')->count(), '⛔ ক্রয়ের পরের তারিখে খোলা মজুদ বসল — FIFO-র সারিতে শেষে দাঁড়াত।');

        // ⭐ ক্রয়ের আগের দিনে — বসে
        $this->post(route('inventory.stock.opening.cart'), ['warehouse_id' => $this->store->id, 'trx_date' => now()->subDays(2)->toDateString(), 'rows' => $row])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, StockMovement::query()->where('product_id', $p->id)->where('source_type', 'opening')->count(), '⛔ কেবল পরের ক্রয় থাকায়ও খোলা মজুদ নিল না।');

        // ⛔ মাল বেরোলে আর নয়, আগের তারিখ দিলেও
        $q = $this->product('LATE-B');
        $stock->move(product: $q, warehouse: $this->store, sourceType: 'purchase_bill', sourceId: 7778, floor: '50', date: $bought, documentNo: 'PBL-7778');
        $stock->move(product: $q, warehouse: $this->store, sourceType: 'sales_challan', sourceId: 7779, floor: '-5', date: $bought, documentNo: 'CHA-7779');
        $this->post(route('inventory.stock.opening.cart'), ['warehouse_id' => $this->store->id, 'trx_date' => now()->subDays(2)->toDateString(),
            'rows' => [['product' => 'LATE-B', 'qty' => '10', 'unit_cost' => '20', 'batch_no' => '']]])->assertSessionHasErrors();
        $this->assertSame(0, StockMovement::query()->where('product_id', $q->id)->where('source_type', 'opening')->count(), '⛔ মাল বেরোনোর পরেও খোলা মজুদ বসল।');
    }

    /** @param  list<array<string, string>>  $rows */
    private function cart(array $rows)
    {
        return $this->post(route('inventory.stock.opening.cart'), [
            'warehouse_id' => $this->store->id,
            'trx_date' => now()->toDateString(),
            'rows' => $rows,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function product(string $code, array $extra = []): Product
    {
        return Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true,
            'track_batch' => true, 'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id] + $extra);
    }

    /**
     * ⭐ খোলা মজুদের ফ্রি কাউন্টারে দেওয়া যায় — মালিক, ৬ অক্টোবর ২০২৬: *"সুপারে ফ্রি দেওয়া যায় না — সর্বাধিক 0"*।
     * ⛔ ফ্রির অনুপাত কেবল ক্রয় থেকে গোনা হত; খোলা মজুদের লটে ফ্রি থাকলেও "যতটা এসেছিল" শূন্য।
     */
    public function test_free_that_came_with_the_opening_counts_toward_the_ratio(): void
    {
        $p = $this->product('FREE-R');

        $this->cart([['product' => 'FREE-R', 'qty' => '24', 'free_qty' => '1', 'unit_cost' => '50', 'batch_no' => '']])
            ->assertSessionHasNoErrors();

        $batch = Batch::query()->where('product_id', $p->id)->firstOrFail();
        $ratio = app(\App\Modules\Inventory\Services\FreeRatio::class);

        $this->assertSame(0, bccomp($ratio->allowedOn($batch, '24'), '1', 4), '⛔ খোলা মজুদের ২৪:১ লটে ২৪ বেচলে ১ ফ্রি দেওয়া যায় না।');
        $this->assertSame(0, bccomp($ratio->allowedOn($batch, '23'), '0', 4), '⛔ অনুপাতের কম বেচলেও ফ্রি দেওয়া যাচ্ছে।');
    }
}
