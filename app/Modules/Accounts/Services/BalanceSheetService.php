<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * স্থিতিপত্র — একটা দিনে ব্যবসা কোথায় দাঁড়িয়ে।
 *
 * ── কী ভাঙা ছিল, ৩০ আগস্ট ২০২৬ ───────────────────────────────────────
 * মালিকের কথা: *"balance sheet koroni acc e"* — আর তিনি ঠিক ছিলেন।
 * পর্দাটা ছিল, কিন্তু ওটা **নাম বদলানো রেওয়ামিল**:
 *
 *   • ডেবিট ও ক্রেডিট কলাম — অথচ স্থিতিপত্রে **জের** থাকে, চলাচল নয়
 *   • সব খাত একটা সমতল তালিকায় — চলতি/স্থায়ী ভাগ নেই, উপমোট নেই
 *   • **দায়ের একটাও সারি ছিল না** — সমতল তালিকাটা কেবল যেসব খাতে
 *     এন্ট্রি আছে সেগুলো দেখাত
 *   • **চলতি বছরের লাভ মূলধনে যেত না**, তাই মোট শূন্য হত না —
 *     পর্দায় নিচে লেখা থাকত −১০,০৫০
 *
 * শেষ দুইটা একসাথে মানে জিনিসটা তার একমাত্র কাজটাই করত না: **সম্পদ =
 * দায় + মূলধন** দেখানো।
 *
 * ── কেন এটা রিপোর্ট-ইঞ্জিনে বসে না ───────────────────────────────────
 * [[ReportDefinition]] একটা সাধারণ টেবিল আঁকে — সারি, কলাম, যোগফল।
 * স্থিতিপত্র টেবিল নয়, **বিবৃতি**: দুইটা পক্ষ, ভেতরে ভাগ, প্রতিটার
 * উপমোট, আর শেষে একটা সমতার দাবি। ইঞ্জিনটাকে ওটা শেখাতে গেলে ইঞ্জিনে
 * এমন ধারণা ঢুকত যা আর কোনো রিপোর্টের লাগে না।
 *
 * ── কেন চলতি বছরের ফলটা আলাদা করে গোনা হয় ────────────────────────────
 * বছর বন্ধ না হওয়া পর্যন্ত আয়-ব্যয়ের খাতগুলো নিজেরাই ভরা থাকে; ওগুলো
 * সঞ্চিত মুনাফায় যায় কেবল সমাপনী দাখিলায়। তাই বছরের মাঝখানে স্থিতিপত্র
 * খুললে ওই ফলটা কোথাও থাকত না, আর দুই পক্ষ ঠিক লাভের পরিমাণ আলাদা হত।
 *
 * এটাই ছিল ওই −১০,০৫০।
 */
