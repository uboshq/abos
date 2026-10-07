<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsDashboard;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * হিসাবের ড্যাশবোর্ড — মালিকের নকশা (৩ অক্টোবর ২০২৬): রেওয়ামিল এক নজরে, এ মাসের ভাউচারের অবস্থা, আয় ও ব্যয় মাসে মাসে।
 *
 * ⓘ দাবি: accounts.view ছাড়া তিনটার একটাও নেই; ভারসাম্যের কাগজে দুই দিক সমান বাড়ে আর "মিলেছে"; একপেশে সারিতে
 * পার্থক্য বলে; আয়-ব্যয়ের এ মাসের দণ্ড নতুন কাগজে ঠিক ততটা বাড়ে; খসড়া ভাউচার খসড়ার ঘরে গোনে।
 */
final class TheAccountsDashboardReadsTheBooksAtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_the_three_panels_read_the_books_and_need_the_key(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        $this->actingAs($clerk);
        config(['abos.dashboards_v2' => true]);

        foreach (['trial_balance', 'papers_this_month', 'income_expense_months'] as $key) {
            $this->assertNull($this->panel($key), "⛔ accounts.view ছাড়াই '{$key}'।");
        }

        Permission::findOrCreate('accounts.view', 'web');
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo('accounts.view'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh());

        // ── রেওয়ামিল ──
        $before = $this->panel('trial_balance');
        $this->assertNotNull($before, 'accounts.view থাকা সত্ত্বেও রেওয়ামিল নেই।');

        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $income = (int) Account::query()->where('type', Account::INCOME)->where('is_group', false)->value('id');
        $expense = (int) Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->value('id');
        $this->assertGreaterThan(0, $income, 'প্রস্তুতিটাই ভুল — আয়ের পাতা-খাত নেই।');
        $this->assertGreaterThan(0, $expense, 'প্রস্তুতিটাই ভুল — ব্যয়ের পাতা-খাত নেই।');

        $this->paper(1, [[$receivable, '1000', '0'], [$income, '0', '1000']]);
        $this->paper(2, [[$expense, '300', '0'], [$receivable, '0', '300']]);

        $after = $this->panel('trial_balance');
        $this->assertSame(1000.0 + 300.0, $this->num($after->parts[0]['value']) - $this->num($before->parts[0]['value']), '⛔ মোট ডেবিট ঠিক ততটা বাড়েনি।');
        $this->assertSame(1300.0, $this->num($after->parts[1]['value']) - $this->num($before->parts[1]['value']), '⛔ মোট ক্রেডিট ঠিক ততটা বাড়েনি।');
        $this->assertStringContainsString('✓', $after->hint, '⛔ ভারসাম্যের খাতায় "মিলেছে" বলছে না।');

        $this->paper(3, [[$expense, '50', '0']]);   // একপেশে — খাতা মেলে না
        $this->assertStringContainsString('50.00', $this->panel('trial_balance')->hint, '⛔ না মেলা খাতায় পার্থক্য বলছে না।');

        // ── আয় ও ব্যয় — এ মাসের দণ্ড ──
        $series = $this->panel('income_expense_months');
        $now = $series->points[array_key_last($series->points)];
        $this->assertGreaterThanOrEqual(1000.0, (float) $now['first'], '⛔ এ মাসের আয়ের দণ্ডে নতুন আয় নেই।');
        $this->assertGreaterThanOrEqual(350.0, (float) $now['second'], '⛔ এ মাসের ব্যয়ের দণ্ডে নতুন ব্যয় নেই।');

        // ── এ মাসের ভাউচার ──
        $draftsBefore = (int) $this->panel('papers_this_month')->parts[0]['value'];
        Voucher::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'type' => Voucher::JOURNAL, 'document_no' => 'JV-DASH-1', 'trx_date' => now()->toDateString(),
            'amount' => '10', 'status' => DocumentStatus::DRAFT,
        ]);
        $this->assertSame($draftsBefore + 1, (int) $this->panel('papers_this_month')->parts[0]['value'], '⛔ খসড়া ভাউচার খসড়ার ঘরে গোনা হয়নি।');
    }

    private function panel(string $key): ?object
    {
        return collect(AccountsDashboard::dashboard()->panels)->firstWhere('label', __('accounts::dashboard.'.$key));
    }

    private function num(string $value): float
    {
        return (float) str_replace(',', '', $value);
    }

    private function leaf(string $code): int
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id') ?? collect();
        $id = Account::query()->whereIn('id', $ids)->where('is_group', false)->value('id');
        $this->assertNotNull($id, "খাত {$code}-এর নিচে কোনো পাতা-খাত নেই।");

        return (int) $id;
    }

    /** @param list<array{0: int, 1: string, 2: string}> $lines */
    private function paper(int $sourceId, array $lines): void
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->value('id');

        foreach ($lines as [$account, $debit, $credit]) {
            LedgerEntry::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'financial_year_id' => $year, 'account_id' => $account, 'trx_date' => now()->toDateString(),
                'debit' => $debit, 'credit' => $credit, 'source_type' => 'books_glance_probe', 'source_id' => $sourceId,
            ]);
        }
    }
}
