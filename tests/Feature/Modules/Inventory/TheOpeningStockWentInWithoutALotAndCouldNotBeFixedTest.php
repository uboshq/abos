<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খোলা মজুদ লট ছাড়া বসে গেল, আর ঠিক করার পথ ছিল না — মালিক, ৬ অক্টোবর ২০২৬: *"খোলা মজুদ দিতে গিয়ে ভুলে লট ছাড়া
 * সেভ করে ফেলেছি, এডিটের ব্যবস্থা কী?"* (লাইভে ADI, গুদাম ১০, ১৫টা সারি "Opening" লটে)।
 *
 * ⭐ এখন প্রতিটা সারিতে সংশোধন আর মুছে ফেলা ([[OpeningStockService::correct()]], [[OpeningStockService::remove()]]):
 * লট বদল খাতায় কিছু বসায় না; পরিমাণ বা দর বদলালে খাতার মজুদ নতুন মূল্যে (উল্টো + নতুন দাখিলা, কোনো সারি বদলায় না);
 * মাল নড়ে থাকলে কিছুই বদলায় না; খাতার শিকল অক্ষত।
 */
final class TheOpeningStockWentInWithoutALotAndCouldNotBeFixedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->companyId = (int) $company->id;
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->store = Warehouse::query()->create(['code' => 'FIX', 'name_en' => 'Fix store', 'is_active' => true,
            'branch_id' => $company->defaultBranch()?->id]);
    }

    public function test_a_lot_change_moves_the_goods_and_posts_nothing(): void
    {
        $p = $this->product('FIX-LOT');
        $row = $this->enter('FIX-LOT', '10', '50', '');
        $this->assertSame('Opening', Batch::query()->find($row->batch_id)?->batch_no);

        $entries = LedgerEntry::query()->count();
        $books = $this->inventoryOnBooks();

        $this->get(route('inventory.stock.opening.edit', $row->id))->assertOk()->assertSee('FIX-LOT');
        $this->put(route('inventory.stock.opening.update', $row->id), ['batch_no' => 'REAL-1', 'expiry_date' => '2027-12-31', 'qty' => '10', 'unit_cost' => '50'])
            ->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock.opening'));

        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ কেবল লট বদলেও খাতায় দাখিলা বসল।');
        $this->assertSame(0, bccomp($books, $this->inventoryOnBooks(), 4));

        $real = Batch::query()->where('product_id', $p->id)->where('batch_no', 'REAL-1')->firstOrFail();
        $this->assertSame('2027-12-31', $real->expiry_date?->toDateString(), '⛔ নতুন লটের মেয়াদ বসেনি।');
        $this->assertSame(0, bccomp($this->onLot($p, $real), '10', 4), '⛔ মাল নতুন লটে যায়নি।');
        $this->assertSame(0, bccomp($this->onLot($p, Batch::query()->findOrFail($row->batch_id)), '0', 4), '⛔ "Opening" লটে মাল রয়ে গেল।');

        $layer = CostLayer::query()->where('product_id', $p->id)->get();
        $this->assertCount(1, $layer, '⛔ পুরনো স্তর রয়ে গেল বা নতুন স্তর বসেনি।');
        $this->assertSame((int) $real->id, (int) $layer->first()->batch_id, '⛔ স্তর নতুন লট চেনে না — FIFO ভুল লট থেকে টানবে।');

        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'], '⛔ খাতার শিকল ভাঙল।');
    }

    public function test_a_quantity_or_rate_change_puts_the_new_value_on_the_books(): void
    {
        $p = $this->product('FIX-VAL');
        $books = $this->inventoryOnBooks();
        $row = $this->enter('FIX-VAL', '10', '50', 'V-1');
        $this->assertSame(0, bccomp(bcsub($this->inventoryOnBooks(), $books, 4), '500', 4));

        $this->put(route('inventory.stock.opening.update', $row->id), ['batch_no' => 'V-1', 'qty' => '8', 'free_qty' => '2', 'unit_cost' => '55'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp(bcsub($this->inventoryOnBooks(), $books, 4), '440', 4), '⛔ খাতার মজুদ নতুন মূল্যে (৮ × ৫৫ = ৪৪০) নয়।');
        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('floor_change'), '8', 4));
        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('free_change'), '2', 4), '⛔ ফ্রি বসেনি।');
        $this->assertSame(1, LedgerEntry::query()->where('source_type', 'opening_stock:withdrawn')->count() / 2, '⛔ পুরনো মূল্যের উল্টো দাখিলা একটা নয়।');

        // ⓘ দুইবার সংশোধন — দ্বিতীয়টাও আগের মূল্য থেকেই ফেরে
        $again = StockMovement::query()->where('product_id', $p->id)->where('source_type', 'opening')->orderByDesc('id')->firstOrFail();
        $this->put(route('inventory.stock.opening.update', $again->id), ['batch_no' => 'V-1', 'qty' => '8', 'unit_cost' => '60'])->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp(bcsub($this->inventoryOnBooks(), $books, 4), '480', 4), '⛔ দ্বিতীয় সংশোধনে খাতা ভুল।');
        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('free_change'), '0', 4), '⛔ আগের ফ্রি উল্টায়নি।');

        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'], '⛔ খাতার শিকল ভাঙল।');
        $this->assertTrue(DB::table('audit_trails')->where('action', OpeningStockService::AUDIT_CORRECTED)->exists(), '⛔ নিরীক্ষায় আগে-পরে নেই।');
    }

    public function test_a_row_whose_goods_moved_does_not_change(): void
    {
        $p = $this->product('FIX-SOLD');
        $row = $this->enter('FIX-SOLD', '10', '50', 'S-1');

        // ⓘ খোলা মজুদের পরে যেকোনো চলাচল — এখানে একটা সমন্বয়
        app(StockService::class)->move(product: $p, warehouse: $this->store, sourceType: 'adjustment', sourceId: 1, floor: '-1',
            documentNo: 'ADJ-1', batch: Batch::query()->findOrFail($row->batch_id));
        $entries = LedgerEntry::query()->count();

        $this->put(route('inventory.stock.opening.update', $row->id), ['batch_no' => 'S-1', 'qty' => '20', 'unit_cost' => '50'])
            ->assertSessionHasErrors('movement');
        $this->delete(route('inventory.stock.opening.destroy', $row->id))->assertSessionHasErrors('movement');

        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('floor_change'), '9', 4), '⛔ নড়া মালের সারি বদলে গেল।');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ খাতায় দাখিলা বসল।');
    }

    public function test_remove_reverses_stock_layer_and_books_and_the_lot_can_go_in_again(): void
    {
        $p = $this->product('FIX-DEL');
        $books = $this->inventoryOnBooks();
        $row = $this->enter('FIX-DEL', '4', '25', 'D-1');

        $this->delete(route('inventory.stock.opening.destroy', $row->id))->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock.opening'));

        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $p->id)->sum('floor_change'), '0', 4), '⛔ মজুদ ফেরেনি।');
        $this->assertSame(0, CostLayer::query()->where('product_id', $p->id)->count(), '⛔ খরচের স্তর রয়ে গেল।');
        $this->assertSame(0, bccomp($this->inventoryOnBooks(), $books, 4), '⛔ খাতার মজুদ ফেরেনি।');
        $this->assertTrue(DB::table('audit_trails')->where('action', OpeningStockService::AUDIT_REMOVED)->exists(), '⛔ নিরীক্ষায় নেই।');

        // ⓘ একই লট আবার — মুছে ফেলা সারি "আগেই বসানো" নয়; আর তালিকায় মুছে ফেলা সারি নেই
        $this->enter('FIX-DEL', '4', '25', 'D-1');
        $this->get(route('inventory.stock.opening'))->assertOk()->assertSee('data-opening-edit', false);
        $this->assertSame(1, substr_count($this->get(route('inventory.stock.opening'))->getContent(), 'FIX-DEL - '), '⛔ মুছে ফেলা সারিও তালিকায়।');

        // ⓘ মুছে ফেলা সারি আবার সংশোধন হয় না
        $this->put(route('inventory.stock.opening.update', $row->id), ['batch_no' => 'D-1', 'qty' => '1', 'unit_cost' => '25'])->assertSessionHasErrors('movement');
        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'], '⛔ খাতার শিকল ভাঙল।');
    }

    public function test_only_an_opening_row_opens(): void
    {
        $p = $this->product('FIX-OTHER');
        $other = app(StockService::class)->move(product: $p, warehouse: $this->store, sourceType: 'adjustment', sourceId: 1, floor: '3',
            documentNo: 'ADJ-2', batch: app(\App\Modules\Inventory\Services\BatchService::class)->receive(product: $p, batchNo: 'O-1'));

        $this->get(route('inventory.stock.opening.edit', $other->id))->assertNotFound();
        $this->delete(route('inventory.stock.opening.destroy', $other->id))->assertNotFound();
    }

    private function enter(string $code, string $qty, string $cost, string $lot): StockMovement
    {
        $this->post(route('inventory.stock.opening.cart'), [
            'warehouse_id' => $this->store->id,
            'trx_date' => now()->toDateString(),
            'rows' => [['product' => $code, 'qty' => $qty, 'unit_cost' => $cost, 'batch_no' => $lot]],
        ])->assertSessionHasNoErrors();

        return StockMovement::query()->where('warehouse_id', $this->store->id)->where('source_type', 'opening')->orderByDesc('id')->firstOrFail();
    }

    /** খাতায় মজুদ খাতের জের — খোলা মজুদের দাখিলা আর তার উল্টো */
    private function inventoryOnBooks(): string
    {
        $account = StandardChart::find(StandardChart::INVENTORY);
        $row = LedgerEntry::query()->where('account_id', $account->id)->where('source_type', 'like', 'opening_stock%')
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')->value('net');

        return bcadd((string) ($row ?? '0'), '0', 4);
    }

    private function onLot(Product $p, Batch $batch): string
    {
        return bcadd((string) StockMovement::query()->where('product_id', $p->id)->where('batch_id', $batch->id)->sum('floor_change'), '0', 4);
    }

    private function product(string $code): Product
    {
        return Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true,
            'track_batch' => true, 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id]);
    }
}