final class BalanceSheetService
{
    /**
     * @return array{
     *   assets: list<array<string, mixed>>,
     *   liabilities: list<array<string, mixed>>,
     *   equity: list<array<string, mixed>>,
     *   totals: array{assets: string, liabilities: string, equity: string, funding: string},
     *   profit: string,
     *   agrees: bool,
     *   difference: string,
     *   as_of: string,
     * }
     */
    public function build(?string $asOf = null, ?int $branchId = null): array
    {
        $asOf ??= now()->toDateString();

        /*
         * ⭐ শাখা না বললে হেডারে বাছা শাখা (৩০ সেপ্টেম্বর ২০২৬) — "সব শাখা"-তে `null`,
         * গোটা কোম্পানি। ⓘ মূলধনের পাতাও এটাই ডাকে, তাই দুই পাতা একই শাখায় থাকে।
         * ⛔ এটা দেখানোর হিসাব; কোনো যাচাই এটা ডাকে না।
         */
        $branchId ??= ViewedBranch::one();

        /*
         * ⛔ "সব শাখা"-তেও নাগালের ভেতরে — অডিট ⛔১১ (৬ অক্টোবর ২০২৬)। আগে `null` মানে গোটা কোম্পানি, তাই দুই শাখায় সীমিত
         * মানুষও সব শাখার স্থিতিপত্র আর চলতি বছরের লাভ দেখতেন। ⓘ শাখা না বললে দেখার নিয়ম ([[DataScope::inView()]]):
         * এক শাখা বাছা → সেটা; "সব শাখা" → নাগালের শাখা আর শাখাহীন সারি; সীমাহীন মানুষ (মালিক) → সব, আগের মতো।
         * ⓘ ডাকনেওয়ালা নিজে শাখা দিলে ঠিক সেটা।
         */
        $explicit = $branchId;
        $this->scope = $explicit !== null
            ? fn ($q, string $column) => $q->where($column, $explicit)
            : fn ($q, string $column) => app(DataScope::class)->inView($q, $column);

        $balances = $this->balances($asOf, $branchId);
        $accounts = Account::query()->orderBy('code')->get();

        /*
         * ⭐ অগ্রিম আলাদা লাইনে — উপস্থাপনে, খাতায় নয় (মালিকের পরিকল্পনা সংস্করণ ২, ৪ অক্টোবর ২০২৬; সমন্বয়কের সিদ্ধান্ত (ক):
         * "ERP-তে অগ্রিম নেই", খাতা যেমন আছে)। ⓘ পাওনার খাতে যে গ্রাহকদের জের Cr, তাঁদের যোগফল দায়ের দিকে "গ্রাহকের অগ্রিম";
         * দেনার খাতে যে সরবরাহকারীদের জের Dr, তাঁদের যোগফল সম্পদের দিকে "সরবরাহকারীর অগ্রিম"। পাওনা আর দেনা তাই নিজের
         * নিজের মোট দেখায়, নিট নয় — দুই দিকে একই অঙ্ক বাড়ে, স্থিতিপত্র মেলে।
         */
        $receivable = $accounts->firstWhere('code', StandardChart::RECEIVABLE);
        $payable = $accounts->firstWhere('code', StandardChart::PAYABLE);
        $customerAdvance = $receivable === null ? '0' : $this->partyAdvances((int) $receivable->id, $asOf, $branchId, creditSide: true);
        $supplierAdvance = $payable === null ? '0' : $this->partyAdvances((int) $payable->id, $asOf, $branchId, creditSide: false);

        if ($receivable !== null) {
            $balances[$receivable->id] = bcadd($balances[$receivable->id] ?? '0', $customerAdvance, 4);
        }

        if ($payable !== null) {
            $balances[$payable->id] = bcsub($balances[$payable->id] ?? '0', $supplierAdvance, 4);
        }

        $assets = $this->side($accounts, $balances, Account::ASSET);
        $liabilities = $this->side($accounts, $balances, Account::LIABILITY);

        $assets = $this->withAdvance($assets, $accounts, $receivable, $payable, $supplierAdvance, __('accounts::field.supplier_advance'));
        $liabilities = $this->withAdvance($liabilities, $accounts, $payable, $receivable, $customerAdvance, __('accounts::field.customer_advance'));
        $equity = $this->side($accounts, $balances, Account::EQUITY);

        /*
         * চলতি বছরের ফলটা মূলধনের একটা সারি হিসেবে বসে।
         *
         * খাত নয় — খাত বানালে ওটা খতিয়ানে থাকত, আর তখন বছর বন্ধ করার
         * সময় সংখ্যাটা দুইবার গোনা হত। এটা হিসাব করা একটা সারি, আর
         * পর্দায় সেটা স্পষ্ট করে বলা আছে।
         */
        $profit = $this->profitSoFar($asOf, $branchId);

        $totalAssets = $this->sum($assets);
        $totalLiabilities = $this->sum($liabilities);
        $totalEquity = bcadd($this->sum($equity), $profit, 4);
        $funding = bcadd($totalLiabilities, $totalEquity, 4);

        $difference = bcsub($totalAssets, $funding, 4);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'funding' => $funding,
            ],
            'profit' => $profit,
            'agrees' => bccomp($difference, '0', 4) === 0,
            'difference' => $difference,
            'as_of' => $asOf,
        ];
    }

    /**
     * এক পক্ষের গাছ — মাথা, তার নিচের খাত, আর উপমোট।
     *
     * ── কেন কেবল দুই স্তর ───────────────────────────────────────────
     * ছকটা চার স্তর গভীর হতে পারে (১০০০ › ১১০০ › ১১০১ › টিল)। পুরো
     * গাছটা আঁকলে স্থিতিপত্র পাঁচ পাতা হত, অথচ কেউ ওটা পড়ে না।
     *
     * যেটা পড়া হয়: "চলতি সম্পদ কত, তার মধ্যে মজুদ কত"। তাই মাথা
     * (১১০০) আর তার সরাসরি সন্তানরা — নিচের সব যোগ হয়ে সন্তানের ঘরে
     * ওঠে ([[Account::balanceOn()]] গ্রুপে সন্তানদের যোগ করে)।
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, string>  $balances
     * @return list<array<string, mixed>>
     */
    /** @var \Closure(mixed, string): mixed শাখার ছাঁকনি — [[build()]] বসায় */
    private \Closure $scope;

    private function side(Collection $accounts, array $balances, string $type): array
    {
        /*
         * ── চিহ্নটা পক্ষের, খাতের নয় — ৩০ আগস্ট ২০২৬ ─────────────────
         * প্রথমে প্রতিটা খাতের চিহ্ন তার **নিজের** প্রকৃতি ধরে উল্টানো
         * হয়েছিল, আর পর্দা খুলেই ৩২ লাখের ফারাক দেখাল — ঠিক উত্তোলনের
         * দ্বিগুণ।
         *
         * কারণ: **উত্তোলন (৩২০০) মূলধনের ঘরে বসে, কিন্তু তার প্রকৃতি
         * ডেবিট** — ওটা মূলধন কমায়। খাতের প্রকৃতি ধরে উল্টালে ওটা
         * ধনাত্মক থেকে যেত আর মূলধনে **যোগ** হত, অথচ বিয়োগ হওয়ার কথা।
         *
         * নিয়মটা তাই পক্ষের: সম্পদের পক্ষে ডেবিট ধনাত্মক, দায় ও
         * মূলধনের পক্ষে ক্রেডিট ধনাত্মক। তখন সঞ্চিত অবচয় (সম্পদের ঘরে
         * ক্রেডিট প্রকৃতি) নিজে থেকেই ঋণাত্মক হয়ে সম্পদ কমায় — আর
         * সেটাই সঠিক।
         *
         * পর্দাটা নিজেই এই ভুলটা ধরিয়ে দিয়েছে, কারণ এখন সে সমতার
         * কথাটা জোরে বলে। পুরনো পর্দা চুপ করে থাকত।
         */
        $creditSide = $type !== Account::ASSET;

        $out = [];

        foreach ($accounts->where('type', $type)->whereNull('parent_id') as $root) {
            foreach ($accounts->where('parent_id', $root->id) as $head) {
                $lines = [];

                foreach ($accounts->where('parent_id', $head->id) as $child) {
                    $amount = $this->signed($this->treeTotal($accounts, $balances, $child), $creditSide);

                    /*
                     * শূন্য সারি বাদ — কিন্তু কেবল সন্তানের স্তরে।
                     *
                     * ছকে ৬৪টা খাত, আর একটা ডিপোতে তার বেশিরভাগ কোনোদিন
                     * ছোঁয়া হয় না। সবগুলো দেখালে যে দশটা সারিতে সত্যিই
                     * টাকা আছে সেগুলো শূন্যের ভিড়ে হারাত।
                     *
                     * মাথাটা তবু থাকে, শূন্য হলেও — নাহলে "দায় কোথায়"
                     * প্রশ্নটা ফিরে আসত, আর ওটাই পুরনো পর্দার দোষ ছিল।
                     */
                    if (bccomp($amount, '0', 4) !== 0) {
                        $lines[] = ['account' => $child, 'amount' => $amount];
                    }
                }

                $out[] = [
                    'head' => $head,
                    'lines' => $lines,
                    'total' => $this->signed($this->treeTotal($accounts, $balances, $head), $creditSide),
                ];
            }
        }

        return $out;
    }

    /**
     * এই খাত ও তার নিচের সবার যোগফল — স্বাভাবিক দিকে ধনাত্মক।
     *
     * ── কেন `Account::balanceOn()` ডাকা হয় না ───────────────────────
     * ওটা প্রতিটা খাতের জন্য আলাদা কোয়েরি চালায়, আর গ্রুপে সন্তানদের
     * জন্য আবার। ৬৪টা খাতের স্থিতিপত্রে সেটা শ'খানেক কোয়েরি হত।
     * এখানে জেরগুলো একবারে তোলা হয়, তারপর গাছটা মেমরিতে যোগ হয়।
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, string>  $balances
     */
    private function treeTotal(Collection $accounts, array $balances, Account $account): string
    {
        $own = $balances[$account->id] ?? '0';

        foreach ($accounts->where('parent_id', $account->id) as $child) {
            $own = bcadd($own, $this->treeTotal($accounts, $balances, $child), 4);
        }

        return $own;
    }

    /**
     * প্রতিটা খাতের জের, একবারে — স্বাভাবিক দিকে ধনাত্মক।
     *
     * @return array<int, string>
     */
    private function balances(string $asOf, ?int $branchId): array
    {
        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->where('ledger_entries.trx_date', '<=', $asOf)
            ->tap(fn ($q) => ($this->scope)($q, 'ledger_entries.branch_id'))
            ->groupBy('ledger_entries.account_id', 'accounts.nature')
            ->select([
                'ledger_entries.account_id',
                'accounts.nature',
                DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net'),
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            /*
             * কাঁচা নিট — ডেবিট বিয়োগ ক্রেডিট, কোনো উল্টানো ছাড়াই।
             *
             * চিহ্নটা বসে পরে, পক্ষ অনুযায়ী ([[BalanceSheetService::side()]])।
             * এখানে খাতের নিজের প্রকৃতি ধরে উল্টালে উত্তোলন মূলধনে
             * যোগ হয়ে যেত।
             */
            $out[$row->account_id] = (string) $row->net;
        }

        return $out;
    }

    /**
     * এই বছরের ফল যতটা এখনো মূলধনে বসেনি।
     *
     * আয় − ব্যয়, বছরের শুরু থেকে ওই তারিখ পর্যন্ত, **সব সারি ধরে** —
     * বন্ধের দাখিলা আর তার উলটানো সারিও।
     *
     * ── ⛔ কী ভাঙা ছিল, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * আগে বন্ধের সারিগুলো বাদ যেত। ⚠️ তাতে বছর বন্ধ করার পর লাভটা
     * **দুইবার** গোনা হত: একবার সঞ্চিত মুনাফায় (বন্ধের দাখিলা ওটাকেই
     * বাড়ায়, আর [[balances()]] ওটা গোনে), আর একবার এখানে। ⓘ মাপা
     * হয়েছিল: লাভ ২০,০০০ → সম্পদ ৮,৬০,০০০, অথচ দায়+মূলধন ৮,৮০,০০০।
     *
     * ── ⭐ কেন এখন কোনো ছাঁকনি লাগে না ──────────────────────────────
     * খাতাটা নিজেই সত্যিটা বলে। বছর খোলা থাকলে বন্ধের সারি নেই, তাই
     * এটা চলতি ফল। বন্ধ হলে ওই সারিগুলো আয়-ব্যয়ের খাত শূন্য করে দেয়,
     * তাই এটা শূন্য — আর ফলটা তখন সঞ্চিত মুনাফার ঘরে দেখা যায়, যেখানে
     * সেটা থাকার কথা। ⓘ বছর আবার খুললে উলটানো সারিগুলো ফলটা ফিরিয়ে
     * আনে, আর সংখ্যাটাও ফিরে আসে — কোনো বাড়তি নিয়ম ছাড়াই।
     *
     * ⚠️ "ওই বছরে কত লাভ হয়েছিল" প্রশ্নের উত্তর এটা নয়, আর হওয়ার
     * কথাও নয় — সেটা লাভ-ক্ষতির রিপোর্ট বলে ([[CoreReports::profitAndLoss]]),
     * যেখানে বন্ধের সারিগুলো বাদ যায়।
     */
    private function profitSoFar(string $asOf, ?int $branchId): string
    {
        $row = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            /*
             * ⛔ বছরের শুরু থেকে নয়, শুরু থেকেই — অডিট গ১১, ৪ অক্টোবর ২০২৬।
             * আগের বছর বন্ধ না হলে তার লাভ আয়-ব্যয়ের খাতেই পড়ে থাকে, সঞ্চিত মুনাফায় যায়নি। বছরের শুরু থেকে
             * গুনলে সেই লাভ কোথাও আসত না, আর স্থিতিপত্র ঠিক ওই অঙ্কে "মেলে না" দেখাত। ⓘ বন্ধ বছরের আয়-ব্যয়
             * বন্ধের দাখিলাতেই শূন্য হয়ে আছে, তাই শুরু থেকে গুনলেও তা দুবার আসে না।
             */
            ->where('ledger_entries.trx_date', '<=', $asOf)
            ->tap(fn ($q) => ($this->scope)($q, 'ledger_entries.branch_id'))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN accounts.type = ? THEN credit - debit ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN accounts.type = ? THEN debit - credit ELSE 0 END), 0) as expense
            ', [Account::INCOME, Account::EXPENSE])
            ->first();

        return bcsub((string) $row->income, (string) $row->expense, 4);
    }

    /**
     * পক্ষ অনুযায়ী চিহ্ন — দায় ও মূলধনে ক্রেডিট ধনাত্মক।
     *
     * নাহলে দায়ের প্রতিটা সারি ঋণাত্মক দেখাত, আর পড়তে গিয়ে প্রতিবার
     * মাথায় চিহ্ন উল্টাতে হত।
     */
    private function signed(string $net, bool $creditSide): string
    {
        return $creditSide ? bcmul($net, '-1', 4) : $net;
    }

    /**
     * এক খাতে পক্ষ ধরে উল্টো দিকের জেরের যোগফল — পাওনায় Cr জেরের গ্রাহক, দেনায় Dr জেরের সরবরাহকারী (ধনাত্মক অঙ্কে)।
     */
    private function partyAdvances(int $accountId, string $asOf, ?int $branchId, bool $creditSide): string
    {
        $nets = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $accountId)
            ->whereNotNull('party_id')
            ->where('trx_date', '<=', $asOf)
            ->tap(fn ($q) => ($this->scope)($q, 'branch_id'))
            ->groupBy('party_type', 'party_id')
            ->selectRaw('SUM(debit) - SUM(credit) as net')
            ->pluck('net');

        $sum = '0';

        foreach ($nets as $net) {
            $net = (string) $net;

            if ($creditSide ? bccomp($net, '0', 4) < 0 : bccomp($net, '0', 4) > 0) {
                $sum = bcadd($sum, $creditSide ? bcmul($net, '-1', 4) : $net, 4);
            }
        }

        return $sum;
    }

    /**
     * অগ্রিমের লাইন যে মাথায় তার উল্টো খাত বসে (গ্রাহকের অগ্রিম → দেনার মাথা, সরবরাহকারীর অগ্রিম → পাওনার মাথা); লিংক
     * পক্ষের খাতেই — অঙ্কটা ওখান থেকে আসে।
     *
     * @param  list<array<string, mixed>>  $side
     * @return list<array<string, mixed>>
     */
    private function withAdvance(array $side, Collection $accounts, ?Account $home, ?Account $source, string $amount, string $label): array
    {
        if ($home === null || $source === null || bccomp($amount, '0', 4) === 0) {
            return $side;
        }

        $head = $home;

        while ($head->parent_id !== null && ($parent = $accounts->firstWhere('id', $head->parent_id)) !== null && $parent->parent_id !== null) {
            $head = $parent;
        }

        foreach ($side as $i => $group) {
            if ((int) $group['head']->id === (int) $head->id) {
                $side[$i]['lines'][] = ['account' => $source, 'label' => $label, 'amount' => $amount];
                $side[$i]['total'] = bcadd($group['total'], $amount, 4);
            }
        }

        return $side;
    }

    /** @param  list<array<string, mixed>>  $side */
    private function sum(array $side): string
    {
        $total = '0';

        foreach ($side as $group) {
            $total = bcadd($total, $group['total'], 4);
        }

        return $total;
    }
}
