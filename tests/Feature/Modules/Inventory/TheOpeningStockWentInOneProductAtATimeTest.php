<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খোলা মজুদ বসত এক পণ্যে এক ফর্মে — মালিক, ৬ অক্টোবর ২০২৬: *"এভাবে না দিয়ে পাশাপাশি করে দিলে হতো না"*।
 *
 * ⭐ এখন এক পর্দায় কার্ট: উপরে একবার গুদাম আর তারিখ, নিচে সারিপ্রতি এক পণ্য; এক চাপে সব সারি এক লেনদেনে
 * ([[OpeningStockService::bringInMany()]]), খাতায় একটা দাখিলা; খালি লট "Opening"; একটা সারি ভুল হলে কিছুই বসে না।
 */
final class TheOpeningStockWentInOneProductAtATimeTest extends TestCase
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
        $this->store = Warehouse::query()->create(['code' => 'CART', 'name_en' => 'Cart store', 'is_active' => true,
            'branch_id' => $company->defaultBranch()?->id]);
    }

    public function test_three_rows_go_in_with_one_press_one_ledger_entry_and_three_lots(): void
    {
        $a = $this->product('CART-A');
        $b = $this->product('CART-B');
        $c = $this->product('CART-C');

        $this->get(route('inventory.stock.opening'))->assertOk()->assertSee('data-opening-cart', false)->assertSee('openingCart(', false)->assertSee('CART-A');

        $this->post(route('inventory.stock.opening.cart'), [
            'warehouse_id' => $this->store->id,
            'trx_date' => now()->toDateString(),
            'rows' => [
                ['product' => 'CART-A — CART-A', 'qty' => '10', 'unit_cost' => '50', 'batch_no' => 'LOT-A1'],
                ['product' => 'CART-B', 'qty' => '4', 'unit_cost' => '25', 'batch_no' => ''],
                ['product' => '', 'qty' => ''],
                ['product' => 'CART-C — CART-C', 'qty' => '2', 'unit_cost' => '100', 'batch_no' => 'LOT-C1'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock.opening'));

        $moves = StockMovement::query()->where('warehouse_id', $this->store->id)->where('source_type', 'opening')->get();
        $this->assertCount(3, $moves, '⛔ তিন সারি এক চাপে বসেনি।');
        $this->assertSame(3, Batch::query()->whereIn('product_id', [$a->id, $b->id, $c->id])->count(), '⛔ তিনটা লট হয়নি।');

        $sources = LedgerEntry::query()->where('source_type', 'opening_stock')->whereIn('source_id', $moves->pluck('id'))->distinct()->pluck('source_id');
        $this->assertCount(1, $sources, '⛔ খাতায় সারিপ্রতি আলাদা দাখিলা — এক চাপে একটা হওয়ার কথা।');
        $debit = LedgerEntry::query()->where('source_type', 'opening_stock')->where('source_id', $sources->first())->sum('debit');
        $this->assertSame(0, bccomp((string) $debit, '800', 2), '⛔ দাখিলার মোট সব সারির মূল্য (৫০০ + ১০০ + ২০০) নয়।');

        // ⭐ খালি লট = "Opening" — মালিক, ৬ অক্টোবর ২০২৬ (আগে সিরিজ থেকে নম্বর)
        $autoLot = (string) Batch::query()->where('product_id', $b->id)->value('batch_no');
        $this->assertSame('Opening', $autoLot, '⛔ খালি লট "Opening" নামে বসেনি।');
    }

    public function test_one_wrong_row_puts_nothing_in_and_says_which_row(): void
    {
        $a = $this->product('CART-OK');
        $before = LedgerEntry::query()->where('source_type', 'opening_stock')->count();

        $this->post(route('inventory.stock.opening.cart'), [
            'warehouse_id' => $this->store->id,
            'rows' => [
                ['product' => 'CART-OK', 'qty' => '5', 'unit_cost' => '10', 'batch_no' => 'OK-1'],
                ['product' => 'NO-SUCH-CODE', 'qty' => '3', 'unit_cost' => '10'],
            ],
        ])->assertSessionHasErrors('rows.1.product');

        $this->post(route('inventory.stock.opening.cart'), [
            'warehouse_id' => $this->store->id,
            'rows' => [
                ['product' => 'CART-OK', 'qty' => '5', 'unit_cost' => '10', 'batch_no' => 'OK-1'],
                ['product' => 'CART-OK', 'qty' => '5', 'unit_cost' => '0', 'batch_no' => 'OK-2'],
            ],
        ])->assertSessionHasErrors('rows.1.unit_cost');

        $this->assertSame(0, StockMovement::query()->where('product_id', $a->id)->count(), '⛔ ভুল সারি থাকা সত্ত্বেও ঠিক সারিটা বসে গেল।');
        $this->assertSame($before, LedgerEntry::query()->where('source_type', 'opening_stock')->count(), '⛔ খাতায় দাখিলা বসল।');
    }

    private function product(string $code): Product
    {
        return Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true,
            'track_batch' => true, 'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id]);
    }
}
