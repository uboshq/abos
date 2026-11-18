<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ আর্থিক বিবরণীর পূর্ণ সেট — IAS 1-এর ছকে, NEXUS-এর নমুনার মানে, আমাদের নিয়মে (মালিক, ১৭ নভেম্বর ২০২৬ "ok")।
 *
 * ⓘ স্থিতিপত্রের অঙ্ক এখানে নতুন করে কষা হয় না — পর্দার একই [[BalanceSheetService::build()]], আজ আর আগের অর্থবছরের শেষ দিন।
 *   এখানে কেবল উপস্থাপন:
 *   - অ-চলতি / চলতি / অন্যান্য ভাগ (ছকের মাথা ধরে: ১২০০ স্থায়ী সম্পদ, ১১০০ চলতি সম্পদ, ২২০০ দীর্ঘমেয়াদি দায়, ২১০০ চলতি দায়);
 *   - টাকার তিন পরিবার (১১০১, ১১০২, ১১০৫) এক লাইনে "নগদ ও নগদ সমতুল্য", স্থায়ী সম্পদ এক লাইনে বইমূল্য — দুটোর ভাঙন নোটে;
 *   - দীর্ঘমেয়াদি ঋণের পরের ১২ মাসের কিস্তি চলতি দায়ে (IAS 1.69) — যে ঋণের কিস্তির তালিকা আছে;
 *   - আয়করের সঞ্চিতির লাইন সবসময় — খাতার জের, খাত না থাকলে লেখা থাকে "খাত নেই" (অঙ্ক বানানো হয় না);
 *   - প্রতিটা লাইনে নোট নম্বর, আগের বছর-শেষের অঙ্ক, পরিবর্তন আর %;
 *   - চলতি অনুপাত, চলতি মূলধন, দায় ÷ স্বত্ব; আর সম্পদ = মালিকানা স্বত্ব + দায় যাচাই।
 * ⓘ লাভ-ক্ষতি: কার্যভিত্তিক (IAS 1.103), পাশে আগের বছরের একই সময়; বছর বন্ধের দাখিলা বাদ ([[YearEndService::closingSources()]])।
 * ⓘ নগদ প্রবাহ: রিপোর্ট-ইঞ্জিনের নগদ প্রবাহ আর তার পরোক্ষ মিলন ([[CoreReports::cashFlow()]]) — একই অঙ্ক, বিবরণীর ছকে।
 */
final class FinancialStatements
{
    /** নোট নম্বর — স্থির, তাই বিবরণী আর নোটের পাতা একই নম্বর বলে */
    public const NOTE_PPE = 1;

    public const NOTE_CASH = 2;

    public const NOTE_RE = 3;

    public const NOTE_LOANS = 4;

    public const NOTE_TAX = 5;

    /** @var \Closure(mixed, string): mixed */
    private \Closure $scope;

    public function __construct(
        private readonly BalanceSheetService $sheet,
        private readonly ReportEngine $reports,
    ) {}

    // ══ আর্থিক অবস্থার বিবরণী ═══════════════════════════════════════════

