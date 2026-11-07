<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\CarrierAndLabourLedger;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ প্রতিদিনের সারাংশ — মালিক, ৭ নভেম্বর ২০২৬ (নমুনা আর A4 PDF "ok"):
 * *"প্রতিদিন এর হিসাব এর সামারি যেমন আজকে কত টাকার পণ্য ডেলিভারি হলো, কত টাকা ভাড়া গেল, ড্রাইভার কে কে ছিল, কত টাকা ভাড়া
 * পরিশোধ হলো, কত টাকা বাকি রইলো, কত টাকার মাল আসলো, কত টাকা নগদে আসলো, কোন কাউন্টারে কত টাকা জমা হলো, কোন কাউন্টার থেকে
 * কত টাকা খরচ হলো, কার কাছে কত টাকা আছে, কি কি প্রোডাক্ট অর্ডার দিতে হবে"*।
 *
 * এক দিন, এক কোম্পানি, বাছা শাখা ([[CompanyContext]]) — পাতা, PDF আর রাতের ইমেইল তিনটাই এখান থেকে, তাই অঙ্ক এক।
 * ⓘ প্রতিটা অঙ্ক যেখানে পারে সফটওয়্যারের চালু হিসাব থেকে: বিক্রি [[SalesMetrics::netParts()]], বাকি ভাড়া
 * [[CarrierAndLabourLedger::parties()]], টাকার খাতের জের [[Account::balanceOn()]], সীমার নিচের পণ্য [[StockFacts::belowReorderRows()]]।
 * ⚠️ কনসোল থেকে ডাকলে [[CompanyLens::within()]]-এর ভেতরে — শাখার দেয়াল আর অনুমোদনের তালিকা লগইন করা মানুষ চায়।
 */
