<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খাতা এক শাখায় বসত, মাল ঢুকত আরেক শাখার গুদামে — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⛔ বিল আর ক্রয়-ফেরতের শাখা আসত হেডারের বাছাই থেকে (`$data['branch_id'] ?? CompanyContext::branchId()`),
 * অথচ মাল ঢুকত/বেরোত কাগজের গুদামে। ⓘ এখানে মানুষটা হেডারে শাখা A বেছে শাখা B-র গুদামে কেনেন আর
 * ফেরত দেন — কাগজ, দেনা আর মজুদের দাখিলা সব B-তে বসার কথা, যেখানে মাল।
 */
final class TheBooksWereInOneBranchAndTheGoodsInAnotherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_and_its_return_follow_the_warehouses_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = $company->defaultBranch();
        $b = Branch::query()->where('company_id', $company->id)->whereKeyNot($a->id)->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ হেডারে শাখা A
        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $whB = Warehouse::query()->create(['code' => 'WH-B-BOOKS', 'name_en' => 'B store', 'is_active' => true, 'branch_id' => $b->id]);
        $product = Product::query()->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whB->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'BRANCH-BOOKS-1',
        ], [['product_id' => $product->id, 'qty' => '10', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']])['bill'];

        $this->assertSame($b->id, (int) $bill->fresh()->branch_id, '⛔ বিল শাখা A-তে, অথচ মাল শাখা B-র গুদামে।');
        $this->assertSame([$b->id], $this->ledgerBranches(PurchaseBill::drillSourceType(), $bill->id),
            '⛔ বিলের দেনা আর মজুদের দাখিলা মালের শাখায় বসেনি।');

        $bill->load('lines');
        $return = app(PurchaseReturnService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whB->id,
            'purchase_bill_id' => $bill->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->lines->first()->id]]);

        $this->assertSame($b->id, (int) $return->fresh()->branch_id, '⛔ ফেরত শাখা A-তে, অথচ মাল বেরোয় শাখা B-র গুদাম থেকে।');

        app(PurchaseReturnService::class)->confirm($return->fresh());

        $this->assertSame([$b->id], $this->ledgerBranches(PurchaseReturn::drillSourceType(), $return->id),
            '⛔ ফেরতের দাখিলা মালের শাখায় বসেনি।');
    }

    /** @return list<int> */
    private function ledgerBranches(string $type, int $id): array
    {
        return LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', $type)->where('source_id', $id)
            ->distinct()->pluck('branch_id')->map(fn ($v) => (int) $v)->values()->all();
    }
}
