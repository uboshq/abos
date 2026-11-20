<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\AssetVerification;
use App\Modules\Accounts\Models\AssetVerificationLine;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ⭐ স্থায়ী সম্পদের এক পাতা — মোট দাম, সঞ্চিত ক্ষয়, খাতার দাম, এ মাসের অবচয়, অবস্থা ধরে, আর শেষ হতে চলা ওয়ারেন্টি-বিমা
 * (স্থায়ী সম্পদ ধাপ ৫; ড্যাশবোর্ড ইঞ্জিনের ইট দিয়ে — [[Stat]], [[Breakdown]], [[Listing]], [[Tile]])।
 *
 * ⓘ সংখ্যাগুলো সম্পদের মডেল থেকে, তাই শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]]) — শাখায় সীমিত মানুষ নিজের
 * শাখার অঙ্ক দেখেন। প্রতিটা সংখ্যা তার প্রতিবেদনে খোলে।
 *
 * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "fa"): দাম → খাতার দাম (কলাম), ১২ মাসের অবচয় (রেখা),
 * অবস্থা ধরে (ডোনাট), শ্রেণি ধরে খাতার দাম (আড়াআড়ি কলাম), ওয়ারেন্টি-বিমা কবে শেষ (সময়রেখা), শেষ গণনা (কাঁটা-মিটার)। আগের চার
 * সংখ্যা, অবস্থার চার্ট আর শেষ হওয়ার তালিকা এখন গ্রাফে; টাইলগুলো (তালিকা, গোনা, করের অবচয়, খাতার যাচাই) নিচে।
 */
final class FixedAssetDashboard
{
    /** ⓘ কত দিনের মধ্যে শেষ হলে "আসছে" — মালিকের প্রশ্ন, আপাতত ৬০ দিন */
    public const EXPIRY_DAYS = 60;

    /** ⓘ গ্রাফের সময়রেখা কত মাস সামনে দেখে — নমুনার "আগামী 6 মাস" */
    private const TIMELINE_MONTHS = 6;

