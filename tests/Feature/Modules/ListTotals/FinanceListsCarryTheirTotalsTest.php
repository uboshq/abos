<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * অর্থের তালিকাগুলোর নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   উত্তোলন     সারি · অঙ্ক — ৫১টা × ১০০ (দুই পাতা) আর ৩টা × ৭, দুই খোঁজাতেই ডাটাবেজের সরাসরি গোনা ও যোগ
 *   প্রতিষ্ঠান, জমার ধরন   সারির সংখ্যা
 */
final class FinanceListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_withdrawal_list_sums_every_page_under_the_search(): void
    {
        foreach ([['ZQG', 51, '100'], ['ZQH', 3, '7']] as [$prefix, $count, $amount]) {
            for ($i = 1; $i <= $count; $i++) {
                DB::table('fin_withdrawals')->insert([
                    'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'public_id' => (string) Str::uuid(),
                    'document_no' => sprintf('%s-%03d', $prefix, $i),
                    'amount' => $amount, 'trx_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $this->assertBar('finance.withdrawal.index', ['q' => $prefix], $this->dbCount('fin_withdrawals', $prefix),
                [__('finance::field.amount') => $this->dbSum('fin_withdrawals', 'amount', $prefix)]);
        }
    }

    public function test_the_lists_without_a_sum_still_say_how_many_rows(): void
    {
        foreach (['finance.institution.index' => 'fin_institutions', 'finance.deposit_kind.index' => 'fin_deposit_kinds'] as $list => $table) {
            $html = (string) $this->get(route($list))->assertOk()->getContent();
            $this->assertStringContainsString($this->rowsText(DB::table($table)->where('company_id', CompanyContext::id())->count()),
                $this->bar($html, $list));
        }
    }

    /** ⓘ বাকি তালিকাগুলো খোলে, আর পট্টিতে পাতা ভাগের নিজের গোনা (গোটা ছাঁকনির) — টাকার ঘর টেবিলের সর্বমোট থেকেই */
    public function test_every_other_finance_list_opens_with_its_bar(): void
    {
        foreach ([
            'finance.bank_charge.index' => 'rows', 'finance.bank_facility.index' => 'facilities', 'finance.budget.index' => 'plan',
            'finance.capital.index' => 'entries', 'finance.deposit.all' => 'deposits', 'finance.insurance.index' => 'policies',
            'finance.profit.index' => 'history', 'finance.rental.index' => 'contracts',
        ] as $list => $rows) {
            $response = $this->get(route($list))->assertOk();
            $this->assertStringContainsString($this->rowsText($response->viewData($rows)->total()),
                $this->bar((string) $response->getContent(), $list));
        }
    }
}