    /**
     * @return array<string, mixed>
     */
    public function position(string $asOf, ?int $branchId = null): array
    {
        $branchId ??= ViewedBranch::one();
        $priorDate = $this->sheet->priorDate($asOf);
        $now = $this->sheet->build($asOf, $branchId);
        $then = $this->sheet->build($priorDate, $branchId);
        $this->useScope($branchId);

        $loansNow = $this->loanDueWithinAYear($asOf, $branchId);
        $loansThen = $this->loanDueWithinAYear($priorDate, $branchId);
        $taxAccount = Account::query()->where('code', StandardChart::INCOME_TAX_PROVISION)->first();

        // ── সম্পদ ──
        $assets = ['non_current' => [], 'current' => [], 'other' => []];
        foreach ($this->pairGroups($now['assets'], $then['assets']) as $g) {
            $section = match ((string) $g['head']->code) {
                StandardChart::FIXED_ASSETS => 'non_current',
                '1100' => 'current',
                default => 'other',
            };

            if ($section === 'non_current') {
                $assets[$section][] = $this->line(__('accounts::fs.bs.ppe'), $g['now'], $g['then'], self::NOTE_PPE, 'ppe');

                continue;
            }

            if ($g['lines'] === [] && $section === 'other') {
                $assets[$section][] = $this->line($g['head']->name(), $g['now'], $g['then']);

                continue;
            }

            $cash = ['0', '0'];
            $rest = [];
            foreach ($g['lines'] as $l) {
                if (in_array((string) $l['account']->code, StandardChart::MONEY_PARENTS, true) && ! isset($l['label'])) {
                    $cash = [bcadd($cash[0], $l['now'], 4), bcadd($cash[1], $l['then'], 4)];
                } else {
                    $rest[] = $this->line($l['label'] ?? $l['account']->name(), $l['now'], $l['then']);
                }
            }

            $assets[$section] = [...$assets[$section], ...$rest];
            if ($section === 'current') {
                $assets[$section][] = $this->line(__('accounts::fs.bs.cash'), $cash[0], $cash[1], self::NOTE_CASH, 'cash');
            }
        }

        // ── দায় ──
        $liabilities = ['non_current' => [], 'current' => [], 'other' => []];
        $hasTaxLine = false;
        foreach ($this->pairGroups($now['liabilities'], $then['liabilities']) as $g) {
            $section = match ((string) $g['head']->code) {
                StandardChart::LONG_TERM_LIABILITIES => 'non_current',
                '2100' => 'current',
                default => 'other',
            };

            if ($g['lines'] === []) {
                $liabilities[$section][] = $this->line($g['head']->name(), $g['now'], $g['then']);

                continue;
            }

            foreach ($g['lines'] as $l) {
                $id = (int) $l['account']->id;
                $nowAmount = $l['now'];
                $thenAmount = $l['then'];
                $note = null;

                if ($section === 'non_current') {
                    // ⓘ চলতি অংশ বাদ — তা চলতি দায়ের নিজের লাইনে (IAS 1.69)
                    $nowAmount = bcsub($nowAmount, $this->capped($loansNow[$id] ?? '0', $l['now']), 4);
                    $thenAmount = bcsub($thenAmount, $this->capped($loansThen[$id] ?? '0', $l['then']), 4);
                    $note = isset($loansNow[$id]) || isset($loansThen[$id]) ? self::NOTE_LOANS : null;
                }

                $isTax = $taxAccount !== null && $id === (int) $taxAccount->id && ! isset($l['label']);
                $hasTaxLine = $hasTaxLine || $isTax;

                $liabilities[$section][] = $this->line($l['label'] ?? $l['account']->name(), $nowAmount, $thenAmount,
                    $isTax ? self::NOTE_TAX : $note, $isTax ? 'tax' : null);
            }
        }

        $currentNow = '0';
        $currentThen = '0';
        foreach ($this->loanLines($now, $then) as $l) {
            $currentNow = bcadd($currentNow, $this->capped($loansNow[(int) $l['account']->id] ?? '0', $l['now']), 4);
            $currentThen = bcadd($currentThen, $this->capped($loansThen[(int) $l['account']->id] ?? '0', $l['then']), 4);
        }

        if (bccomp($currentNow, '0', 4) !== 0 || bccomp($currentThen, '0', 4) !== 0) {
            $liabilities['current'][] = $this->line(__('accounts::fs.bs.loan_current'), $currentNow, $currentThen, self::NOTE_LOANS, 'loan_current');
        }

        // ⓘ আয়করের সঞ্চিতি — জের শূন্য হলেও লাইনটা থাকে; খাতই না থাকলে লেখা "খাত নেই", অঙ্ক শূন্য
        if (! $hasTaxLine) {
            $liabilities['current'][] = $this->line(
                $taxAccount?->name() ?? __('accounts::fs.bs.tax_provision_none'),
                '0', '0', self::NOTE_TAX, 'tax',
            );
        }

        // ── মালিকানা স্বত্ব ──
        $equity = [];
        $reNow = '0';
        $reThen = '0';
        foreach ($this->pairGroups($now['equity'], $then['equity']) as $g) {
            $isRe = (string) $g['head']->code === StandardChart::RETAINED_EARNINGS;
            $equity[] = $this->line($g['head']->name(), $g['now'], $g['then'], $isRe ? self::NOTE_RE : null, $isRe ? 're' : null);

            if ($isRe) {
                $reNow = $g['now'];
                $reThen = $g['then'];
            }
        }
        if (bccomp($now['profit_earlier'], '0', 4) !== 0 || bccomp($then['profit_earlier'], '0', 4) !== 0) {
            $equity[] = $this->line(__('accounts::fs.bs.profit_earlier'), $now['profit_earlier'], $then['profit_earlier'], self::NOTE_RE);
        }
        $equity[] = $this->line(__('accounts::fs.bs.profit_this_year'), $now['profit_this_year'], $then['profit_this_year'], self::NOTE_RE, 'profit');

        $sum = fn (array $lines, int $i) => array_reduce($lines, fn (string $t, array $l) => bcadd($t, $l[$i === 0 ? 'now' : 'then'], 4), '0');
        $pair = fn (array $lines) => [$sum($lines, 0), $sum($lines, 1)];

        $totals = [
            'non_current_assets' => $pair($assets['non_current']),
            'current_assets' => $pair($assets['current']),
            'other_assets' => $pair($assets['other']),
            'equity' => $pair($equity),
            'non_current_liabilities' => $pair($liabilities['non_current']),
            'current_liabilities' => $pair($liabilities['current']),
            'other_liabilities' => $pair($liabilities['other']),
        ];
        $add = fn (array ...$p) => [array_reduce($p, fn ($t, $x) => bcadd($t, $x[0], 4), '0'), array_reduce($p, fn ($t, $x) => bcadd($t, $x[1], 4), '0')];
        $totals['assets'] = $add($totals['non_current_assets'], $totals['current_assets'], $totals['other_assets']);
        $totals['liabilities'] = $add($totals['non_current_liabilities'], $totals['current_liabilities'], $totals['other_liabilities']);
        $totals['funding'] = $add($totals['equity'], $totals['liabilities']);

        $difference = bcsub($totals['assets'][0], $totals['funding'][0], 4);

        return [
            'as_of' => $asOf,
            'prior_as_of' => $priorDate,
            'branch_id' => $branchId,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => $totals,
            'difference' => $difference,
            'agrees' => bccomp($difference, '0', 2) === 0,
            'ratios' => [
                'current' => $this->ratio($totals['current_assets'][0], $totals['current_liabilities'][0]),
                'current_prior' => $this->ratio($totals['current_assets'][1], $totals['current_liabilities'][1]),
                'working_capital' => bcsub($totals['current_assets'][0], $totals['current_liabilities'][0], 4),
                'debt_equity' => $this->ratio($totals['liabilities'][0], $totals['equity'][0]),
            ],
            'notes' => [
                self::NOTE_PPE => $this->ppeNote($priorDate, $asOf),
                self::NOTE_CASH => $this->cashNote($priorDate, $asOf),
                self::NOTE_RE => [
                    'opening' => bcadd(bcadd($reThen, $then['profit_earlier'], 4), $then['profit_this_year'], 4),
                    'profit' => $now['profit_this_year'],
                    'closing' => bcadd(bcadd($reNow, $now['profit_earlier'], 4), $now['profit_this_year'], 4),
                ],
                self::NOTE_LOANS => [
                    'total' => array_reduce($this->loanLines($now, $then), fn (string $t, array $l) => bcadd($t, $l['now'], 4), '0'),
                    'current' => $currentNow,
                    'scheduled' => $loansNow !== [],
                ],
                self::NOTE_TAX => [
                    'account' => $taxAccount === null ? null : $taxAccount->code.' '.$taxAccount->name(),
                    'balance' => collect($liabilities['current'])->firstWhere('kind', 'tax')['now'] ?? '0',
                    'expense_account' => ($e = Account::query()->where('code', StandardChart::INCOME_TAX_EXPENSE)->first()) === null ? null : $e->code.' '.$e->name(),
                ],
            ],
        ];
    }

