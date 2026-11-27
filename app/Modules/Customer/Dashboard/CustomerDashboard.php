<?php

declare(strict_types=1);

namespace App\Modules\Customer\Dashboard;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * গ্রাহক মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে টাকার সংখ্যা নেই ───────────────────────────────────────
 * গ্রাহকের বকেয়া বিক্রয়ের প্রশ্ন, আর সেটা বিক্রয়ের পর্দায় আছে। এখানে
 * টেনে আনলে Customer মডিউলকে Sales-এর উপর দাঁড়াতে হত — ঠিক যে চক্রটা
 * [[Customer]] মডেলে একবার ভেঙে সরানো হয়েছিল (`lastPurchaseOn()`)।
 *
 * ⓘ ৫ অক্টোবর ২০২৬: নতুন ড্যাশবোর্ডে বিক্রির অঙ্ক এসেছে, কিন্তু চক্রটা ফেরেনি — কোরের চুক্তি
 * ([[CustomerSalesFilters]]) দিয়ে, যেটা বিক্রয় বাস্তবায়ন করে ([[CustomerTradeCharts]])।
 *
 * এই পর্দার প্রশ্ন তাই আলাদা: **তালিকাটা সুস্থ আছে তো** — কতজন সচল,
 * কতজন নিষ্ক্রিয়, আর নতুন কারা এলেন।
 */
