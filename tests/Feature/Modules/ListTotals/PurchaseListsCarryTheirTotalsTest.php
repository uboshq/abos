<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ক্রয়ের তালিকাগুলোর নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   বিল        সারি · মোট · পরিশোধিত · বাকি (শোধ নেই, তাই বাকি = মোট)
 *   আদেশ, মাল গ্রহণ, ফেরত, পরিশোধ   সারি · মোট
 *
 * ⓘ ৫১টা কাগজ × ১০০ (দুই পাতা) আর ৩টা × ৭ — দুই ছাঁকনিতেই পট্টি ডাটাবেজের সরাসরি গোনা ও যোগ বলে।
 */
final class PurchaseListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_purchase_lists_count_and_sum_the_whole_filtered_set(): void
    {
        $supplier = (int) DB::table('suppliers')->where('company_id', CompanyContext::id())->value('id');
        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');
        $account = (int) DB::table('accounts')->where('company_id', CompanyContext::id())->value('id');

        foreach ([
            'purchase.order.index' => ['pur_orders', 'total', ['supplier_id' => $supplier], __('purchase::field.total')],
            'purchase.receipt.index' => ['pur_receipts', 'total', ['supplier_id' => $supplier, 'warehouse_id' => $warehouse], __('purchase::field.total')],
            'purchase.return.index' => ['pur_returns', 'total', ['supplier_id' => $supplier, 'warehouse_id' => $warehouse], __('purchase::field.total')],
            'purchase.payment.index' => ['pur_payments', 'amount', ['supplier_id' => $supplier, 'account_id' => $account], __('core.list.page_total')],
        ] as $list => [$table, $column, $extra, $label]) {
            $this->papers($table, 'ZQG', 51, [$column => '100', ...$extra]);
            $this->papers($table, 'ZQH', 3, [$column => '7', ...$extra]);

            foreach (['ZQG', 'ZQH'] as $prefix) {
                $this->assertBar($list, ['q' => $prefix], $this->dbCount($table, $prefix),
                    [$label => $this->dbSum($table, $column, $prefix)]);
            }
        }
    }

    public function test_the_bill_list_says_total_paid_and_payable(): void
    {
        $supplier = (int) DB::table('suppliers')->where('company_id', CompanyContext::id())->value('id');

        $this->papers('pur_bills', 'ZQG', 51, ['total' => '100', 'supplier_id' => $supplier]);
        $this->papers('pur_bills', 'ZQH', 3, ['total' => '7', 'supplier_id' => $supplier]);

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $total = $this->dbSum('pur_bills', 'total', $prefix);

            // ⓘ শোধ নেই — পরিশোধিত শূন্য, বাকি পুরো মোট (ডাটাবেজের সরাসরি যোগ)
            $this->assertBar('purchase.bill.index', ['q' => $prefix], $this->dbCount('pur_bills', $prefix), [
                __('purchase::field.total') => $total,
                __('purchase::field.bill_paid') => '0',
                __('purchase::field.bill_due') => $total,
            ]);
        }
    }

    public function test_the_lists_without_money_still_say_how_many_rows(): void
    {
        foreach (['purchase.requisition.index' => 'pur_requisitions', 'purchase.rfq.index' => 'pur_rfqs', 'purchase.contract.index' => 'pur_contracts'] as $list => $table) {
            $html = (string) $this->get(route($list))->assertOk()->getContent();
            $this->assertStringContainsString($this->rowsText(DB::table($table)->where('company_id', CompanyContext::id())->count()),
                $this->bar($html, $list));
        }
    }
}
