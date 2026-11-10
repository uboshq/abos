<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Engines\Approval\SignedButNotPosted;
use App\Core\Support\DocumentStatus;
use App\Models\Attachment;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ হিসাবের ড্যাশবোর্ডের অঙ্ক — মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * ⓘ সমস্যা কেন্দ্র খাতার দশটা প্রচলিত নিয়ন্ত্রণ (internal control) — প্রতিটা ঘর একটা তালিকায় নিয়ে যায়, রং জরুরিতা ধরে
 * (লাল জরুরি, কমলা আজকের মধ্যে, সোনালি এ সপ্তাহে, সবুজ ঠিক আছে)। ⓘ কাগজগুলো মডেলের দেয়াল মানে (কোম্পানি, দেখা শাখা)।
 */
final class AccountsBoard
{
    /** পেছনের তারিখ — লেখার দিনের চেয়ে এত দিনের বেশি পুরনো তারিখ (প্রচলিত সীমা) */
    public const BACKDATED_DAYS = 3;

    /** হাতের বড় জাবেদা — এর বেশি টাকার সাধারণ জাবেদায় দ্বিতীয় চোখ */
    public const BIG_JOURNAL = '100000';

    /** পুরনো খসড়া — এত দিনের বেশি পড়ে থাকা */
    public const OLD_DRAFT_DAYS = 2;

