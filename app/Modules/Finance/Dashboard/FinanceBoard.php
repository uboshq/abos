<?php

declare(strict_types=1);

namespace App\Modules\Finance\Dashboard;

use App\Core\Engines\Report\ReportEngine;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Finance\Services\FundPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের অঙ্ক, সারি 2 থেকে — মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * ⓘ প্রতিটা অঙ্ক আগের হিসাব থেকেই — নগদের পূর্বাভাস ([[CashForecast]]), তহবিল ([[FundPosition]]), নগদ প্রবাহ বিবরণী
 * (হিসাবের রিপোর্ট accounts.cash_flow, IAS 7) — যাতে ড্যাশবোর্ড আর রিপোর্ট কখনো দুই কথা না বলে।
 */
final class FinanceBoard
{
    private ?array $fund = null;

    public function __construct(
        private readonly CashForecast $forecast,
        private readonly FundPosition $position,
        private readonly ReportEngine $reports,
    ) {}

    /**
     * ⭐ 13 সপ্তাহের নগদের পূর্বাভাস — আজকের তহবিল আর 13টা সপ্তাহ-শেষ; নিরাপদ সীমা = দুই সপ্তাহের গড় টাকা বেরোনো
     * (শেষ 90 দিনের দিনে গড় × 14 — প্রচলিত ন্যূনতম নগদের মাপ)।
     * ⓘ সবচেয়ে টানের সপ্তাহ = যেখানে তহবিল সবচেয়ে নিচে; তার বড় দেনাটা নামসহ।
     *
     * @return array{points: list<string>, weeks: list<array{from: string, to: string, net: string, closing: string, biggest: array{who: string, amount: string}|null}>, floor: string, low: int}
     */
    public function forecast(): array
    {
        $f = $this->forecast->weekly(13);
        $points = [$f['opening'], ...array_map(fn (array $w) => $w['closing'], $f['weeks'])];
        $low = 0;

        foreach ($f['weeks'] as $i => $w) {
            if (bccomp($w['closing'], $f['weeks'][$low]['closing'], 4) < 0) {
                $low = $i;
            }
        }

        return [
            'points' => $points,
            'weeks' => $f['weeks'],
            'floor' => bcmul($this->fund()['daily_out'], '14', 4),
            'low' => $low,
        ];
    }

    /**
     * ⭐ নগদ প্রবাহ বিবরণী · এ মাস (IAS 7) — মাসের শুরুর তহবিল → পরিচালন → বিনিয়োগ → অর্থায়ন → আজকের তহবিল।
     * ⓘ হিসাবের নিজের রিপোর্ট চালিয়ে (accounts.cash_flow) — ভাগ, খাত আর শুরু-শেষের নগদ সেখান থেকেই; প্রতিটা ভাগের বড় চার খাত নামসহ।
     * ⓘ রিপোর্টের চাবি (accounts.report.final) না থাকলে null — ঘরটা বসে না।
     *
     * @return array{opening: string, closing: string, sections: list<array{key: string, net: string, lines: list<array{name: string, net: string}>}>}|null
     */
    public function cashFlow(): ?array
    {
        if (! Gate::allows('accounts.report.final')) {
            return null;
        }

        $f = ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->toDateString()];
        $result = $this->reports->run('accounts.cash_flow', $f, 1, 100000);
        $summary = $result->report->summary === null ? ['lines' => []] : ($result->report->summary)($result->totals, $result->filters);
        $lines = array_values($summary['lines'] ?? []);

        $keys = [__('accounts::message.cf_operating') => 'operating', __('accounts::message.cf_investing') => 'investing', __('accounts::message.cf_financing') => 'financing'];
        $sections = array_map(fn (string $k) => ['key' => $k, 'net' => '0', 'lines' => []], array_values($keys));
        $at = array_flip(array_values($keys));

        foreach ($result->rows as $row) {
            $row = (array) $row;
            $i = $at[$keys[(string) ($row['section'] ?? '')] ?? 'operating'];
            $net = (string) ($row['net'] ?? '0');
            $sections[$i]['net'] = bcadd($sections[$i]['net'], $net, 4);
            $sections[$i]['lines'][] = ['name' => (string) ($row['account_name'] ?? ''), 'net' => $net];
        }

        foreach ($sections as $i => $s) {
            usort($s['lines'], fn (array $a, array $b) => bccomp(ltrim($b['net'], '-'), ltrim($a['net'], '-'), 4));
            $sections[$i]['lines'] = array_slice(array_values(array_filter($s['lines'], fn (array $l) => bccomp($l['net'], '0', 4) !== 0)), 0, 4);
        }