final class CustomerDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        return new DashboardDefinition(
            title: __('customer::dashboard.title'),
            subtitle: __('customer::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('customer::action.new'), href: route('customer.create'),
                    permission: 'customer.create', icon: 'plus'),
                new Tile(label: __('customer::menu.customers'), href: route('customer.index'),
                    permission: 'customer.view', icon: 'reports'),
            ],

            stats: [
                new Stat(
                    label: __('customer::dashboard.total'),
                    value: (string) Customer::query()->inViewedBranch()->count(),
                    hint: __('customer::dashboard.total_hint'),
                    href: route('customer.index'),
                ),
                new Stat(
                    label: __('customer::dashboard.active'),
                    value: (string) Customer::query()->inViewedBranch()->where('is_active', true)->count(),
                    hint: __('customer::dashboard.active_hint'),
                    href: route('customer.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('customer::dashboard.inactive'),
                    value: (string) Customer::query()->inViewedBranch()->where('is_active', false)->count(),
                    hint: __('customer::dashboard.inactive_hint'),
                    href: route('customer.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('customer::dashboard.new_this_month'),
                    value: (string) Customer::query()->inViewedBranch()->where('created_at', '>=', $month)->count(),
                    hint: __('customer::dashboard.new_hint'),
                    href: route('customer.index'),
                ),
                // ⭐ নতুন ড্যাশবোর্ডে বিক্রি, গড় বিল আর মেয়াদ পেরোনো বাকি — বিক্রয়ের চুক্তি দিয়ে ([[CustomerTradeCharts]], ৫ অক্টোবর ২০২৬)
                ...CustomerTradeCharts::stats(),
            ],

            panels: [...self::growth(), ...self::ageing(), ...CustomerTradeCharts::panels()],

            listings: [
                // ⭐ এ মাসের সবচেয়ে বড় পাঁচ ক্রেতা, বকেয়াসহ — নতুন ড্যাশবোর্ডে (৫ অক্টোবর ২০২৬)
                ...CustomerTradeCharts::listings(),
                new Listing(
                    label: __('customer::dashboard.newest'),
                    columns: [
                        ['key' => 'code', 'label' => __('customer::field.code'), 'width' => '7rem',
                            'render' => fn ($c) => $c->code],
                        ['key' => 'name', 'label' => __('customer::field.name'),
                            'render' => fn ($c) => $c->name()],
                        ['key' => 'phone', 'label' => __('customer::field.phone'), 'width' => '9rem',
                            'render' => fn ($c) => $c->phone ?? '—'],
                    ],
                    rows: Customer::query()->inViewedBranch()->latest('id')->limit(8)->get(),
                    empty: __('customer::dashboard.none'),
                    href: route('customer.index'),
                ),
            ],

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "ok"); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            // ⓘ মাসে মাসে নতুন আর তাঁদের কতজন এখনো সচল — বোর্ডের কলাম কেবল মাসের শেষে মোট দেখায়, তাই নিচে থাকে (নিরীক্ষা, ২৩ নভেম্বর ২০২৬)
            sections: [Card::board(fn (): array => self::charts(), covers: [
                __('customer::dashboard.total'), __('customer::dashboard.active'), __('customer::dashboard.inactive'),
                __('customer::dashboard.new_this_month'), __('customer::dashboard.ageing'),
                __('customer::dashboard.segments'),
            ])],
        );
    }

    /**
     * ⭐ গ্রাহকের গ্রাফের বোর্ড — গ্রাহক বৃদ্ধি (কলাম), অবস্থা (ওয়াফল), বাকির বয়স (ডোনাট), এলাকা × ধরন (হিটম্যাপ),
     *   সবচেয়ে বেশি বকেয়া (লোলিপপ), ফিরে আসা গ্রাহক (কাঁটা-মিটার)। সবই দেখার শাখার গ্রাহক ([[Customer::scopeInViewedBranch()]]);
     *   ডিলারের দেয়াল মডেলের নিজের স্কোপে। ⛔ টাকার আর বিক্রির গ্রাফ কেবল `customer.report`-এ — নিচের বয়সের চার্টের একই চাবি।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(): array
    {
        $today = Carbon::today();
        $money = (bool) auth()->user()?->can('customer.report');
        $cards = [];

        // ── গ্রাহক বৃদ্ধি — প্রতি মাসের শেষে মোট গ্রাহক, গত ছয় মাস ──────────────────
        $start = $today->copy()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(created_at, '%Y-%m')";
        $added = Customer::query()->inViewedBranch()->where('created_at', '>=', $start)
            ->selectRaw("{$expr} as ym, COUNT(*) as n")->groupByRaw($expr)->toBase()->pluck('n', 'ym');
        $running = Customer::query()->inViewedBranch()->where('created_at', '<', $start)->count();
        $totals = [];
        $months = [];
        for ($month = $start->copy(); $month->lessThanOrEqualTo($today); $month->addMonth()) {
            $running += (int) ($added[$month->format('Y-m')] ?? 0);
            $totals[] = $running;
            $months[] = $month->translatedFormat('M');
        }
        $cards[] = Card::make(__('customer::dashboard.viz_growth'), 'bars', [
            'v' => $totals, 'l' => $months, 'top' => array_map(fn (int $n) => number_format($n), $totals),
            'colors' => [...array_fill(0, max(count($totals) - 1, 0), '#93c5fd'), '#2160d8'],
        ], value: __('customer::dashboard.viz_people', ['n' => number_format($running)]),
            sub: __('customer::dashboard.viz_growth_sub'), href: route('customer.index'), colour: '#2160d8');

        // ── অবস্থা — সচল (বাকি খোলা), সচল কিন্তু বাকি বন্ধ, নিষ্ক্রিয়; যোগফল মোট গ্রাহক ──────
        // ⓘ মাথায় আগের "সচল" ঘরের সংখ্যা (চালু সবাই, বাকি বন্ধসহ) — তাই ঘরের নাম বলে কোন সচল (নিরীক্ষা, ২৩ নভেম্বর ২০২৬)
        $state = Customer::query()->inViewedBranch()
            ->selectRaw('SUM(CASE WHEN customers.is_active = 1 AND customers.credit_blocked_at IS NULL THEN 1 ELSE 0 END) as on_,'
                .' SUM(CASE WHEN customers.is_active = 0 THEN 1 ELSE 0 END) as off_,'
                .' SUM(CASE WHEN customers.is_active = 1 AND customers.credit_blocked_at IS NOT NULL THEN 1 ELSE 0 END) as blocked_')
            ->toBase()->first();
        $parts = [
            [__('customer::dashboard.viz_active_open'), (int) ($state->on_ ?? 0)],
            [__('customer::dashboard.inactive'), (int) ($state->off_ ?? 0)],
            [__('customer::dashboard.viz_blocked'), (int) ($state->blocked_ ?? 0)],
        ];
        $active = (int) ($state->on_ ?? 0) + (int) ($state->blocked_ ?? 0);
        $cards[] = Card::make(__('customer::dashboard.viz_state'), 'waffle', [
            'p' => array_map(fn (array $p) => [$p[0], $p[1], number_format($p[1])], $parts),
        ], value: __('customer::dashboard.viz_active_n', ['n' => number_format($active)]),
            sub: __('customer::dashboard.viz_state_sub'), href: route('customer.index'), colour: '#16a34a');

        // ── বাকির বয়স — বয়স রিপোর্টের যোগফল (নিচের পুরনো চার্টের একই উৎস) ──────────────
        $ageing = $money ? self::ageingTotals() : null;
        if ($ageing !== null) {
            $buckets = ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90'];
            $outstanding = (string) ($ageing['outstanding'] ?? '0');
            $cards[] = Card::make(__('customer::dashboard.viz_ageing'), 'donut', [
                'c' => self::short($outstanding), 'cs' => __('customer::dashboard.viz_outstanding'),
                'p' => array_map(fn (string $b, int $i) => [
                    __('customer::dashboard.viz_'.$b), (string) ($ageing[$b] ?? '0'), Svg::STATUS[$i], self::short((string) ($ageing[$b] ?? '0')),
                ], $buckets, array_keys($buckets)),
            ], value: bccomp($outstanding, '0', 4) === 0 ? '' : self::short($outstanding), sub: __('customer::dashboard.viz_ageing_sub'),
                href: route('customer.report.show', ['slug' => 'ageing']), colour: '#f97316');
        }

        // ── এলাকা × ধরন — সচল গ্রাহক, এরিয়া ধরে (জায়গার মই বেয়ে উপরে) ────────────────
        $cards[] = self::areaHeat();

        // ── সবচেয়ে বেশি বকেয়া — গ্রাহক তালিকার "বকেয়া" ঘরের একই হিসাব (দেখার শাখার খাতা) ─────
        if ($money) {
            $top = Customer::query()->inViewedBranch()->withOutstandingInView()
                ->orderByDesc('outstanding_in_view')->limit(5)->get()
                ->filter(fn (Customer $c) => bccomp((string) ($c->getAttribute('outstanding_in_view') ?? '0'), '0', 4) > 0)->values();
            $cards[] = Card::make(__('customer::dashboard.viz_top_due'), 'lollipop', [
                'v' => $top->map(fn (Customer $c) => (string) $c->getAttribute('outstanding_in_view'))->all(),
                'l' => $top->map(fn (Customer $c) => Str::limit($c->name(), 16))->all(),
                'top' => $top->map(fn (Customer $c) => self::short((string) $c->getAttribute('outstanding_in_view')))->all(),
            ], sub: __('customer::dashboard.viz_top_due_sub'), href: route('customer.index'), colour: '#e11d48');
        }

        // ── ফিরে আসা — গত মাসে কিনেছেন, এ মাসেও কিনলেন (বিক্রয়ের চুক্তি; বিক্রয় না থাকলে খালি) ──────
        if ($money) {
            $sales = app(CustomerSalesFilters::class);
            $lastFrom = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
            $lastTo = $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
            $last = $sales->boughtBetween(Customer::query()->inViewedBranch(), $lastFrom, $lastTo)->count();
            $back = $last === 0 ? 0 : $sales->boughtBetween(
                $sales->boughtBetween(Customer::query()->inViewedBranch(), $lastFrom, $lastTo),
                $today->copy()->startOfMonth()->toDateString(), $today->toDateString(),
            )->count();
            $rate = $last > 0 ? $back / $last : null;
            $cards[] = Card::make(__('customer::dashboard.viz_returning'), 'gauge', $rate === null ? [] : [
                'v' => $rate, 'label' => round($rate * 100).'%',
                'sub' => __('customer::dashboard.viz_returning_of', ['n' => number_format($back), 'of' => number_format($last)]),
            ], value: $rate === null ? '' : round($rate * 100).'%', sub: __('customer::dashboard.viz_returning_sub'), colour: '#14b8a6');
        }

        return $cards;
    }

    /**
     * এলাকা × গ্রাহকের ধরন — সচল গ্রাহক; এরিয়া জায়গার মই বেয়ে ([[Customer::area()]]-এর একই নিয়ম), এক কোয়েরিতে সব জায়গা।
     * বড় পাঁচ এরিয়া আর বড় চার ধরন, বাকি "অন্যান্য" — যোগফল সচল গ্রাহকের সমান থাকে।
     *
     * @return array<string, mixed>
     */
    private static function areaHeat(): array
    {
        $rows = Customer::query()->inViewedBranch()->where('customers.is_active', true)
            ->selectRaw('customers.location_id as loc, customers.party_type_id as pt, COUNT(*) as n')
            ->groupBy('customers.location_id', 'customers.party_type_id')->toBase()->get();

        $places = Location::query()->get(['id', 'parent_id', 'level', 'name_en', 'name_bn'])->keyBy('id');
        $none = __('customer::dashboard.viz_no_area');
        $areaOf = function ($id) use ($places, $none): string {
            $node = $id === null ? null : ($places[(int) $id] ?? null);
            for ($depth = 0; $node !== null && $depth < 10; $depth++) {
                if ($node->level === Location::AREA) {
                    return $node->name();
                }
                $node = $node->parent_id === null ? null : ($places[(int) $node->parent_id] ?? null);
            }

            return $none;
        };
        $types = PartyType::query()->whereIn('id', $rows->pluck('pt')->filter()->all())->get()
            ->mapWithKeys(fn (PartyType $t) => [$t->id => $t->name()]);

        $grid = [];
        foreach ($rows as $r) {
            $area = $areaOf($r->loc);
            $type = $r->pt === null ? __('customer::dashboard.segments_none') : (string) ($types[$r->pt] ?? __('customer::dashboard.segments_none'));
            $grid[$area][$type] = ($grid[$area][$type] ?? 0) + (int) $r->n;
        }

        $other = __('customer::dashboard.viz_other');
        $areaTotals = array_map('array_sum', $grid);
        arsort($areaTotals);
        $typeTotals = [];
        foreach ($grid as $byType) {
            foreach ($byType as $type => $n) {
                $typeTotals[$type] = ($typeTotals[$type] ?? 0) + $n;
            }
        }
        arsort($typeTotals);
        $areas = array_slice(array_keys($areaTotals), 0, 5);
        $cols = array_slice(array_keys($typeTotals), 0, 4);
        $moreAreas = count($areaTotals) > 5;
        $moreTypes = count($typeTotals) > 4;

        $v = [];
        foreach ([...$areas, ...($moreAreas ? [$other] : [])] as $i => $area) {
            $source = $area === $other && $moreAreas ? array_slice(array_keys($areaTotals), 5) : [$area];
            foreach ([...$cols, ...($moreTypes ? [$other] : [])] as $j => $type) {
                $typesHere = $type === $other && $moreTypes ? array_slice(array_keys($typeTotals), 4) : [$type];
                $sum = 0;
                foreach ($source as $a) {
                    foreach ($typesHere as $t) {
                        $sum += $grid[$a][$t] ?? 0;
                    }
                }
                $v[$i][$j] = $sum;
            }
        }

        return Card::make(__('customer::dashboard.viz_area'), 'heat', $grid === [] ? [] : [
            'rows' => array_map(fn ($a) => Str::limit((string) $a, 10), [...$areas, ...($moreAreas ? [$other] : [])]),
            'cols' => array_map(fn ($t) => Str::limit((string) $t, 14), [...$cols, ...($moreTypes ? [$other] : [])]),
            'v' => $v,
        ], sub: __('customer::dashboard.viz_area_sub'), href: route('customer.index'), colour: '#7c3aed');
    }

    /**
     * বয়স রিপোর্টের পুরো ফলের যোগফল — নিচের বয়সের চার্ট আর বোর্ডের ডোনাটের একটাই উৎস।
     *
     * @return array<string, mixed>
     */
    private static function ageingTotals(): array
    {
        return app(\App\Core\Engines\Report\ReportEngine::class)
            ->run('customer.ageing', ['to' => Carbon::today()->toDateString(), 'branch_id' => \App\Core\Support\ViewedBranch::one()], 1, 1)
            ->totals;
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক, bcmath */
    private static function short(string $amount): string
    {
        $amount = $amount === '' ? '0' : $amount;
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        return match (true) {
            bccomp($a, '10000000', 4) >= 0 => $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('customer::dashboard.crore'),
            bccomp($a, '100000', 4) >= 0 => $sign.'৳'.bcdiv($a, '100000', 1).' '.__('customer::dashboard.lakh'),
            bccomp($a, '1000', 4) >= 0 => $sign.'৳'.bcdiv($a, '1000', 0).'k',
            default => $sign.'৳'.bcadd($a, '0', 0),
        };
    }

    /**
     * ⭐ পাওনা-এর বয়স — চলতি, ৩০, ৬০, ৯০+ দিন (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ নিজের হিসাব নয়: "বয়স" রিপোর্টের ([[customer.ageing]]) পুরো ফলের যোগফল — রিপোর্ট আর চার্ট কখনো দুই কথা বলে না,
     * আর রিপোর্টের শাখার দেয়াল ([[ReportEngine::branchWall()]]) এখানেও খাটে; হেডারে বাছা শাখা থাকলে সেটাই।
     * ⛔ রিপোর্টের নিজের চাবি (`customer.report`) ছাড়া চার্টই নেই — রিপোর্ট যেখানে বন্ধ, ড্যাশবোর্ডেও বন্ধ।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function ageing(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('customer.report')) {
            return [];
        }

        $totals = self::ageingTotals();

        return [new Breakdown(
            label: __('customer::dashboard.ageing'),
            parts: array_map(fn (string $bucket) => [
                'label' => __('customer::field.'.$bucket),
                'value' => \App\Core\Support\Money::format($totals[$bucket] ?? '0'),
            ], ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']),
            hint: __('customer::dashboard.ageing_hint', ['total' => \App\Core\Support\Money::format($totals['outstanding'] ?? '0')]),
            // ⓘ বকেয়ার বয়স — বয়সের ধাপ ধরে খাড়া স্তম্ভ (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph")
            chart: 'columns',
        )];
    }

    /**
     * ⭐ গ্রাহক বৃদ্ধি — গত ছয় মাসে কতজন যোগ হলেন, আর তাঁদের কতজন এখনো চালু (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ উপরের "এ মাসে নতুন" সংখ্যার একই ভিত (দেখার শাখার গ্রাহক, যোগ হওয়ার দিন) — এ মাসের দণ্ড আর ঐ সংখ্যা এক।
     * ⓘ দুই দণ্ড একই মাপের (মানুষ): দ্বিতীয়টা প্রথমটার ভেতরের ভাগ, তাই ফাঁকটা বলে কতজন যোগ হয়েই বন্ধ হলেন।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<\App\Core\Engines\Dashboard\Series>
     */
    private static function growth(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $start = \Illuminate\Support\Carbon::today()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(created_at, '%Y-%m')";

        $rows = Customer::query()->inViewedBranch()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$expr} as ym, COUNT(*) as added, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as still_on")
            ->groupByRaw($expr)
            ->toBase()->get()->keyBy('ym');

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(\Illuminate\Support\Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => (string) (int) ($row->added ?? 0),
                'second' => (string) (int) ($row->still_on ?? 0),
            ];
        }

        return [new \App\Core\Engines\Dashboard\Series(
            label: __('customer::dashboard.growth'),
            points: $points,
            firstLabel: __('customer::dashboard.growth_added'),
            secondLabel: __('customer::dashboard.growth_still_on'),
            // ⓘ কবে থেকে কবে — মালিক, ৫ অক্টোবর ২০২৬: প্রতিটা চার্টে তারিখ
            range: \App\Core\Engines\Dashboard\DateRange::label($start, \Illuminate\Support\Carbon::today()),
        )];
    }
}
