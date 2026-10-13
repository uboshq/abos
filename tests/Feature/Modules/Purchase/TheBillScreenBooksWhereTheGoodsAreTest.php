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
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বিলের পর্দা গুদাম পাঠায় না, তাই 4843b36f-এর "মালের শাখা" নিয়ম সেখানে খাটত না (cb-র রিভিউ, cloud/purchase-fixes
 * মেশানোর আগে, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ বিলের পর্দার পথ ([[PurchaseBillService::create()]], গুদাম ছাড়া): (১) সাধারণ বিল — মাল যায় মূল গুদামে, তাই খাতাও মূল
 * গুদামের শাখায়, হেডারের নয়; (২) চালানের বিল — সারিগুলো যে চালানের, তার গুদামের শাখায়, যাতে ২১৬০ শাখা ধরে শূন্য হয়।
 */
final class TheBillScreenBooksWhereTheGoodsAreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_from_the_screen_books_in_the_branch_of_the_warehouse_its_goods_use(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $away = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $main = Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->firstOrFail();
        $this->assertSame((int) $home->id, (int) $main->branch_id, 'প্রস্তুতিটাই ভুল — মূল গুদাম মূল শাখায় নয়।');

        CompanyContext::set($company->id, $home->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();
        $awayStore = Warehouse::query()->create(['code' => 'WH-AWAY-BILL', 'name_en' => 'Away store', 'is_active' => true, 'branch_id' => $away->id]);
        $product = Product::query()->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $bills = app(PurchaseBillService::class);

        // (১) হেডারে দূরের শাখা, বিলের পর্দা গুদাম পাঠায় না — মাল মূল গুদামে, খাতাও মূল শাখায়
        CompanyContext::set($company->id, $away->id);
        $plain = $bills->confirm($bills->create(['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'BSB-1'],
            [['product_id' => $product->id, 'qty' => '3', 'rate' => '100', 'tax' => '0']]));

        $this->assertSame((int) $home->id, (int) $plain->fresh()->branch_id, '⛔ বিল হেডারের শাখায়, অথচ মাল মূল গুদামে।');
        $this->assertSame([(int) $home->id], $this->ledgerBranches($plain->id), '⛔ বিলের খাতা মালের শাখায় নয়।');

        // (২) চালান দূরের গুদামে; হেডারে মূল শাখা; চালানের সারি ধরে বিল — খাতা চালানের শাখায়
        CompanyContext::set($company->id, $away->id);
        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $awayStore->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '4', 'rate' => '100']],
        );
        $receipt = app(PurchaseReceiptService::class)->confirm($receipt->fresh());

        CompanyContext::set($company->id, $home->id);
        $linked = $bills->confirm($bills->create(['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'BSB-2'],
            [['product_id' => $product->id, 'qty' => '4', 'rate' => '100', 'tax' => '0', 'purchase_receipt_line_id' => $receipt->lines->first()->id]]));

        $this->assertSame((int) $away->id, (int) $linked->fresh()->branch_id, '⛔ চালানের বিল হেডারের শাখায়, অথচ চালান অন্য শাখার।');
        $this->assertSame([(int) $away->id], $this->ledgerBranches($linked->id), '⛔ চালানের বিলের ২১৬০ চালানের শাখায় সরেনি।');
    }

    /** @return list<int> */
    private function ledgerBranches(int $billId): array
    {
        return LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $billId)
            ->distinct()->pluck('branch_id')->map(fn ($v) => (int) $v)->values()->all();
    }
}
