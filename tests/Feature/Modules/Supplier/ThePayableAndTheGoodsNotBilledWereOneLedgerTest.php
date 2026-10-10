<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরবরাহকারীর খাতায় দেনা আর বিল-না-আসা মাল এক খাতায় মিশে ছিল — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬ (মালিক, ১০ অক্টোবর ২০২৬: "দুই ভাগে দেখাও")।
 *
 * ⛔ পাতার "প্রদেয়" আর লেনদেনের ছক সরবরাহকারীর নামের সব সারি নিত — মাল-গ্রহণের GRNI (২১৬০) সারিও। তাই ১,০০০ টাকার মাল এসে
 * বিল না এলেও পাতা ১,৫০০ শোধযোগ্য দেখাত। ⓘ এখানে: ৫০০-র বিল (দেনা) আর ১,০০০-র বিল-না-আসা মাল।
 */
final class ThePayableAndTheGoodsNotBilledWereOneLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_supplier_page_shows_payable_and_goods_not_billed_as_two_parts(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '100']],
        ));

        app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TWO-PARTS-1',
        ], [['product_id' => $product->id, 'qty' => '5', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']]);

        $grni = Supplier::goodsNotBilledAccountIds();
        $this->assertTrue(
            LedgerEntry::query()->forParty(Supplier::drillSourceType(), $supplier->id)->whereIn('account_id', $grni)->exists(),
            'দাবির ভিত্তি: মাল-গ্রহণ সরবরাহকারীর নামে GRNI-তে বসেনি — দাবি অন্ধ।',
        );

        // ── প্রথম ভাগ: কেবল দেনা ──
        $first = $this->get(route('supplier.show', $supplier))->assertOk();
        $this->assertSame(0, bccomp((string) $first->viewData('payable'), '500', 4),
            "⛔ পাতার প্রদেয় {$first->viewData('payable')} — বিল-না-আসা মাল দেনায় মিশল।");
        $this->assertSame(0, bccomp((string) $first->viewData('goodsNotBilled'), '1000', 4),
            "⛔ বিল-না-আসা মাল {$first->viewData('goodsNotBilled')}, ১,০০০ নয়।");
        $this->assertSame([], collect($first->viewData('entries')->items())
            ->filter(fn (LedgerEntry $e) => in_array((int) $e->account_id, $grni, true))->all(),
            '⛔ দেনার ভাগে GRNI-র সারি।');
        $first->assertSee('data-goods-not-billed', false);

        // ── দ্বিতীয় ভাগ: কেবল বিল-না-আসা মাল ──
        $second = $this->get(route('supplier.show', [$supplier, 'part' => 'goods_not_billed']))->assertOk();
        $rows = collect($second->viewData('entries')->items());
        $this->assertNotEmpty($rows, '⛔ বিল-না-আসা মালের ভাগ খালি।');
        $this->assertTrue($rows->every(fn (LedgerEntry $e) => in_array((int) $e->account_id, $grni, true)),
            '⛔ বিল-না-আসা মালের ভাগে দেনার সারি।');
        $this->assertSame(0, bccomp((string) $rows->last()->net_balance, '-1000', 4),
            "⛔ দ্বিতীয় ভাগের শেষ জের {$rows->last()->net_balance} — ভাগের অঙ্কের সাথে মেলে না।");
        $this->assertSame('goods_not_billed', $second->viewData('part'));
        $this->assertNotNull($receipt);
    }
}
