<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\DataScope;
use App\Core\Services\LedgerBalances;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Contracts\Database\Query\Builder;
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

    /** ব্যাংকে — ⛔ MFS নয়, নাহলে "ব্যাংকে কত আছে" সংখ্যাটাই মিথ্যা হত। */
    public function bankBalance(): string
    {
        return $this->sumOf(
            Account::query()->ofMoneyKind(Account::BANK)->pluck('id')->all()
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
        // ⭐ দেখার শাখার টিল — কোম্পানি-স্তরের (শাখাহীন) টিল কেবল "সব শাখা"-তে (২৯ সেপ্টেম্বর ২০২৬)
        return $this->inView(CashTill::query()->active(), 'cash_tills.branch_id')->with('account')->get();
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
        // ⭐ একটা শাখা বাছা থাকলে কেবল সেই শাখার সারি (২৯ সেপ্টেম্বর ২০২৬)
        $scope = app(DataScope::class);
        $branch = $scope->viewsOneBranch(auth()->user()) ? ($scope->viewBranchIds(auth()->user())[0] ?? null) : null;

        return StandardChart::find($code)?->balanceOn(null, $branch) ?? '0';
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া; "সব শাখা"-তে নাগাল,
     * শাখাহীনসহ ([[DataScope::viewBranchIds()]])। ⛔ এই ক্লাস কেবল **দেখায়** —
     * টাকার যাচাই ([[CashOnHand]], [[CreditExposure]]) গোটা কোম্পানি পড়ে।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private function inView(Builder $query, string $column): Builder
    {
        return app(DataScope::class)->inView($query, $column);
    }

    /** এক ধরনের সব খাতের নিট — স্বাভাবিক দিকে ধনাত্মক। */
    public function netOfType(string $type, Carbon $from, Carbon $to): string
    {
        /*
         * ⭐ হেডারে বাছা শাখায় — মালিকের ধরা ভুল, ৫ অক্টোবর ২০২৬: শাখা A বাছা থাকতে শাখা B-র বিক্রিতে হিসাবের প্রথম চার্ট
         * ("এই মাস এ পর্যন্ত") বদলাত, অথচ হোমের বাকি সব সংখ্যা শাখা মানত। ⓘ টাকার ঘর ([[moneyPositions()]]) যেভাবে মানে,
         * ঠিক সেভাবে ([[inView()]]); "সব শাখা"-য় আগের মতোই গোটা কোম্পানি।
         */
        $row = $this->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$from->toDateString(), $to->toDateString()])
            // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে আয়-খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
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

        // ⭐ হেডারে বাছা শাখায় (৫ অক্টোবর ২০২৬) — [[netOfType()]]-এর একই কারণ; আজকের আদায়-পরিশোধও শাখা মানে
        $row = $this->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
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

        // ⭐ হেডারে বাছা শাখায় (৩০ সেপ্টেম্বর ২০২৬) — অর্থ-প্রধানের পাতার বাকি সংখ্যার সাথে এক নিয়মে
        $row = $this->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
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
     * একজন পক্ষের কাছে এখন কত পাওনা — আদায়ের পর্দার "Collectable"।
     *
     * ধনাত্মক মানে **তিনি আমাদের দেবেন**, ঋণাত্মক মানে আমরা তাঁকে।
     *
     * ── ⭐ কেন সংখ্যাটা সংরক্ষণ করা হয় না ────────────────────────────
     * মালিকের সিদ্ধান্ত (১৪ সেপ্টেম্বর ২০২৬): ঘরটা নিজে থেকে ভরবে,
     * টাইপ করা যাবে না। সংরক্ষণ করলে সেটা খতিয়ানের একটা **দ্বিতীয়
     * উৎস** হত, আর এই রিপো ইতিমধ্যে একবার শিখেছে দুই উৎস মানে দুই
     * উত্তর — খাতের পাতা ৮০,০০০ দেখাত আর ট্রায়াল ব্যালেন্স ৩০,০০০
     * ([[AccountService::create()]]-এর মন্তব্যে পুরো ঘটনা)।
     *
     * ── ⚠️ কেন খাতের কোড ধরে নয়, পুরো খতিয়ান ধরে ────────────────────
     * [[topDue()]] একটা নির্দিষ্ট নিয়ন্ত্রক খাত ধরে গোনে (প্রাপ্য, বা
     * প্রদেয়) — ওটা ঠিক আছে, কারণ ঐ রিপোর্ট এক ধরনের পক্ষ নিয়েই।
     *
     * এখানে চার ধরনের পক্ষ: গ্রাহক, সরবরাহকারী, কর্মী, ব্যক্তি। কর্মীর
     * অগ্রিম প্রাপ্য খাতে বসে না, ব্যক্তির হাতে-ধারও নয়। খাতের কোড ধরে
     * গুনলে ⛔ ঐ দুই ধরনের জন্য উত্তরটা **সবসময় শূন্য** আসত — আর শূন্য
     * দেখে মানুষ ভাবতেন হিসাব চুকে গেছে, অথচ টাকা পড়ে আছে।
     *
     * তাই পক্ষের সব সারি: ডেবিট − ক্রেডিট। গ্রাহকের প্রাপ্য ডেবিটে বাড়ে
     * তাই ধনাত্মক; সরবরাহকারীর প্রদেয় ক্রেডিটে বাড়ে তাই ঋণাত্মক — আর
     * চিহ্নটাই পর্দায় "তিনি দেবেন / আমরা দেব" বলে দেয়।
     *
     * ⓘ বাতিল ভাউচারের সারি খতিয়ানে থাকে না (পোস্টিং ইঞ্জিন উল্টো
     * দাখিলা বসায়), তাই এখানে আলাদা ছাঁকনি লাগে না।
     */
    public function dueFrom(string $partyType, int $partyId): string
    {
        if ($partyId <= 0) {
            return '0.0000';
        }

        $row = LedgerEntry::query()
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
    }

    /**
     * ⭐ [[dueFrom()]]-এর হুবহু নিয়ম, অনেক পক্ষের জন্য এক কোয়েরিতে — হাতধারের ব্যক্তির তালিকার "মোট পাওনা" (মালিক,
     * ৫ অক্টোবর ২০২৬; [[HandLoanService::people()]])। ⛔ নিয়মটা এখানেই, dueFrom-এর পাশে — অন্য মডিউলে আলাদা SUM নয়;
     * দুইটা মেলে কি না দাবিতে বাঁধা ([[TheHandLoanBookEndsWhereTheListSaysTest]])।
     *
     * @param  list<int>  $partyIds
     * @return array<int, string> পক্ষের id → ডেবিট − ক্রেডিট (যাঁর কোনো সারি নেই তিনি নেই)
     */
    public function dueFromMany(string $partyType, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        return LedgerEntry::query()
            ->where('party_type', $partyType)
            ->whereIn('party_id', $partyIds)
            ->groupBy('party_id')
            ->selectRaw('party_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->party_id => bcsub((string) $row->d, (string) $row->c, 4)])
            ->all();
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
        // ⭐ দেখার শাখার সারি — খাতগুলো কোম্পানির, টাকাটা শাখার (২৯ সেপ্টেম্বর ২০২৬)
        $rows = $this->inView(LedgerEntry::query()->whereIn('account_id', $ids), 'ledger_entries.branch_id')
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

    /**
     * ⭐ নগদ প্রবাহ — মাসে মাসে কত টাকা এল আর গেল, নগদ + ব্যাংক + MFS মিলিয়ে (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ খাত [[moneyPositions()]]-এর একই তিন ঝুড়ি; ডেবিট = এল, ক্রেডিট = গেল; দেখার শাখার সারি।
     * ⛔ নিজের মধ্যে স্থানান্তর বাদ — যে কাগজের প্রতিটা সারি টাকার খাতেই (নগদ থেকে ব্যাংকে জমা, বিকাশ থেকে নগদ),
     * সেটা টাকা আসাও নয়, যাওয়াও নয়। ⚠️ না বাদ দিলে একটা জমাই দুই দণ্ডে বসত আর মাসটা আসলের দ্বিগুণ ব্যস্ত দেখাত।
     *
     * @return list<array{month: string, in: string, out: string}>  পুরনো থেকে নতুন
     */
    public function moneyFlowByMonth(int $months = 6): array
    {
        $ids = [];

        foreach ([StandardChart::CASH_IN_HAND, StandardChart::BANK, StandardChart::MOBILE_MONEY] as $code) {
            foreach (StandardChart::find($code)?->selfAndDescendants()->pluck('id') ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);
        $rows = collect();

        if ($ids !== []) {
            $expr = "DATE_FORMAT(ledger_entries.trx_date, '%Y-%m')";

            $rows = $this->inView(LedgerEntry::query()->whereIn('ledger_entries.account_id', $ids), 'ledger_entries.branch_id')
                ->where('ledger_entries.trx_date', '>=', $start->toDateString())
                // ⓘ একই কাগজে টাকার খাতের বাইরের অন্তত একটা সারি — নাহলে কাগজটা নিজের মধ্যে স্থানান্তর
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as other')
                    ->whereColumn('other.company_id', 'ledger_entries.company_id')
                    ->whereColumn('other.source_type', 'ledger_entries.source_type')
                    ->whereColumn('other.source_id', 'ledger_entries.source_id')
                    ->whereNotIn('other.account_id', $ids))
                ->selectRaw("{$expr} as ym, COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c")
                ->groupByRaw($expr)
                ->toBase()->get()->keyBy('ym');
        }

        $out = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $out[] = [
                'month' => $month->translatedFormat('M'),
                'in' => bcadd((string) ($row->d ?? '0'), '0', 2),
                'out' => bcadd((string) ($row->c ?? '0'), '0', 2),
            ];
        }

        return $out;
    }

    /**
     * ⭐ রেওয়ামিল এক নজরে — আজ পর্যন্ত সব খাতের মোট ডেবিট আর মোট ক্রেডিট (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     * ⓘ দুইটা সমান হলে খাতা মিলেছে; না মিললে পার্থক্যটাই খোঁজার জায়গা। দেখার শাখা মানে।
     *
     * @return array{debit: string, credit: string}
     */
    public function trialBalanceTotals(): array
    {
        $row = $this->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->where('ledger_entries.trx_date', '<=', Carbon::today()->toDateString())
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')
            ->toBase()->first();

        return [
            'debit' => bcadd((string) ($row->d ?? '0'), '0', 2),
            'credit' => bcadd((string) ($row->c ?? '0'), '0', 2),
        ];
    }

    /**
     * ⭐ আয় আর ব্যয় — মাসে মাসে (খাতার চলাচল; মালিকের ড্যাশবোর্ড নকশা)। ⓘ [[netOfType()]]-এর একই হিসাব, বছর বন্ধের
     * দাখিলা বাদ — তাই উপরের "এ মাসে" ভাগের সাথে এ মাসের দণ্ড হুবহু এক।
     *
     * @return list<array{month: string, income: string, expense: string}>
     */
    public function incomeExpenseByMonth(int $months = 6): array
    {
        $out = [];
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $to = $month->copy()->endOfMonth()->min(Carbon::today());
            $out[] = [
                'month' => $month->translatedFormat('M'),
                'income' => bcadd($this->netOfType(Account::INCOME, $month->copy(), $to), '0', 2),
                'expense' => bcadd($this->netOfType(Account::EXPENSE, $month->copy(), $to), '0', 2),
            ];
        }

        return $out;
    }

    /**
     * ⭐ এ মাসের নিট লাভ — আয় বাদ ব্যয় (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ সংজ্ঞা একটাই: [[incomeThisMonth()]] আর [[expenseThisMonth()]] — "এই মাস এ পর্যন্ত" ভাগের ঠিক ঐ দুই সংখ্যা।
     * ⛔ এখানে আলাদা SUM লিখলে একদিন ভাগ বলত এক, লাভের ঘর বলত আরেক।
     */
    public function netProfit(string $income, string $expense): string
    {
        return bcsub($income, $expense, 4);
    }

    /**
     * ⭐ চলতি সম্পদ, চলতি দায় আর নিট সম্পদ (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ "চলতি" ছকেই চিহ্নিত, নতুন কোনো ভাগ বানানো হয়নি: ১১০০ *চলতি সম্পদ* আর ২১০০ *চলতি দায়* দুইটা দল
     * ([[StandardChart]]); স্থায়ী সম্পদ (১২০০) আর দীর্ঘমেয়াদি দায় (২২০০) তাদের বাইরে। অর্থের CFO পাতার চলতি অনুপাতও
     * ঠিক এই দুই দল পড়ে ([[CfoFigures]])।
     * ⓘ নিট সম্পদ = মোট সম্পদ (১০০০) − মোট দায় (২০০০) — দেনা মিটিয়ে ব্যবসার যা থাকে।
     * ⓘ দেখার শাখা মানে — [[balanceOfCode()]] দিয়েই, প্রাপ্য-প্রদেয়র মতো।
     *
     * @return array{current_assets: string, current_liabilities: string, net_assets: string}
     */
    public function currentPosition(): array
    {
        return [
            'current_assets' => $this->balanceOfCode('1100'),
            'current_liabilities' => $this->balanceOfCode('2100'),
            'net_assets' => bcsub($this->balanceOfCode('1000'), $this->balanceOfCode('2000'), 4),
        ];
    }

    /**
     * ⭐ খতিয়ানের আজকের চলাচল — আজ কয়টা ভাউচার পোস্ট হলো, এ মাসে কয়টা দাখিলা উল্টানো হলো, আর এ মাসে কয়টা ভাউচার
     * পিছনের তারিখে বসল (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ পোস্ট = নিশ্চিত বা বন্ধ ([[DocumentStatus::POSTED]]) — পাশের "এ মাসের ভাউচার" ভাগ যেভাবে গোনে; "আজ" মানে
     * পোস্টের মুহূর্ত (`approved_at`, [[VoucherService::post()]] বসায়), কাগজের তারিখ নয়।
     * ⓘ উল্টানো = খতিয়ানের `<উৎস>:reversal` সারি ([[PostingEngine::reverse()]]); এক কাগজের অনেক সারি একবারই গোনা।
     * ⓘ পিছনের তারিখ = এ মাসে লেখা পোস্ট-করা ভাউচার, যার তারিখ লেখার দিনের আগে। ⚠️ সংখ্যাটা শূন্য না হলে কেউ বন্ধ
     * হয়ে যাওয়া দিনের হিসাব বদলাচ্ছেন — নিরীক্ষক প্রথমে এটাই জিজ্ঞেস করেন।
     * ⓘ ভাউচার মডেলের নিজের দেয়াল দেখার শাখা মানে; খতিয়ানের সারি [[inView()]] দিয়ে।
     *
     * @return array{posted_today: int, reversed_this_month: int, backdated_this_month: int}
     */
    public function ledgerActivity(): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $postedToday = Voucher::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('approved_at', '>=', $today->toDateTimeString())
            ->where('approved_at', '<', $today->copy()->addDay()->toDateTimeString())
            ->count();

        $reversed = $this->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->where('ledger_entries.source_type', 'like', '%:reversal')
            ->whereBetween('ledger_entries.trx_date', [$monthStart->toDateString(), $today->toDateString()])
            ->selectRaw('COUNT(DISTINCT ledger_entries.source_type, ledger_entries.source_id) as n')
            ->toBase()->value('n');

        // ⓘ তারিখের তুলনা SQL-এ, কিন্তু মাসের শুরু আসে PHP থেকে — ডাটাবেসের ঘড়ি নয়
        $backdated = Voucher::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('vouchers.created_at', '>=', $monthStart->toDateTimeString())
            ->whereRaw('vouchers.trx_date < DATE(vouchers.created_at)')
            ->count();

        return [
            'posted_today' => $postedToday,
            'reversed_this_month' => (int) $reversed,
            'backdated_this_month' => $backdated,
        ];
    }

    /**
     * ⭐ যে ড্রয়ারে টাকা শূন্যের নিচে — কয়টা (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ ড্রয়ারের জের [[tillBalances()]] থেকেই, টিলের পর্দা যেটা দেখায়। ⛔ হাতের নগদ শূন্যের নিচে যেতে পারে না —
     * এমন একটা ড্রয়ার মানে খরচ বা জমা ভুল ড্রয়ার থেকে লেখা, নয়তো আদায় লেখা বাকি।
     */
    public function tillsBelowZero(): int
    {
        return count(array_filter(
            $this->tillBalances($this->tills()),
            fn (string $balance) => bccomp($balance, '0', 4) < 0,
        ));
    }

    /**
     * ⭐ সইয়ের অপেক্ষায় যে ভাউচার — কয়টা (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ অনুমোদনের সারি কোরের ([[Approval]], `App\Models`) — হিসাব কোনো মডিউলের উপর দাঁড়ায় না, তাই এটাই পড়া যায়।
     * ভাউচারের তালিকা আর মাস-শেষের চেকলিস্ট একই শর্তে গোনে ([[VoucherController]], [[MonthEndChecklist]])।
     * ⓘ ভাউচার ধরে গোনা, অনুমোদন ধরে নয় — তাই দেখার শাখার দেয়াল ভাউচার মডেলের নিজেরটাই।
     */
    public function vouchersAwaitingSignature(): int
    {
        return Voucher::query()
            ->whereIn('id', Approval::query()
                ->where('approvable_type', Voucher::class)
                ->where('module', VoucherApproval::MODULE)
                ->pending()
                ->select('approvable_id'))
            ->count();
    }

    /**
     * ⭐ ব্যাংক অনুযায়ী জের — প্রতিটা ব্যাংক খাতে কত (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ "ব্যাংক" = ব্যাংক-চিহ্নিত পাতা-খাত ([[Account::scopeOfMoneyKind()]]) — [[bankBalance()]]-এর একই খাতগুলো, ⛔ MFS নয়।
     * ⓘ জের খাতের নিজের [[Account::balanceOn()]], হেডারে বাছা শাখায় — খাতের পাতা যা দেখায় তাই; সব খাতের যোগফল একটাই
     * কোয়েরিতে আগে তোলা ([[LedgerBalances::preload()]]), তাই খাত যত, কোয়েরি তত নয়।
     *
     * @return list<array{id: int, name: string, balance: string}>
     */
    public function bankBalances(): array
    {
        $banks = Account::query()->ofMoneyKind(Account::BANK)->where('is_active', true)->orderBy('code')->get();

        if ($banks->isEmpty()) {
            return [];
        }

        $scope = app(DataScope::class);
        $branch = $scope->viewsOneBranch(auth()->user()) ? ($scope->viewBranchIds(auth()->user())[0] ?? null) : null;

        app(LedgerBalances::class)->preload($banks->map(fn (Account $a) => (int) $a->getKey())->all(), null, $branch);

        return $banks->map(fn (Account $bank) => [
            'id' => (int) $bank->getKey(),
            'name' => $bank->name(),
            'balance' => $bank->balanceOn(null, $branch),
        ])->values()->all();
    }
}