    /**
     * ⭐ সমস্যা কেন্দ্র — দশটা ঘর, নমুনার ক্রমে; প্রতিটা: চাবি, সংখ্যা, টাকা বা খুঁটিনাটি, জরুরিতা (urgent/today/week/ok), কোথায় যাবে।
     *
     * @return list<array{key: string, n: string, detail: string, note: string, sev: string, href: string}>
     */
    public function problems(): array
    {
        $today = Carbon::today();
        $month = $today->copy()->startOfMonth();
        $user = auth()->user();
        $out = [];

        // 1 — সই হয়েছে, পোস্ট হয়নি ([[SignedButNotPosted]], টপবারের আইকনের একই তালিকা)
        $signed = $user === null ? [] : app(SignedButNotPosted::class)->papers($user);
        $out[] = ['key' => 'signed', 'n' => (string) count($signed), 'detail' => implode(', ', array_slice(array_values($signed), 0, 2)),
            'note' => '', 'sev' => $signed === [] ? 'ok' : 'urgent', 'href' => self::link('approval.inbox.signed_unposted')];

        // 2 — ব্যাংকে না-মেলা বিবরণীর সারি
        $unmatched = BankStatementLine::query()->unmatched()->get(['bank_account_id', 'debit', 'credit']);
        $banks = $unmatched->groupBy('bank_account_id');
        $names = Account::query()->whereIn('id', $banks->keys()->all() ?: [0])->get()->mapWithKeys(fn (Account $a) => [(int) $a->id => $a->name()]);
        $out[] = ['key' => 'bank', 'n' => (string) $unmatched->count(),
            'detail' => __('accounts::board.p.bank_detail', ['n' => $banks->count(), 'value' => self::short($unmatched->reduce(fn (string $c, $l) => bcadd($c, bcadd((string) $l->debit, (string) $l->credit, 4), 4), '0'))]),
            'note' => $banks->sortByDesc(fn ($ls) => $ls->count())->take(2)->map(fn ($ls, $id) => ($names[(int) $id] ?? '').' '.$ls->count())->implode(' · '),
            'sev' => $unmatched->isEmpty() ? 'ok' : 'urgent', 'href' => self::link('accounts.reconciliation.index')];

        // 3 — দুইবার এন্ট্রির সন্দেহ: শেষ 30 দিনে একই ধরন, একই পক্ষ, একই অঙ্ক, একই দিন — বাতিল ছাড়া
        $dupes = Voucher::query()->where('status', '<>', DocumentStatus::CANCELLED)->whereNotNull('party_id')
            ->where('trx_date', '>=', $today->copy()->subDays(30)->toDateString())
            ->groupBy('type', 'party_type', 'party_id', 'amount', 'trx_date')->havingRaw('COUNT(*) > 1')
            ->selectRaw('COUNT(*) as n, MIN(document_no) as first_no')->get();
        $out[] = ['key' => 'duplicate', 'n' => (string) $dupes->count(), 'detail' => (string) __('accounts::board.p.duplicate_detail'),
            'note' => (string) ($dupes->first()->first_no ?? ''), 'sev' => $dupes->isEmpty() ? 'ok' : 'urgent', 'href' => self::link('accounts.voucher.list')];

        // 4 — উৎসে করের জমার শেষ দিন: আগের মাসে কাটা উৎসে কর/ভ্যাট জমার শেষ দিন এ মাসের 15 তারিখ (প্রচলিত বাংলাদেশি নিয়ম)
        $due = $today->day <= 15 ? $today->copy()->day(15) : $today->copy()->addMonthNoOverflow()->day(15);
        $taxOwed = bcadd(self::balance(StandardChart::VAT_PAYABLE), self::balance(StandardChart::VDS_PAYABLE), 4);
        $daysLeft = (int) $today->diffInDays($due);
        $out[] = ['key' => 'tax', 'n' => (string) __('accounts::board.days', ['n' => $daysLeft]),
            'detail' => $due->day.' '.__('accounts::board.months_short')[$due->month].' · '.self::short($taxOwed), 'note' => '',
            'sev' => bccomp($taxOwed, '0', 4) <= 0 ? 'ok' : ($daysLeft <= 2 ? 'urgent' : ($daysLeft <= 7 ? 'today' : 'week')), 'href' => self::link('accounts.obligation.index')];

        // 5 — পেছনের তারিখে এন্ট্রি: এ মাসে লেখা, তারিখ লেখার দিনের 3 দিনের বেশি আগে
        $back = Voucher::query()->where('status', '<>', DocumentStatus::CANCELLED)->where('created_at', '>=', $month)
            ->whereRaw('DATEDIFF(DATE(created_at), trx_date) > ?', [self::BACKDATED_DAYS])->get(['trx_date']);
        $out[] = ['key' => 'backdated', 'n' => (string) $back->count(), 'detail' => (string) __('accounts::board.p.backdated_detail', ['n' => self::BACKDATED_DAYS]),
            'note' => (string) __('accounts::board.p.backdated_note', ['n' => $back->filter(fn ($v) => (string) $v->trx_date < $month->toDateString())->count()]),
            'sev' => $back->isEmpty() ? 'ok' : 'today', 'href' => self::link('accounts.voucher.list')];

        // 6 — হাতের বড় জাবেদা: এ মাসের সাধারণ জাবেদা, 1 লাখের বেশি
        $big = Voucher::query()->where('type', Voucher::JOURNAL)->where('status', '<>', DocumentStatus::CANCELLED)
            ->where('trx_date', '>=', $month->toDateString())->where('amount', '>', self::BIG_JOURNAL)->get(['amount']);
        $out[] = ['key' => 'journal', 'n' => (string) $big->count(), 'detail' => (string) __('accounts::board.p.journal_detail', ['value' => self::short($big->reduce(fn (string $c, $v) => bcadd($c, (string) $v->amount, 4), '0'))]),
            'note' => '', 'sev' => $big->isEmpty() ? 'ok' : 'today', 'href' => self::link('accounts.voucher.index', ['type' => Voucher::JOURNAL])];

        // 7 — সংযুক্তি ছাড়া: এ মাসের পাকা খরচ ও পরিশোধ ভাউচার, একটাও ছবি/ফাইল নেই
        $paid = Voucher::query()->whereIn('type', [Voucher::EXPENSE, Voucher::PAYMENT])->posted()->where('trx_date', '>=', $month->toDateString())->pluck('id');
        $withFile = Attachment::query()->where('source_entity', Voucher::drillSourceType())->whereIn('source_entity_id', $paid->all() ?: [0])->distinct()->pluck('source_entity_id');
        $bare = $paid->diff($withFile)->count();
        $out[] = ['key' => 'attachment', 'n' => (string) $bare, 'detail' => (string) __('accounts::board.p.attachment_detail'), 'note' => '',
            'sev' => $bare === 0 ? 'ok' : 'week', 'href' => self::link('accounts.voucher.index', ['type' => Voucher::EXPENSE])];

        // 8 — পক্ষ ছাড়া খরচ: এ মাসের খরচ ভাউচার, কাকে দেওয়া লেখা নেই
        $noParty = Voucher::query()->where('type', Voucher::EXPENSE)->where('status', '<>', DocumentStatus::CANCELLED)
            ->where('trx_date', '>=', $month->toDateString())->whereNull('party_id')->get(['amount']);
        $out[] = ['key' => 'party', 'n' => (string) $noParty->count(), 'detail' => self::short($noParty->reduce(fn (string $c, $v) => bcadd($c, (string) $v->amount, 4), '0')),
            'note' => '', 'sev' => $noParty->isEmpty() ? 'ok' : 'week', 'href' => self::link('accounts.voucher.index', ['type' => Voucher::EXPENSE])];

        // 9 — পুরনো খসড়া: 2 দিনের বেশি পড়ে থাকা
        $drafts = Voucher::query()->draft()->where('created_at', '<', now()->subDays(self::OLD_DRAFT_DAYS))->get(['created_by']);
        $out[] = ['key' => 'draft', 'n' => (string) $drafts->count(), 'detail' => (string) __('accounts::board.p.draft_detail', ['n' => self::OLD_DRAFT_DAYS]),
            'note' => (string) __('accounts::board.p.draft_note', ['n' => $drafts->pluck('created_by')->unique()->count()]),
            'sev' => $drafts->isEmpty() ? 'ok' : 'week', 'href' => self::link('accounts.voucher.list', ['status' => DocumentStatus::DRAFT])];

        // 10 — সাসপেন্স/অচেনা জমা: নামে "Suspense" বা "অচেনা" এমন পাতা-খাতের জের
        $suspense = Account::query()->where(fn ($q) => $q->where('name_en', 'like', '%Suspense%')->orWhere('name_bn', 'like', '%অচেনা%')->orWhere('name_bn', 'like', '%সাসপেন্স%'))
            ->get()->reduce(fn (string $c, Account $a) => bcadd($c, $a->balanceInView(), 4), '0');
        $out[] = ['key' => 'suspense', 'n' => self::short($suspense), 'detail' => (string) __(bccomp($suspense, '0', 4) === 0 ? 'accounts::board.p.suspense_clear' : 'accounts::board.p.suspense_left'),
            'note' => '', 'sev' => bccomp($suspense, '0', 4) === 0 ? 'ok' : 'today', 'href' => self::link('accounts.coa.index')];

        return $out;
    }

