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
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Reports\HandLoanReports;
use Illuminate\Support\Carbon;

/**
 * ⭐ ঋণের ঘর — অর্থের ড্যাশবোর্ডের সবার নিচে (মালিক, ৯ নভেম্বর ২০২৬: "loan box sobar nice daw"; অনুমোদিত নমুনা)।
 *
 * সারি: চালু ব্যাংক সুবিধা (CC/OD, মেয়াদি, LTR, লিজ — গ্যারান্টি নয়, ওটা টাকা নয়), নেওয়া ঋণ ([[Loan]]), আর হাতধার যা আমরা
 * ফেরত দেব। মোট = সারিগুলোর যোগ — টেবিল যা দেখায় মাথাও তাই বলে।
 * পাঁচ মাপ: পরের কিস্তি, এ মাসের সুদ আর গড় হার, ঋণসীমা ব্যবহার, ঋণ ÷ মূলধন, কিস্তি চালানোর ক্ষমতা (DSCR = গত মাসের নিট আয় +
 * সুদ, ÷ এ মাসের কিস্তি — আন্তর্জাতিক নিয়মে সুদ ফেরত যোগ হয়, কারণ কিস্তিতেই সুদ আছে)।
 * ⓘ "গত মাসের চেয়ে" কেবল খাতার তারিখওয়ালা অংশ (দীর্ঘমেয়াদি ঋণ ২২০০ + ব্যাংক ওভারড্রাফট ২১০৫) — তাই লেখাটা "ব্যাংক ঋণ";
 * হাতধারের জের তারিখ ধরে মাপা যায় না।
 */
final class LoanBox
{
    public function __construct(
        private readonly BankFacilityService $facilities,
        private readonly AccountsFacts $facts,
        private readonly DataScope $scope,
    ) {}

    /**
     * @return array{rows: list<array{name: string, kind: string, left: string, rate: ?string, next: ?array{date: string, amount: string}, note: ?string}>,
     *     total: string, bank_change: string, next: ?array{date: string, amount: string, name: string, kind: string, days: int},
     *     interest_month: string, avg_rate: ?string, od_used: string, od_limit: string, od_pct: ?string,
     *     debt_equity: ?string, dscr: ?string}
     */
    public function on(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $rows = [];

        // ── ব্যাংক সুবিধা
        $live = BankFacility::query()->live()->inViewedBranch()->where('kind', '!=', BankFacility::GUARANTEE)->orderBy('id')->get();
        $standing = $live->isEmpty() ? [] : $this->facilities->standing($live);
        $nextOf = [];

        foreach ($this->facilities->instalmentsDue(62, $today) as $due) {
            $id = (int) $due['facility']->id;

            if ($due['state'] === BankFacilityService::PAID || $due['due_on'] === null) {
                continue;
            }

            if (! isset($nextOf[$id]) || $due['due_on'] < $nextOf[$id]['date']) {
                $nextOf[$id] = ['date' => (string) $due['due_on'], 'amount' => bcadd((string) $due['amount'], '0', 4)];
            }
        }

        $odUsed = '0';
        $odLimit = '0';

        foreach ($live as $facility) {
            $used = $standing[(int) $facility->id]['used'] ?? '0';
            $cc = $facility->kind === BankFacility::CC;

            if ($cc) {
                $odUsed = bcadd($odUsed, $used, 4);
                $odLimit = bcadd($odLimit, (string) $facility->limit_amount, 4);
            }

            $rows[] = [
                'name' => (string) $facility->bank,
                'kind' => __('finance::field.facility_'.$facility->kind),
                'left' => bcadd($used, '0', 4),
                'rate' => $facility->interest_rate === null ? null : bcadd((string) $facility->interest_rate, '0', 1),
                'next' => $nextOf[(int) $facility->id] ?? null,
                'note' => $cc ? __('finance::loan_box.month_end_interest') : null,
            ];
        }

        // ── নেওয়া ঋণ (হিসাবের ঋণের খাতা)
        foreach (Loan::query()->whereIn('status', DocumentStatus::POSTED)->where('direction', '!=', Loan::GIVEN)->orderBy('id')->get() as $loan) {
            $left = $loan->outstanding();

            if (bccomp($left, '0', 4) <= 0) {
                continue;
            }

            $due = LoanInstalment::query()->where('loan_id', $loan->id)->where('status', LoanInstalment::DUE)->orderBy('due_date')->first();

            $rows[] = [
                'name' => (string) $loan->lender,
                'kind' => $loan->kindLabel(),
                'left' => bcadd($left, '0', 4),
                'rate' => $loan->interest_rate === null ? null : bcadd((string) $loan->interest_rate, '0', 1),
                'next' => $due === null ? null : [
                    'date' => Carbon::parse($due->due_date)->toDateString(),
                    'amount' => bcsub(bcadd((string) $due->principal, (string) $due->interest, 4), (string) ($due->paid_amount ?? 0), 4),
                ],
                'note' => null,
            ];
        }

        // ── হাতধার — আমরা যা ফেরত দেব (চাবি থাকলে)
        if (auth()->user()?->can('finance.hand_loan.view')) {
            $hand = app(HandLoanService::class)->standing();
            $people = count(array_filter($hand['rows'], fn (array $r) => bccomp((string) $r['balance'], '0', 4) < 0));

            if ($people > 0) {
                $late = app(ReportEngine::class)->run(HandLoanReports::SCHEDULE, [
                    'to' => $today->toDateString(), 'state' => HandLoanReports::OVERDUE, 'branch_id' => ViewedBranch::one(),
                ], 1, 1000)->rows;
                $lateOurs = count(array_unique(array_map(fn (array $r) => (int) $r['person_id'],
                    array_filter($late, fn (array $r) => bccomp((string) $r['we_owe'], '0', 4) > 0))));

                $rows[] = [
                    'name' => __('finance::loan_box.hand_people', ['n' => $this->digits((string) $people)]),
                    'kind' => __('finance::loan_box.hand'),
                    'left' => bcadd($hand['we_owe'], '0', 4),
                    'rate' => null,
                    'next' => null,
                    'note' => $lateOurs > 0 ? __('finance::loan_box.hand_late', ['n' => $this->digits((string) $lateOurs)]) : null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => bccomp($b['left'], $a['left'], 4));
        $total = array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r['left'], 4), '0');

        // ── পরের কিস্তি — সব সারির মধ্যে সবচেয়ে আগের
        $next = null;

        foreach ($rows as $r) {
            if ($r['next'] !== null && ($next === null || $r['next']['date'] < $next['date'])) {
                $next = [...$r['next'], 'name' => $r['name'], 'kind' => $r['kind'],
                    'days' => (int) $today->diffInDays(Carbon::parse($r['next']['date'])->startOfDay(), false)];
            }
        }

        // ── গড় হার — বাকির ওজনে
        $weighted = '0';
        $weight = '0';

        foreach ($rows as $r) {
            if ($r['rate'] !== null && bccomp($r['left'], '0', 4) > 0) {
                $weighted = bcadd($weighted, bcmul($r['left'], $r['rate'], 4), 4);
                $weight = bcadd($weight, $r['left'], 4);
            }
        }

        $monthStart = $today->copy()->startOfMonth();
        $lastStart = $monthStart->copy()->subMonthNoOverflow();
        $lastEnd = $monthStart->copy()->subDay();

        // ── DSCR: গত মাসের নিট আয় + সুদ, ÷ এ মাসের কিস্তি
        $income = $this->facts->netOfType(\App\Modules\Accounts\Models\Account::INCOME, $lastStart, $lastEnd);
        $expense = $this->facts->netOfType(\App\Modules\Accounts\Models\Account::EXPENSE, $lastStart, $lastEnd);
        $lastInterest = $this->moved(StandardChart::INTEREST_EXPENSE, $lastStart, $lastEnd);
        $operating = bcadd(bcsub($income, $expense, 4), $lastInterest, 4);
        $monthDue = $this->instalmentsIn($monthStart, $monthStart->copy()->endOfMonth());

        $equity = StandardChart::find('3000');
        $equityBalance = $equity === null ? '0' : $this->balance($equity, $today->toDateString());

        $bankNow = bcadd($this->balance(StandardChart::find('2200'), $today->toDateString()), $this->balance(StandardChart::find(StandardChart::BANK_OVERDRAFT), $today->toDateString()), 4);
        $prev = $monthStart->copy()->subDay()->toDateString();
        $bankPrev = bcadd($this->balance(StandardChart::find('2200'), $prev), $this->balance(StandardChart::find(StandardChart::BANK_OVERDRAFT), $prev), 4);

        return [
            'rows' => $rows,
            'total' => $total,
            'bank_change' => bcsub($bankNow, $bankPrev, 4),
            'next' => $next,
            'interest_month' => $this->moved(StandardChart::INTEREST_EXPENSE, $monthStart, $today),
            'avg_rate' => bccomp($weight, '0', 4) > 0 ? bcdiv($weighted, $weight, 1) : null,
            'od_used' => $odUsed,
            'od_limit' => $odLimit,
            'od_pct' => bccomp($odLimit, '0', 4) > 0 ? bcdiv(bcmul($odUsed, '100', 4), $odLimit, 0) : null,
            'debt_equity' => bccomp($equityBalance, '0', 4) > 0 ? bcdiv($total, $equityBalance, 2) : null,
            'dscr' => bccomp($monthDue, '0', 4) > 0 ? bcdiv($operating, $monthDue, 1) : null,
        ];
    }

