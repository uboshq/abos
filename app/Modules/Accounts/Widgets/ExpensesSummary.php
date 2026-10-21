<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Widgets;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummary;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ১৬ · এই মাসের খরচ — খাত ধরে (উইজেটের নকশা ১৯ অক্টোবর, পাতা ২; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; cb)।
 *
 * ⓘ হিসাব খরচের খাত-ধরে রিপোর্টের একই ([[CoreReports::expenseByHead()]]) — খাতের ধরন খরচ, দল-খাত নয়, ডেবিট − ক্রেডিট, বছর বন্ধের
 * দাখিলা বাদ — আর হোমের "এই মাসের খরচ"-এর একই শাখা-নিয়ম ([[AccountsFacts::netOfType()]], দেখার শাখা)।
 *
 * ⛔ কর্মীর বেতনের খাত পুরো বাদ — সারিতেও নয়, মোটেও নয় (দলের নিয়ম "বেতন কোনো উইজেটে নয়"; a4, ২০ অক্টোবর ২০২৬: "বাকি সব"-এ
 * মেশালে আগের মাসের সাথে মিলিয়ে আন্দাজ করা যেত)। তিনভাবে চেনা হয়:
 *  - বেতনের খরচ খাত (৫২০১) আর তার নিচের সব খাত;
 *  - HR-এর বেতনের অংশগুলো যে খাতে বসে (`hr_salary_heads.account_id`) — HR মডিউলের নাম না নিয়ে, টেবিল ধরে, কারণ হিসাব HR-এর
 *    উপর দাঁড়ায় না ([[BoundariesTest]]);
 *  - বেতনের রানের প্রতিটা দাখিলা (`payroll_run`), যে খাতেই বসুক।
 * ফোন তাই লেখে "কর্মীর খরচ বাদে"। ⓘ সবচেয়ে বড় চারটা খাত, বাকিগুলো এক সারিতে।
 */
final class ExpensesSummary implements WidgetSummary
{
    /** বেতনের রানের দাখিলার উৎস — HR-এর `PayrollRun::SOURCE_TYPE` */
    public const PAYROLL_SOURCE = 'payroll_run';

    public function key(): string
    {
        return 'expenses';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'accounts.report';
    }

    public function data(User $user): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        $heads = app(DataScope::class)->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', Account::EXPENSE)
            ->where('accounts.is_group', false)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->whereNotIn('ledger_entries.source_type', [...YearEndService::closingSources(), self::PAYROLL_SOURCE])
            ->whereNotIn('ledger_entries.account_id', self::staffPayAccounts())
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id, COALESCE(SUM(ledger_entries.debit), 0) - COALESCE(SUM(ledger_entries.credit), 0) as spent')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->account_id, 'spent' => bcadd((string) $r->spent, '0', 4)])
            ->filter(fn (array $r) => bccomp($r['spent'], '0', 4) !== 0)
            ->sort(fn (array $a, array $b) => bccomp($b['spent'], $a['spent'], 4))
            ->values();

        $names = Account::query()->whereKey($heads->pluck('id')->all())->get()->keyBy('id');
        $total = $heads->reduce(fn (string $sum, array $r) => bcadd($sum, $r['spent'], 4), '0.0000');

        $rows = $heads->take(4)->map(fn (array $r) => [
            'head' => $names[$r['id']]?->name() ?? '',
            'amount' => $r['spent'],
        ])->all();

        if ($heads->count() > 4) {
            $rows[] = [
                'head' => __('accounts::field.widget_other_heads'),
                'amount' => $heads->slice(4)->reduce(fn (string $sum, array $r) => bcadd($sum, $r['spent'], 4), '0.0000'),
            ];
        }

        return [
            'month' => Carbon::today()->format('Y-m'),
            'total' => $total,
            'rows' => $rows,
            'link' => '/accounts/reports/expense-by-head',
        ];
    }

    /** @return list<int> কর্মীর বেতনের খাতগুলো — ৫২০১ আর তার নিচে, আর HR-এর বেতনের অংশের খাত */
    public static function staffPayAccounts(): array
    {
        $ids = StandardChart::find(StandardChart::SALARY_EXPENSE)?->selfAndDescendants()->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];

        if (Schema::hasTable('hr_salary_heads')) {
            $ids = [...$ids, ...DB::table('hr_salary_heads')->where('company_id', CompanyContext::id())->whereNotNull('account_id')->pluck('account_id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($ids)) ?: [0];
    }
}
