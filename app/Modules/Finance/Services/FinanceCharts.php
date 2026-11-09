<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের আটটা ছোট চার্ট — মালিকের অনুমোদিত নমুনা (৯ নভেম্বর ২০২৬): নগদ প্রবাহ (৬ মাস), তহবিলের ধারা (১২ মাস),
 * পাওনার বয়স + DSO, দেনার মেয়াদ + DPO, খরচের ভাগ, বাজেট বনাম আসল, মূল অনুপাত (চলতি, দ্রুত, DIO, নগদ চক্র), ঋণ ও আমানত (সামনের ৬ মাস)।
 *
 * ⓘ সব অঙ্ক bcmath-এ ৪ ঘর; দিনের গোনা পূর্ণসংখ্যায়। কোনো সংখ্যার নতুন সংজ্ঞা নেই: টাকা [[AccountsFacts]]-এর, পাওনার বয়স "বয়স"
 * রিপোর্টের ([[customer.ageing]]), খরচ [[HeadTotals]]-এর, বাজেট [[BudgetService::vsActual()]]-এর, অনুপাতের ভাগগুলো [[WorkingCapital]]-এর।
 * ⓘ দিনের মাপ (DSO, DIO, DPO) শেষ ৯০ দিনের প্রবাহে — আন্তর্জাতিক নিয়ম: জের ÷ (৯০ দিনের প্রবাহ ÷ ৯০)।
 * ⛔ যে চার্টের চাবি নেই সেটা `null` — পর্দায় ঘরটাই নেই (পাওনা: `customer.report`, বাজেট: `finance.budget.view`)।
 */
final class FinanceCharts
{
    public const WINDOW_DAYS = 90;

    /** @var array<string, array{dso: ?int, dio: ?int, dpo: ?int, ccc: ?int}> এক ডাকের ভিতরে একই দিনের চক্র একবারই */
    private array $cycles = [];

    public function __construct(
        private readonly AccountsFacts $facts,
        private readonly WorkingCapital $workingCapital,
        private readonly HeadTotals $heads,
        private readonly BudgetService $budget,
        private readonly DataScope $scope,
    ) {}

    /** @return array<string, mixed> */
    public function all(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $user = auth()->user();

        return [
            'cashflow' => $this->cashflow(),
            'trend' => $this->trend($today),
            'receivable' => $user?->can('customer.report') ? $this->receivable($today) : null,
            'payable' => $this->payable($today),
            'expense' => $this->expense($today),
            'budget' => $user?->can('finance.budget.view') ? $this->budgetCard($today) : null,
            'ratios' => $this->ratios($today),
            'loans' => $this->loansAndDeposits($today),
        ];
    }

    /** @return array{months: list<array{month: string, in: string, out: string}>, net: string, out_heavy: list<string>} */
    private function cashflow(): array
    {
        $months = $this->facts->moneyFlowByMonth(6);
        $in = '0';
        $out = '0';

        foreach ($months as $m) {
            $in = bcadd($in, $m['in'], 4);
            $out = bcadd($out, $m['out'], 4);
        }

        return [
            'months' => $months,
            'net' => bcsub($in, $out, 4),
            'out_heavy' => array_values(array_map(fn ($m) => $m['month'], array_filter($months, fn ($m) => bccomp($m['out'], $m['in'], 4) > 0))),
        ];
    }

    /** @return array{points: list<array{date: string, total: string}>, change: string, up_months: int, peak_now: bool} */
    private function trend(Carbon $today): array
    {
        $points = [];

        for ($i = 11; $i >= 1; $i--) {
            $date = $today->copy()->startOfMonth()->subMonthsNoOverflow($i - 1)->subDay()->toDateString();
            $m = $this->facts->moneyPositions($date);
            $points[] = ['date' => $date, 'total' => bcadd(bcadd($m['cash'], $m['bank'], 4), $m['mfs'], 4)];
        }

        $m = $this->facts->moneyPositions();
        $points[] = ['date' => $today->toDateString(), 'total' => bcadd(bcadd($m['cash'], $m['bank'], 4), $m['mfs'], 4)];

        $up = 0;
        $peak = true;
        $last = end($points)['total'];

        foreach ($points as $i => $p) {
            if ($i > 0 && bccomp($p['total'], $points[$i - 1]['total'], 4) > 0) {
                $up++;
            }

            if (bccomp($p['total'], $last, 4) > 0) {
                $peak = false;
            }
        }

        return ['points' => $points, 'change' => bcsub($last, $points[0]['total'], 4), 'up_months' => $up, 'peak_now' => $peak];
    }

