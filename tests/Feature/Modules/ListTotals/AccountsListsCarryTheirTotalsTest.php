<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * হিসাবের তালিকাগুলোর নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   ভাউচার (ধরন ধরে)       সারি · অঙ্ক
 *   ভাউচারের তালিকা (ট্যাব)  সারি · অঙ্ক — চলতি মাসের, ট্যাবের
 *   স্থায়ী সম্পদ           সারি · কেনা দাম
 *
 * ⓘ ৫১টা × ১০০ (দুই পাতা) আর ৩টা × ৭ — দুই খোঁজাতেই পট্টি ডাটাবেজের সরাসরি গোনা ও যোগ বলে।
 */
final class AccountsListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_voucher_lists_count_and_sum_the_whole_filtered_set(): void
    {
        $money = (int) DB::table('accounts')->where('company_id', CompanyContext::id())->where('is_group', false)->value('id');
        $year = (int) DB::table('financial_years')->where('company_id', CompanyContext::id())->orderByDesc('id')->value('id');

        foreach ([['ZQG', 51, '100'], ['ZQH', 3, '7']] as [$prefix, $count, $amount]) {
            for ($i = 1; $i <= $count; $i++) {
                DB::table('vouchers')->insert([
                    'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'type' => Voucher::RECEIPT, 'document_no' => sprintf('%s-%03d', $prefix, $i),
                    'trx_date' => now()->toDateString(), 'amount' => $amount, 'status' => DocumentStatus::CONFIRMED,
                    'money_account_id' => $money, 'financial_year_id' => $year, 'public_id' => (string) Str::uuid(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $rows = $this->dbCount('vouchers', $prefix);
            $sum = $this->dbSum('vouchers', 'amount', $prefix);

            $this->assertBar('accounts.voucher.index', ['type' => Voucher::RECEIPT, 'q' => $prefix], $rows,
                [__('accounts::field.amount') => $sum]);
            $this->assertBar('accounts.voucher.list', ['tab' => Voucher::RECEIPT, 'q' => $prefix], $rows,
                [__('accounts::field.amount') => $sum]);
        }
    }

    public function test_the_fixed_asset_list_sums_the_cost_of_every_page(): void
    {
        $account = (int) DB::table('accounts')->where('company_id', CompanyContext::id())->where('is_group', false)->value('id');

        foreach ([['ZQG', 51, '100'], ['ZQH', 3, '7']] as [$prefix, $count, $cost]) {
            for ($i = 1; $i <= $count; $i++) {
                DB::table('acc_fixed_assets')->insert([
                    'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'public_id' => (string) Str::uuid(), 'document_no' => sprintf('%s-%03d', $prefix, $i),
                    'name' => "{$prefix} Van {$i}", 'asset_account_id' => $account, 'accumulated_account_id' => $account,
                    'expense_account_id' => $account, 'cost' => $cost, 'acquired_on' => now()->toDateString(),
                    'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $this->assertBar('accounts.asset.index', ['q' => $prefix], $this->dbCount('acc_fixed_assets', $prefix),
                [__('accounts::asset.cost') => $this->dbSum('acc_fixed_assets', 'cost', $prefix)]);
        }
    }

    public function test_the_lists_without_a_sum_still_say_how_many_rows(): void
    {
        foreach (['accounts.reconciliation.index' => 'acc_bank_reconciliations', 'accounts.inter_company.index' => 'acc_inter_company'] as $list => $table) {
            $html = (string) $this->get(route($list))->assertOk()->getContent();
            $this->assertStringContainsString($this->rowsText(DB::table($table)->where('company_id', CompanyContext::id())->count()),
                $this->bar($html, $list));
        }
    }
}
