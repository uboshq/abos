<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Models\DayClose;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Dashboard\SalesBoard;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ দিন শেষের হিসাব (Day Closing · Daily Flash Report) — মালিকের অনুমোদিত নমুনা v7 (১৬ নভেম্বর ২০২৬ "ok"; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * এগারোটা অংশ, নমুনার ক্রমে: ১ আজ টাকা এল (পার্টি ধরে) · ২ আজকের ডেলিভারি · ৩ পার্টি ধরে বিক্রয় ও লাভ · ৪ আজকের খরচ · ৫ নগদ গণনা ও মিল ·
 * ৬ টাকা গেছে কিন্তু খরচ নয় · ৭ হিসাবের জের · ৮ লাভ-ক্ষতির সারাংশ (আজ · মাসে আজ পর্যন্ত · দৈনিক গড়) · ৯ মজুদ ও বকেয়া · ১০ স্থির খরচ ·
 * ১১ মূসক ও মাসের লক্ষ্য; মাথায় "আজকের খবর", শেষে "দিন বন্ধ" আর তিন সই।
 *
 * ⓘ যেখানে সফটওয়্যারের চালু হিসাব আছে, সেটাই পড়া হয় — টাকার খাতের জের আর ভাড়া [[DailySummary]], নিট বিক্রি [[SalesMetrics]],
 *   মাসের লক্ষ্য [[SalesBoard::monthTarget()]] — তাই এই কাগজের অঙ্ক বাকি পাতার সাথে মেলে।
 * ⓘ শাখা: হেডারে বাছা শাখা ([[ViewedBranch]]), প্রতিদিনের সারাংশের মতোই।
 * ⓘ কেবল পোস্ট হওয়া কাগজ — খতিয়ানে খসড়া থাকেই না, আর বিল/চালানে `POSTED` অবস্থা।
 * ⓘ কোথায় থাকে: নির্বাহী মডিউলে, হিসাবে নয় — বিক্রয়, গ্রাহক আর প্রতিদিনের সারাংশ পড়ে, আর হিসাব কারও উপর নির্ভর করে না
 *   (BoundariesTest; ম্যাক, ১৮ নভেম্বর ২০২৬)। মাস শেষের হিসাবের ([[MonthCloseController]]) একই জায়গা।
 */
final class DayClosingService
{
    /** @var list<string> স্থির খরচের ঘর — সেটিংসে মাসিক অঙ্ক ([[module.php]] `accounts.day_close.fixed.*`) */
    public const FIXED_HEADS = ['salary', 'rent', 'utilities', 'marketing', 'depreciation', 'other', 'interest'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DailySummary $summary,
    ) {}

    /** @return array<string, mixed> */
    public function build(Carbon $day): array
    {
        $d = $day->toDateString();
        $base = $this->summary->for($day->copy());
        $calendar = $this->calendar($day);

        $inflow = $this->inflow($d);
        $delivery = $this->delivery($d, $base['fare']['rows']);
        $sales = $this->salesByParty($d);
        $fareAccounts = $this->fareAccounts();
        $expenses = $this->expenses($d, $fareAccounts);
        $count = $this->cashCount($d, $base['money']['rows']);
        $paidOut = $this->paidNotExpense($d);
        $fixed = $this->fixed($calendar);
        $pl = $this->profitLoss($day, $calendar, $fareAccounts, $fixed);
        $stock = $this->stockAndDues($day);
        $vat = $this->vatAndTarget($day, $calendar);

        return [
            'date' => $day->copy(),
            'calendar' => $calendar,
            'branch_id' => CompanyContext::branchId(),
            'inflow' => $inflow,
            'delivery' => $delivery,
            'sales' => $sales,
            'expenses' => $expenses,
            'count' => $count,
            'paid_out' => $paidOut,
            'balances' => $base['money'],
            'pl' => $pl,
            'stock' => $stock,
            'fixed' => $fixed,
            'vat' => $vat,
            'news' => $this->news($sales, $delivery, $inflow, $count, $stock, $vat, $base['sales']),
            'closed' => DayClose::closedOn($day, CompanyContext::branchId()),
            'record' => ($record = DayClose::recordOn($day, CompanyContext::branchId())),
            'late' => $this->addedAfterClose($d, $record),
            'staff_cash' => $this->staffCash($base['money']['rows']),
        ];
    }

    // ── ১ · আজ টাকা এল ─────────────────────────────────────────────────

    /**
     * টাকার খাতে (নগদ, ব্যাংক, মোবাইল) ডেবিট — রসিদ, আদায় আর নগদ বিক্রির কাগজে; নিজের খাত থেকে খাতে সরানো (কন্ট্রা, ট্রান্সফার) টাকা আসা নয়।
     *
     * @return array{groups: list<array{kind: string, rows: list<array<string, mixed>>, total: string, charge: string}>, total: string, charge: string}
     */
    private function inflow(string $d): array
    {
        $rows = $this->ledger($d)
            ->whereIn('a.money_kind', [Account::CASH, Account::BANK, Account::MFS])
            ->where('ledger_entries.debit', '>', 0)
            ->whereIn(DB::raw("SUBSTRING_INDEX(ledger_entries.source_type, ':', 1)"), ['receipt_voucher', 'collection', 'sales_invoice', 'pos_sale'])
            ->where('ledger_entries.source_type', 'not like', '%:reversal')
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.id', 'ledger_entries.source_type', 'ledger_entries.source_id', 'ledger_entries.document_no',
                'ledger_entries.narration', 'ledger_entries.debit', 'a.money_kind', 'a.name_en', 'a.name_bn']);

        $sources = $rows->map(fn ($r) => [$r->source_type, (int) $r->source_id])->unique()->values();
        $parties = $this->entryParties($sources);
        $charges = $this->entryCharges($sources);
        $vouchers = DB::table('vouchers')->where('company_id', CompanyContext::id())
            ->whereIn('id', $rows->where('source_type', 'receipt_voucher')->pluck('source_id')->all())
            ->get(['id', 'instrument', 'instrument_no', 'deposit_slip_no'])->keyBy('id');

        $groups = [];

        foreach ([Account::CASH, Account::BANK, Account::MFS] as $kind) {
            $in = $rows->where('money_kind', $kind)->values()->map(function ($r) use ($parties, $charges, $vouchers) {
                $key = $r->source_type.'#'.$r->source_id;
                $v = $r->source_type === 'receipt_voucher' ? $vouchers->get($r->source_id) : null;
                $how = $v ? trim(implode(' · ', array_filter([(string) $v->instrument, (string) ($v->instrument_no ?: $v->deposit_slip_no)]))) : '';

                return [
                    'party' => $parties[$key] ?? null,
                    'account' => (string) ((app()->getLocale() === 'bn' ? $r->name_bn : null) ?: $r->name_en),
                    'how' => $how !== '' ? $how : (string) $r->document_no,
                    'document_no' => (string) $r->document_no,
                    'amount' => (string) $r->debit,
                    'charge' => $charges[$key] ?? '0',
                    'note' => (string) ($r->narration ?? ''),
                ];
            })->all();

            if ($in === []) {
                continue;
            }

            $groups[] = ['kind' => $kind, 'rows' => $in, 'total' => $this->sum($in, 'amount'), 'charge' => $this->sum($in, 'charge')];
        }

        return ['groups' => $groups, 'total' => $this->sum($groups, 'total'), 'charge' => $this->sum($groups, 'charge')];
    }

    // ── ২ · আজকের ডেলিভারি ─────────────────────────────────────────────

    /**
     * @param  Collection<int, array<string, mixed>>  $fareRows  [[DailySummary]]-এর ভাড়ার সারি
     * @return array{rows: list<array<string, mixed>>, value: string, fare: string}
     */
    private function delivery(string $d, Collection $fareRows): array
    {
        $fares = $fareRows->keyBy('id');

        $rows = DeliveryChallan::query()->with(Customer::placeVia('customer'))
            ->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d)->orderBy('id')->get()
            ->map(function (DeliveryChallan $c) use ($fares) {
                $fare = $fares->get($c->id);
                $amount = $fare ? (string) $fare['amount'] : (is_numeric($c->transport_cost) ? (string) $c->transport_cost : '0');

                return [
                    'no' => (string) $c->document_no,
                    'party' => $c->customer?->nameWithPoint() ?? '—',
                    'value' => (string) $c->total,
                    'vehicle' => trim(implode(' · ', array_filter([(string) ($fare['vehicle'] ?? $c->vehicle_no ?? ''), (string) ($fare['driver'] ?? $c->driver_name ?? '')]))),
                    'fare' => $amount,
                    'pct' => bccomp((string) $c->total, '0', 4) > 0 ? bcmul(bcdiv($amount, (string) $c->total, 6), '100', 2) : null,
                ];
            })->values()->all();

        $value = $this->sum($rows, 'value');
        $fare = $this->sum($rows, 'fare');

        return ['rows' => $rows, 'value' => $value, 'fare' => $fare,
            'pct' => bccomp($value, '0', 4) > 0 ? bcmul(bcdiv($fare, $value, 6), '100', 2) : null];
    }

    // ── ৩ · পার্টি ধরে বিক্রয় ও লাভ ─────────────────────────────────────

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, string>} */
    private function salesByParty(string $d): array
    {
        $bills = SalesInvoice::query()->posted()->withCollected()->with(Customer::placeVia('customer'))
            ->whereDate('trx_date', $d)->orderBy('id')->get();

        // ⓘ বিলের চালানের ভাড়া — বিলের লাইন → চালানের লাইন → চালান
        $fare = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereIn('il.sales_invoice_id', $bills->pluck('id')->all() ?: [0])
            ->groupBy('il.sales_invoice_id')
            ->selectRaw('il.sales_invoice_id, SUM(DISTINCT c.transport_cost) as fare')
            ->pluck('fare', 'il.sales_invoice_id');

        $rows = $bills->map(function (SalesInvoice $b) use ($fare) {
            $net = bcsub(bcsub((string) $b->total, (string) $b->tax, 4), (string) ($b->freight_charge ?? '0'), 4);
            $cogs = (string) ($b->cost_of_goods ?? '0');
            $margin = bcsub($net, $cogs, 4);
            $delivery = (string) ($fare[$b->id] ?? '0');
            // ⓘ বাকি — [[SalesInvoice::dueSql()]]-এর হুবহু: আদায়, রসিদ, ফেরত, দাবি আর নোট বাদে
            $due = (string) $b->total;
            foreach (['collected_total', 'voucher_total', 'returned_total', 'claimed_total', 'noted_total'] as $paid) {
                $due = bcsub($due, (string) ($b->getAttribute($paid) ?? '0'), 4);
            }

            return [
                'no' => (string) $b->document_no,
                'party' => $b->customer?->nameWithPoint() ?? '—',
                'net' => $net,
                'vat' => (string) $b->tax,
                'cogs' => $cogs,
                'margin' => $margin,
                'rate' => bccomp($net, '0', 4) > 0 ? bcmul(bcdiv($margin, $net, 6), '100', 1) : null,
                'delivery' => $delivery,
                'after' => bcsub($margin, $delivery, 4),
                'terms' => bccomp($due, '0.005', 4) <= 0 ? 'cash' : (bccomp($due, (string) $b->total, 4) >= 0 ? 'credit' : 'partial'),
                'gross' => (string) $b->total,
            ];
        })->values()->all();

        $totals = [];

        foreach (['net', 'vat', 'cogs', 'margin', 'delivery', 'after', 'gross'] as $k) {
            $totals[$k] = $this->sum($rows, $k);
        }

        $totals['rate'] = bccomp($totals['net'], '0', 4) > 0 ? bcmul(bcdiv($totals['margin'], $totals['net'], 6), '100', 1) : null;

        return ['rows' => $rows, 'totals' => $totals];
    }

    // ── ৪ · আজকের খরচ ─────────────────────────────────────────────────

    /**
     * খরচের খাতে ডেবিট (বিক্রীত পণ্যের ব্যয় বাদে) — ডেলিভারির খরচ (চালানের ভাড়ার খাত) আর অফিস ও অন্যান্য।
     *
     * @param  list<int>  $fareAccounts
     * @return array{groups: list<array{key: string, rows: list<array<string, mixed>>, total: string}>, total: string}
     */
    private function expenses(string $d, array $fareAccounts): array
    {
        $lines = $this->expenseLines($d, $d);
        $from = $this->creditSides($lines->map(fn ($l) => [$l->source_type, (int) $l->source_id])->unique()->values());

        $rows = $lines->map(fn ($l) => [
            'code' => (string) $l->code,
            'head' => (string) ((app()->getLocale() === 'bn' ? $l->name_bn : null) ?: $l->name_en),
            'note' => (string) ($l->narration ?? ''),
            'from' => $from[$l->source_type.'#'.$l->source_id] ?? '',
            'amount' => bcsub((string) $l->debit, (string) $l->credit, 4),
            'delivery' => in_array((int) $l->account_id, $fareAccounts, true),
        ]);

        $groups = [];

        foreach (['delivery' => true, 'office' => false] as $key => $isDelivery) {
            $in = $rows->where('delivery', $isDelivery)->values()->all();

            if ($in !== []) {
                $groups[] = ['key' => $key, 'rows' => $in, 'total' => $this->sum($in, 'amount')];
            }
        }

        return ['groups' => $groups, 'total' => $this->sum($groups, 'total')];
    }

    // ── ৫ · নগদ গণনা ও মিল ─────────────────────────────────────────────

    /**
     * দিনের শেষ পোস্ট হওয়া নগদ গণনা — নোট ধরে, গুনে পাওয়া বনাম খাতা, পার্থক্য আর কারণ। না গোনা হলে সেটাই লেখা।
     *
     * @param  Collection<int, array<string, mixed>>  $money
     * @return array<string, mixed>|null
     */
    private function cashCount(string $d, Collection $money): ?array
    {
        $count = CashCount::query()->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'cash_counts.branch_id'))
            ->orderByDesc('id')->first();

        if ($count === null) {
            return null;
        }

        $notes = collect((array) $count->denominations)->map(fn ($qty, $note) => [
            'note' => (int) $note, 'qty' => (int) $qty, 'amount' => bcmul((string) $note, (string) $qty, 4),
        ])->sortByDesc('note')->values()->all();

        return [
            'no' => (string) $count->document_no,
            'notes' => $notes,
            'counted' => (string) $count->counted_amount,
            'book' => (string) $count->expected_amount,
            'gap' => (string) $count->difference,
            'reason' => (string) ($count->narration ?? ''),
        ];
    }

    // ── ৬ · আজ টাকা গেছে, কিন্তু খরচ নয় ─────────────────────────────────

    /** @return array{rows: list<array<string, mixed>>, total: string} */
    private function paidNotExpense(string $d): array
    {
        // ⓘ যে কাগজে খরচের খাতে ডেবিট আছে, সেটা ৪-এ — এখানে কেবল বাকি টাকা যাওয়া (সরবরাহকারীকে দেওয়া, ব্যাংকে জমা, খুচরা নগদ পূরণ…)
        $withExpense = $this->expenseLines($d, $d)->map(fn ($l) => $l->source_type.'#'.$l->source_id)->unique()->flip();

        $rows = $this->ledger($d)
            ->whereIn('a.money_kind', [Account::CASH, Account::BANK, Account::MFS])
            ->where('ledger_entries.credit', '>', 0)
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.source_type', 'ledger_entries.source_id', 'ledger_entries.document_no', 'ledger_entries.narration',
                'ledger_entries.credit', 'a.name_en', 'a.name_bn'])
            ->reject(fn ($r) => $withExpense->has($r->source_type.'#'.$r->source_id))
            ->map(fn ($r) => [
                'no' => (string) $r->document_no,
                'note' => (string) ($r->narration ?? ''),
                'from' => (string) ((app()->getLocale() === 'bn' ? $r->name_bn : null) ?: $r->name_en),
                'amount' => (string) $r->credit,
            ])->values()->all();

        return ['rows' => $rows, 'total' => $this->sum($rows, 'amount')];
    }

    // ── ৮ · লাভ-ক্ষতির সারাংশ ───────────────────────────────────────────

    /**
     * আজ · মাসে আজ পর্যন্ত · দৈনিক গড় (মাসের অঙ্ক ÷ পেরোনো কর্মদিবস)।
     *
     * @param  array<string, mixed>  $calendar
     * @param  list<int>  $fareAccounts
     * @param  array<string, mixed>  $fixed
     * @return array<string, array<string, ?string>>
     */
    private function profitLoss(Carbon $day, array $calendar, array $fareAccounts, array $fixed): array
    {
        $d = $day->toDateString();
        $from = $day->copy()->startOfMonth()->toDateString();

        $today = $this->plFor($d, $d, $fareAccounts, $fixed['daily'], 1);
        $month = $this->plFor($from, $d, $fareAccounts, $fixed['daily'], max(1, (int) $calendar['done']));
        $avg = [];

        foreach ($month as $k => $v) {
            $avg[$k] = $v === null ? null : bcdiv($v, (string) max(1, (int) $calendar['done']), 4);
        }

        // ⓘ হার গড়ের নয়, অঙ্কের — গড়ের হার মাসেরই হার
        $avg['margin_rate'] = $month['margin_rate'];
        $avg['net_rate'] = $month['net_rate'];

        return ['today' => $today, 'month' => $month, 'avg' => $avg];
    }

    /**
     * @param  list<int>  $fareAccounts
     * @return array<string, ?string>
     */
    private function plFor(string $from, string $to, array $fareAccounts, string $fixedDaily, int $days): array
    {
        $parts = SalesMetrics::netParts($from, $to);
        $gross = bcadd((string) $parts['gross'], '0', 4);
        $vat = (string) $parts['vat'];
        $net = (string) $parts['net'];
        $cogs = $this->ledgerNet([StandardChart::COST_OF_GOODS_SOLD], $from, $to);
        $margin = bcsub($net, $cogs, 4);

        $expenses = $this->expenseLines($from, $to);
        $delivery = $expenses->filter(fn ($l) => in_array((int) $l->account_id, $fareAccounts, true))
            ->reduce(fn ($s, $l) => bcadd($s, bcsub((string) $l->debit, (string) $l->credit, 4), 4), '0');
        $office = $expenses->reject(fn ($l) => in_array((int) $l->account_id, $fareAccounts, true))
            ->reduce(fn ($s, $l) => bcadd($s, bcsub((string) $l->debit, (string) $l->credit, 4), 4), '0');

        $fixed = bcmul($fixedDaily, (string) $days, 4);
        $after = bcsub($margin, $delivery, 4);
        $profit = bcsub(bcsub($after, $office, 4), $fixed, 4);
        $rate = fn (string $v) => bccomp($net, '0', 4) > 0 ? bcmul(bcdiv($v, $net, 6), '100', 1) : null;

        return [
            'gross' => $gross, 'vat' => $vat, 'returned' => (string) $parts['returned'], 'net' => $net, 'cogs' => $cogs,
            'margin' => $margin, 'margin_rate' => $rate($margin), 'delivery' => $delivery, 'after' => $after,
            'office' => $office, 'fixed' => $fixed, 'profit' => $profit, 'net_rate' => $rate($profit),
        ];
    }

    // ── ৯ · মজুদ ও বকেয়া ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function stockAndDues(Carbon $day): array
    {
        $d = $day->toDateString();
        $roll = function (string $code) use ($d): array {
            $ids = $this->accountTree($code);
            $before = (string) (ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
                ->whereIn('account_id', $ids ?: [0])->where('trx_date', '<', $d)
                ->selectRaw('COALESCE(SUM(debit - credit),0) as v')->value('v') ?? '0');
            $moves = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
                ->whereIn('account_id', $ids ?: [0])->where('trx_date', $d)
                ->selectRaw('COALESCE(SUM(debit),0) as dr, COALESCE(SUM(credit),0) as cr')->first();

            return ['opening' => $before, 'in' => (string) ($moves->dr ?? '0'), 'out' => (string) ($moves->cr ?? '0'),
                'closing' => bcsub(bcadd($before, (string) ($moves->dr ?? '0'), 4), (string) ($moves->cr ?? '0'), 4)];
        };

        // ⓘ সবচেয়ে পুরনো বকেয়া — যে পার্টির সবচেয়ে পুরনো না-মেটা বিল, তার মোট বাকি আর সেই বিলের বয়স
        $due = SalesInvoice::dueSql('i');
        $oldest = DB::query()->fromSub(SalesInvoice::query()->posted()->withCollected()->toBase(), 'i')
            ->whereRaw("{$due} > 0.005")->where('i.trx_date', '<=', $d)
            ->groupBy('i.customer_id')
            ->selectRaw("i.customer_id, MIN(i.trx_date) as since, SUM({$due}) as amount")
            ->orderBy('since')->limit(3)->get();
        $customers = Customer::query()->with(Customer::placeVia(''))->whereIn('id', $oldest->pluck('customer_id')->all() ?: [0])->get()->keyBy('id');

        return [
            'stock' => $roll(StandardChart::INVENTORY),
            'dues' => $roll(StandardChart::RECEIVABLE),
            'oldest' => $oldest->map(fn ($o) => [
                'party' => $customers->get($o->customer_id)?->nameWithPoint() ?? '—',
                'amount' => (string) $o->amount,
                'days' => (int) Carbon::parse($o->since)->diffInDays($day),
            ])->all(),
        ];
    }

    // ── ১০ · স্থির খরচ ────────────────────────────────────────────────

    /** @return array{rows: list<array{key: string, monthly: string, daily: string}>, monthly: string, daily: string, workdays: int} */
    private function fixed(array $calendar): array
    {
        $workdays = max(1, (int) $calendar['workdays']);
        $rows = [];

        foreach (self::FIXED_HEADS as $head) {
            $monthly = (string) ($this->settings->get('accounts.day_close.fixed.'.$head) ?? '0');
            $monthly = is_numeric($monthly) ? bcadd($monthly, '0', 4) : '0';

            if (bccomp($monthly, '0', 4) === 0) {
                continue;
            }

            $rows[] = ['key' => $head, 'monthly' => $monthly, 'daily' => bcdiv($monthly, (string) $workdays, 4)];
        }

        $monthly = $this->sum($rows, 'monthly');

        return ['rows' => $rows, 'monthly' => $monthly, 'daily' => bcdiv($monthly, (string) $workdays, 4), 'workdays' => $workdays];
    }

    // ── ১১ · মূসক ও মাসের লক্ষ্য ───────────────────────────────────────

    /** @return array<string, mixed> */
    private function vatAndTarget(Carbon $day, array $calendar): array
    {
        $d = $day->toDateString();
        $bills = SalesInvoice::query()->posted()->whereDate('trx_date', $d);
        $returns = DB::table('sal_returns')->where('company_id', CompanyContext::id())
            ->whereIn('status', DocumentStatus::POSTED)->where('trx_date', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'sal_returns.branch_id'));

        $target = app(SalesBoard::class)->monthTarget();
        $achieved = (string) ($target['achieved'] ?? '0');
        $goal = $target['target'] ?? null;
        $left = max(0, (int) $calendar['workdays'] - (int) $calendar['done']);

        return [
            'challans_63' => (clone $bills)->where('tax', '>', 0)->count(),
            'vat_today' => (string) (clone $bills)->sum('tax'),
            'notes_67' => (clone $returns)->count(),
            'vat_month' => (string) SalesMetrics::netParts($day->copy()->startOfMonth()->toDateString(), $d)['vat'],
            'vat_due_on' => $day->copy()->addMonthNoOverflow()->day(15)->toDateString(),
            'target' => $goal,
            'achieved' => $achieved,
            'achieved_pct' => $goal !== null && bccomp((string) $goal, '0', 4) > 0 ? bcmul(bcdiv($achieved, (string) $goal, 6), '100', 1) : null,
            'elapsed_pct' => bcmul(bcdiv((string) $calendar['done'], (string) max(1, (int) $calendar['workdays']), 6), '100', 1),
            'left_days' => $left,
            'per_day' => $goal !== null && $left > 0 ? bcdiv(bcsub((string) $goal, $achieved, 4), (string) $left, 4) : null,
        ];
    }

    // ── আজকের খবর ─────────────────────────────────────────────────────

    /** @return list<array{key: string, params: array<string, mixed>}> */
    private function news(array $sales, array $delivery, array $inflow, ?array $count, array $stock, array $vat, array $summarySales): array
    {
        $out = [];
        $biggest = collect($sales['rows'])->sortByDesc(fn ($r) => (float) $r['gross'])->first();

        if ($biggest !== null) {
            $out[] = ['key' => 'biggest', 'params' => ['party' => $biggest['party'], 'amount' => $biggest['gross'],
                'challans' => count($delivery['rows']), 'delivered' => $delivery['value']]];
        }

        $out[] = bccomp((string) $summarySales['returned'], '0', 4) > 0
            ? ['key' => 'returns', 'params' => ['amount' => $summarySales['returned']]]
            : ['key' => 'no_returns', 'params' => []];

        if ($vat['achieved_pct'] !== null) {
            $out[] = ['key' => bccomp($vat['achieved_pct'], $vat['elapsed_pct'], 1) < 0 ? 'target_behind' : 'target_ahead',
                'params' => ['achieved' => $vat['achieved_pct'], 'elapsed' => $vat['elapsed_pct'], 'days' => $vat['left_days'], 'per_day' => $vat['per_day'] ?? '0']];
        }

        $byKind = collect($inflow['groups'])->keyBy('kind');
        $out[] = ['key' => 'inflow', 'params' => ['total' => $inflow['total'],
            'cash' => $byKind->get(Account::CASH)['total'] ?? '0', 'bank' => $byKind->get(Account::BANK)['total'] ?? '0',
            'mfs' => $byKind->get(Account::MFS)['total'] ?? '0']];

        $out[] = $count === null
            ? ['key' => 'not_counted', 'params' => []]
            : (bccomp($count['gap'], '0', 2) === 0
                ? ['key' => 'count_ok', 'params' => []]
                : ['key' => bccomp($count['gap'], '0', 2) < 0 ? 'count_short' : 'count_over',
                    'params' => ['gap' => ltrim($count['gap'], '-'), 'reason' => $count['reason'] !== '' ? $count['reason'] : '—']]);

        if (($first = $stock['oldest'][0] ?? null) !== null) {
            $out[] = ['key' => 'oldest', 'params' => $first];
        }

        return $out;
    }

    // ── কর্মীর হাতে টাকা ─────────────────────────────────────────────────

    /**
     * ⭐ মালিক আর ব্যাংক ছাড়া কর্মীর জিম্মায় নগদ — মালিক, ১৮ নভেম্বর ২০২৬: *"কোম্পানির কোনো কর্মীর হাতে টাকা থাকে, সেটার
     * ওয়ার্নিংটাও … বন্ধের নোটিশে এটা থাকবে যে অমুকের কাছে এত টাকা আছে, এবং হাইলাইট করেই থাকবে"*।
     * ⓘ নগদের খাত (কাউন্টার বা জিম্মাদারসহ হিসাব), দিনের শেষ জের শূন্যের বেশি, আর জিম্মাদার মালিক (সুপার অ্যাডমিন) নন।
     *   জিম্মাদার নেই এমন নগদও আসে — "নামহীন জিম্মা"।
     *
     * @param  Collection<int, array<string, mixed>>  $money  [[DailySummary]]-এর টাকার খাতের সারি
     * @return list<array{who: ?string, account: string, amount: string}>
     */
    private function staffCash(Collection $money): array
    {
        $cash = $money->where('kind', Account::CASH)->filter(fn (array $r) => bccomp((string) $r['closing'], '0', 2) > 0);

        if ($cash->isEmpty()) {
            return [];
        }

        $ids = $cash->pluck('id')->all();
        $tills = \App\Modules\Accounts\Models\CashTill::query()->whereIn('account_id', $ids)->pluck('holder_id', 'account_id');
        $keepers = Account::query()->postable()->whereIn('id', $ids)->pluck('held_by', 'id');
        $holderOf = fn (int $account) => $tills[$account] ?? $keepers[$account] ?? null;

        $owners = \App\Models\User::query()
            ->whereIn('id', $cash->map(fn ($r) => $holderOf((int) $r['id']))->filter()->unique()->all() ?: [0])
            ->whereHas('roles', fn ($q) => $q->where('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $cash
            ->reject(fn ($r) => in_array((int) $holderOf((int) $r['id']), $owners, true))
            ->sortByDesc(fn ($r) => (float) $r['closing'])
            ->map(fn ($r) => ['who' => $r['holder'] !== '' ? (string) $r['holder'] : null, 'account' => (string) $r['name'], 'amount' => (string) $r['closing']])
            ->values()->all();
    }

    // ── দিন বন্ধের পরে যোগ হয়েছে ──────────────────────────────────────────

    /**
     * ⭐ বন্ধ দিনের তারিখে, বন্ধের মুহূর্তের পরে খাতায় বসা কাগজ — মালিক, ১৮ নভেম্বর ২০২৬: কেউ চুপচাপ পুরনো দিনের অঙ্ক বদলাতে পারবে না,
     * সব চোখের সামনে। ⓘ কাগজ ধরে এক সারি — নম্বর, ধরন, টাকা (ডেবিটের যোগ), কে বসালেন, কখন।
     *
     * @return list<array<string, mixed>>
     */
    private function addedAfterClose(string $d, ?DayClose $record): array
    {
        if ($record === null || $record->closed_at === null) {
            return [];
        }

        $rows = $this->ledger($d)
            ->where('ledger_entries.created_at', '>', $record->closed_at)
            ->groupBy('ledger_entries.source_type', 'ledger_entries.source_id', 'ledger_entries.document_no')
            ->selectRaw('ledger_entries.source_type, ledger_entries.source_id, ledger_entries.document_no, SUM(ledger_entries.debit) as amount,'
                .' MIN(ledger_entries.created_at) as added_at, MIN(ledger_entries.created_by) as added_by')
            ->orderBy('added_at')
            ->get();

        $users = \App\Models\User::query()->whereIn('id', $rows->pluck('added_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'no' => (string) $r->document_no,
            'type' => explode(':', (string) $r->source_type)[0],
            'amount' => (string) $r->amount,
            'who' => (string) ($users[$r->added_by] ?? '—'),
            'when' => Carbon::parse($r->added_at),
        ])->all();
    }

    // ── কর্মদিবস ──────────────────────────────────────────────────────

    /**
     * মাসের কর্মদিবস — সাপ্তাহিক ছুটির দিন বাদে (সেটিংস, ডিফল্ট শুক্রবার); আজ পর্যন্ত কয়টা গেল (আজসহ, আজ ছুটি না হলে)।
     *
     * @return array{workdays: int, done: int, day_of_month: int, holiday: string}
     */
    public function calendar(Carbon $day): array
    {
        $holiday = (string) ($this->settings->get('accounts.day_close.weekly_holiday') ?? 'friday');
        $off = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6][$holiday] ?? null;

        $workdays = 0;
        $done = 0;
        $cursor = $day->copy()->startOfMonth();
        $end = $day->copy()->endOfMonth();

        while ($cursor->lte($end)) {
            if ($cursor->dayOfWeek !== $off) {
                $workdays++;

                if ($cursor->lte($day)) {
                    $done++;
                }
            }

            $cursor->addDay();
        }

        return ['workdays' => $workdays, 'done' => $done, 'day_of_month' => (int) $day->day, 'holiday' => $holiday];
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    /** দিনের খতিয়ান, হিসাবসহ, দেখার শাখায় */
    private function ledger(string $d): Builder
    {
        return ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.trx_date', $d);
    }

    /** @return Collection<int, object> খরচের খাতের লাইন (বিক্রীত পণ্যের ব্যয় বাদে), সময়ের মধ্যে */
    private function expenseLines(string $from, string $to): Collection
    {
        $cogs = $this->accountTree(StandardChart::COST_OF_GOODS_SOLD);

        return ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.type', Account::EXPENSE)
            ->whereNotIn('ledger_entries.account_id', $cogs ?: [0])
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.account_id', 'ledger_entries.source_type', 'ledger_entries.source_id', 'ledger_entries.narration',
                'ledger_entries.debit', 'ledger_entries.credit', 'a.code', 'a.name_en', 'a.name_bn'])
            ->toBase();
    }

    /** @return list<int> চালানের ভাড়া যে খরচের খাতে বসে — ডেলিভারির খরচ এগুলোই */
    private function fareAccounts(): array
    {
        return DB::table('sal_challans')->where('company_id', CompanyContext::id())->whereNotNull('fare_account_id')
            ->distinct()->pluck('fare_account_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> একটা কোডের হিসাব আর তার নিচের সব */
    private function accountTree(string $code): array
    {
        // ⓘ ছকের গাছ হাঁটা — দল-খাতও লাগে (মূল আর মাঝের দল), এটা কোনো বাছাইয়ের তালিকা নয়; তাই খাতের মডেল নয়, কোম্পানি-বাঁধা কাঁচা সারি
        $all = DB::table('accounts')->where('company_id', CompanyContext::id())->whereNull('deleted_at')->get(['id', 'parent_id', 'code']);
        $root = $all->firstWhere('code', $code);

        if ($root === null) {
            return [];
        }

        $ids = [(int) $root->id];
        $queue = [(int) $root->id];

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($all->where('parent_id', $id) as $child) {
                $ids[] = (int) $child->id;
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /** খাতের নিট (ডেবিট − ক্রেডিট), কোড ও নিচের সব, সময়ের মধ্যে */
    private function ledgerNet(array $codes, string $from, string $to): string
    {
        $ids = collect($codes)->flatMap(fn ($c) => $this->accountTree($c))->all();

        return (string) (ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->whereIn('account_id', $ids ?: [0])->whereBetween('trx_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(debit - credit),0) as net')->value('net') ?? '0');
    }

    /**
     * @param  Collection<int, array{0: string, 1: int}>  $sources
     * @return array<string, string> "ধরন#id" => "নাম · পয়েন্ট · শাখা"
     */
    private function entryParties(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }

        $rows = DB::table('ledger_entries')->where('company_id', CompanyContext::id())
            ->whereNotNull('party_type')->whereNotNull('party_id')
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $type)->where('source_id', $id));
                }
            })
            ->orderBy('id')->get(['source_type', 'source_id', 'party_type', 'party_id']);

        $first = $rows->unique(fn ($r) => $r->source_type.'#'.$r->source_id);
        $labels = app(\App\Core\Services\PartyRegistry::class)->labelsOf($first->map(fn ($r) => [(string) $r->party_type, (int) $r->party_id])->unique()->values()->all());

        return $first->mapWithKeys(fn ($r) => [$r->source_type.'#'.$r->source_id => $labels[$r->party_type.':'.$r->party_id] ?? null])->all();
    }

    /**
     * কাগজের ব্যাংক বা মোবাইল-ব্যাংকিংয়ের চার্জ (খরচের খাত ৫২১০/৫২১১)।
     *
     * @param  Collection<int, array{0: string, 1: int}>  $sources
     * @return array<string, string>
     */
    private function entryCharges(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }

        $ids = array_merge($this->accountTree(StandardChart::BANK_CHARGES), $this->accountTree(StandardChart::MFS_CHARGES));

        return DB::table('ledger_entries')->where('company_id', CompanyContext::id())->whereIn('account_id', $ids ?: [0])
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $type)->where('source_id', $id));
                }
            })
            ->groupBy('source_type', 'source_id')
            ->selectRaw('source_type, source_id, SUM(debit - credit) as charge')
            ->get()->mapWithKeys(fn ($r) => [$r->source_type.'#'.$r->source_id => (string) $r->charge])->all();
    }

    /**
     * কাগজের ক্রেডিট পাশের প্রথম হিসাবের নাম — "কোথা থেকে"।
     *
     * @param  Collection<int, array{0: string, 1: int}>  $sources
     * @return array<string, string>
     */
    private function creditSides(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }

        return DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.company_id', CompanyContext::id())->where('le.credit', '>', 0)
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('le.source_type', $type)->where('le.source_id', $id));
                }
            })
            ->orderBy('le.id')->get(['le.source_type', 'le.source_id', 'a.name_en', 'a.name_bn'])
            ->unique(fn ($r) => $r->source_type.'#'.$r->source_id)
            ->mapWithKeys(fn ($r) => [$r->source_type.'#'.$r->source_id => (string) ((app()->getLocale() === 'bn' ? $r->name_bn : null) ?: $r->name_en)])
            ->all();
    }

    /** @param  iterable<array<string, mixed>>  $rows */
    private function sum(iterable $rows, string $key): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = bcadd($total, (string) ($row[$key] ?? '0'), 4);
        }

        return $total;
    }
}
