<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * লটের গতিপথ — রিপোর্ট সেন্টার ধাপ ৪ ([[InventoryControlReports::lotTrace()]])।
 *
 * LOT-R: ৫০ এল (৫ ফ্রি সহ), ২০ বেচা হলো (১ ফ্রি সহ), আর মাঝে তাক থেকে আটকে রাখা হলো (হাতে বদলায় না — সারি নয়)।
 * অন্য লট LOT-X একই সময়ে। ⓘ LOT-R বাছলে কেবল তার দুই সারি, ক্রমে; গুদামটা অন্য শাখার হলে এই শাখায় কিছুই নেই।
 */
final class ALotCanBeFollowedWhereverItWentTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    public function test_a_chosen_lot_shows_each_move_in_order_and_nothing_else(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'ZQ-LOT', 'name_en' => 'Lot probe', 'name_bn' => 'লটের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true,
        ]);
        $recalled = $this->lot('LOT-R');
        $other = $this->lot('LOT-X');

        $this->move($recalled, 'purchase_receipt', '2026-09-01', floor: '50', free: '5');
        // ⓘ অ্যাপের আটকানো তাক কমায় না, কেবল আটকায় ([[StockService::hold()]]) — গতিপথে তাই আসে না (অডিট ম১০, ৫ অক্টোবর ২০২৬)
        $this->move($recalled, 'quality_hold', '2026-09-02', hold: '3');
        $this->move($recalled, 'delivery_challan', '2026-09-03', floor: '-20', free: '-1');
        $this->move($other, 'purchase_receipt', '2026-09-02', floor: '10');

        $rows = $this->rows(['batch_id' => $recalled->id]);

        $this->assertSame([
            ['LOT-R', '2026-09-01', 'purchase_receipt', '50', '0', '5'],
            ['LOT-R', '2026-09-03', 'delivery_challan', '0', '20', '-1'],
        ], $rows, '⛔ লটের গতিপথ ভুল — অন্য লট এসেছে, আটকানো সারি এসেছে, বা ক্রম ভেঙেছে।');

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $this->warehouse->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertSame([], $this->rows(['batch_id' => $recalled->id, 'branch_id' => $company->defaultBranch()->id]),
            '⛔ অন্য শাখার গুদামের লট এই শাখায়।');
    }

    /** @return list<list<string>> লট, তারিখ, উৎস, এল, গেল, ফ্রি */
    private function rows(array $extra): array
    {
        $rows = app(ReportEngine::class)->run('inventory.lot_trace', [
            'from' => '2026-08-01', 'to' => '2026-09-30', ...$extra,
        ], perPage: 500)->rows;

        return array_map(fn ($r) => [
            (string) $r['batch_no'],
            substr((string) $r['trx_date'], 0, 10),
            (string) $r['source_type'],
            (string) round((float) $r['qty_in']),
            (string) round((float) $r['qty_out']),
            (string) round((float) $r['free_change']),
        ], $rows);
    }

    private function lot(string $no): Batch
    {
        return Batch::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $this->product->id, 'batch_no' => $no]);
    }

    private function move(Batch $lot, string $source, string $date, string $floor = '0', string $hold = '0', string $free = '0'): void
    {
        DB::table('inv_stock_movements')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->warehouse->branch_id,
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'batch_id' => $lot->id,
            'trx_date' => $date, 'floor_change' => $floor, 'hold_change' => $hold, 'free_change' => $free,
            'source_type' => $source, 'source_id' => 1, 'document_no' => strtoupper($source), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