final class DailySummary
{
    /**
     * @return array<string, mixed>
     */
    public function for(Carbon $day): array
    {
        $d = $day->toDateString();
        $yesterday = $day->copy()->subDay();

        $sales = $this->sales($d);
        $fare = $this->fare($d);
        $money = $this->money($day);
        $purchase = $this->purchase($d);
        $watch = $this->watch($day);
        $reorder = app(StockFacts::class)->belowReorderRows(40);
        $before = $this->sales($yesterday->toDateString());

        return [
            'date' => $day->copy(),
            'company' => \App\Models\Company::query()->find(CompanyContext::id()),
            'branch_id' => CompanyContext::branchId(),
            'sales' => $sales,
            'fare' => $fare,
            'money' => $money,
            'purchase' => $purchase,
            'watch' => $watch,
            'reorder' => $reorder,
            'kpis' => [
                'delivered' => ['value' => $sales['delivered'], 'before' => $before['delivered']],
                'collected' => ['value' => $money['collected'], 'before' => SalesMetrics::collectionTotal($yesterday->toDateString(), $yesterday->toDateString())],
                'fare' => ['value' => $fare['total'], 'due' => $fare['due']],
                'received' => ['value' => $purchase['received'], 'suppliers' => $purchase['rows']->count()],
                'cash_left' => ['value' => $money['cash_closing'], 'tills' => $money['rows']->where('kind', Account::CASH)->count()],
                'reorder' => ['value' => $reorder->count()],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sales(string $d): array
    {
        $parts = SalesMetrics::netParts($d, $d);
        $challans = DeliveryChallan::query()->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d);
        $bills = SalesInvoice::query()->posted()->whereDate('trx_date', $d);

        return [
            'delivered' => (string) (clone $challans)->sum('total'),
            'challans' => (clone $challans)->count(),
            'bills' => (clone $bills)->count(),
            'billed' => (string) ($parts['billed'] ?? '0'),
            'returned' => (string) ($parts['returned'] ?? '0'),
            'net' => (string) ($parts['net'] ?? '0'),
        ];
    }

    /** @return array<string, mixed> */
    private function fare(string $d): array
    {
        $rows = DeliveryChallan::query()->with('carrier')
            ->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d)
            ->where(fn ($w) => $w->where('transport_cost', '>', 0)->orWhereIn('vehicle_owner', ['own', 'hired']))
            ->orderBy('id')->get()
            ->map(fn (DeliveryChallan $c) => [
                'no' => $c->document_no,
                'id' => $c->id,
                'driver' => (string) ($c->driver_name ?? ''),
                'driver_phone' => (string) ($c->driver_phone ?? ''),
                'vehicle' => $c->vehiclePlate() ?: (string) ($c->transportLabel() ?: ''),
                'owner' => (string) ($c->vehicle_owner ?? ''),
                'carrier' => (string) $c->transportLabel(),
                'amount' => is_numeric($c->transport_cost) ? (string) $c->transport_cost : '0',
                'state' => in_array($c->fare_paid_by, ['customer', 'none'], true) ? 'none'
                    : ($c->fare_status === 'due' && $c->fare_voucher_id === null ? 'due' : (bccomp((string) ($c->transport_cost ?? '0'), '0', 4) > 0 ? 'paid' : 'missing')),
            ]);

        $trips = Shipment::query()->whereDate('dispatched_at', $d)->where('transport_cost', '>', 0)->get()
            ->map(fn (Shipment $t) => [
                'no' => $t->document_no, 'id' => $t->id,
                'driver' => (string) ($t->driver_name ?? ''), 'driver_phone' => (string) ($t->driver_phone ?? ''),
                'vehicle' => (string) ($t->vehicle_no ?? ''), 'owner' => 'trip', 'carrier' => (string) ($t->carrier_name ?? ''),
                'amount' => (string) $t->transport_cost,
                'state' => $t->fare_status === 'due' && $t->fare_voucher_id === null ? 'due' : 'paid',
            ]);

        $all = $rows->concat($trips)->values();
        $sum = fn (Collection $c) => $c->reduce(fn (string $s, array $r) => bcadd($s, $r['amount'], 4), '0');

        $ledger = app(CarrierAndLabourLedger::class);
        $head = $ledger->head('transport');
        $outstanding = $head === null ? '0' : $ledger->parties($head, $d, $d)
            ->reduce(fn (string $s, $p) => bcadd($s, (string) (data_get($p, 'closing') ?? '0'), 4), '0');

        return [
            'rows' => $all,
            'total' => $sum($all->whereIn('state', ['paid', 'due'])),
            'paid' => $sum($all->where('state', 'paid')),
            'due' => $sum($all->where('state', 'due')),
            'missing' => $all->where('state', 'missing')->count(),
            'outstanding' => $outstanding,
        ];
    }

    /** @return array<string, mixed> */
    private function money(Carbon $day): array
    {
        $d = $day->toDateString();
        $prev = $day->copy()->subDay()->toDateString();
        $branch = CompanyContext::branchId();

        $tills = CashTill::query()->with('holder')->get()->keyBy('account_id');

        $rows = Account::query()->postable()->active()
            ->whereIn('money_kind', [Account::CASH, Account::BANK, Account::MFS])
            ->with('keeper')->orderBy('code')->get()
            ->map(function (Account $a) use ($d, $prev, $branch, $tills) {
                $moves = LedgerEntry::query()->where('account_id', $a->id)->whereDate('trx_date', $d)
                    ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                    ->selectRaw('COALESCE(SUM(debit),0) as dr, COALESCE(SUM(credit),0) as cr')->first();

                return [
                    'id' => $a->id,
                    'code' => (string) $a->code,
                    'name' => $a->name(),
                    'kind' => (string) $a->money_kind,
                    'holder' => $tills->get($a->id)?->holder?->name ?? $a->keeper?->name ?? '',
                    'opening' => $a->balanceOn($prev, $branch),
                    'in' => (string) ($moves->dr ?? '0'),
                    'out' => (string) ($moves->cr ?? '0'),
                    'closing' => $a->balanceOn($d, $branch),
                ];
            })
            // ⓘ চুপচাপ খাত নয় — দিনে চলাচল আছে, নয়তো হাতে টাকা আছে
            ->filter(fn (array $r) => bccomp($r['in'], '0', 4) !== 0 || bccomp($r['out'], '0', 4) !== 0 || bccomp($r['closing'], '0', 4) !== 0)
            ->values();

        $byKind = fn (string $kind, string $col) => $rows->where('kind', $kind)->reduce(fn (string $s, array $r) => bcadd($s, $r[$col], 4), '0');

        return [
            'rows' => $rows,
            'collected' => SalesMetrics::collectionTotal($d, $d),
            'in_total' => $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['in'], 4), '0'),
            'out_total' => $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['out'], 4), '0'),
            'closing_total' => $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['closing'], 4), '0'),
            'cash_closing' => $byKind(Account::CASH, 'closing'),
            'in_by_kind' => [Account::CASH => $byKind(Account::CASH, 'in'), Account::BANK => $byKind(Account::BANK, 'in'), Account::MFS => $byKind(Account::MFS, 'in')],
        ];
    }

    /** @return array<string, mixed> */
    private function purchase(string $d): array
    {
        $rows = PurchaseReceipt::query()->with('supplier')->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d)->get()
            ->groupBy('supplier_id')
            ->map(fn (Collection $g) => [
                'supplier' => (string) ($g->first()->supplier?->name() ?? '—'),
                'documents' => $g->pluck('document_no')->implode(', '),
                'amount' => $g->reduce(fn (string $s, PurchaseReceipt $r) => bcadd($s, (string) $r->total, 4), '0'),
            ])->values();

        $paid = bcadd(
            (string) Payment::query()->whereIn('status', DocumentStatus::POSTED)->whereDate('trx_date', $d)->sum('amount'),
            (string) Voucher::query()->posted()->where('type', Voucher::PAYMENT)->where('party_type', 'supplier')->whereDate('trx_date', $d)
                // ⓘ বাহকও সরবরাহকারী — ভাড়ার ভাউচার এখানে নয়, ভাড়ার ঘরে
                ->where(fn ($w) => $w->whereNull('against_type')->orWhereNotIn('against_type', ['delivery_challan', 'shipment']))
                ->sum('amount'),
            4,
        );

        return [
            'rows' => $rows,
            'received' => $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['amount'], 4), '0'),
            'paid' => $paid,
        ];
    }

    /** @return array<string, mixed> */
    private function watch(Carbon $day): array
    {
        $d = $day->toDateString();
        $pending = Approval::query()->where('status', Approval::PENDING)->where('company_id', CompanyContext::id());

        $bills = SalesInvoice::query()->posted()->withCollected()->toBase();
        $due = SalesInvoice::dueSql('i');
        $crossed = DB::query()->fromSub($bills, 'i')
            ->whereRaw('COALESCE(i.due_on, i.trx_date) = ?', [$day->copy()->subDay()->toDateString()])
            ->whereRaw("{$due} > 0.005")
            ->selectRaw("COUNT(DISTINCT i.customer_id) as customers, COALESCE(SUM({$due}),0) as amount")->first();

        $facts = app(\App\Modules\Accounts\Services\AccountsFacts::class);
        $income = $facts->netOfType(Account::INCOME, $day->copy()->startOfDay(), $day->copy()->endOfDay());
        $expense = $facts->netOfType(Account::EXPENSE, $day->copy()->startOfDay(), $day->copy()->endOfDay());
        $cogs = $this->ledgerNet([StandardChart::COST_OF_GOODS_SOLD], $d);

        return [
            'pending_count' => (clone $pending)->count(),
            'pending_amount' => (string) (clone $pending)->sum('amount'),
            'crossed_customers' => (int) ($crossed->customers ?? 0),
            'crossed_amount' => (string) ($crossed->amount ?? '0'),
            'expenses' => bcsub($expense, $cogs, 4),
            'profit' => bcsub($income, $expense, 4),
        ];
    }

    /** খাতের দিনের নিট (ডেবিট − ক্রেডিট), কোড ধরে */
    private function ledgerNet(array $codes, string $d): string
    {
        $branch = CompanyContext::branchId();

        return (string) (LedgerEntry::query()
            ->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->whereIn('a.code', $codes)->whereDate('ledger_entries.trx_date', $d)
            ->when($branch, fn ($q) => $q->where('ledger_entries.branch_id', $branch))
            ->selectRaw('COALESCE(SUM(ledger_entries.debit - ledger_entries.credit),0) as net')->value('net') ?? '0');
    }
}