    /** এ মাসের সব কিস্তি — ব্যাংক সুবিধার সূচি আর নেওয়া ঋণের কিস্তি (শোধ হোক বা না হোক: DSCR-এ মাসের পুরো দায়) */
    private function instalmentsIn(Carbon $from, Carbon $to): string
    {
        $sum = '0';

        foreach ($this->facilities->instalmentsDue((int) $from->diffInDays($to) + 31, $from) as $due) {
            if ($due['due_on'] !== null && $due['due_on'] >= $from->toDateString() && $due['due_on'] <= $to->toDateString()) {
                $sum = bcadd($sum, (string) $due['amount'], 4);
            }
        }

        foreach (LoanInstalment::query()->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->where('direction', '!=', Loan::GIVEN))->get() as $row) {
            $sum = bcadd($sum, bcadd((string) $row->principal, (string) $row->interest, 4), 4);
        }

        return $sum;
    }

    private function moved(string $code, Carbon $from, Carbon $to): string
    {
        $account = StandardChart::find($code);

        return $account === null ? '0' : bcsub($this->balance($account, $to->toDateString()), $this->balance($account, $from->copy()->subDay()->toDateString()), 4);
    }

    private function digits(string $s): string
    {
        return app()->getLocale() === 'bn' ? \App\Core\Support\BanglaDigits::of($s) : $s;
    }

    /** খাতের জের তারিখ পর্যন্ত, দেখার শাখায়, নিজের প্রকৃতিতে */
    private function balance(?\App\Modules\Accounts\Models\Account $account, string $upto): string
    {
        if ($account === null) {
            return '0';
        }

        $ids = $this->scope->viewBranchIds(auth()->user());
        $total = '0';

        foreach ($ids === null ? [null] : $ids as $branch) {
            $total = bcadd($total, $account->balanceOn($upto, $branch), 4);
        }

        return $total;
    }
}