    /**
     * ⭐ সারি 2 — লাভ-ক্ষতির সেতু · এ মাস: বিক্রি → বিক্রীত পণ্যের খরচ → মোট লাভ → বেতন ও ভাড়া → পরিবহন ও অন্যান্য খরচ → অন্যান্য আয় → নিট লাভ।
     * ⓘ বিক্রি = নিট বিক্রি (ফেরত ও আদায়ে ছাড় বাদ); অন্যান্য আয় = সব আয় − নিট বিক্রি; খরচ = সব খরচ − বিক্রীত পণ্যের খরচ, তার বেতন (5201) ও
     *   ভাড়া (5202) আলাদা। ⓘ নিট লাভ = সব আয় − সব খরচ — লাভ-ক্ষতির হিসাবের একই সংখ্যা।
     *
     * @return array{sales: string, cogs: string, gross: string, people: string, other_cost: string, other_income: string, net: string}
     */
    public function pnl(): array
    {
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();
        $sales = self::moved(StandardChart::NET_SALES, $from, $to);
        $income = self::moved([StandardChart::INCOME], $from, $to);
        $cogs = self::moved([StandardChart::COST_OF_GOODS_SOLD], $from, $to);
        $expense = self::moved(['5000'], $from, $to);
        $people = self::moved([StandardChart::SALARY_EXPENSE, StandardChart::RENT], $from, $to);

        return [
            'sales' => $sales,
            'cogs' => $cogs,
            'gross' => bcsub($sales, $cogs, 4),
            'people' => $people,
            'other_cost' => bcsub(bcsub($expense, $cogs, 4), $people, 4),
            'other_income' => bcsub($income, $sales, 4),
            'net' => bcsub($income, $expense, 4),
        ];
    }

