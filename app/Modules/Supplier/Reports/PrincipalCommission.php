<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\PartyLedger;
use App\Models\Branch;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * প্রিন্সিপালের কমিশন — ডিপোর আয়, প্রিন্সিপাল ধরে, তার নিজের মাসে (মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ── ব্যবসাটা ─────────────────────────────────────────────────────────────
 * এক কোম্পানির ভেতরে কয়েকটা প্রিন্সিপাল, আর প্রতিটা শাখা একটা প্রিন্সিপাল (এসএল-সুপার, এসএল-লায়ন, জাবেদ অ্যাগ্রো…)।
 * ডিপো ডিলারের কাছ থেকে টাকা তোলে সেই প্রিন্সিপালের শাখাতেই, আর তার আয় সেই **আদায়ের** উপর কমিশন:
 *
 *   মার্জিন r%  → কমিশন = আদায় × r / 100            (৩.৮৫% × ১,০০,০০০ = ৩,৮৫০.০০)
 *   মার্কআপ r%  → কমিশন = আদায় × r / (100 + r)      (৪% × ১,০০,০০০ = ৩,৮৪৬.১৫)
 *
 *   প্রিন্সিপালের অংশ = আদায় − কমিশন;  জের = অংশ − প্রিন্সিপালকে দেওয়া
 *
 * ⛔ **কেবল রিপোর্ট** — মালিকের সিদ্ধান্ত: খাতায় কিছুই বসে না। এই ক্লাস কেবল পড়ে।
 * ⓘ ক্রয়ের "প্রিন্সিপাল নিষ্পত্তি" ([[SettlementReport]]) আলাদা প্রশ্ন (মাল এল, বিক্রি হলো, মার্জিন) — ওটা যেমন আছে থাকে।
 *
 * ── ⭐ আদায় কাকে বলে — নিয়মটা ───────────────────────────────────────────────
 * প্রিন্সিপালের শাখায় (`ledger_entries.branch_id` — যে শাখায় টাকাটা নেওয়া হলো) **টাকার খাতের** নিট চলাচল
 * (ডেবিট − ক্রেডিট), কেবল সেই কাগজগুলোর যেগুলো কোনো **গ্রাহকের** খাতা ছোঁয় (একই `source_type` + `source_id`-এ একটা
 * `party_type = customer` সারি আছে)।
 *
 *   টাকার খাত = নগদ, ব্যাংক, MFS-এর পাতা-খাত (`money_kind` বসানো, দল নয় — হিসাবের "আদায়ের তালিকা"-র একই সংজ্ঞা,
 *   [[CoreReports::inflow()]]) আর হাতে আসা চেক ([[StandardChart::MONEY_HOLDING]], ১১০৪): চেকে আদায়ও আদায়, আর চেক
 *   ভাঙানোর কাগজ (ব্যাংক ↔ ১১০৪) কোনো গ্রাহক ছোঁয় না, তাই একই টাকা দুইবার গোনা হয় না।
 *
 * কেন এভাবে:
 *   · ⛔ নিজের খাত থেকে নিজের খাতে টাকা সরানো (কন্ট্রা, টিল → ব্যাংক) কোনো গ্রাহক ছোঁয় না — বাদ।
 *   · ⛔ ঋণ, মূলধন, প্রিন্সিপালের ফেরত টাকা — গ্রাহক নেই — বাদ।
 *   · ⛔ ডিলার সরাসরি প্রিন্সিপালকে দিলে তিন-কোণা সমন্বয়ে কোনো টাকার খাত নেই — ডিপোর হাতে টাকা আসেনি — বাদ।
 *   · ⭐ নগদ বিক্রি (বিলেই টাকা) আর আদায়ের রসিদ — দুইটাই গ্রাহক ছোঁয় আর টাকা আনে — গোনা।
 *   · ⓘ নিট, কেবল ডেবিট নয়: রসিদ বাতিল হলে উল্টো কাগজ (`…:reversal`) গ্রাহক ছোঁয় আর টাকা কমায়; চেক ফেরত বা ডিলারকে
 *     টাকা ফেরতও তাই। ডেবিট গুনলে বাতিল রসিদের টাকা আদায়ে থেকে যেত, আর তার উপর কমিশনও।
 *   · ⓘ ব্যাংক চার্জ কাটা রসিদে (ব্যাংক ৯৮ + চার্জ ২ = গ্রাহক ১০০) আদায় ৯৮ — যা সত্যিই এল।
 *
 * ── প্রিন্সিপালকে দেওয়া ─────────────────────────────────────────────────────
 * এই সরবরাহকারীর খাতার নিট ডেবিট (ডেবিট − ক্রেডিট), কেবল টাকার কাগজে — পক্ষের খাতার "টাকা" ধরনের একই তালিকা
 * ([[PartyLedger::KINDS]]`['money']`: পরিশোধ, পরিশোধ/রসিদ ভাউচার, চেক; বাতিলের উল্টো সারিসহ)। খাতার সারি মানেই
 * পোস্ট হওয়া কাগজ। ⓘ শাখা ধরে নয় — প্রিন্সিপালকে টাকা প্রধান অফিস থেকেও যায়, আর জেরটা প্রিন্সিপালের, শাখার নয়।
 *
 * ── মাস (চক্র) ─────────────────────────────────────────────────────────────
 * শুরুর দিন S, শেষের দিন C (৩১ = মাসের শেষ দিন; ছোট মাসে শেষ তারিখে নামে)। "M মাসে শেষ হওয়া চক্র":
 *   শেষ = M-এর C তারিখ;  শুরু = S > C হলে আগের মাসের S, নইলে M-এর S।
 *   ⓘ S > C-তে শুরুর তারিখ আগের চক্রের শেষের পরদিনের আগে নামে না — ৩১/৩০-এর মতো জোড়ায় ফেব্রুয়ারির শেষ দিন দুই চক্রে পড়ত।
 * চলতি চক্র = যেটায় আজ পড়ে; দুই চক্রের মাঝের ফাঁকে (যেমন ১–২৫, আজ ২৭) সদ্য শেষ হওয়াটা।
 */
final class PrincipalCommission
{
    public const MARGIN = 'margin';

    public const MARKUP = 'markup';

    /** @var list<string> */
    public const BASES = [self::MARGIN, self::MARKUP];

    /** শেষের দিন ৩১ = মাসের শেষ দিন */
    public const MONTH_END = 31;

    /** ⓘ গ্রাহকের খাতার পক্ষ-নাম — খাতার `party_type`-এর লেখা ([[Customer::drillSourceType()]]); ক্লাসটা অন্য মডিউলের */
    private const CUSTOMER_PARTY = 'customer';

    /** কমিশন — bcmath-এ, দুই ঘরে গোল (অর্ধেক হলে উপরে)। */
    public static function commission(string $inflow, string $basis, string $rate): string
    {
        $gross = bcmul($inflow, $rate, 10);

        $raw = match ($basis) {
            self::MARGIN => bcdiv($gross, '100', 10),
            self::MARKUP => bcdiv($gross, bcadd('100', $rate, 10), 10),
            default => throw new \InvalidArgumentException("Unknown commission basis '{$basis}'."),
        };

        return Money::round($raw, 2);
    }

    /**
     * M মাসে শেষ হওয়া চক্র।
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function cycleClosingIn(int $start, int $close, Carbon $month): array
    {
        $m = $month->copy()->startOfMonth();
        $to = $m->copy()->day(min($close, $m->daysInMonth));

        if ($start <= $close) {
            return [$m->copy()->day(min($start, $m->daysInMonth)), $to];
        }

        $prev = $m->copy()->subMonthNoOverflow();
        $from = $prev->copy()->day(min($start, $prev->daysInMonth));
        $prevClose = $prev->copy()->day(min($close, $prev->daysInMonth));

        if ($from->lte($prevClose)) {
            $from = $prevClose->copy()->addDay();
        }

        return [$from, $to];
    }

    /**
     * আজকের চক্র — যেটায় দিনটা পড়ে; ফাঁকের দিনে সদ্য শেষ হওয়াটা।
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function cycleContaining(int $start, int $close, Carbon $day): array
    {
        $day = $day->copy()->startOfDay();
        $month = $day->copy()->startOfMonth();

        foreach ([0, 1, -1] as $shift) {
            [$from, $to] = self::cycleClosingIn($start, $close, $month->copy()->addMonthsNoOverflow($shift));

            if ($day->betweenIncluded($from, $to)) {
                return [$from, $to];
            }
        }

        $here = self::cycleClosingIn($start, $close, $month);

        return $here[1]->lt($day) ? $here : self::cycleClosingIn($start, $close, $month->copy()->subMonthNoOverflow());
    }

    /** ঠিকানার `month` (2026-10) — খালি হলে `null` (চলতি চক্র); ভুল আকারে হলে ফেরত। */
    public static function month(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw, $m) !== 1) {
            throw ValidationException::withMessages(['month' => __('supplier::principal.month_invalid')]);
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
    }

    /** শাখায়, সময়ে আদায় — নিয়মটা উপরে। */
    public function collections(int $company, int $branch, string $from, string $to): string
    {
        $net = DB::table('ledger_entries as m')
            ->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.company_id', $company)
            ->where('m.branch_id', $branch)
            ->whereBetween('m.trx_date', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $k) => $k->whereNotNull('a.money_kind')->where('a.is_group', false))
                ->orWhereIn('a.code', StandardChart::MONEY_HOLDING))
            ->whereExists(fn (Builder $c) => $c->selectRaw('1')
                ->from('ledger_entries as c')
                ->whereColumn('c.company_id', 'm.company_id')
                ->whereColumn('c.source_type', 'm.source_type')
                ->whereColumn('c.source_id', 'm.source_id')
                ->where('c.party_type', self::CUSTOMER_PARTY))
            ->selectRaw('COALESCE(SUM(m.debit) - SUM(m.credit), 0) as net')
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', 4);
    }

    /** সময়ে এই সরবরাহকারীকে দেওয়া — পোস্ট হওয়া টাকার কাগজ, নিট। */
    public function paidTo(int $company, int $supplier, string $from, string $to): string
    {
        $net = DB::table('ledger_entries')
            ->where('company_id', $company)
            ->where('party_type', Supplier::drillSourceType())
            ->where('party_id', $supplier)
            ->whereBetween('trx_date', [$from, $to])
            ->where(function (Builder $q) {
                foreach (PartyLedger::KINDS['money'] as $prefix) {
                    $q->orWhere('source_type', $prefix)->orWhere('source_type', 'like', $prefix.':%');
                }
            })
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', 4);
    }

    /**
     * রিপোর্টের সারি — প্রতিটা প্রিন্সিপাল একবার, তার নিজের চক্রে।
     *
     * ⓘ প্রিন্সিপাল = সরবরাহকারী যাঁর শাখা, ভিত্তি, হার আর দুইটা দিন বসানো। শাখার দেয়াল তাঁর শাখায়
     * ([[ReportEngine::branchWall()]]) — শাখায় আটকানো মানুষ কেবল নিজের শাখার প্রিন্সিপাল দেখেন।
     *
     * @param  array<string, mixed>  $f  ইঞ্জিনের ছাঁকনি (`company_id`, শাখা, `month`)
     * @return list<array<string, mixed>>
     */
    public function rows(array $f, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $month = self::month($f['month'] ?? null);

        $principals = DB::table('suppliers')
            ->where('suppliers.company_id', $f['company_id'])
            ->whereNull('suppliers.deleted_at')
            ->whereNotNull('suppliers.principal_branch_id')
            ->whereIn('suppliers.commission_basis', self::BASES)
            ->whereNotNull('suppliers.commission_rate')
            ->whereNotNull('suppliers.cycle_start_day')
            ->whereNotNull('suppliers.cycle_close_day')
            ->tap(ReportEngine::branchWall($f, 'suppliers.principal_branch_id'))
            ->orderBy('suppliers.code')
            ->select(['suppliers.id', 'suppliers.code', 'suppliers.name_en', 'suppliers.name_bn', 'suppliers.short_name', 'suppliers.principal_branch_id',
                'suppliers.commission_basis', 'suppliers.commission_rate', 'suppliers.cycle_start_day', 'suppliers.cycle_close_day'])
            ->get();

        if ($principals->isEmpty()) {
            return [];
        }

        $branches = Branch::query()->withoutGlobalScopes()
            ->whereIn('id', $principals->pluck('principal_branch_id')->unique()->all())
            ->get()->keyBy('id');

        $bn = app()->getLocale() === 'bn';
        $rows = [];

        foreach ($principals as $p) {
            $start = (int) $p->cycle_start_day;
            $close = (int) $p->cycle_close_day;

            [$from, $to] = $month !== null
                ? self::cycleClosingIn($start, $close, $month)
                : self::cycleContaining($start, $close, $today);

            $fromDate = $from->toDateString();
            $toDate = $to->toDateString();
            $rate = bcadd((string) $p->commission_rate, '0', 3);

            $inflow = $this->collections((int) $f['company_id'], (int) $p->principal_branch_id, $fromDate, $toDate);
            $commission = self::commission($inflow, (string) $p->commission_basis, $rate);
            $share = bcsub(Money::round($inflow, 2), $commission, 2);
            $paid = Money::round($this->paidTo((int) $f['company_id'], (int) $p->id, $fromDate, $toDate), 2);

            $rows[] = [
                'supplier_id' => (int) $p->id,
                // ⭐ সংক্ষিপ্ত নাম, না থাকলে নাম — কোড নয় (মালিক, ৬ অক্টোবর ২০২৬: *"SUP-0002 = code দেওয়ার দরকার নাই"*)
                'supplier_name' => self::shortName($p->short_name, $p->name_bn, (string) $p->name_en, $bn),
                'party_type_literal' => Supplier::drillSourceType(),
                'branch_name' => $branches->get($p->principal_branch_id)?->name() ?? '—',
                'period' => DateFormat::format($from).' – '.DateFormat::format($to),
                'period_from' => $fromDate,
                'period_to' => $toDate,
                'inflow' => Money::round($inflow, 2),
                'basis_rate' => __('supplier::principal.basis_'.$p->commission_basis).' '.self::rate($rate).'%',
                'commission' => $commission,
                'share' => $share,
                'paid' => $paid,
                'balance' => bcsub($share, $paid, 2),
            ];
        }

        return $rows;
    }

    /**
     * প্রিন্সিপাল কোন নামে — সংক্ষিপ্ত নাম ("Star Line"), না থাকলে বাংলা নাম, তাও না থাকলে ইংরেজি। ⛔ কোড কখনো নয়।
     */
    public static function shortName(?string $short, ?string $bn, string $en, bool $bangla = true): string
    {
        if (filled($short)) {
            return trim((string) $short);
        }

        return $bangla && filled($bn) ? (string) $bn : $en;
    }

    /**
     * চক্রের সময়কাল, এ পর্যন্ত — "26/09/2026 – আজ পর্যন্ত"; অঙ্কগুলো চক্রের শুরু থেকে আজ পর্যন্তই।
     *
     * ⓘ আজ চক্রের বাইরে (দুই চক্রের মাঝের ফাঁকে, বা মাস বেছে পুরনো চক্র) হলে পুরো সময়কালই — "আজ পর্যন্ত" তখন মিথ্যা।
     */
    public static function soFar(string $from, string $to, ?Carbon $today = null): string
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if ($today->betweenIncluded(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay())) {
            return (string) __('supplier::principal.so_far', ['from' => DateFormat::format($from)]);
        }

        return DateFormat::format($from).' – '.DateFormat::format($to);
    }

    /** হার পড়ার রূপে — ৩.৮৫০ → ৩.৮৫, ৪.০০০ → ৪ */
    private static function rate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