    public static function dashboard(): DashboardDefinition
    {
        $inBooks = FixedAsset::query()->inService()->with('category')->get();
        // ⓘ প্রতিটা সম্পদের সঞ্চিত ক্ষয় একবারই পড়া — মোট আর শ্রেণির ভাগ দুইটাই এখান থেকে, তাই দুই অঙ্ক কখনো আলাদা নয়
        $accumulatedOf = $inBooks->mapWithKeys(fn (FixedAsset $a) => [$a->id => $a->accumulated()]);
        $cost = Money::sumOf($inBooks, fn (FixedAsset $a) => (string) $a->cost);
        $accumulated = Money::sumOf($inBooks, fn (FixedAsset $a) => $accumulatedOf[$a->id]);

        $month = Carbon::today()->endOfMonth()->toDateString();
        $thisMonth = Money::of((string) (DepreciationEntry::query()
            ->whereIn('fixed_asset_id', FixedAsset::query()->select('id'))
            ->where('period_end', $month)->sum('amount') ?: '0'));

        $report = fn (string $slug) => route('accounts.report.show', ['slug' => $slug]);

        $byStatus = FixedAsset::query()->where('status', '!=', FixedAsset::AWAITING)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $expiringLabel = __('accounts::asset_report.expiring_soon', ['days' => self::EXPIRY_DAYS]);

        return new DashboardDefinition(
            title: __('accounts::asset_report.dashboard'),
            subtitle: __('accounts::asset_report.dashboard_hint'),
            stats: [
                new Stat(label: __('accounts::asset_report.total_cost'), value: Money::format($cost),
                    hint: __('accounts::asset_report.total_cost_hint', ['count' => $inBooks->count()]), href: $report('asset-register'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.accumulated'), value: Money::format($accumulated),
                    hint: __('accounts::asset_report.accumulated_hint'), href: $report('asset-movement'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.nbv'), value: Money::format(bcsub($cost, $accumulated, 4)),
                    hint: __('accounts::asset_report.nbv_hint'), href: $report('asset-nbv'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.this_month'), value: Money::format($thisMonth),
                    hint: __('accounts::asset_report.this_month_hint'), href: $report('asset-depreciation'), permission: 'accounts.report'),
            ],
            panels: [
                new Breakdown(
                    label: __('accounts::asset_report.by_status'),
                    parts: collect(FixedAsset::STATUSES)->reject(fn ($s) => $s === FixedAsset::AWAITING)
                        ->map(fn ($s) => ['label' => __('accounts::asset.status_'.$s), 'value' => (string) (int) ($byStatus[$s] ?? 0)])
                        ->values()->all(),
                    hint: __('accounts::asset_report.by_status_hint'),
                ),
            ],
            listings: [
                new Listing(
                    label: $expiringLabel,
                    columns: [
                        ['key' => 'ends_on', 'label' => __('accounts::asset_report.ends_on'), 'width' => '8rem', 'render' => fn ($r) => $r['ends_on']],
                        ['key' => 'what', 'label' => __('accounts::asset_report.what'), 'width' => '7rem', 'render' => fn ($r) => $r['what']],
                        ['key' => 'name', 'label' => __('accounts::asset_report.name'), 'render' => fn ($r) => $r['name']],
                    ],
                    rows: self::expiring(),
                    empty: __('accounts::asset_report.nothing_expiring'),
                    href: $report('asset-expiring'),
                ),
            ],
            tiles: [
                new Tile(label: __('accounts::menu.assets'), href: route('accounts.asset.index'), permission: 'accounts.asset.view'),
                new Tile(label: __('accounts::menu.asset_verifications'), href: route('accounts.asset.verify.index'), permission: 'accounts.asset.view'),
                new Tile(label: __('accounts::asset_report.tax_page'), href: route('accounts.asset.tax'), permission: 'accounts.asset.manage'),
                new Tile(label: __('accounts::menu.books_check'), href: route('accounts.integrity'), permission: 'accounts.report'),
            ],
            // ⭐ গ্রাফের বোর্ড — যে সংখ্যা-চার্ট-তালিকা গ্রাফে এল, পাতায় আর আলাদা নয় ([[DashboardDefinition::forPage()]])
            sections: [Card::board(
                fn (): array => self::charts($inBooks, $accumulatedOf, $cost, $accumulated, $thisMonth, $byStatus->all(), $report),
                covers: [
                    __('accounts::asset_report.total_cost'), __('accounts::asset_report.accumulated'), __('accounts::asset_report.nbv'),
                    __('accounts::asset_report.this_month'), __('accounts::asset_report.by_status'), $expiringLabel,
                ],
            )],
        );
    }

    /**
     * ⭐ স্থায়ী সম্পদের গ্রাফের বোর্ড — উপরের একই অঙ্ক (একবার পড়া); টাকার গ্রাফ কেবল `accounts.report` চাবিতে (আগের চার সংখ্যার চাবি)।
     * ⓘ টাকা স্ট্রিং হয়ে যায়; ছোট লেখায় (৳ 1.12 কো) কেবল পূর্ণ টাকার পূর্ণসংখ্যা — float নয়।
     *
     * @param  Collection<int, FixedAsset>  $inBooks
     * @param  Collection<int, string>  $accumulatedOf
     * @param  array<string, int|string>  $byStatus
     * @return list<array<string, mixed>>
     */
    private static function charts(Collection $inBooks, Collection $accumulatedOf, string $cost, string $accumulated, string $thisMonth, array $byStatus, \Closure $report): array
    {
        $money = (bool) auth()->user()?->can('accounts.report');
        $short = fn (string $amount): string => (bccomp($amount, '0', 4) < 0 ? '−' : '').'৳ '.Svg::short((int) ltrim(Money::round($amount, 0), '-'));
        // ⓘ পুরো টাকা (পূর্ণ টাকায়, লাখ-কমায়) — আগের সংখ্যার ঘর যে অঙ্ক দেখাত
        $exact = fn (string $amount): string => (bccomp($amount, '0', 4) < 0 ? '−' : '').'৳ '.Money::format(ltrim(Money::round($amount, 0), '-'), 0);
        $t = fn (string $key, array $with = []): string => (string) __('accounts::asset_report.'.$key, $with);
        $nbv = bcsub($cost, $accumulated, 4);

        // ── অবচয়, ১২ মাস — উপরের "এ মাসের অবচয়"-এর একই সারি (মাসের শেষ দিন ধরে), এ মাসসহ
        $start = Carbon::today()->startOfMonth()->subMonths(11);
        $expr = "DATE_FORMAT(period_end, '%Y-%m')";
        $byMonth = $money ? DepreciationEntry::query()
            ->whereIn('fixed_asset_id', FixedAsset::query()->select('id'))
            ->where('period_end', '>=', $start->toDateString())->where('period_end', '<=', Carbon::today()->endOfMonth()->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(amount), 0) as amount")->groupByRaw($expr)
            ->toBase()->pluck('amount', 'ym') : collect();
        $depreciation = [];
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $depreciation[] = (string) ($byMonth[$m->format('Y-m')] ?? '0');
            $months[] = $i % 3 === 0 || $i === 11 ? $m->translatedFormat('M') : '';
        }

        // ── শ্রেণি ধরে খাতার দাম — বড় চারটা, বাকি এক ঘরে
        $byCategory = [];
        foreach ($inBooks as $asset) {
            $name = $asset->category?->name() ?? $t('no_category');
            $byCategory[$name] = bcadd($byCategory[$name] ?? '0', bcsub((string) $asset->cost, $accumulatedOf[$asset->id], 4), 4);
        }
        uasort($byCategory, fn (string $a, string $b) => bccomp($b, $a, 4));
        $categories = array_slice($byCategory, 0, 4, true);
        if (count($byCategory) > 4) {
            $categories[$t('viz_others')] = array_reduce(array_slice($byCategory, 4), fn (string $s, string $v) => bcadd($s, $v, 4), '0');
        }
        $categories = array_filter($categories, fn (string $v) => bccomp($v, '0', 4) > 0);

        // ── অবস্থা ধরে — আগের চার্টের একই গোনা (অনুমোদনের অপেক্ষার সম্পদ বাদ)
        $colours = [FixedAsset::ACTIVE => Svg::STATUS[0], FixedAsset::UNDER_REPAIR => '#f59e0b', FixedAsset::IDLE => Svg::PALETTE[2],
            FixedAsset::DISPOSED => '#94a3b8', FixedAsset::WRITTEN_OFF => Svg::STATUS[2], FixedAsset::LOST => Svg::STATUS[3]];
        $statusParts = [];
        foreach ($colours as $status => $colour) {
            $n = (int) ($byStatus[$status] ?? 0);
            $statusParts[] = [(string) __('accounts::asset.status_'.$status), $n, $colour, (string) $n];
        }
        $assetCount = array_sum(array_column($statusParts, 1));

        // ── ওয়ারেন্টি ও বিমা — আগামী ছয় মাসে যা শেষ, যেটা আগে শেষ সেটা উপরে; ৬০ দিনের ভিতরেরগুলো লাল (আগের তালিকার সীমা)
        $today = Carbon::today();
        $until = $today->copy()->addMonths(self::TIMELINE_MONTHS);
        $span = max(1, (int) $today->diffInDays($until));
        $ends = collect();
        foreach (['warranty_ends_on' => 'warranty', 'insured_until' => 'insurance'] as $column => $what) {
            FixedAsset::query()->inService()->whereBetween($column, [$today->toDateString(), $until->toDateString()])
                ->orderBy($column)->limit(6)->get()
                ->each(fn (FixedAsset $a) => $ends->push(['name' => Str::limit($a->name.' · '.$t($what), 13), 'full' => $a->name.' · '.$t($what).' · '.$a->{$column}->format('d M Y'), 'on' => $a->{$column}]));
        }
        $timeline = $ends->sortBy('on')->take(6)->map(function (array $e) use ($today, $span) {
            $days = (int) $today->diffInDays($e['on']);

            // ⓘ পুরো নাম আর তারিখ দাগের উপর রাখলে — আগের তালিকার মতো (নাম ছোট করায় হারায় না)
            return [$e['name'], 0, $days / $span, $days <= self::EXPIRY_DAYS ? Svg::STATUS[3] : null, $e['full']];
        })->values()->all();
        $ticks = [];
        for ($i = 0; $i < self::TIMELINE_MONTHS; $i++) {
            $ticks[] = $today->copy()->addMonths($i)->translatedFormat('M');
        }

        // ── শেষ গণনা — বন্ধ হওয়া শেষ অভিযানের সারি: কতগুলো পাওয়া গেল (অভিযানের মডেল নিজেই শাখা মানে)
        $count = AssetVerification::query()->where('status', AssetVerification::CLOSED)->latest('closed_on')->latest('id')->first();
        $results = $count === null ? collect() : AssetVerificationLine::query()->where('verification_id', $count->id)
            ->selectRaw('result, COUNT(*) as n')->groupBy('result')->toBase()->pluck('n', 'result');
        $lines = (int) $results->sum();
        $found = (int) ($results[AssetVerificationLine::FOUND] ?? 0);
        $foundShare = $lines > 0 ? $found / $lines : null;

        return [
            Card::make($t('viz_value'), 'bars', $money ? [
                'v' => [$cost, $accumulated, $nbv],
                'l' => [$t('total_cost'), $t('accumulated'), $t('nbv')],
                // ⓘ পুরো টাকা — আগের তিন সংখ্যার ঘর (মোট খরচ, জমা অবচয়, বইয়ের মূল্য) এই গ্রাফে এসেছে, পয়সা ছাড়া অঙ্ক হারায় না
                'top' => [$exact($cost), $exact(bcmul($accumulated, '-1', 4)), $exact($nbv)],
                'colors' => ['#5b6b86', Svg::STATUS[3], Svg::PALETTE[0]],
            ] : [], value: $money ? $exact($nbv) : '', sub: $t('viz_value_sub', ['count' => $inBooks->count()]), href: $report('asset-nbv'), colour: Svg::PALETTE[0]),
            Card::make($t('viz_depreciation'), 'line', $money ? ['v' => $depreciation, 'l' => $months, 'color' => Svg::PALETTE[1]] : [],
                value: $money ? $exact($thisMonth) : '', sub: $t('viz_depreciation_sub'), href: $report('asset-depreciation'), colour: Svg::PALETTE[1]),
            Card::make($t('by_status'), 'donut', ['c' => (string) $assetCount, 'cs' => $t('viz_assets'), 'p' => $statusParts],
                value: $assetCount > 0 ? $t('viz_count', ['n' => $assetCount]) : '', sub: $t('by_status_hint'), href: route('accounts.asset.index'), colour: Svg::STATUS[0]),
            Card::make($t('viz_by_category'), 'hbars', $money ? [
                'v' => array_values($categories), 'l' => array_map(fn ($n) => Str::limit((string) $n, 12), array_keys($categories)),
                'top' => array_map($short, array_values($categories)),
            ] : [], sub: $t('viz_by_category_sub'), href: $report('asset-nbv'), colour: Svg::PALETTE[2]),
            Card::make($t('viz_ends'), 'gantt', ['ticks' => $ticks, 'today' => 0, 'p' => $timeline],
                value: $timeline === [] ? '' : $t('viz_count', ['n' => count($timeline)]), sub: $t('viz_ends_sub', ['days' => self::EXPIRY_DAYS]),
                href: $report('asset-expiring'), colour: '#f97316'),
            Card::make($t('viz_counted'), 'gauge', $foundShare === null ? [] : [
                'v' => $foundShare, 'label' => round($foundShare * 100).'%',
                'sub' => $t('viz_counted_gauge', ['found' => $found, 'of' => $lines, 'missing' => (int) ($results[AssetVerificationLine::NOT_FOUND] ?? 0)]),
            ], value: $foundShare === null ? '' : round($foundShare * 100).'%',
                sub: $count === null ? $t('viz_counted_none') : $t('viz_counted_sub', ['no' => $count->document_no, 'on' => $count->closed_on?->format('d M Y') ?? '—']),
                href: route('accounts.asset.verify.index'), colour: Svg::PALETTE[7]),
        ];
    }

    /** @return Collection<int, array{ends_on: string, what: string, name: string}> */
    private static function expiring(): Collection
    {
        $from = Carbon::today();
        $to = Carbon::today()->addDays(self::EXPIRY_DAYS);
        $rows = collect();

        foreach (['warranty_ends_on' => 'warranty', 'insured_until' => 'insurance'] as $column => $what) {
            FixedAsset::query()->inService()->whereBetween($column, [$from->toDateString(), $to->toDateString()])
                ->orderBy($column)->limit(10)->get()
                ->each(fn (FixedAsset $a) => $rows->push([
                    'ends_on' => $a->{$column}?->format('d M Y'), 'what' => __('accounts::asset_report.'.$what), 'name' => $a->name, 'sort' => $a->{$column},
                ]));
        }

        return $rows->sortBy('sort')->take(10)->values();
    }
}