    /**
     * ⭐ স্থিতিপত্র · আজ — সম্পদ (টাকা, পাওনা, মজুদ, অগ্রিম ও জামানত, স্থায়ী ও অন্যান্য) বনাম দায় (সরবরাহকারীর দেনা, অন্যান্য চলতি, দীর্ঘমেয়াদি)
     * আর মালিকানা (মূলধন খাত + জমা লাভ)। ⓘ জমা লাভ = সম্পদ − দায় − মূলধন খাতের জের — বছরের না-বন্ধ লাভও এতে; তাই দুই পাশ গঠনেই মেলে,
     * আর "মিলেছে" মানে খাতার সব দাখিলা ডেবিট = ক্রেডিট ([[ledgerTotals()]])।
     *
     * @return array{assets: array<string, string>, claims: array<string, string>, total: string}
     */
    public function balanceSheet(): array
    {
        $money = '0';

        foreach (StandardChart::MONEY_PARENTS as $code) {
            $money = bcadd($money, self::balance($code), 4);
        }

        $receivable = self::balance(StandardChart::RECEIVABLE);
        $stock = self::balance(StandardChart::INVENTORY);
        $current = self::balance('1100');
        $assets = self::balance('1000');
        $payable = self::balance(StandardChart::PAYABLE_GROUP);
        $currentDebt = self::balance('2100');
        $debt = self::balance('2000');
        $capital = self::balance('3000');

        return [
            'assets' => [
                'money' => $money,
                'receivable' => $receivable,
                'stock' => $stock,
                'advances' => bcsub(bcsub(bcsub($current, $money, 4), $receivable, 4), $stock, 4),
                'fixed' => bcsub($assets, $current, 4),
            ],
            'claims' => [
                'payable' => $payable,
                'other_current' => bcsub($currentDebt, $payable, 4),
                'long_term' => bcsub($debt, $currentDebt, 4),
                'capital' => $capital,
                'retained' => bcsub(bcsub($assets, $debt, 4), $capital, 4),
            ],
            'total' => $assets,
        ];
    }

