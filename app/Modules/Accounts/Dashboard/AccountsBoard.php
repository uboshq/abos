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

    /** মাস বন্ধের লক্ষ্য — এত কার্যদিবসের মধ্যে (প্রচলিত "fast close" মাপ) */
    public const CLOSE_TARGET_DAYS = 5;

    /**
     * ⭐ মাস বন্ধ — আগের মাস (তালা না পড়া পর্যন্ত): খাতা থেকে যাচাইযোগ্য ধাপগুলো, কোনটা হয়েছে, আজ কততম কার্যদিবস (শুক্র-শনি ছুটি),
     * লক্ষ্য 5 দিন থেকে কত পিছিয়ে, আর তার আগের মাস কততম কার্যদিবসে বন্ধ হয়েছিল।
     * ⓘ ধাপ: খসড়া শেষ · সই হওয়া কাগজ পোস্ট · প্রতিটা ব্যাংক মাস-শেষ পর্যন্ত মেলানো · মাস-শেষে নগদ গোনা · অবচয় (স্থায়ী সম্পদ থাকলে) · মাস তালা।
     *
     * @return array{month: int, year: int, steps: list<array{key: string, done: bool}>, done: int, day: int, behind: int, locked: bool, previous: ?int}
     */
    public function monthClose(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->startOfMonth()->subMonthNoOverflow();
        $end = $start->copy()->endOfMonth();
        $range = [$start->toDateString(), $end->toDateString()];
        $locked = \App\Models\PeriodLock::query()->where('year', $start->year)->where('month', $start->month)->first();

        $banks = Account::query()->ofMoneyKind(Account::BANK)->where('is_active', true)->pluck('id');
        $reconciled = \App\Modules\Accounts\Models\BankReconciliation::query()->where('status', \App\Modules\Accounts\Models\BankReconciliation::CONFIRMED)
            ->where('statement_date', '>=', $end->toDateString())->distinct()->pluck('bank_account_id');
        $user = auth()->user();
        $signedInMonth = $user === null ? [] : app(SignedButNotPosted::class)->papers($user);
        $hasAssets = bccomp(self::balance(StandardChart::FIXED_ASSETS), '0', 4) > 0;

        $steps = [
            ['key' => 'drafts', 'done' => ! Voucher::query()->draft()->whereBetween('trx_date', $range)->exists()],
            ['key' => 'signed', 'done' => $signedInMonth === []],
            ['key' => 'bank', 'done' => $banks->diff($reconciled)->isEmpty()],
            ['key' => 'cash', 'done' => \App\Modules\Accounts\Models\CashCount::query()->where('status', '<>', DocumentStatus::CANCELLED)
                ->whereBetween('trx_date', [$end->copy()->subDays(2)->toDateString(), $end->copy()->addDays(5)->toDateString()])->exists()],
        ];

        if ($hasAssets) {
            $steps[] = ['key' => 'depreciation', 'done' => \App\Modules\Accounts\Models\DepreciationRun::query()->where('period_end', '>=', $end->toDateString())->exists()];
        }

        $steps[] = ['key' => 'lock', 'done' => $locked !== null];

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