        return [
            'opening' => (string) ($lines[0]['value'] ?? '0'),
            'closing' => (string) ($lines[1]['value'] ?? '0'),
            'sections' => $sections,
        ];
    }

    /**
     * ⭐ সারি 6 — টাকা কোন ব্যাংকে (ব্যাংক হিসাব ধরে + MFS এক ভাগে; এক ব্যাংকে 40%-এর বেশি হলে সতর্কতা — ঝুঁকি ছড়ানোর প্রচলিত সীমা)।
     *
     * @return array{parts: list<array{name: string, value: string}>, total: string, top: ?array{name: string, pct: int}}
     */
    public function banks(): array
    {
        $parts = array_map(fn (array $b) => ['name' => $b['name'], 'value' => $b['balance']],
            array_values(array_filter(app(\App\Modules\Accounts\Services\AccountsFacts::class)->bankBalances(), fn (array $b) => bccomp($b['balance'], '0', 4) > 0)));
        $mfs = app(\App\Modules\Accounts\Services\AccountsFacts::class)->moneyPositions()['mfs'];

        if (bccomp($mfs, '0', 4) > 0) {
            $parts[] = ['name' => 'MFS', 'value' => $mfs];
        }

        usort($parts, fn (array $a, array $b) => bccomp($b['value'], $a['value'], 4));
        $total = array_reduce($parts, fn (string $c, array $p) => bcadd($c, $p['value'], 4), '0');
        $top = $parts === [] || bccomp($total, '0', 4) <= 0 ? null
            : ['name' => $parts[0]['name'], 'pct' => (int) round((float) bcdiv(bcmul($parts[0]['value'], '100', 4), $total, 4))];

        return ['parts' => $parts, 'total' => $total, 'top' => $top];
    }

    /**
     * ⭐ চেকের পাইপলাইন · 4 সপ্তাহ — হাতে থাকা পাওয়া চেক (জমা হয়নি) আর দেওয়া চেক (ভাঙানো হয়নি), চেকের তারিখের সপ্তাহ ধরে।
     * ⓘ তারিখ পেরোনো চেক প্রথম সপ্তাহে; ফেরত হার = গত 90 দিনে পাওয়া চেকের কত শতাংশ ফেরত এসেছে (সংখ্যায়)।
     *
     * @return array{weeks: list<array{in: string, out: string}>, in: string, out: string, bounce: ?string}
     */
    public function cheques(): array
    {
        $today = Carbon::today();
        $weeks = array_fill(0, 4, ['in' => '0', 'out' => '0']);
        $cheque = \App\Modules\Accounts\Models\Cheque::class;

        $cheque::query()->whereIn('status', [$cheque::PENDING, $cheque::DEPOSITED])
            ->where('cheque_date', '<=', $today->copy()->addDays(27)->toDateString())
            ->get(['direction', 'status', 'cheque_date', 'amount'])
            ->each(function ($c) use (&$weeks, $today, $cheque): void {
                $in = $c->direction === $cheque::RECEIVED;

                // ⓘ দেওয়া চেক ব্যাংকে "জমা" হয় না — কেবল ঝুলে থাকা; পাওয়া চেক জমা হলেও এখনো টাকা হয়নি
                if (! $in && $c->status !== $cheque::PENDING) {
                    return;
                }

                $day = Carbon::parse($c->cheque_date)->startOfDay();
                $i = $day->lt($today) ? 0 : min(3, intdiv((int) $today->diffInDays($day), 7));
                $weeks[$i][$in ? 'in' : 'out'] = bcadd($weeks[$i][$in ? 'in' : 'out'], (string) $c->amount, 4);
            });

        $received = $cheque::query()->where('direction', $cheque::RECEIVED)->where('cheque_date', '>=', $today->copy()->subDays(90)->toDateString())->count();
        $bounced = $cheque::query()->where('direction', $cheque::RECEIVED)->where('status', $cheque::BOUNCED)->where('cheque_date', '>=', $today->copy()->subDays(90)->toDateString())->count();

        return [
            'weeks' => $weeks,
            'in' => array_reduce($weeks, fn (string $c, array $w) => bcadd($c, $w['in'], 4), '0'),
            'out' => array_reduce($weeks, fn (string $c, array $w) => bcadd($c, $w['out'], 4), '0'),
            'bounce' => $received === 0 ? null : bcdiv((string) ($bounced * 100), (string) $received, 1),
        ];
    }

    /**
     * ⭐ সঞ্চয় ও FDR — মেয়াদের সময়রেখা: চালু সঞ্চয় মেয়াদের দিন ধরে, সামনের 12 মাসে; টাকার ওজনে গড় মুনাফার হার; 30 দিনে কী মেয়াদে।
     *
     * @return array{rows: list<array{name: string, principal: string, date: string, days: int}>, total: string, rate: ?string, soon: string, soon_name: string}
     */
    public function deposits(): array
    {
        $deposit = \App\Modules\Finance\Models\Deposit::class;

        if (! \Illuminate\Support\Facades\Schema::hasTable((new $deposit)->getTable())) {
            return ['rows' => [], 'total' => '0', 'rate' => null, 'soon' => '0', 'soon_name' => ''];
        }

        $today = Carbon::today();
        $open = $deposit::query()->open()->with('institution')->get();
        $total = $open->reduce(fn (string $c, $d) => bcadd($c, (string) $d->principal, 4), '0');
        $weighted = $open->filter(fn ($d) => $d->profit_rate !== null)->reduce(fn (string $c, $d) => bcadd($c, bcmul((string) $d->principal, (string) $d->profit_rate, 6), 6), '0');
        $weight = $open->filter(fn ($d) => $d->profit_rate !== null)->reduce(fn (string $c, $d) => bcadd($c, (string) $d->principal, 4), '0');
        // ⓘ `institution` নামে কলামও আছে (পুরনো লেখা) — সম্পর্কটা getRelation দিয়ে, নইলে লেখাটাই আসত
        $name = function ($d): string {
            $place = $d->relationLoaded('institution') ? $d->getRelation('institution') : null;

            return (string) ($place?->name() ?? $d->getAttribute('institution') ?? $d->document_no);
        };
        $rows = $open->filter(fn ($d) => $d->matures_on !== null && Carbon::parse($d->matures_on)->between($today, $today->copy()->addYear()))
            ->sortBy('matures_on')->map(fn ($d) => ['name' => $name($d), 'principal' => (string) $d->principal,
                'date' => Carbon::parse($d->matures_on)->toDateString(), 'days' => (int) $today->diffInDays(Carbon::parse($d->matures_on))])
            ->values();
        $soon = $rows->filter(fn ($r) => $r['days'] <= 30);

        return [
            'rows' => $rows->take(6)->all(),
            'total' => $total,
            'rate' => bccomp($weight, '0', 4) > 0 ? bcdiv($weighted, $weight, 1) : null,
            'soon' => $soon->reduce(fn (string $c, $r) => bcadd($c, $r['principal'], 4), '0'),
            'soon_name' => (string) ($soon->sortByDesc(fn ($r) => (float) $r['principal'])->first()['name'] ?? ''),
        ];
    }

    /**
     * ⭐ মূলধন, উত্তোলন ও লাভ বণ্টন · এ অর্থবছর — পাকা কাগজের যোগ; নিচে শেষ 5 মাসের মূলধন আসা আর উত্তোলন।
     *
     * @return array{capital: string, drawn: string, shared: string, months: list<array{month: int, in: string, out: string}>}|null
     */
    public function owners(): ?array
    {
        if (! auth()->user()?->can('finance.capital.view')) {
            return null;
        }

        $from = \App\Core\Support\FiscalYearStart::on(Carbon::today());
        $today = Carbon::today()->toDateString();
        $sum = fn (string $model) => (string) $model::query()->posted()->whereBetween('trx_date', [$from, $today])->sum('amount');
        $months = [];

        for ($i = 4; $i >= 0; $i--) {
            $m = Carbon::today()->startOfMonth()->subMonthsNoOverflow($i);
            $range = [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()];
            $months[] = [
                'month' => (int) $m->month,
                'in' => (string) \App\Modules\Finance\Models\CapitalEntry::query()->posted()->whereBetween('trx_date', $range)->sum('amount'),
                'out' => (string) \App\Modules\Finance\Models\Withdrawal::query()->posted()->whereBetween('trx_date', $range)->sum('amount'),
            ];
        }

        return [
            'capital' => $sum(\App\Modules\Finance\Models\CapitalEntry::class),
            'drawn' => $sum(\App\Modules\Finance\Models\Withdrawal::class),
            'shared' => $sum(\App\Modules\Finance\Models\ProfitShare::class),
            'months' => $months,
        ];
    }

    /** @return array<string, mixed> */
    private function fund(): array
    {
        return $this->fund ??= $this->position->on();
    }
}