    /** @return array{buckets: list<array{key: string, amount: string}>, total: string, old_pct: ?string, dso: ?int}|null */
    private function receivable(Carbon $today): ?array
    {
        if (! in_array('customer.ageing', app(ReportEngine::class)->keys(), true)) {
            return null;
        }

        $totals = app(ReportEngine::class)
            ->run('customer.ageing', ['to' => $today->toDateString(), 'branch_id' => ViewedBranch::one()], 1, 1)
            ->totals;

        $buckets = array_map(fn (string $key) => ['key' => $key, 'amount' => bcadd((string) ($totals[$key] ?? '0'), '0', 4)],
            ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']);
        $total = bcadd((string) ($totals['outstanding'] ?? '0'), '0', 4);

        return [
            'buckets' => $buckets,
            'total' => $total,
            'old_pct' => bccomp($total, '0', 4) > 0 ? bcdiv(bcmul($buckets[3]['amount'], '100', 4), $total, 0) : null,
            'dso' => $this->cycle($today)['dso'],
        ];
    }

    /** @return array{buckets: list<array{key: string, amount: string}>, total: string, due7: string, dpo: ?int} */
    private function payable(Carbon $today): array
    {
        $sums = ['later' => '0', 'week' => '0', 'late30' => '0', 'late_more' => '0'];
        $bills = PurchaseBill::query()->posted()->withPaid();

        foreach (PurchaseBill::query()->fromSub($bills, 'pur_bills')
            ->whereRaw('pur_bills.total - pur_bills.ait_amount - pur_bills.vds_amount - pur_bills.paid_total - pur_bills.voucher_paid_total - COALESCE(pur_bills.returned_total, 0) > 0')
            ->get() as $bill) {
            $days = $bill->due_on === null ? 0 : $today->diffInDays(Carbon::parse($bill->due_on)->startOfDay(), false);
            $key = match (true) {
                $days > 7 => 'later',
                $days >= 0 => 'week',
                $days >= -30 => 'late30',
                default => 'late_more',
            };
            $sums[$key] = bcadd($sums[$key], $bill->dueAmount(), 4);
        }

        $total = array_reduce($sums, fn (string $c, string $v) => bcadd($c, $v, 4), '0');

        return [
            'buckets' => array_map(fn (string $k) => ['key' => $k, 'amount' => $sums[$k]], array_keys($sums)),
            'total' => $total,
            'due7' => $sums['week'],
            'dpo' => $this->cycle($today)['dpo'],
        ];
    }

    /** @return array{parts: list<array{label: string, amount: string}>, total: string, change_pct: ?string} */
    private function expense(Carbon $today): array
    {
        $from = $today->copy()->startOfMonth();
        $total = $this->moved([StandardChart::OPERATING_EXPENSES], $from, $today);
        $top = $this->heads->topUnder(StandardChart::OPERATING_EXPENSES, $from->toDateString(), $today->toDateString(), 4);
        $rest = $total;

        foreach ($top as $part) {
            $rest = bcsub($rest, $part['amount'], 4);
        }

        $parts = array_map(fn (array $p) => ['label' => $p['label'], 'amount' => bcadd($p['amount'], '0', 4)], $top);

        if (bccomp($rest, '0', 4) > 0) {
            $parts[] = ['label' => __('finance::charts.others'), 'amount' => $rest];
        }

        // ⓘ গত মাসের একই দিনগুলোর সাথে — ১০ তারিখে পুরো গত মাসের সাথে তুলনা করলে সবসময় "কম" দেখাত
        $lastFrom = $from->copy()->subMonthNoOverflow();
        $lastTo = $lastFrom->copy()->addDays($from->diffInDays($today))->min($lastFrom->copy()->endOfMonth());
        $last = $this->moved([StandardChart::OPERATING_EXPENSES], $lastFrom, $lastTo);

        return [
            'parts' => $parts,
            'total' => $total,
            'change_pct' => bccomp($last, '0', 4) > 0 ? bcdiv(bcmul(bcsub($total, $last, 4), '100', 4), $last, 0) : null,
        ];
    }

    /** @return array{rows: list<array{name: string, budget: string, actual: string, pct: ?string, over: bool}>, over: int}|null */
    private function budgetCard(Carbon $today): ?array
    {
        if (! Schema::hasTable('fin_budgets')) {
            return null;
        }

        $rows = $this->budget->vsActual((int) $today->year, (int) $today->month, (int) $today->month)
            ->filter(fn (array $r) => $r['over_is_bad'])
            ->sort(fn (array $a, array $b) => bccomp($b['budget'], $a['budget'], 4))
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'rows' => $rows->take(4)->map(fn (array $r) => [
                'name' => $r['account']->name(), 'budget' => $r['budget'], 'actual' => $r['actual'],
                'pct' => $r['used_pct'] === null ? null : bcadd($r['used_pct'], '0', 0), 'over' => $r['over'],
            ])->all(),
            'over' => $rows->filter(fn (array $r) => $r['over'])->count(),
        ];
    }

    /** @return array{current: ?string, quick: ?string, dio: ?int, dio_before: ?int, ccc: ?int, ccc_before: ?int} */
    private function ratios(Carbon $today): array
    {
        $wc = $this->workingCapital->on($today);
        $owes = bcadd($wc['payable'], $wc['other'], 4);
        $now = $this->cycle($today);
        $before = $this->cycle($today->copy()->startOfMonth()->subDay());

        return [
            'current' => $wc['ratio'],
            'quick' => bccomp($owes, '0', 4) > 0 ? bcdiv(bcadd($wc['receivable'], $wc['money'], 4), $owes, 2) : null,
            'dio' => $now['dio'],
            'dio_before' => $before['dio'],
            'ccc' => $now['ccc'],
            'ccc_before' => $before['ccc'],
        ];
    }

