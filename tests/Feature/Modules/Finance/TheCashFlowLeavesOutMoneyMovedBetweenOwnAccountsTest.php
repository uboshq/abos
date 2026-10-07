<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * নগদ প্রবাহ — এল বনাম গেল, নিজের মধ্যে স্থানান্তর বাদ (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ গ্রাহকের টাকা নগদে এল → "এল"; খরচ নগদে গেল → "গেল"।
 * ⛔ নগদ থেকে ব্যাংকে জমা — একই কাগজের দুই সারিই টাকার খাতে — কোনো দণ্ডেই নয়।
 * ⛔ একই মানুষ: `accounts.view` ছাড়া চার্ট নেই; চাবিসহ আছে।
 */
final class TheCashFlowLeavesOutMoneyMovedBetweenOwnAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_in_and_out_are_counted_and_a_transfer_is_not(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        $this->actingAs($clerk);
        config(['abos.dashboards_v2' => true]);

        $label = __('finance::dashboard.cash_flow');
        $this->assertNull(collect(FinanceDashboard::dashboard()->panels)->firstWhere('label', $label), '⛔ accounts.view ছাড়াই নগদ প্রবাহ।');

        Permission::findOrCreate('accounts.view', 'web');
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo('accounts.view'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh());

        $before = $this->thisMonth($label);

        $cash = $this->leaf(StandardChart::CASH_IN_HAND);
        $bank = $this->leaf(StandardChart::BANK);
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $expense = $this->leaf(StandardChart::OPERATING_EXPENSES);

        $this->paper(1, [[$cash, '1000', '0'], [$receivable, '0', '1000']]);   // গ্রাহকের টাকা এল
        $this->paper(2, [[$expense, '300', '0'], [$cash, '0', '300']]);        // খরচ গেল
        $this->paper(3, [[$bank, '500', '0'], [$cash, '0', '500']]);           // নগদ থেকে ব্যাংকে — নিজের মধ্যে

        $after = $this->thisMonth($label);

        $this->assertSame(0, bccomp(bcsub($after['first'], $before['first'], 2), '1000', 2), '⛔ "এল" ১,০০০ বাড়েনি — বা ব্যাংকে জমাটাও এল-তে গোনা।');
        $this->assertSame(0, bccomp(bcsub($after['second'], $before['second'], 2), '300', 2), '⛔ "গেল" ৩০০ বাড়েনি — বা নগদ থেকে ব্যাংকে জমাটাও গেল-তে গোনা।');
    }

    /** @return array{first: string, second: string} এ মাসের দণ্ড */
    private function thisMonth(string $label): array
    {
        $panel = collect(FinanceDashboard::dashboard()->panels)->firstWhere('label', $label);
        $this->assertNotNull($panel, 'accounts.view থাকা সত্ত্বেও নগদ প্রবাহ নেই।');
        $now = $panel->points[array_key_last($panel->points)];

        return ['first' => (string) $now['first'], 'second' => (string) $now['second']];
    }

    private function leaf(string $code): int
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id') ?? collect();
        $id = Account::query()->whereIn('id', $ids)->where('is_group', false)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        // ⓘ নতুন কোম্পানির ব্যাংক দলটা খালি থাকে — নিচে একটা পাতা-খাত বানিয়ে নেওয়া (দলের ধরন আর দিক নিয়েই)
        $group = StandardChart::find($code);
        $this->assertNotNull($group, "খাত {$code} তালিকাতেই নেই — দাবিটা ফাঁকা।");

        return (int) Account::query()->create([
            'company_id' => $this->company->id,
            'code' => $code.'-T',
            'name_en' => 'Test '.$code,
            'name_bn' => 'পরীক্ষা '.$code,
            'parent_id' => $group->id,
            'type' => $group->type,
            'nature' => $group->nature,
            'is_active' => true,
            'status' => \App\Core\Support\DocumentStatus::CONFIRMED,
        ])->id;
    }

    /** @param list<array{0: int, 1: string, 2: string}> $lines */
    private function paper(int $sourceId, array $lines): void
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->value('id');

        foreach ($lines as [$account, $debit, $credit]) {
            LedgerEntry::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'financial_year_id' => $year, 'account_id' => $account, 'trx_date' => now()->toDateString(),
                'debit' => $debit, 'credit' => $credit, 'source_type' => 'cash_flow_probe', 'source_id' => $sourceId,
            ]);
        }
    }
}
