<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Models\Approval;
use App\Models\DayClose;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ দিন বন্ধের চেকলিস্ট আর বন্ধ-না-হওয়া দিনের খোঁজ — মালিক, ১৮ নভেম্বর ২০২৬:
 * *"বিকাল 5টার সময় বা রাতের 10টার সময় … একটা বোতামে ক্লিক করলো … এই গাড়িগুলোর ভাড়াগুলো বসানো হয় নাই … চেকলিস্ট আকারে …
 * যেটি যেটি করা হয় নাই লিঙ্কে ক্লিক করে এই কাজগুলো সম্পন্ন করে ফেললো … পরের দিন সকালবেলায় … এই চেকলিস্টে সামনে চলে আসবে"*।
 *
 * ⛔ সবকিছু কেবল সতর্কতা — *"কাজ যাতে কোনোভাবে আটকে না থাকে"* (১৮ নভেম্বর)। এখানে কিছু আটকায় না; প্রতিটা সারি একটা লিংক।
 *
 * ⓘ প্রতিটা সারি একটা সস্তা প্রশ্ন — পপআপ প্রতি পাতায় আঁকা হয়, তাই ভারী হিসাব ([[DayClosingService]]) এখানে নয়।
 */
final class DayChecklist
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * ⭐ পপআপ কত মিনিট পরে ফেরে (`accounts.day_close.popup_every`) — `null` মানে "দেখাবে না"।
     * ⓘ "দেখাবে না"-র মান `off`; ১.৩.৪-এর `0` আগে জমা থাকলে সেটাও "দেখাবে না" (ম্যাক, ১৯ নভেম্বর ২০২৬)। অচেনা মান হলে ডিফল্ট ১ ঘণ্টা।
     */
    public static function popupEvery(): ?int
    {
        $value = (string) (app(SettingsService::class)->get('accounts.day_close.popup_every') ?? '60');

        if ($value === 'off' || $value === '0') {
            return null;
        }

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : 60;
    }

    /**
     * একটা দিনের বাকি কাজ — খালি তালিকা মানে কিছু বাকি নেই।
     *
     * @return list<array{key: string, count: int, amount: ?string, url: ?string, tone: string, names?: list<string>}>
     */
    public function for(Carbon $day): array
    {
        $d = $day->toDateString();
        $items = [];
        $url = fn (string $name, array $params = []) => Route::has($name) ? route($name, $params) : null;

        // ── টাকার হাতবদল ──
        $hasCashMoves = $this->cashMoved($d);
        if ($hasCashMoves && ! CashCount::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'cash_counts.branch_id'))->exists()) {
            $items[] = ['key' => 'not_counted', 'count' => 1, 'amount' => null, 'url' => $url('accounts.count.index'), 'tone' => 'danger'];
        }

        // ⭐ গোনায় তফাত — আগে কেবল দিন বন্ধের সময় দেখা হত; এখন চেকলিস্টেও (মালিক, ১৮ নভেম্বর ২০২৬: "নগদ গোনা হয়নি, বা গোনায় তফাত")
        $gaps = CashCount::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', $d)
            ->where('difference', '!=', 0)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'cash_counts.branch_id'));
        if (($n = (clone $gaps)->count()) > 0) {
            $items[] = ['key' => 'count_gap', 'count' => $n, 'amount' => (string) (clone $gaps)->sum('difference'),
                'url' => $url('accounts.count.index'), 'tone' => 'danger'];
        }

        $staff = $this->staffCash($d);
        if ($staff !== []) {
            $items[] = ['key' => 'staff_cash', 'count' => count($staff), 'amount' => array_reduce($staff, fn ($s, $r) => bcadd($s, $r['amount'], 4), '0'),
                'url' => $url('accounts.custody'), 'tone' => 'danger',
                'names' => array_map(fn ($r) => ($r['who'] ?? '—').' · '.\App\Core\Support\Money::format($r['amount']), $staff)];
        }

        // ── গাড়ি আর ভাড়া ──
        $challans = DB::table('sal_challans')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
            ->whereIn('status', DocumentStatus::POSTED)->where('trx_date', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'sal_challans.branch_id'));

        $noFare = (clone $challans)->whereIn('vehicle_owner', ['own', 'hired'])
            ->where(fn ($q) => $q->whereNull('fare_paid_by')->orWhereNotIn('fare_paid_by', ['customer', 'none']))
            ->where(fn ($q) => $q->whereNull('transport_cost')->orWhere('transport_cost', '<=', 0))->count();
        if ($noFare > 0) {
            $items[] = ['key' => 'fare_missing', 'count' => $noFare, 'amount' => null, 'url' => $url('sales.transport.index'), 'tone' => 'warning'];
        }

        $fareDue = (clone $challans)->where('fare_status', 'due')->whereNull('fare_voucher_id');
        if (($n = (clone $fareDue)->count()) > 0) {
            $items[] = ['key' => 'fare_due', 'count' => $n, 'amount' => (string) (clone $fareDue)->sum('transport_cost'),
                'url' => $url('sales.transport.index', ['tab' => 'fare_due']), 'tone' => 'warning'];
        }

        // ── কাগজ: খসড়া আর অনুমোদনের অপেক্ষা ──
        $drafts = 0;
        foreach (['vouchers', 'sal_invoices', 'pur_bills', 'sal_challans'] as $table) {
            $drafts += DB::table($table)->where('company_id', CompanyContext::id())->whereNull('deleted_at')
                ->where('status', DocumentStatus::DRAFT)->where('trx_date', $d)
                ->tap(fn ($q) => ViewedBranch::narrow($q, $table.'.branch_id'))->count();
        }
        if ($drafts > 0) {
            $items[] = ['key' => 'drafts', 'count' => $drafts, 'amount' => null, 'url' => $url('accounts.voucher.list'), 'tone' => 'warning'];
        }

        $waiting = Approval::query()->where('company_id', CompanyContext::id())->where('status', Approval::PENDING)->count();
        if ($waiting > 0) {
            $items[] = ['key' => 'approvals', 'count' => $waiting, 'amount' => null, 'url' => $url('approval.inbox.index'), 'tone' => 'warning'];
        }

        // ── জমার খবর, ব্যাংকে এখনো মেলেনি ──
        if (DB::getSchemaBuilder()->hasTable('sal_deposit_claims')) {
            $unmatched = DB::table('sal_deposit_claims')->where('company_id', CompanyContext::id())
                // ⓘ জমার খবরের "অপেক্ষায়" অবস্থা (DepositClaim::PENDING) — হিসাব মডিউল বিক্রয়ের মডেলের নাম জানে না (BoundariesTest)
                ->where('status', 'pending')
                ->whereNull('statement_line_id')->where('claimed_on', '<=', $d);
            if (($n = (clone $unmatched)->count()) > 0) {
                $items[] = ['key' => 'deposits', 'count' => $n, 'amount' => (string) (clone $unmatched)->sum('amount'),
                    'url' => $url('sales.claim.match.index'), 'tone' => 'warning'];
            }
        }

        // ── খাতা মেলে কি না (দিনের ডেবিট = ক্রেডিট) ──
        $sides = DB::table('ledger_entries')->where('company_id', CompanyContext::id())->where('trx_date', $d)
            ->selectRaw('COALESCE(SUM(debit),0) dr, COALESCE(SUM(credit),0) cr')->first();
        if (bccomp((string) $sides->dr, (string) $sides->cr, 2) !== 0) {
            $items[] = ['key' => 'books', 'count' => 1, 'amount' => bcsub((string) $sides->dr, (string) $sides->cr, 4),
                'url' => $url('accounts.integrity'), 'tone' => 'danger'];
        }

        /*
         * ── ⭐ কোম্পানির BIN নেই, অথচ আজ ভ্যাটের কাগজ (ক্রেডিট নোট মূসক-৬.৭, গ্রাহকের ডেবিট নোট মূসক-৬.৮, বিক্রয় ভাউচার মূসক-৬.৩) ──
         * মালিকের নকশা v5 (২৩ নভেম্বর ২০২৬): সতর্কতা কেবল, থামায় না। ⓘ লাইভে ADI-র BIN খালি।
         */
        if (blank(\App\Models\Company::query()->whereKey(CompanyContext::id())->value('bin'))) {
            $vatPapers = DB::table('acc_notes')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
                ->whereIn('note_type', ['cn', 'cdn'])->whereIn('status', \App\Core\Support\DocumentStatus::POSTED)->where('trx_date', $d)->count()
                + (DB::getSchemaBuilder()->hasTable('acc_trade_vouchers')
                    ? DB::table('acc_trade_vouchers')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
                        ->where('kind', 'sale')->whereIn('status', \App\Core\Support\DocumentStatus::POSTED)->where('trx_date', $d)->count()
                    : 0);

            if ($vatPapers > 0) {
                $items[] = ['key' => 'bin_missing', 'count' => $vatPapers, 'amount' => null,
                    'url' => $url('system_admin.company.edit', ['company' => CompanyContext::id()]), 'tone' => 'warning'];
            }
        }

        // ── সরবরাহকারীকে আজ যা দেওয়া হলো (নিশ্চিত করার জন্য তথ্য) ──
        // ⓘ খাতা থেকে: সরবরাহকারীর পাওনায় ডেবিট, টাকা দেওয়ার কাগজে — যে পথেই কাগজ বসুক, খাতায় যা বসেছে তাই
        $paid = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->where('party_type', 'supplier')->where('debit', '>', 0)->where('trx_date', $d)
            ->where('source_type', 'like', 'payment_voucher%');
        if (($n = (clone $paid)->distinct()->count('source_id')) > 0) {
            $items[] = ['key' => 'supplier_paid', 'count' => $n, 'amount' => (string) (clone $paid)->sum('debit'),
                'url' => $url('accounts.voucher.index', ['type' => 'payment']), 'tone' => 'info'];
        }

        // ── আজকের বাকিতে বিক্রি, আর আজ শোধের দিন অথচ সরবরাহকারীকে দেওয়া হয়নি (মালিক, ১৮ নভেম্বর ২০২৬) ──
        if (($credit = $this->creditSales($d)) !== null) {
            $items[] = ['key' => 'credit_bills', 'count' => $credit[0], 'amount' => $credit[1], 'url' => $url('sales.invoice.index'), 'tone' => 'info'];
        }

        if (($owed = $this->supplierDueUnpaid($d)) !== null) {
            $items[] = ['key' => 'supplier_due', 'count' => $owed[0], 'amount' => $owed[1], 'url' => $url('purchase.payment.index'), 'tone' => 'warning'];
        }

        return $items;
    }

    /**
     * ⭐ আজকের যে বিল বাকিতে গেল — কয়টা আর কত বাকি।
     *
     * ⓘ বাকি গোনে বিক্রয় মডিউল নিজে ([[PartyOpenBills]] — বিলের পাতা আর গ্রাহকের বকেয়া যে অঙ্ক দেখায়); হিসাব মডিউল বিক্রয়ের মডেল জানে না
     *   ([[BoundariesTest]]), তাই কেবল টেবিলের নাম আর মূল চুক্তি। ⓘ কেবল আজকের পাকা বিলের গ্রাহক — হাতে গোনা কয়েকজন।
     * ⓘ তথ্য, সতর্কতা নয় — বাকিতে বিক্রি দোষ নয়, দিন শেষে চোখ বুলিয়ে নেওয়া। ⛔ কিছু আটকায় না।
     *
     * @return array{0: int, 1: string}|null
     */
    private function creditSales(string $d): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable('sal_invoices')) {
            return null;
        }

        $today = DB::table('sal_invoices')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
            ->where('status', DocumentStatus::CONFIRMED)->where('trx_date', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'sal_invoices.branch_id'))
            ->get(['id', 'customer_id as party_id']);

        return $this->stillOwed($today, fn (int $party) => app(\App\Core\Contracts\PartyOpenBills::class)->openBills('customer', $party, 200));
    }

    /**
     * ⭐ আজ শোধের শেষ দিন, অথচ সরবরাহকারীর বিলে এখনো বাকি — ক্রয় মডিউলের [[PayableOpenBills]] যে বাকি বলে তা-ই। ⛔ কিছু আটকায় না।
     *
     * @return array{0: int, 1: string}|null
     */
    private function supplierDueUnpaid(string $d): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable('pur_bills')) {
            return null;
        }

        $due = DB::table('pur_bills')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
            ->where('status', DocumentStatus::CONFIRMED)->where('due_on', $d)
            ->tap(fn ($q) => ViewedBranch::narrow($q, 'pur_bills.branch_id'))
            ->get(['id', 'supplier_id as party_id']);

        return $this->stillOwed($due, fn (int $party) => app(\App\Core\Contracts\PayableOpenBills::class)->openBills('supplier', $party, 200));
    }

    /**
     * এই বিলগুলোর কোনটায় এখনো বাকি — পক্ষ ধরে একবার মডিউলের খোলা বিলের তালিকা, তা থেকে কেবল এই বিলগুলো।
     *
     * @param  \Illuminate\Support\Collection<int, object>  $bills
     * @param  \Closure(int): list<array<string, mixed>>  $open
     * @return array{0: int, 1: string}|null
     */
    private function stillOwed(\Illuminate\Support\Collection $bills, \Closure $open): ?array
    {
        if ($bills->isEmpty()) {
            return null;
        }

        $wanted = $bills->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $count = 0;
        $amount = '0';

        foreach ($bills->pluck('party_id')->filter()->unique() as $party) {
            foreach ($open((int) $party) as $row) {
                if (isset($wanted[(int) ($row['id'] ?? 0)]) && bccomp((string) ($row['outstanding'] ?? '0'), '0', 4) > 0) {
                    $count++;
                    $amount = bcadd($amount, (string) $row['outstanding'], 4);
                }
            }
        }

        return $count > 0 ? [$count, $amount] : null;
    }

    /**
     * আগের কোন কর্মদিবসগুলো বন্ধ হয়নি — যেদিন খাতায় কিছু লেখা হয়েছিল; সবচেয়ে পুরনো আগে।
     *
     * @return list<Carbon>
     */
    public function openDays(Carbon $today, int $lookBack = 7): array
    {
        $holiday = (string) ($this->settings->get('accounts.day_close.weekly_holiday') ?? 'friday');
        $off = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6][$holiday] ?? null;
        $from = $today->copy()->subDays($lookBack)->toDateString();
        $to = $today->copy()->subDay()->toDateString();

        // ⓘ দিন ধরে সেদিন কোন কোন শাখায় লেখা হয়েছে — "সব শাখা" দেখলে দিনটা বন্ধ তখনই, যখন সেই প্রতিটা শাখা (বা পুরো কোম্পানি) বন্ধ করেছে
        $active = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->whereBetween('trx_date', [$from, $to])->distinct()->get(['trx_date', 'branch_id'])
            ->groupBy(fn ($r) => Carbon::parse($r->trx_date)->toDateString())
            ->sortKeys();
        $viewed = CompanyContext::branchId();

        return $active
            ->map(fn ($rows, string $date) => [Carbon::parse($date)->startOfDay(), $rows->pluck('branch_id')->unique()->values()])
            ->reject(fn (array $d) => $d[0]->dayOfWeek === $off)
            ->reject(fn (array $d) => $viewed !== null
                ? DayClose::closedOn($d[0], $viewed) !== null
                : DayClose::closedOn($d[0]) !== null || $d[1]->every(fn ($b) => $b !== null && DayClose::closedOn($d[0], (int) $b) !== null))
            ->map(fn (array $d) => $d[0])
            ->values()->all();
    }

    /** গত মাস বন্ধ হয়নি — মাস শেষ হয়ে গেছে, তালা নেই, আর সেই মাসে খাতায় কিছু ছিল */
    public function openMonth(Carbon $today): ?Carbon
    {
        $last = $today->copy()->startOfMonth()->subMonthNoOverflow();

        if (PeriodLock::query()->where('year', $last->year)->where('month', $last->month)->exists()) {
            return null;
        }

        $had = LedgerEntry::query()->whereBetween('trx_date', [$last->toDateString(), $last->copy()->endOfMonth()->toDateString()])->exists();

        return $had ? $last : null;
    }

    /**
     * ⭐ বন্ধ-না-হওয়া মাসের বাকি কাজ, পপআপের জন্য — মালিক, ১৮ নভেম্বর ২০২৬: *"মাস শেষ হলে কিন্তু বন্ধ না হলে একই পপআপ আর চেকলিস্ট"*।
     *
     * ⓘ মাস বন্ধের পাতার একই তালিকা ([[MonthEndChecklist]]), কেবল যা বাকি — "প্রযোজ্য নয়" আর তালার সারি বাদ (তালাটাই তো পপআপের কথা)।
     * ⓘ তালিকাটা ভারী (মাস জুড়ে এক ডজন প্রশ্ন), আর পপআপ প্রতি পাতায় আঁকা হয় — তাই কোম্পানি-মাস ধরে পাঁচ মিনিট মনে রাখা হয়;
     *   ঠিক করার পরে সারিটা পাঁচ মিনিটের মধ্যে সরে যায়। লিংক এখানে বানানো হয় না, পপআপ বানায় (ক্যাশে ঠিকানা থাকে না)।
     * ⛔ কিছু আটকায় না — কেবল দেখায়।
     *
     * @return list<array{key: string, count: int, route: string, params: array<string, mixed>}>
     */
    public function monthLeft(Carbon $month): array
    {
        $key = 'daycheck.month-left.'.CompanyContext::id().'.'.$month->format('Y-m');

        return \Illuminate\Support\Facades\Cache::remember($key, 300, fn () => collect(app(MonthEndChecklist::class)->run(\Carbon\CarbonImmutable::parse($month->toDateString())))
            ->filter(fn (array $r) => $r['state'] === MonthEndChecklist::PENDING && $r['key'] !== 'locked')
            ->map(fn (array $r) => ['key' => $r['key'], 'count' => (int) $r['count'], 'route' => $r['route'], 'params' => $r['params']])
            ->values()->all());
    }

    private function cashMoved(string $d): bool
    {
        return ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.money_kind', Account::CASH)->where('ledger_entries.trx_date', $d)->exists();
    }

    /**
     * কর্মীর হাতে নগদ, দিনের শেষে — [[DayClosingService::staffCash()]]-এর একই নিয়ম, হালকা প্রশ্নে।
     *
     * @return list<array{who: ?string, amount: string, holder_id: ?int, account_id: int, account: string}>
     */
    public function staffCash(string $d): array
    {
        $accounts = Account::query()->postable()->where('is_active', true)->where('money_kind', Account::CASH)->get(['id', 'held_by', 'name_en', 'name_bn']);
        $tills = CashTill::query()->whereIn('account_id', $accounts->pluck('id')->all() ?: [0])->pluck('holder_id', 'account_id');
        $branch = CompanyContext::branchId();
        $out = [];

        $holders = $accounts->mapWithKeys(fn (Account $a) => [$a->id => $tills[$a->id] ?? $a->held_by]);
        $owners = User::query()->whereIn('id', $holders->filter()->unique()->values()->all() ?: [0])
            ->whereHas('roles', fn ($q) => $q->where('name', PermissionSyncer::SUPER_ADMIN_ROLE))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $names = User::query()->whereIn('id', $holders->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');

        foreach ($accounts as $a) {
            $holder = $holders[$a->id] ?? null;

            if ($holder !== null && in_array((int) $holder, $owners, true)) {
                continue;
            }

            $balance = (string) $a->balanceOn($d, $branch);

            if (bccomp($balance, '0', 2) > 0) {
                $out[] = ['who' => $holder !== null ? (string) ($names[$holder] ?? '') : null, 'amount' => $balance,
                    'holder_id' => $holder !== null ? (int) $holder : null, 'account_id' => (int) $a->id, 'account' => $a->name()];
            }
        }

        return $out;
    }
}
