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

    /**
     * আজকের তিনটা সংখ্যা — **একটাই কোয়েরিতে**।
     *
     * ── কেন একসাথে, আলাদা তিনটা মেথড নয় ─────────────────────────────
     * ⚠️ ড্যাশবোর্ডের প্রতিটা টালি একটা করে কোয়েরি হলে দশটা টালি মানে
     * পাতা-লোডে দশটা কোয়েরি। আজ হাতেগোনা সারি, তাই কেউ টের পায় না —
     * কিন্তু বছরখানেক পরে **ড্যাশবোর্ডই সবচেয়ে ধীর পাতা** হয়ে দাঁড়ায়,
     * আর কারণটা কেউ খুঁজে পান না, কারণ কোনো একটা কোয়েরি ধীর নয়।
     *
     * তিনটাই একই দিনের একই খতিয়ান পড়ে, তাই একবার পড়াই যথেষ্ট।
     *
     * ── সংজ্ঞাগুলো ──────────────────────────────────────────────────
     * আদায় = প্রাপ্য খাতে আজ যত **ক্রেডিট** — অর্থাৎ গ্রাহকের বকেয়া
     *        যতটা কমল। নগদ বিক্রি এতে আসে না, কারণ ওখানে বকেয়াই তৈরি
     *        হয়নি; ওটা বিক্রয়ের সংখ্যা, আদায়ের নয়।
     * প্রদান = প্রদেয় খাতে আজ যত **ডেবিট** — আমরা যতটা মিটিয়ে দিলাম।
     * খরচ   = ব্যয় ধরনের সব খাতে আজকের নিট ডেবিট।
     *
     * @return array{collection: string, payment: string, expense: string}
     */
    public function today(): array
    {
        $today = Carbon::today()->toDateString();

        $row = LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.trx_date', $today)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN accounts.code = ? THEN ledger_entries.credit ELSE 0 END), 0) as collection,'
                .'COALESCE(SUM(CASE WHEN accounts.code = ? THEN ledger_entries.debit ELSE 0 END), 0) as payment,'
                .'COALESCE(SUM(CASE WHEN accounts.type = ? THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END), 0) as expense',
                [StandardChart::RECEIVABLE, StandardChart::PAYABLE, Account::EXPENSE],
            )
            ->first();

        return [
            'collection' => bcadd((string) ($row->collection ?? 0), '0', 4),
            'payment' => bcadd((string) ($row->payment ?? 0), '0', 4),
            'expense' => bcadd((string) ($row->expense ?? 0), '0', 4),
        ];
    }

    /**
     * বকেয়া ঋণ — দীর্ঘমেয়াদি দায়ের গোটা ডালটা।
     *
     * ⓘ কোড ধরে নয়, **বাবার সন্তানেরা** ধরে: কোম্পানি নিজের ঋণের জন্য
     * নতুন খাত বানালে (যেমন "গাড়ির ঋণ") সেটাও এখানে আসা উচিত। কেবল
     * ২২১০ ও ২২২০ গুনলে ওই টাকাটা সংখ্যাটার বাইরে থেকে যেত, আর মালিক
     * ভাবতেন ঋণ কম।
     */
    public function outstandingLoan(): string
    {
        $parent = Account::query()->where('code', '2200')->first();

        if ($parent === null) {
            return '0';
        }

        /*
         * ⚠️ পুরো বংশ, এক ধাপ নয় — [[assetValue]]-এ একই কারণ লেখা।
         * কেউ "ব্যাংক ঋণ → সোনালী · ইসলামী" বানালে দাখিলা নাতির ঘরে
         * বসত, আর ঋণের সংখ্যাটা নীরবে কম দেখাত।
         */
        $ids = $parent->selfAndDescendants()->pluck('id');

        $row = LedgerEntry::query()
            ->whereIn('account_id', $ids)
            ->selectRaw('COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')
            ->first();

        // দায় ক্রেডিট প্রকৃতির — ক্রেডিট বাদ ডেবিটই আজকের বকেয়া
        return bcsub((string) ($row->c ?? 0), (string) ($row->d ?? 0), 4);
    }

    /**
     * সম্পদের বইমূল্য — মূল দাম বাদ জমা অবচয়।
     *
     * ⚠️ কেবল ১২০০ দেখালে সংখ্যাটা **বাড়িয়ে বলত**: পাঁচ বছরের পুরনো
     * ট্রাক কেনা দামেই দেখাত। বইমূল্য মানে আজকের মূল্য, কেনার দিনের নয়।
     */
    public function assetValue(): string
    {
        /*
         * ⚠️ দুইবার `balanceOfCode()` ডাকা হত — আর সেটা **৯টা কোয়েরি**
         * নিত (মেপে দেখা): প্রতিটা `find()` একটা, আর প্রতিটা
         * `balanceOn()` নিজের সন্তানদের হেঁটে দেখে।
         *
         * ⓘ একটা টালির জন্য নয়টা কোয়েরি — আর ড্যাশবোর্ডে টালি দশটা।
         * এখানেই ধীর পাতা জন্মায়, একটাও ধীর কোয়েরি ছাড়াই।
         */
        /*
         * ⚠️ `1200` নিজে একটা **গ্রুপ** — ওতে কোনো দাখিলা বসে না।
         * আসল সংখ্যাগুলো তার সন্তানদের ঘরে: আসবাব · যানবাহন · যন্ত্রপাতি ·
         * কম্পিউটার, আর জমা অবচয় (`1290`, ক্রেডিট প্রকৃতির)।
         *
         * কেবল `1200` ও `1290` কোড ধরে খুঁজলে **কেবল অবচয়টাই আসত**, আর
         * সম্পদের মূল্য ঋণাত্মক দেখাত — মালিকের পাতায়।
         */
        $parent = StandardChart::find(StandardChart::FIXED_ASSETS);

        if ($parent === null) {
            return '0';
        }

        /*
         * ⚠️ **এক ধাপ নয়, পুরো বংশ** — আর কারণটা আজকের নয়, কালকের।
         *
         * আজ ১২০০-এর নিচে সবগুলোই পাতা (১২০১–১২০৪, ১২৯০)। কিন্তু এই
         * ছকটা ক্রেতা **নিজে বাড়াতে পারেন** — মালিকের স্থায়ী নিয়ম।
         * যেদিন কেউ "যানবাহন → ট্রাক ১, ট্রাক ২" বানাবেন, সেদিন দাখিলা
         * বসবে **নাতির ঘরে**, আর এক ধাপ দেখা কোয়েরি সেটা দেখত না।
         *
         * ⚠️ তখন সম্পদের মূল্য **নীরবে কমে যেত** — কোনো ত্রুটি নয়,
         * কোনো লাল টেস্ট নয়, কেবল মালিকের পাতায় একটা কম সংখ্যা।
         *
         * ⓘ ছকে তিন ধাপ **আজই আছে** (১১০১-CASH → ১১০১ → ১১০০), তাই
         * নেস্টিং কল্পনা নয়। `selfAndDescendants()` নিজেই এক কোয়েরিতে
         * পুরো গাছ আনে (Account:299)।
         */
        $row = LedgerEntry::query()
            ->whereIn('account_id', $parent->selfAndDescendants()->pluck('id'))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        // মূল দাম ডেবিটে, জমা অবচয় ক্রেডিটে — বিয়োগেই বইমূল্য
        return bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
    }

    /**
     * সবচেয়ে বেশি বকেয়া যাদের — গ্রাহক বা সরবরাহকারী।
     *
     * ⭐ **একটাই কোয়েরি**, পক্ষ ধরে গুচ্ছ করা। প্রতি পক্ষের জন্য আলাদা
     * কোয়েরি করলে দশজনের তালিকায় দশবার ডাটাবেসে যেতে হত।
     *
     * ⚠️ নামটা এখানে আনা হয় না — খতিয়ান কেবল `party_type` ও `party_id`
     * রাখে ([[CoreReports]]-এ একই কথা লেখা)। নাম দেখানোর সময় ডাকা পক্ষ
     * **একবারেই** আনতে হবে, প্রতি সারিতে নয়।
     *
     * @return list<array{party_id: int, amount: string}>
     */
    public function topDue(string $partyType, string $accountCode, int $limit = 10): array
    {
        $account = StandardChart::find($accountCode);

        if ($account === null) {
            return [];
        }

        // প্রাপ্য ডেবিটে বাড়ে, প্রদেয় ক্রেডিটে — তাই দিকটা খাত থেকেই নেওয়া
        $receivable = $accountCode === StandardChart::RECEIVABLE;

        $rows = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->where('party_type', $partyType)
            ->whereNotNull('party_id')
            ->groupBy('party_id')
            ->selectRaw(
                $receivable
                    ? 'party_id, COALESCE(SUM(debit) - SUM(credit), 0) as amount'
                    : 'party_id, COALESCE(SUM(credit) - SUM(debit), 0) as amount'
            )
            ->havingRaw('amount > 0')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'party_id' => (int) $row->party_id,
            'amount' => bcadd((string) $row->amount, '0', 4),
        ])->all();
    }

    /**
     * টাকার তিনটা অবস্থান — নগদ · ব্যাংক · MFS, **আলাদা করে**।
     *
     * ── কেন MFS ব্যাংকের সাথে মেশানো যায় না ─────────────────────────
     * ⚠️ এই ভুলটা এই প্রকল্পে একবার হয়েছিল, আর মালিক ধরিয়ে দিয়েছিলেন
     * ([[StandardChart::BANK]]-এর মন্তব্যে পুরো কারণ):
     *
     *   • **বিকাশ ক্যাশ-আউটে চার্জ কাটে, ব্যাংক কাটে না**
     *   • মিলকরণের কাগজ আলাদা — ব্যাংকের বিবরণী বনাম অ্যাপের লগ
     *   • সেটেলমেন্টের সময় আলাদা
     *
     * এক ঘরে দেখালে **"ব্যাংকে কত আছে" সংখ্যাটাই মিথ্যা বলত**।
     *
     * ── কেন `is_bank` পতাকা নয়, সাবট্রি ─────────────────────────────
     * মেপে দেখা: `1105-BKASH`-এ `is_bank`ও নেই, `is_cash`ও নেই। তাই
     * পতাকা ধরে গুনলে **MFS-এর টাকা কোনো টালিতেই আসত না** — না নগদে,
     * না ব্যাংকে। সাবট্রি ধরলে তিনটাই নিজের জায়গায় বসে।
     *
     * ⓘ বংশধর ধরে, এক ধাপ নয় — [[assetValue]]-এ একই কারণ লেখা।
     *
     * @return array{cash: string, bank: string, mfs: string}
     */
    public function moneyPositions(): array
    {
        $parents = [
            'cash' => StandardChart::CASH_IN_HAND,
            'bank' => StandardChart::BANK,
            'mfs' => StandardChart::MOBILE_MONEY,
        ];

        $ids = [];
        $owner = [];

        foreach ($parents as $key => $code) {
            $parent = StandardChart::find($code);

            if ($parent === null) {
                continue;
            }

            foreach ($parent->selfAndDescendants()->pluck('id') as $id) {
                $ids[] = $id;
                $owner[$id] = $key;
            }
        }

        if ($ids === []) {
            return ['cash' => '0', 'bank' => '0', 'mfs' => '0'];
        }

        // একটাই কোয়েরি, খাত ধরে — তারপর তিনটা ঝুড়িতে ভাগ
        $rows = LedgerEntry::query()
            ->whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get();

        $out = ['cash' => '0', 'bank' => '0', 'mfs' => '0'];

        foreach ($rows as $row) {
            $key = $owner[$row->account_id] ?? null;

            if ($key === null) {
                continue;
            }

            $out[$key] = bcadd($out[$key], bcsub((string) $row->d, (string) $row->c, 4), 4);
        }

        return $out;
    }
}