    /**
     * চলতি অনুপাত বা দায় ÷ স্বত্ব — দুই দশমিকে; ভাজক শূন্য বা ঋণাত্মক হলে `null` ("প্রযোজ্য নয়")।
     */
    public function ratio(string $top, string $bottom): ?string
    {
        return bccomp($bottom, '0', 4) <= 0 ? null : self::rounded(bcdiv($top, $bottom, 8), 2);
    }

    /** পরিবর্তনের শতাংশ — এক দশমিকে; আগে শূন্য হলে `null` ("নতুন") */
    public static function percent(string $now, string $then): ?string
    {
        if (bccomp($then, '0', 4) === 0) {
            return null;
        }

        $abs = bccomp($then, '0', 4) < 0 ? bcmul($then, '-1', 4) : $then;

        return self::rounded(bcdiv(bcmul(bcsub($now, $then, 4), '100', 8), $abs, 8), 1);
    }

    /** অর্ধেক হলে শূন্য থেকে দূরে গোল — bcmath-এ, float ছাড়া (টাকার নিয়ম, [[MoneyIsNeverAFloatTest]]) */
    public static function rounded(string $value, int $places): string
    {
        $scale = bcpow('10', (string) $places);
        $half = bccomp($value, '0', 10) < 0 ? '-0.5' : '0.5';

        return bcdiv(bcdiv(bcadd(bcmul($value, $scale, 10), $half, 10), '1', 0), $scale, $places);
    }

