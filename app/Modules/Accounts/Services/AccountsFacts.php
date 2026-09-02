<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * হিসাবের সংখ্যাগুলোর সংজ্ঞা — একটাই জায়গা।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * হিসাবের নিজস্ব ড্যাশবোর্ডে এই হিসাবগুলো **কন্ট্রোলারের ভেতরে ব্যক্তিগত
 * পদ্ধতি হিসেবে** লেখা ছিল, আর সেটা তখন ঠিকই ছিল: একটাই পর্দা, একটাই
 * পাঠক।
 *
 * ২ সেপ্টেম্বর ২০২৬-এ ইঞ্জিনের ছকে দ্বিতীয় একটা পর্দা এলো
 * ([[AccountsDashboard]])। তার নিজের `SUM` লেখা মানে হত **প্রতিটা
 * সংখ্যার দ্বিতীয় সংজ্ঞা** — আর ঠিক ওই ভুলটা বিক্রয়ে একবার হয়েছিল,
 * যেখানে "আজকের বিক্রয়" চার জায়গায় গোনা হত আর একবার দুইটা আলাদা উত্তর
 * দিয়েছিল।
 *
 * তাই কোডটা এখানে সরানো হয়েছে — **একটা লাইনও না বদলে**, মন্তব্যসহ —
 * আর দুই পর্দাই এখান থেকে নেয়।
 */
final class AccountsFacts
{
    /** হাতে নগদ — সব সচল ড্রয়ার মিলে। */
    public function cashInHand(): string
    {
        return $this->sumOf($this->tills()->pluck('account_id')->all());
    }

    /** ব্যাংকে — যে খাতগুলো ব্যাংক বলে চিহ্নিত। */
    public function bankBalance(): string
    {
        return $this->sumOf(
            Account::query()->where('is_bank', true)->postable()->pluck('id')->all()
        );
    }

    /** প্রাপ্য — গ্রাহকের কাছে যা পাওনা। */
    public function receivable(): string
    {
        return $this->balanceOfCode(StandardChart::RECEIVABLE);
    }

    /** প্রদেয় — সরবরাহকারীকে যা দিতে হবে। */
    public function payable(): string
    {
        return $this->balanceOfCode(StandardChart::PAYABLE);
    }

    /** এই মাসের আয়। */
    public function incomeThisMonth(): string
    {
        return $this->netOfType(Account::INCOME, Carbon::today()->startOfMonth(), Carbon::today());
    }

    /** এই মাসের ব্যয়। */
    public function expenseThisMonth(): string
    {
        return $this->netOfType(Account::EXPENSE, Carbon::today()->startOfMonth(), Carbon::today());
    }

    /** @return Collection<int, CashTill> */
    public function tills(): Collection
    {
        return CashTill::query()->active()->with('account')->get();
    }

    /**
     * @param  list<int>  $accountIds
     */
    public function sumOf(array $accountIds): string
    {
        if ($accountIds === []) {
            return '0';
        }

        $row = LedgerEntry::query()
            ->whereIn('account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        /* খোলার জের এখন খতিয়ানেই — এখানে যোগ করলে দ্বিগুণ হত
           ([[OpeningBalanceService]], ২৯ আগস্ট ২০২৬) */
        return bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
    }

    public function balanceOfCode(string $code): string
    {
        return StandardChart::find($code)?->balanceOn() ?? '0';
    }

    /** এক ধরনের সব খাতের নিট — স্বাভাবিক দিকে ধনাত্মক। */
    public function netOfType(string $type, Carbon $from, Carbon $to): string
    {
        $row = LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')
            ->first();

        $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

        // আয় ক্রেডিট প্রকৃতির, তাই চিহ্ন উল্টে দিলে সংখ্যাটা ধনাত্মক হয় —
        // "এই মাসের আয় −২০,০০০" দেখানোর কোনো মানে নেই
        return $type === Account::INCOME ? bcmul($net, '-1', 4) : $net;
    }

    /**
     * প্রতিটা ড্রয়ারে কত।
     *
     * @param  Collection<int, CashTill>  $tills
     * @return array<int, string>
     */
    public function tillBalances(Collection $tills): array
    {
        if ($tills->isEmpty()) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->whereIn('account_id', $tills->pluck('account_id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->keyBy('account_id');

        $out = [];

        foreach ($tills as $till) {
            $row = $sums[$till->account_id] ?? null;

            /* খোলার জের খতিয়ানেই বসে গেছে — আর যোগ করার নেই */
            $out[$till->id] = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
        }

        return $out;
    }
}
