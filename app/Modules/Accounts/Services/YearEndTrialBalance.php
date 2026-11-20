<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বছর-শেষের রেওয়ামিল — সমন্বয়ের আগে → নম্বর দেওয়া সমন্বয় জাবেদা → সমন্বয়ের পরে, পাশে আগের বছর-শেষ (মালিক, ১৭ নভেম্বর ২০২৬
 * "ok": রেওয়ামিল আর স্থিতিপত্র NEXUS-এর মানে — এটা তার শেষ অংশ)।
 *
 * ── ⓘ কোন অঙ্ক কোথা থেকে ───────────────────────────────────────────────
 *   · সমন্বয়ের পরে = অর্থবছরের শেষ দিন পর্যন্ত খাতার সব সারি, **এই বছরের সমাপনী দাখিলা বাদে** (বন্ধের আগের রেওয়ামিল —
 *     আয়-খরচের খাত তখনও খোলা; আগের বছরগুলোর সমাপনী থাকে, তাই আয়-খরচ কেবল এই বছরের)।
 *   · সমন্বয় = এই বছরের তারিখের পাকা সমন্বয় জাবেদা (`vouchers.is_adjusting`: বকেয়া, অগ্রিম সরানো, অবচয়, প্রভিশন, আর তাদের
 *     উল্টো জাবেদা — [[AdjustingReversals]]); খাতের মোট ডেবিট আর মোট ক্রেডিট আলাদা ঘরে, নিট নয় (কার্যপত্রের ধারা)।
 *     প্রতিটা জাবেদার একটা নম্বর (তারিখের ক্রমে ১, ২, ৩…) — সারিতে কোন কোন নম্বর ছুঁয়েছে, নিচে নম্বরের তালিকা।
 *   · সমন্বয়ের আগে = পরে − (সমন্বয় ডেবিট − সমন্বয় ক্রেডিট) — তাই আগে + সমন্বয় = পরে, গঠনেই, প্রতিটা সারিতে।
 *   · আগের বছর-শেষ = আগের অর্থবছরের শেষ দিন পর্যন্ত, ঐ বছরের সমাপনী বাদে (একই নিয়ম, তুলনীয়)। আগের বছর না থাকলে শুরুর আগের দিন।
 *
 * ⓘ দল, উল্টো জের, বাদ পড়া ভাউচার, তালা দেওয়া মাস আর শাখার দেয়াল সাধারণ রেওয়ামিলের একই ([[TrialBalanceStatement]]) — একই
 *   রিপোর্ট-ইঞ্জিনের ছাঁকনি থেকে, তাই নাগালের বাইরের শাখা এখানেও খোলে না।
 */
final class YearEndTrialBalance
{
    /** আট ঘর — সমন্বয়ের আগে, সমন্বয়, পরে, আগের বছর-শেষ; প্রতিটা ডেবিট/ক্রেডিট */
    public const CELLS = [
        'before_debit', 'before_credit', 'adj_debit', 'adj_credit', 'after_debit', 'after_credit', 'prev_debit', 'prev_credit',
    ];

    /** চার জোড়া — মেলানোর জন্য */
    public const PAIRS = ['before', 'adj', 'after', 'prev'];

    /** @var array<int, string> শেষ হিসাবের জাবেদা-ধরা ডেবিটের যোগ ([[adjustmentsByAccount()]]) */
    private array $perVoucher = [];

    public function __construct(private readonly TrialBalanceStatement $statement) {}

    /** বাছার তালিকা — নতুন বছর আগে */
    public static function years(): Collection
    {
        return FinancialYear::query()->orderByDesc('starts_on')->get();
    }

    /** না বললে আজকের বছর, না থাকলে সবশেষ বছর */
    public static function yearFor(?int $yearId): ?FinancialYear
    {
        if ($yearId !== null) {
            $year = FinancialYear::query()->find($yearId);

            if ($year !== null) {
                return $year;
            }
        }

        return FinancialYear::forDate(now()) ?? FinancialYear::query()->orderByDesc('starts_on')->first();
    }