    /**
     * @return array{label: string, now: string, then: string, change: string, pct: ?string, note: ?int, kind: ?string}
     */
    private function line(string $label, string $now, string $then, ?int $note = null, ?string $kind = null): array
    {
        return [
            'label' => $label,
            'now' => $now,
            'then' => $then,
            'change' => bcsub($now, $then, 4),
            'pct' => self::percent($now, $then),
            'note' => $note,
            'kind' => $kind,
        ];
    }

    /**
     * আজকের আর আগের দিনের দল জোড়া — মাথা ধরে; সারিগুলো [[BalanceSheetService::withComparative()]]-এর একই নিয়মে।
     *
     * @param  list<array<string, mixed>>  $now
     * @param  list<array<string, mixed>>  $then
     * @return list<array{head: Account, now: string, then: string, lines: list<array<string, mixed>>}>
     */
    private function pairGroups(array $now, array $then): array
    {
        $paired = $this->sheet->withComparative(
            ['assets' => $now, 'liabilities' => [], 'equity' => [], 'totals' => [], 'profit_this_year' => '0', 'profit_earlier' => '0', 'agrees' => true, 'as_of' => ''],
            ['assets' => $then, 'liabilities' => [], 'equity' => [], 'totals' => [], 'profit_this_year' => '0', 'profit_earlier' => '0', 'agrees' => true, 'as_of' => ''],
        )['assets'];

        // ⓘ কেবল আগের দিনে ছিল এমন মাথা (যেমন শোধ হওয়া ঋণের দল) — আজ শূন্য নিয়ে বসে
        $seen = array_map(fn (array $g) => (int) $g['head']->id, $paired);
        foreach ($then as $g) {
            if (! in_array((int) $g['head']->id, $seen, true)) {
                $paired[] = [...$g, 'total' => '0', 'prior' => $g['total'],
                    'lines' => array_map(fn (array $l) => [...$l, 'amount' => '0', 'prior' => $l['amount']], $g['lines'])];
            }
        }

        return array_map(fn (array $g) => [
            'head' => $g['head'],
            'now' => (string) $g['total'],
            'then' => (string) ($g['prior'] ?? '0'),
            'lines' => array_map(fn (array $l) => [...$l, 'now' => (string) $l['amount'], 'then' => (string) ($l['prior'] ?? '0')], $g['lines']),
        ], $paired);
    }

    /**
     * দীর্ঘমেয়াদি দায়ের (২২০০) সারিগুলো, আজ আর আগের দিন জোড়া।
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @return list<array<string, mixed>>
     */
    private function loanLines(array $now, array $then): array
    {
        foreach ($this->pairGroups($now['liabilities'], $then['liabilities']) as $g) {
            if ((string) $g['head']->code === StandardChart::LONG_TERM_LIABILITIES) {
                return $g['lines'];
            }
        }

        return [];
    }

    /** চলতি অংশ খাতার জেরের বেশি নয় (খাতায় যা আছে তার বেশি সরানো যায় না), আর ঋণাত্মক নয় */
    private function capped(string $due, string $balance): string
    {
        if (bccomp($balance, '0', 4) <= 0) {
            return '0';
        }

        return bccomp($due, $balance, 4) > 0 ? $balance : $due;
    }

