<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DepositClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * বিক্রয়ের তালিকাগুলোর নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   ৫১টা কাগজ × ১০০ (দুই পাতা) আর ৩টা × ৭ — খোঁজার ছাঁকনিতে পট্টি বলে "৫১টি সারি · মোট ৫,১০০" (এই পাতার ৫,০০০ নয়),
 *   আর অন্য ছাঁকনিতে "৩টি সারি · ২১"; প্রতিটা সংখ্যা ডাটাবেজের সরাসরি গোনা ও যোগের সাথে মেলানো।
 */
final class SalesListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_document_lists_count_and_sum_the_whole_filtered_set(): void
    {
        $customer = (int) Customer::query()->value('id');
        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');
        $account = (int) DB::table('accounts')->where('company_id', CompanyContext::id())->value('id');

        foreach ([
            'sales.invoice.index' => ['sal_invoices', 'total', ['customer_id' => $customer], __('core.list.page_total')],
            'sales.challan.index' => ['sal_challans', 'total', ['customer_id' => $customer, 'warehouse_id' => $warehouse], __('sales::field.total')],
            'sales.order.index' => ['sal_orders', 'total', ['customer_id' => $customer], __('sales::field.total')],
            'sales.order.track' => ['sal_orders', 'total', ['customer_id' => $customer], __('sales::field.total')],
            'sales.return.index' => ['sal_returns', 'total', ['customer_id' => $customer, 'warehouse_id' => $warehouse], __('sales::field.total')],
            'sales.collection.index' => ['sal_collections', 'amount', ['customer_id' => $customer, 'account_id' => $account], __('core.list.page_total')],
        ] as $list => [$table, $column, $extra, $label]) {
            if ($this->dbCount($table, 'ZQG') === 0) {
                $this->papers($table, 'ZQG', 51, [$column => '100', ...$extra]);
                $this->papers($table, 'ZQH', 3, [$column => '7', ...$extra]);
            }

            foreach (['ZQG', 'ZQH'] as $prefix) {
                $this->assertBar($list, ['q' => $prefix], $this->dbCount($table, $prefix),
                    [$label => $this->dbSum($table, $column, $prefix)]);
            }
        }
    }

    public function test_the_deposit_claims_sum_every_page_under_the_status_filter(): void
    {
        $customer = (int) Customer::query()->value('id');

        foreach ([['pending', 51, '100'], ['rejected', 3, '7']] as [$status, $count, $amount]) {
            for ($i = 1; $i <= $count; $i++) {
                (new DepositClaim)->forceFill([
                    'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'customer_id' => $customer, 'claimed_on' => now()->toDateString(),
                    'amount' => $amount, 'method' => 'bank', 'reference' => 'ZQC'.$status.$i, 'status' => $status,
                ])->save();
            }
        }

        foreach (['pending', 'rejected'] as $status) {
            $direct = DB::table('sal_deposit_claims')->where('company_id', CompanyContext::id())->where('status', $status)
                ->where('reference', 'like', 'ZQC%');

            $this->assertBar('sales.claim.index', ['status' => $status, 'q' => 'ZQC'], (clone $direct)->count(),
                [__('sales::portal.claimed') => (string) (clone $direct)->sum('amount')]);
        }
    }

    public function test_a_list_without_money_still_says_how_many_rows(): void
    {
        $html = (string) $this->get(route('sales.gate_pass.index'))->assertOk()->getContent();
        $this->assertStringContainsString($this->rowsText(DB::table('sal_gate_passes')->where('company_id', CompanyContext::id())->count()),
            $this->bar($html, 'sales.gate_pass.index'));
    }
}