    /**
     * ⭐ টাকার চক্র কোনো এক দিনে — পাওনা আদায়ের দিন (DSO), মজুদ ঘোরার দিন (DIO), দেনা শোধের দিন (DPO), আর নগদ চক্র = DSO + DIO − DPO।
     * ⓘ জের সেই দিনের, প্রবাহ সেই দিনে শেষ হওয়া ৯০ দিনের; প্রবাহ শূন্য হলে মাপ নেই (`null`), শূন্য দিন নয়।
     *
     * @return array{dso: ?int, dio: ?int, dpo: ?int, ccc: ?int}
     */
    private function cycle(Carbon $date): array
    {
        $key = $date->toDateString();

        if (isset($this->cycles[$key])) {
            return $this->cycles[$key];
        }

        $from = $date->copy()->subDays(self::WINDOW_DAYS - 1);
        $sales = $this->moved(StandardChart::NET_SALES, $from, $date);
        $cogs = $this->moved([StandardChart::COST_OF_GOODS_SOLD], $from, $date);
        $wc = $this->workingCapital->on($date);

        $days = fn (string $balance, string $flow): ?int => bccomp($flow, '0', 4) > 0
            ? (int) bcdiv(bcmul($balance, (string) self::WINDOW_DAYS, 4), $flow, 0)
            : null;

        $dso = $days($wc['receivable'], $sales);
        $dio = $days($wc['stock'], $cogs);
        $dpo = $days($wc['payable'], $cogs);

        return $this->cycles[$key] = [
            'dso' => $dso, 'dio' => $dio, 'dpo' => $dpo,
            'ccc' => $dso === null || $dio === null || $dpo === null ? null : $dso + $dio - $dpo,
        ];
    }

    /** @return array{months: list<array{month: string, instalments: string, deposits: string}>, big: ?string} */
    private function loansAndDeposits(Carbon $today): array
    {
        $months = [];
        $start = $today->copy()->startOfMonth();

        for ($i = 0; $i < 6; $i++) {
            $m = $start->copy()->addMonthsNoOverflow($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'instalments' => '0', 'deposits' => '0'];
        }

        $end = $start->copy()->addMonthsNoOverflow(5)->endOfMonth()->toDateString();

        foreach (LoanInstalment::query()->with('loan')->where('status', LoanInstalment::DUE)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->where('direction', '!=', Loan::GIVEN))
            ->whereBetween('due_date', [$start->toDateString(), $end])->get() as $row) {
            $key = Carbon::parse($row->due_date)->format('Y-m');
            $left = bcsub(bcadd((string) $row->principal, (string) $row->interest, 4), (string) ($row->paid_amount ?? 0), 4);
            $months[$key]['instalments'] = bcadd($months[$key]['instalments'], $left, 4);
        }

        if (Schema::hasTable((new Deposit)->getTable())) {
            foreach (Deposit::query()->open()->whereBetween('matures_on', [$start->toDateString(), $end])->get() as $deposit) {
                $key = Carbon::parse($deposit->matures_on)->format('Y-m');
                $months[$key]['deposits'] = bcadd($months[$key]['deposits'], (string) $deposit->principal, 4);
            }
        }

        $months = array_values($months);
        $total = array_reduce($months, fn (string $c, array $m) => bcadd($c, $m['instalments'], 4), '0');
        $peak = null;

        foreach ($months as $m) {
            if ($peak === null || bccomp($m['instalments'], $peak['instalments'], 4) > 0) {
                $peak = $m;
            }
        }

        // ⓘ "বড় কিস্তি" — যে মাসের কিস্তি ছয় মাসের গড়ের দেড় গুণের বেশি
        $big = $peak !== null && bccomp($total, '0', 4) > 0 && bccomp(bcmul($peak['instalments'], '6', 4), bcmul($total, '1.5', 4), 4) > 0
            ? $peak['month'] : null;

        return ['months' => $months, 'big' => $big];
    }

    /** @param  list<string>  $codes  খাতগুলোর জের দুই তারিখের মধ্যে কতটা সরল — নিজের প্রকৃতিতে, দেখার শাখায় */
    private function moved(array $codes, Carbon $from, Carbon $to): string
    {
        $ids = $this->scope->viewBranchIds(auth()->user());
        $branches = $ids === null ? [null] : $ids;
        $before = $from->copy()->subDay()->toDateString();
        $total = '0';

        foreach ($codes as $code) {
            $account = StandardChart::find($code);

            if ($account === null) {
                continue;
            }

            foreach ($branches as $branch) {
                $total = bcadd($total, bcsub($account->balanceOn($to->toDateString(), $branch), $account->balanceOn($before, $branch), 4), 4);
            }
        }

        return $total;
    }
}