    /**
     * ⭐ পরের ১২ মাসে শোধযোগ্য আসল — নেওয়া ঋণের কিস্তির তালিকা থেকে, আসলের খাত ধরে (IAS 1.69)।
     *
     * ⓘ যে কিস্তি ঐ দিনের পরের ১২ মাসের মধ্যে পড়ে, বা আগে পড়েছে অথচ ঐ দিন পর্যন্ত শোধ হয়নি। কিস্তির তালিকা নেই (CC, হাতধার)
     *   এমন ঋণ এখানে আসে না — তার পুরোটা খাতা যেখানে রেখেছে সেখানেই।
     *
     * @return array<int, string> আসলের খাত → অঙ্ক
     */
    private function loanDueWithinAYear(string $asOf, ?int $branchId): array
    {
        $until = Carbon::parse($asOf)->addYearNoOverflow()->toDateString();

        $rows = LoanInstalment::query()
            ->join('acc_loans', 'acc_loans.id', '=', 'acc_loan_instalments.loan_id')
            ->where('acc_loans.company_id', CompanyContext::id())
            ->where('acc_loans.direction', Loan::TAKEN)
            ->where('acc_loans.status', '!=', DocumentStatus::DRAFT)
            ->whereNotNull('acc_loans.principal_account_id')
            ->where('acc_loans.start_date', '<=', $asOf)
            ->when($branchId !== null, fn ($q) => $q->where('acc_loans.branch_id', $branchId))
            ->where('acc_loan_instalments.due_date', '<=', $until)
            ->where(fn ($q) => $q->whereNull('acc_loan_instalments.paid_on')->orWhere('acc_loan_instalments.paid_on', '>', $asOf))
            ->groupBy('acc_loans.principal_account_id')
            ->selectRaw('acc_loans.principal_account_id as account_id, SUM(acc_loan_instalments.principal) as due')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->account_id] = bcadd((string) $row->due, '0', 4);
        }

        return $out;
    }

    /**
     * স্থায়ী সম্পদের চলাচল — খরচ আর জমা অবচয়, আগের বছর-শেষ থেকে আজ (IAS 16.73)।
     * ⓘ জমা অবচয় মানে ১২০০-এর নিচের ক্রেডিট-প্রকৃতির খাত (১২৯০ ও নিচের)।
     *
     * @return array<string, string>
     */
    private function ppeNote(string $priorDate, string $asOf): array
    {
        $all = Account::query()->get(['id', 'code', 'parent_id', 'nature']);
        $head = $all->firstWhere('code', StandardChart::FIXED_ASSETS);
        $ids = $head === null ? [] : $this->subtree($all, (int) $head->id);
        $dep = $all->whereIn('id', $ids)->where('nature', Account::CREDIT)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $cost = array_values(array_diff($ids, $dep));

        $move = fn (array $accounts) => $accounts === [] ? (object) ['opening' => 0, 'dr' => 0, 'cr' => 0] : DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $accounts)
            ->where('trx_date', '<=', $asOf)
            ->tap(fn ($q) => ($this->scope)($q, 'branch_id'))
            ->selectRaw('COALESCE(SUM(CASE WHEN trx_date <= ? THEN debit - credit ELSE 0 END), 0) as opening', [$priorDate])
            ->selectRaw('COALESCE(SUM(CASE WHEN trx_date > ? THEN debit ELSE 0 END), 0) as dr', [$priorDate])
            ->selectRaw('COALESCE(SUM(CASE WHEN trx_date > ? THEN credit ELSE 0 END), 0) as cr', [$priorDate])
            ->first();

        $c = $move($cost);
        $d = $move($dep);
        $s = fn ($v) => bcadd((string) $v, '0', 4);

        $costOpen = $s($c->opening);
        $costClose = bcadd($costOpen, bcsub($s($c->dr), $s($c->cr), 4), 4);
        $depOpen = bcmul($s($d->opening), '-1', 4);
        $depClose = bcadd($depOpen, bcsub($s($d->cr), $s($d->dr), 4), 4);

        return [
            'cost_open' => $costOpen, 'additions' => $s($c->dr), 'disposals' => $s($c->cr), 'cost_close' => $costClose,
            'dep_open' => $depOpen, 'charge' => $s($d->cr), 'dep_disposals' => $s($d->dr), 'dep_close' => $depClose,
            'nbv' => bcsub($costClose, $depClose, 4),
        ];
    }

    /**
     * নগদ ও নগদ সমতুল্যের ভাঙন — প্রতিটা টাকার খাত (১১০১, ১১০২, ১১০৫-এর নিচে), আজ আর আগের বছর-শেষে; শূন্য-শূন্য বাদ।
     *
     * @return list<array{label: string, now: string, then: string}>
     */
    private function cashNote(string $priorDate, string $asOf): array
    {
        $all = Account::query()->get();
        $ids = [];
        foreach ($all->whereIn('code', StandardChart::MONEY_PARENTS) as $parent) {
            $ids = [...$ids, ...$this->subtree($all, (int) $parent->id)];
        }

        $rows = $ids === [] ? collect() : DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $ids)
            ->where('trx_date', '<=', $asOf)
            ->tap(fn ($q) => ($this->scope)($q, 'branch_id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit - credit) as now_net')
            ->selectRaw('SUM(CASE WHEN trx_date <= ? THEN debit - credit ELSE 0 END) as then_net', [$priorDate])
            ->get()->keyBy('account_id');

        $out = [];
        foreach ($all->whereIn('id', $ids)->sortBy('code') as $account) {
            $row = $rows->get($account->id);
            if ($row === null || (bccomp((string) $row->now_net, '0', 4) === 0 && bccomp((string) $row->then_net, '0', 4) === 0)) {
                continue;
            }
            $out[] = ['label' => $account->code.' '.$account->name(), 'now' => bcadd((string) $row->now_net, '0', 4), 'then' => bcadd((string) $row->then_net, '0', 4)];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Account>  $all
     * @return list<int>
     */
    private function subtree(Collection $all, int $id): array
    {
        $ids = [$id];
        foreach ($all->where('parent_id', $id) as $child) {
            $ids = [...$ids, ...$this->subtree($all, (int) $child->id)];
        }

        return $ids;
    }

    private function useScope(?int $branchId): void
    {
        $this->scope = $branchId !== null
            ? fn ($q, string $column) => $q->where($column, $branchId)
            : fn ($q, string $column) => app(DataScope::class)->inView($q, $column);
    }

    // ══ লাভ-ক্ষতি — আগের বছরের একই সময়ের পাশে ═══════════════════════════════

    /**
     * @return array<string, mixed>
     */
    public function profitOrLoss(string $from, string $to, ?int $branchId = null): array
    {
        $branchId ??= ViewedBranch::one();
        $this->useScope($branchId);
        $priorFrom = Carbon::parse($from)->subYearNoOverflow()->toDateString();
        $priorTo = Carbon::parse($to)->subYearNoOverflow()->toDateString();

        $all = Account::query()->get();
        $in = function (array $codes) use ($all): array {
            $ids = [];
            foreach ($all->whereIn('code', $codes) as $a) {
                $ids = [...$ids, ...$this->subtree($all, (int) $a->id)];
            }

            return $ids;
        };
        $revenue = $in(StandardChart::NET_SALES);
        $cost = $in([StandardChart::COST_OF_GOODS_SOLD, StandardChart::PURCHASE_PRICE_VARIANCE]);
        $finance = $in([StandardChart::INTEREST_EXPENSE, StandardChart::EARLY_BREAK_PENALTY]);
        $tax = $in([StandardChart::INCOME_TAX_EXPENSE]);

        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->where(fn ($q) => $q->whereBetween('ledger_entries.trx_date', [$from, $to])->orWhereBetween('ledger_entries.trx_date', [$priorFrom, $priorTo]))
            ->tap(fn ($q) => ($this->scope)($q, 'ledger_entries.branch_id'))
            ->groupBy('ledger_entries.account_id', 'accounts.type')
            ->select(['ledger_entries.account_id', 'accounts.type'])
            ->selectRaw('SUM(CASE WHEN ledger_entries.trx_date BETWEEN ? AND ? THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END) as now_net', [$from, $to])
            ->selectRaw('SUM(CASE WHEN ledger_entries.trx_date BETWEEN ? AND ? THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END) as then_net', [$priorFrom, $priorTo])
            ->get();

        $sections = array_fill_keys(['revenue', 'cost_of_sales', 'other_income', 'operating_expenses', 'finance_costs', 'income_tax'], []);
        foreach ($rows as $row) {
            $id = (int) $row->account_id;
            $section = match (true) {
                in_array($id, $revenue, true) => 'revenue',
                in_array($id, $cost, true) => 'cost_of_sales',
                $row->type === Account::INCOME => 'other_income',
                in_array($id, $finance, true) => 'finance_costs',
                in_array($id, $tax, true) => 'income_tax',
                default => 'operating_expenses',
            };
            // ⓘ সব অঙ্ক ক্রেডিট − ডেবিট: আয় ধনাত্মক, খরচ ঋণাত্মক (কাগজে বন্ধনীতে) — তাই উপমোটগুলো সোজা যোগ
            $now = bcadd((string) $row->now_net, '0', 4);
            $then = bcadd((string) $row->then_net, '0', 4);

            if (bccomp($now, '0', 4) === 0 && bccomp($then, '0', 4) === 0) {
                continue;
            }

            $account = $all->firstWhere('id', $id);
            $sections[$section][] = $this->line($account === null ? '#'.$id : $account->code.' '.$account->name(), $now, $then);
        }

        $t = fn (string $s) => [
            array_reduce($sections[$s], fn ($x, $l) => bcadd($x, $l['now'], 4), '0'),
            array_reduce($sections[$s], fn ($x, $l) => bcadd($x, $l['then'], 4), '0'),
        ];
        $plus = fn (array $a, array $b) => [bcadd($a[0], $b[0], 4), bcadd($a[1], $b[1], 4)];

        $totals = array_combine(array_keys($sections), array_map($t, array_keys($sections)));
        $totals['gross_profit'] = $plus($totals['revenue'], $totals['cost_of_sales']);
        $totals['operating_profit'] = $plus($plus($totals['gross_profit'], $totals['other_income']), $totals['operating_expenses']);
        $totals['profit_before_tax'] = $plus($totals['operating_profit'], $totals['finance_costs']);
        $totals['profit'] = $plus($totals['profit_before_tax'], $totals['income_tax']);

        return [
            'from' => $from, 'to' => $to, 'prior_from' => $priorFrom, 'prior_to' => $priorTo, 'branch_id' => $branchId,
            'sections' => $sections,
            'totals' => array_map(fn (array $p) => ['now' => $p[0], 'then' => $p[1], 'change' => bcsub($p[0], $p[1], 4), 'pct' => self::percent($p[0], $p[1])], $totals),
        ];
    }

    // ══ নগদ প্রবাহ — পরোক্ষ পদ্ধতি ══════════════════════════════════════

    /**
     * @return array<string, mixed>
     */
    public function cashFlow(string $from, string $to, ?int $branchId = null): array
    {
        $branchId ??= ViewedBranch::one();
        $result = $this->reports->run('accounts.cash_flow', ['from' => $from, 'to' => $to, 'branch_id' => $branchId], perPage: 100000);
        $summary = ($result->report->summary)($result->totals, $result->filters);
        $lines = $summary['lines'];

        $investingLabel = (string) __('accounts::message.cf_investing');
        $financingLabel = (string) __('accounts::message.cf_financing');
        $investing = [];
        $financing = [];
        $operatingDirect = '0';

        foreach ($result->rows as $row) {
            $net = bcadd((string) $row['net'], '0', 4);
            $line = ['label' => (string) $row['account_name'], 'value' => $net];
            match ((string) $row['section']) {
                $investingLabel => $investing[] = $line,
                $financingLabel => $financing[] = $line,
                default => $operatingDirect = bcadd($operatingDirect, $net, 4),
            };
        }

        $sum = fn (array $l) => array_reduce($l, fn (string $t, array $x) => bcadd($t, $x['value'], 4), '0');
        $opening = (string) $lines[0]['value'];
        $closing = (string) $lines[1]['value'];
        $indirect = array_slice($lines, 2, 6);
        $operating = (string) ($lines[8]['value'] ?? '0');
        $direct = (string) ($lines[9]['value'] ?? $operatingDirect);
        $change = bcadd(bcadd($operating, $sum($investing), 4), $sum($financing), 4);

        return [
            'from' => $from, 'to' => $to,
            'indirect' => $indirect,
            'operating' => $operating,
            'direct' => $direct,
            'direct_agrees' => bccomp($operating, $direct, 2) === 0,
            'investing' => $investing, 'investing_total' => $sum($investing),
            'financing' => $financing, 'financing_total' => $sum($financing),
            'change' => $change,
            'opening' => $opening,
            'closing' => $closing,
            'gap' => bcsub(bcadd($opening, $change, 4), $closing, 4),
        ];
    }

    /** সময়ের শুরু — আগের অর্থবছরের শেষের পরের দিন */
    public function periodStart(string $asOf): string
    {
        return Carbon::parse($this->sheet->priorDate($asOf))->addDay()->toDateString();
    }

    /** @return array<string, mixed> */
    public function equity(string $from, string $to, ?int $branchId = null): array
    {
        return $this->sheet->equityStatement($from, $to, $branchId ?? ViewedBranch::one());
    }
}