    /** ঠিক আগের অর্থবছর — এই বছরের শুরুর আগে যেটা শেষ */
    public static function previousOf(FinancialYear $year): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('ends_on', '<', Carbon::parse($year->starts_on)->toDateString())
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(FinancialYear $year, ?int $branchId = null): array
    {
        $from = Carbon::parse($year->starts_on)->toDateString();
        $to = Carbon::parse($year->ends_on)->toDateString();
        $prev = self::previousOf($year);
        $prevEnd = $prev !== null ? Carbon::parse($prev->ends_on)->toDateString() : Carbon::parse($from)->subDay()->toDateString();
        $prevStart = $prev !== null ? Carbon::parse($prev->starts_on)->toDateString() : null;

        // ⓘ ছাঁকনি, শাখার দেয়াল, বাদ পড়া ভাউচার আর তালা — সাধারণ রেওয়ামিলের একই পথে ([[TrialBalanceStatement::build()]])
        $base = $this->statement->build(['from' => $from, 'to' => $to, 'branch_id' => $branchId]);
        $f = $base['filters'];

        $adjusting = $this->adjustingVouchers($f, $from, $to);

        $after = $this->netByAccount($f, $to, $from);
        $prevNet = $this->netByAccount($f, $prevEnd, $prevStart);
        [$adjDebit, $adjCredit, $touched] = $this->adjustmentsByAccount($f, $from, $to, $adjusting->pluck('id')->all());

        // ⓘ দেয়ালের ভিতরে যার একটা সারিও নেই, সেই জাবেদা তালিকায় নয়; বাকিদের নম্বর তারিখের ক্রমে ১ থেকে, অঙ্ক খাতার সারি থেকে
        $adjusting = $adjusting
            ->filter(fn (array $v) => isset($this->perVoucher[$v['id']]))
            ->values()
            ->map(fn (array $v, int $i) => ['no' => $i + 1, 'amount' => $this->perVoucher[$v['id']]] + $v);
        $numberOf = $adjusting->mapWithKeys(fn (array $v) => [$v['id'] => $v['no']])->all();

        $refs = [];
        foreach ($touched as $accountId => $voucherIds) {
            $refs[$accountId] = collect($voucherIds)->map(fn (int $vid) => $numberOf[$vid] ?? null)->filter()->unique()->sort()->values()->all();
        }

        $ids = array_values(array_unique([...array_keys($after), ...array_keys($prevNet), ...array_keys($adjDebit)]));
        $accounts = Account::query()->withTrashed()->with('parent')->whereKey($ids ?: [0])->get()->keyBy('id');

        $groups = [];
        foreach ([...TrialBalanceStatement::CLASSES, 'other'] as $type) {
            $groups[$type] = ['type' => $type, 'rows' => [], 'sum' => array_fill_keys(self::CELLS, '0')];
        }

        $total = array_fill_keys(self::CELLS, '0');
        $wrong = [];

        foreach ($ids as $id) {
            $afterNet = $after[$id] ?? '0';
            $dr = $adjDebit[$id] ?? '0';
            $cr = $adjCredit[$id] ?? '0';
            $beforeNet = bcsub($afterNet, bcsub($dr, $cr, 4), 4);
            $prevValue = $prevNet[$id] ?? '0';

            if (collect([$afterNet, $dr, $cr, $beforeNet, $prevValue])->every(fn (string $v) => bccomp($v, '0', 4) === 0)) {
                continue;
            }

            $values = [
                ...self::split('before', $beforeNet),
                'adj_debit' => bcadd($dr, '0', 4),
                'adj_credit' => bcadd($cr, '0', 4),
                ...self::split('after', $afterNet),
                ...self::split('prev', $prevValue),
            ];

            $account = $accounts->get($id);
            $type = $account !== null && in_array($account->type, TrialBalanceStatement::CLASSES, true) ? $account->type : 'other';

            foreach (self::CELLS as $cell) {
                $groups[$type]['sum'][$cell] = bcadd($groups[$type]['sum'][$cell], $values[$cell], 4);
                $total[$cell] = bcadd($total[$cell], $values[$cell], 4);
            }

            $wrongSide = $account !== null
                && $this->statement->wrongSide($account, ['debit' => $values['after_debit'], 'credit' => $values['after_credit']]);

            $row = [
                'account_id' => $id,
                'code' => (string) ($account->code ?? ''),
                'name' => $account?->name() ?? '#'.$id,
                'group' => $account?->parent?->name() ?? '',
                'values' => $values,
                'refs' => $refs[$id] ?? [],
                'wrong_side' => $wrongSide,
            ];

            if ($wrongSide) {
                $credit = bccomp($values['after_credit'], '0', 4) > 0;
                $wrong[] = [
                    'code' => $row['code'], 'name' => $row['name'],
                    'side' => $credit ? Account::CREDIT : Account::DEBIT,
                    'amount' => $credit ? $values['after_credit'] : $values['after_debit'],
                ];
            }

            $groups[$type]['rows'][] = $row;
        }

        foreach ($groups as &$group) {
            usort($group['rows'], fn (array $a, array $b) => strcmp($a['code'], $b['code']));
        }
        unset($group);

        $groups = array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));

        $gaps = [];
        foreach (self::PAIRS as $pair) {
            $gaps[$pair] = bcsub($total[$pair.'_debit'], $total[$pair.'_credit'], 4);
        }

        return [
            'year' => $year,
            'previous' => $prev,
            'from' => $from,
            'to' => $to,
            'prev_end' => $prevEnd,
            'branch_id' => $f['branch_id'] ?? null,
            'filters' => $f,
            'groups' => $groups,
            'total' => $total,
            'gaps' => $gaps,
            'agrees' => collect($gaps)->every(fn (string $gap) => bccomp($gap, '0', 2) === 0),
            'adjusting' => $adjusting->values()->all(),
            'closed' => (bool) $year->is_closed,
            'wrong_side' => $wrong,
            'held' => $base['held'],
            'locked' => $base['locked'],
        ];
    }

    /**
     * নিট (ডেবিট − ক্রেডিট) → ডেবিট বা ক্রেডিট ঘরে
     *
     * @return array<string, string>
     */
    private static function split(string $pair, string $net): array
    {
        $net = bcadd($net, '0', 4);

        return [
            $pair.'_debit' => bccomp($net, '0', 4) > 0 ? $net : '0.0000',
            $pair.'_credit' => bccomp($net, '0', 4) < 0 ? bcmul($net, '-1', 4) : '0.0000',
        ];
    }

    /**
     * খাতওয়ারি নিট (ডেবিট − ক্রেডিট), `$upto` পর্যন্ত — সমাপনী দাখিলা (বসানো বা উলটানো) যদি `$closingFrom` বা তার পরে হয় তো বাদ।
     *
     * @param  array<string, mixed>  $f
     * @return array<int, string>
     */
    private function netByAccount(array $f, string $upto, ?string $closingFrom): array
    {
        return DB::table('ledger_entries')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->where('ledger_entries.trx_date', '<=', $upto)
            ->when($closingFrom !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
                ->orWhere('ledger_entries.trx_date', '<', $closingFrom)))
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->account_id => bcadd((string) $r->net, '0', 4)])
            ->all();
    }

    /**
     * এই বছরের পাকা সমন্বয় জাবেদা — তারিখের ক্রমে নম্বর ১, ২, ৩…। ⓘ বাতিল জাবেদা নেই (তার আসল আর উল্টো সারি মিলে শূন্য, আর
     * দুটোই "সমন্বয়ের আগে"-তে থাকে); শাখার দেয়াল ভাউচারের শাখায়।
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, array{id: int, date: string, document_no: string, narration: string, amount: string, reversal: bool}>
     */
    private function adjustingVouchers(array $f, string $from, string $to): Collection
    {
        return Voucher::query()->withoutGlobalScope('user-branch')
            ->where('vouchers.type', Voucher::JOURNAL)
            ->where('vouchers.is_adjusting', true)
            ->where('vouchers.status', DocumentStatus::CONFIRMED)
            ->whereBetween('vouchers.trx_date', [$from, $to])
            // ⓘ শাখার দেয়াল এখানে নয়, খাতার সারিতে ([[adjustmentsByAccount()]]) — যে জাবেদার একটা সারিও দেয়ালের ভিতরে নেই, সে পরে বাদ
            ->orderBy('vouchers.trx_date')
            ->orderBy('vouchers.id')
            ->get(['vouchers.id', 'vouchers.trx_date', 'vouchers.document_no', 'vouchers.narration', 'vouchers.amount', 'vouchers.reversal_of_id'])
            ->values()
            ->map(fn (Voucher $v) => [
                'id' => (int) $v->id,
                'date' => Carbon::parse($v->trx_date)->toDateString(),
                'document_no' => (string) $v->document_no,
                'narration' => (string) ($v->narration ?? ''),
                'amount' => bcadd((string) ($v->amount ?? '0'), '0', 4),
                'reversal' => $v->reversal_of_id !== null,
            ]);
    }

    /**
     * খাতওয়ারি সমন্বয় — মোট ডেবিট, মোট ক্রেডিট, আর কোন কোন নম্বরের জাবেদা খাতটা ছুঁয়েছে।
     * ⓘ জাবেদার আসল দাখিলা আর পাকা অবস্থায় বদলের উল্টো-আবার দাখিলা (`:reversal`) দুটোই — নিট যা আছে, তা-ই।
     *
     * ⓘ প্রতিটা জাবেদা·খাতের নিট — ডেবিটে বা ক্রেডিটে; তাই বদলে-আবার-বসানো জাবেদা দুইবার গোনা হয় না।
     *
     * @param  array<string, mixed>  $f
     * @param  list<int>  $voucherIds
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, list<int>>} ডেবিট, ক্রেডিট, খাত => ভাউচার id
     */
    private function adjustmentsByAccount(array $f, string $from, string $to, array $voucherIds): array
    {
        $this->perVoucher = [];

        if ($voucherIds === []) {
            return [[], [], []];
        }

        $journal = Voucher::SOURCE_TYPES[Voucher::JOURNAL];

        $rows = DB::table('ledger_entries')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->whereIn('ledger_entries.source_type', [$journal, $journal.':reversal'])
            ->whereIn('ledger_entries.source_id', $voucherIds)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->groupBy('ledger_entries.account_id', 'ledger_entries.source_id')
            ->selectRaw('ledger_entries.account_id, ledger_entries.source_id, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net')
            ->get();

        $debit = [];
        $credit = [];
        $touched = [];

        foreach ($rows as $r) {
            $id = (int) $r->account_id;
            $voucher = (int) $r->source_id;
            $net = bcadd((string) $r->net, '0', 4);

            if (bccomp($net, '0', 4) === 0) {
                continue;
            }

            if (bccomp($net, '0', 4) > 0) {
                $debit[$id] = bcadd($debit[$id] ?? '0', $net, 4);
                // ⓘ জাবেদার অঙ্ক = দেয়ালের ভিতরে তার নিট ডেবিটের যোগ — নিচের তালিকা আর সমন্বয়ের ডেবিট কলাম একই যোগ
                $this->perVoucher[$voucher] = bcadd($this->perVoucher[$voucher] ?? '0', $net, 4);
            } else {
                $credit[$id] = bcadd($credit[$id] ?? '0', bcmul($net, '-1', 4), 4);
            }

            $touched[$id][] = $voucher;
        }

        return [$debit, $credit, $touched];
    }
}