    /**
     * ⭐ রেওয়ামিল ও উপ-খাতার মিল — খাতার মোট ডেবিট ও ক্রেডিট, আর খাতা-যাচাইয়ের নিয়মগুলো (খাতা যাচাইয়ের পর্দার একই নিয়ম —
     * [[IntegrityRegistry]]): রেওয়ামিল, প্রতিটা কাগজ মেলে, পক্ষগুলো নিয়ন্ত্রণ খাতের সমান, সম্পদের রেজিস্টার = খাতা; সাথে সাসপেন্স, ব্যাংকের
     * না-মেলা বিবরণী, বন্ধ মাসে নতুন দাখিলা। ⓘ যাচাই ভারী — কোম্পানি ও শাখা ধরে 10 মিনিট মনে রাখা; কখন চলেছিল তা দেখানো হয়।
     *
     * @return array{debit: string, credit: string, checks: list<array{key: string, ok: bool, value: string}>, at: string}
     */
    public function ledgerChecks(): array
    {
        $user = auth()->user();
        $key = 'abos.acc-board-checks.'.\App\Core\Support\CompanyContext::id().'.'.(\App\Core\Support\ViewedBranch::one() ?? 'all').'.'.($user?->id ?? 0);

        return \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($user): array {
            $totals = \App\Models\LedgerEntry::query()
                ->tap(fn ($q) => \App\Core\Support\ViewedBranch::narrow($q, 'ledger_entries.branch_id'))
                ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
            $checks = [];
            $registry = collect(app(\App\Core\Integrity\IntegrityRegistry::class)->forUser($user))->keyBy('key');

            foreach (['accounts.trial_balance', 'accounts.every_document_balances', 'accounts.parties_add_up_to_their_control_account', 'accounts.asset_register_vs_ledger'] as $k) {
                $check = $registry->get($k);

                if ($check === null) {
                    continue;
                }

                try {
                    $found = count($check->run());
                } catch (\Throwable) {
                    $found = -1;
                }

                $checks[] = ['key' => substr($k, strlen('accounts.')), 'ok' => $found === 0, 'value' => $found < 0 ? '?' : (string) $found];
            }

            $unmatched = BankStatementLine::query()->unmatched()->get(['debit', 'credit'])
                ->reduce(fn (string $c, $l) => bcadd($c, bcadd((string) $l->debit, (string) $l->credit, 4), 4), '0');
            $checks[] = ['key' => 'bank', 'ok' => bccomp($unmatched, '0', 4) === 0, 'value' => self::short($unmatched)];

            $lateInClosed = \App\Models\PeriodLock::query()->get()->sum(fn ($lock) => \App\Models\LedgerEntry::query()
                ->whereBetween('trx_date', [Carbon::create($lock->year, $lock->month, 1)->toDateString(), Carbon::create($lock->year, $lock->month, 1)->endOfMonth()->toDateString()])
                ->where('created_at', '>', $lock->locked_at)->count());
            $checks[] = ['key' => 'closed_months', 'ok' => $lateInClosed === 0, 'value' => (string) $lateInClosed];

            return ['debit' => (string) ($totals->d ?? '0'), 'credit' => (string) ($totals->c ?? '0'), 'checks' => $checks, 'at' => now()->format('H:i')];
        });
    }

    /**
     * ⭐ এক নজরে · আজকের হিসাব — ছয়টা সারি, প্রতিটা চাপলে তালিকা। ⓘ ছোট রেখা কেবল যেখানে খাতায় অতীত আছে (শেষ 7 দিন/মাস);
     * যার অতীত খাতায় নেই (অপেক্ষার সংখ্যা), সেখানে রেখা আঁকা হয় না — আন্দাজের রেখা নয়।
     *
     * @return list<array{key: string, value: string, sub: string, delta: string, up: ?bool, line: list<float>, href: string}>
     */
    public function glance(): array
    {
        $today = Carbon::today();
        $byDay = Voucher::query()->where('created_at', '>=', $today->copy()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');
        $week = [];

        for ($d = $today->copy()->subDays(6); $d->lte($today); $d->addDay()) {
            $week[] = (float) ($byDay[$d->toDateString()] ?? 0);
        }

        $todayPapers = Voucher::query()->where('created_at', '>=', $today)->get(['status']);
        $yesterday = $week[5];
        $user = auth()->user();
        $signed = $user === null ? [] : app(SignedButNotPosted::class)->papers($user);
        $awaiting = app(\App\Modules\Accounts\Services\MonthEndChecklist::class)->run(\Carbon\CarbonImmutable::parse($today))[1]['count'] ?? 0;

        $unmatched = BankStatementLine::query()->unmatched()->get(['bank_account_id', 'debit', 'credit']);
        $pnl = $this->pnl();
        $pnlBefore = self::moved([StandardChart::INCOME], $today->copy()->startOfMonth()->subMonthNoOverflow(), $today->copy()->startOfMonth()->subDay());
        $lastNet = bcsub($pnlBefore, self::moved(['5000'], $today->copy()->startOfMonth()->subMonthNoOverflow(), $today->copy()->startOfMonth()->subDay()), 4);

        $money = fn (Carbon $at) => array_reduce(StandardChart::MONEY_PARENTS, fn (string $c, string $code) => bcadd($c, self::balanceAt($code, $at), 4), '0');
        $months = [];

        for ($i = 6; $i >= 1; $i--) {
            $months[] = (float) $money($today->copy()->startOfMonth()->subMonthsNoOverflow($i - 1)->subDay());
        }

        $nowMoney = $money($today);
        $months[] = (float) $nowMoney;
        $lastMonthEnd = $months[5];

        return [
            ['key' => 'today', 'value' => (string) $todayPapers->count(),
                'sub' => (string) __('accounts::board.g.today_sub', ['d' => $todayPapers->where('status', DocumentStatus::DRAFT)->count(), 'p' => $todayPapers->where('status', DocumentStatus::CONFIRMED)->count()]),
                'delta' => $yesterday > 0 ? (string) __('accounts::board.g.vs_yesterday', ['pct' => (int) round(($todayPapers->count() - $yesterday) / $yesterday * 100)]) : '',
                'up' => $todayPapers->count() >= $yesterday, 'line' => $week, 'href' => self::link('accounts.voucher.list')],
            ['key' => 'awaiting', 'value' => (string) $awaiting, 'sub' => '', 'delta' => '', 'up' => null, 'line' => [], 'href' => self::link('approval.inbox.index')],
            ['key' => 'signed', 'value' => (string) count($signed), 'sub' => implode(', ', array_slice(array_values($signed), 0, 2)), 'delta' => '', 'up' => null, 'line' => [], 'href' => self::link('approval.inbox.signed_unposted')],
            ['key' => 'bank', 'value' => (string) $unmatched->count(),
                'sub' => (string) __('accounts::board.p.bank_detail', ['n' => $unmatched->groupBy('bank_account_id')->count(), 'value' => self::short($unmatched->reduce(fn (string $c, $l) => bcadd($c, bcadd((string) $l->debit, (string) $l->credit, 4), 4), '0'))]),
                'delta' => '', 'up' => null, 'line' => [], 'href' => self::link('accounts.reconciliation.index')],
            ['key' => 'profit', 'value' => self::short($pnl['net']),
                'sub' => bccomp($pnl['sales'], '0', 4) > 0 ? (string) __('accounts::board.g.profit_sub', ['pct' => bcdiv(bcmul($pnl['net'], '100', 4), $pnl['sales'], 1)]) : '',
                'delta' => (string) __('accounts::board.g.last_month', ['value' => self::short($lastNet)]), 'up' => bccomp($pnl['net'], $lastNet, 4) >= 0, 'line' => [], 'href' => self::link('accounts.balance_sheet')],
            ['key' => 'money', 'value' => self::short($nowMoney), 'sub' => '',
                'delta' => $lastMonthEnd > 0 ? (string) __('accounts::board.g.vs_month', ['pct' => number_format((((float) $nowMoney) - $lastMonthEnd) / $lastMonthEnd * 100, 1, '.', '')]) : '',
                'up' => (float) $nowMoney >= $lastMonthEnd, 'line' => $months, 'href' => self::link('accounts.custody')],
        ];
    }

    /**
     * ⭐ খরচের খাত · ট্রিম্যাপ · এ মাস — খরচের পাতা-খাত ধরে (বিক্রীত পণ্যের খরচ বাদ), বড় সাতটা আর বাকি "অন্যান্য";
     * ঘরের মাপ সারি-ভাগ পদ্ধতিতে (slice-and-dice), শতাংশে।
     *
     * @return array{boxes: list<array{name: string, value: string, pct: int, x: float, y: float, w: float, h: float}>, total: string}
     */
    public function expenseTree(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $cogs = StandardChart::find(StandardChart::COST_OF_GOODS_SOLD);
        $rows = \App\Models\LedgerEntry::query()->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->tap(fn ($q) => \App\Core\Support\ViewedBranch::narrow($q, 'ledger_entries.branch_id'))
            ->where('accounts.type', Account::EXPENSE)->where('ledger_entries.trx_date', '>=', $from)
            ->when($cogs !== null, fn ($q) => $q->where('accounts.code', 'not like', $cogs->code.'%'))
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id as id, SUM(ledger_entries.debit - ledger_entries.credit) as v')
            ->havingRaw('SUM(ledger_entries.debit - ledger_entries.credit) > 0')->get();
        $names = Account::query()->whereIn('id', $rows->pluck('id')->all() ?: [0])->get()->mapWithKeys(fn (Account $a) => [(int) $a->id => $a->name()]);
        $list = $rows->map(fn ($r) => ['name' => (string) ($names[(int) $r->id] ?? ''), 'value' => bcadd((string) $r->v, '0', 4)])
            ->sort(fn (array $a, array $b) => bccomp($b['value'], $a['value'], 4))->values();
        $top = $list->take(7)->all();
        $rest = $list->slice(7)->reduce(fn (string $c, array $r) => bcadd($c, $r['value'], 4), '0');

        if (bccomp($rest, '0', 4) > 0) {
            $top[] = ['name' => (string) __('accounts::board.g.others'), 'value' => $rest];
        }

        $total = array_reduce($top, fn (string $c, array $r) => bcadd($c, $r['value'], 4), '0');

        if (bccomp($total, '0', 4) <= 0) {
            return ['boxes' => [], 'total' => '0'];
        }

        $out = [];
        $box = ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0];
        $left = (float) $total;
        $vertical = true;

        foreach ($top as $i => $g) {
            $share = $left > 0 ? (float) $g['value'] / $left : 1.0;
            $last = $i === count($top) - 1;
            $pct = (int) round((float) $g['value'] / (float) $total * 100);

            if ($vertical) {
                $w = $last ? $box['w'] : $box['w'] * $share;
                $out[] = $g + ['pct' => $pct, 'x' => $box['x'], 'y' => $box['y'], 'w' => $w, 'h' => $box['h']];
                $box['x'] += $w;
                $box['w'] -= $w;
            } else {
                $h = $last ? $box['h'] : $box['h'] * $share;
                $out[] = $g + ['pct' => $pct, 'x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $h];
                $box['y'] += $h;
                $box['h'] -= $h;
            }

            $left -= (float) $g['value'];
            $vertical = ! $vertical;
        }

        return ['boxes' => $out, 'total' => $total];
    }

    /**
     * ⭐ ব্যাংক মিলকরণ — প্রতিটা চালু ব্যাংক খাত: খাতার জের, বিবরণীর শেষ জের, না-মেলা সারি, শেষ নিশ্চিত মিলকরণের দিন, আর শেষ 90 দিনের
     * বিবরণীর কত শতাংশ মিলেছে।
     *
     * @return list<array{name: string, book: string, statement: ?string, unmatched: int, last: ?string, pct: ?int}>
     */
    public function banks(): array
    {
        $since = Carbon::today()->subDays(90)->toDateString();

        return Account::query()->ofMoneyKind(Account::BANK)->where('is_active', true)->orderBy('code')->get()
            ->map(function (Account $a) use ($since): array {
                $lines = BankStatementLine::query()->where('bank_account_id', $a->id);
                $all = (clone $lines)->where('trx_date', '>=', $since)->count();
                $matched = (clone $lines)->where('trx_date', '>=', $since)->whereNotNull('matched_line_id')->count();
                $lastLine = (clone $lines)->orderByDesc('trx_date')->orderByDesc('id')->first(['balance']);
                $last = \App\Modules\Accounts\Models\BankReconciliation::query()->where('bank_account_id', $a->id)
                    ->where('status', \App\Modules\Accounts\Models\BankReconciliation::CONFIRMED)->max('statement_date');

                return [
                    'name' => $a->name(),
                    'book' => $a->balanceInView(),
                    'statement' => $lastLine?->balance === null ? null : (string) $lastLine->balance,
                    'unmatched' => (clone $lines)->unmatched()->count(),
                    'last' => $last === null ? null : substr((string) $last, 0, 10),
                    'pct' => $all === 0 ? null : (int) floor($matched * 100 / $all),
                ];
            })->values()->all();
    }

    /**
     * ⭐ কর ও সরকারি দায় — ভ্যাট (মূসক-9.1: প্রদেয় ভ্যাটের জের, পরের মাসের 15 তারিখ), আর খোলা কোষাগার-জমার দায়
     * ([[ComplianceObligation]] — উৎসে আয়কর ও উৎসে ভ্যাট, নিজের শেষ তারিখে)। ⓘ বৃত্ত = মাসের কতটা পেরিয়েছে শেষ তারিখ পর্যন্ত।
     *
     * @return list<array{key: string, amount: string, due: string, days: int, share: float}>
     */
    public function taxes(): array
    {
        $today = Carbon::today();
        $nextFifteenth = $today->day <= 15 ? $today->copy()->day(15) : $today->copy()->addMonthNoOverflow()->day(15);
        $share = fn (Carbon $due) => min(1.0, max(0.0, 1 - $today->diffInDays($due) / 30));
        $out = [['key' => 'vat', 'amount' => self::balance(StandardChart::VAT_PAYABLE), 'due' => $nextFifteenth->toDateString(),
            'days' => (int) $today->diffInDays($nextFifteenth), 'share' => $share($nextFifteenth)]];
        $obligation = \App\Modules\Accounts\Models\ComplianceObligation::class;

        foreach ([$obligation::AIT => 'ait', $obligation::VDS => 'vds'] as $subject => $key) {
            $open = $obligation::query()->open()->where('kind', $obligation::KOSAGAR_DEPOSIT)->where('subject', $subject)->get(['amount', 'due_on']);
            $first = $open->min('due_on');
            $due = $first === null ? $nextFifteenth : Carbon::parse($first)->startOfDay();
            $out[] = ['key' => $key, 'amount' => $open->reduce(fn (string $c, $o) => bcadd($c, (string) $o->amount, 4), '0'), 'due' => $due->toDateString(),
                'days' => (int) $today->diffInDays($due, false), 'share' => $share($due)];
        }

        return $out;
    }

    /** একটা খাতের (ও নিচের) তারিখের জের, দেখার নাগালে */
    private static function balanceAt(string $code, Carbon $at): string
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            return '0';
        }

        $ids = app(\App\Core\Services\DataScope::class)->viewBranchIds(auth()->user());
        $total = '0';

        foreach ($ids === null ? [null] : $ids as $branch) {
            $total = bcadd($total, $account->balanceOn($at->toDateString(), $branch), 4);
        }

        return $total;
    }

    /** কয়েকটা খাতের (ও নিচের) সময়ের নড়াচড়া — শেষের জের − শুরুর আগের জের, দেখার নাগালে */
    private static function moved(array $codes, Carbon $from, Carbon $to): string
    {
        $ids = app(\App\Core\Services\DataScope::class)->viewBranchIds(auth()->user());
        $before = $from->copy()->subDay()->toDateString();
        $total = '0';

        foreach ($codes as $code) {
            $account = StandardChart::find($code);

            if ($account === null) {
                continue;
            }

            foreach ($ids === null ? [null] : $ids as $branch) {
                $total = bcadd($total, bcsub($account->balanceOn($to->toDateString(), $branch), $account->balanceOn($before, $branch), 4), 4);
            }
        }

        return $total;
    }

    /** মাস বন্ধের লক্ষ্য — এত কার্যদিবসের মধ্যে (প্রচলিত "fast close" মাপ) */
    public const CLOSE_TARGET_DAYS = 5;

    /**
     * ⭐ মাস বন্ধ — আগের মাস: মাস-শেষের চেকলিস্টের নয় ধাপ ([[MonthEndChecklist]] — মাস বন্ধের পাতার একই তালিকা), কোনটা হয়েছে,
     * আজ কততম কার্যদিবস (শুক্র-শনি ছুটি), লক্ষ্য 5 দিন থেকে কত পিছিয়ে, আর তার আগের মাস কততম কার্যদিবসে বন্ধ হয়েছিল।
     * ⓘ "এই মাসে প্রযোজ্য নয়" ধাপ গোনায় নেই — হয়েছেও বলা হয় না, বাকিও নয় (চেকলিস্টের নিজের নিয়ম)।
     *
     * @return array{month: int, year: int, steps: list<array{key: string, done: bool, count: int, href: string}>, done: int, day: int, behind: int, locked: bool, previous: ?int}
     */
    public function monthClose(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->startOfMonth()->subMonthNoOverflow();
        $locked = \App\Models\PeriodLock::query()->where('year', $start->year)->where('month', $start->month)->first();

        $steps = collect(app(\App\Modules\Accounts\Services\MonthEndChecklist::class)->run(\Carbon\CarbonImmutable::parse($start)))
            ->reject(fn (array $r) => $r['state'] === \App\Modules\Accounts\Services\MonthEndChecklist::NOT_APPLICABLE)
            ->map(fn (array $r) => ['key' => $r['key'], 'done' => $r['state'] === \App\Modules\Accounts\Services\MonthEndChecklist::OK,
                'count' => (int) $r['count'], 'href' => self::link($r['route'], $r['params'])])
            ->values()->all();

        $before = \App\Models\PeriodLock::query()->where('year', $start->copy()->subMonthNoOverflow()->year)->where('month', $start->copy()->subMonthNoOverflow()->month)->first();
        $day = self::workingDay($today);

        return [
            'month' => (int) $start->month,
            'year' => (int) $start->year,
            'steps' => $steps,
            'done' => count(array_filter($steps, fn (array $s) => $s['done'])),
            'day' => $day,
            'behind' => $locked !== null ? 0 : max(0, $day - self::CLOSE_TARGET_DAYS),
            'locked' => $locked !== null,
            // ⓘ আগের মাস কততম কার্যদিবসে বন্ধ হয়েছিল — তালার দিনটা তার পরের মাসের কততম কার্যদিবস
            'previous' => $before?->locked_at === null ? null : self::workingDay(Carbon::parse($before->locked_at)->startOfDay()),
        ];
    }

    /** মাসের কততম কার্যদিবস — শুক্র আর শনি বাদে (বাংলাদেশের সাপ্তাহিক ছুটি) */
    public static function workingDay(Carbon $date): int
    {
        $n = 0;

        for ($d = $date->copy()->startOfMonth(); $d->lte($date); $d->addDay()) {
            $n += in_array($d->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true) ? 0 : 1;
        }

        return $n;
    }

    /** একটা খাতের (ও নিচের) আজকের জের, দেখার নাগালে */
    private static function balance(string $code): string
    {
        return StandardChart::find($code)?->balanceInView() ?? '0';
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক */
    public static function short(string $amount): string
    {
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        if (bccomp($a, '10000000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('accounts::board.crore');
        }

        if (bccomp($a, '100000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '100000', 1).' '.__('accounts::board.lakh');
        }

        if (bccomp($a, '1000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '1000', 0).'k';
        }

        return $sign.'৳'.bcadd($a, '0', 0);
    }

    /** @param  array<string, mixed>  $params */
    private static function link(string $route, array $params = []): string
    {
        return Route::has($route) ? route($route, $params) : '#';
    }
}
