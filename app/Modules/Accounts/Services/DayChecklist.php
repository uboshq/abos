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

        // ── সরবরাহকারীকে আজ যা দেওয়া হলো (নিশ্চিত করার জন্য তথ্য) ──
        // ⓘ খাতা থেকে: সরবরাহকারীর পাওনায় ডেবিট, টাকা দেওয়ার কাগজে — যে পথেই কাগজ বসুক, খাতায় যা বসেছে তাই
        $paid = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->where('party_type', 'supplier')->where('debit', '>', 0)->where('trx_date', $d)
            ->where('source_type', 'like', 'payment_voucher%');
        if (($n = (clone $paid)->distinct()->count('source_id')) > 0) {
            $items[] = ['key' => 'supplier_paid', 'count' => $n, 'amount' => (string) (clone $paid)->sum('debit'),
                'url' => $url('accounts.voucher.index', ['type' => 'payment']), 'tone' => 'info'];
        }

        return $items;
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
