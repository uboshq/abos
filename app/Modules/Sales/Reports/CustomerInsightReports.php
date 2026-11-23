<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ গ্রাহক ধরে বিশ্লেষণ — আন্তর্জাতিক সফটওয়্যারে যা থাকে (SAP, Dynamics 365, Odoo, Salesforce), মালিক দেখতে চেয়েছেন
 * (২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ৪, ৭, ৮, ৯; অনুমোদিত নমুনা claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 *   ৪. লক্ষ্য বনাম বিক্রয় — এরিয়া › টেরিটরি › পয়েন্ট › গ্রাহক (ডিলারের মাসিক লক্ষ্যের যোগ) আর বিক্রয়কর্মী ধরে (কর্মীর মাসিক লক্ষ্য)।
 *      ⓘ পণ্য ধরে লক্ষ্য সিস্টেমে নেই — মালিকের সিদ্ধান্তের অপেক্ষায়, তাই সে রূপ নেই।
 *   ৭. গ্রাহকের ABC — নিট বিক্রির ৮০% পর্যন্ত A, ৯৫% পর্যন্ত B, বাকি C।
 *   ৮. নতুন, ঘুমন্ত আর হারানো গ্রাহক — এই সময়ে প্রথম বিল; ৩০, ৬০ আর ৯০ দিনের বেশি কেনেননি।
 *   ৯. আদায়ের দক্ষতা আর DSO — স্তর আর শাখা ধরে।
 *
 * ⛔ দ্বিতীয় কোনো গণনা নয়: বিক্রি, ফেরত, আদায়, বাকি, শুরুর জের আর ডিলারের লক্ষ্য — সব [[AreaSalesReports::rows()]]-এর একই গ্রাহক-সারি
 * থেকে; কর্মীর বিক্রি বিলে লেখা দায়িত্বপ্রাপ্ত জনের ঘর থেকে ([[SalespersonReports]]-এর একই ঘর)। তাই এই রিপোর্টগুলোর মোট এলাকা ধরে
 * বিক্রয়ের মোটের সমান।
 */
final class CustomerInsightReports
{
    public const TARGET_PERSON = 'sales.target_sales_person';

    public const ABC = 'sales.customer_abc';

    public const NEW = 'sales.customers_new';

    public const EFFICIENCY_BRANCH = 'sales.collection_efficiency_branch';

    /** ABC-র সীমা — নিট বিক্রির জমা অংশ (%) */
    public const A_UP_TO = 80;

    public const B_UP_TO = 95;

    /** ঘুমন্তের ধাপ — শেষ বিলের পরে এত দিনের বেশি; শেষ ধাপ "হারানো" */
    public const IDLE = [30, 60, 90];

    private const FILTERS = ['date_range', 'branch', 'location_id', 'salesman_id', 'customer_id'];

    public static function targetKey(string $level): string
    {
        return 'sales.target_sales_'.$level;
    }

    public static function targetSlug(string $level): string
    {
        return $level === Location::AREA ? 'target-sales' : 'target-sales-'.$level;
    }

    public static function efficiencyKey(string $level): string
    {
        return 'sales.collection_efficiency_'.$level;
    }

    public static function efficiencySlug(string $level): string
    {
        return $level === Location::AREA ? 'collection-efficiency' : 'collection-efficiency-'.$level;
    }

    public static function idleKey(int $days): string
    {
        return 'sales.customers_idle_'.$days;
    }

    public static function registerAll(ReportEngine $engine): void
    {
        foreach (SalesPlace::LEVELS as $level) {
            $engine->register(self::targetByLevel($level));
            $engine->register(self::efficiencyByLevel($level));
        }

        $engine->register(self::targetByPerson());
        $engine->register(self::efficiencyByBranch());
        $engine->register(self::abc());
        $engine->register(self::newCustomers());

        foreach (self::IDLE as $at => $days) {
            $engine->register(self::idle($days, self::IDLE[$at + 1] ?? null));
        }
    }

    /**
     * একই রিপোর্টের নিচের স্তর, বা গ্রাহকের পাতা — প্রতিটা অঙ্কের দরজা।
     *
     * @return array<string, mixed>
     */
    private static function door(?string $slug, array $row = ['location_id' => 'place_id']): array
    {
        if ($slug === null) {
            // ⓘ গ্রাহকের স্তরে অঙ্কের উৎস গ্রাহকের নিজের পাতা — বিল, ফেরত, আদায় আর খাতা সবই সেখানে
            return ['source' => ['type' => 'source_type', 'id' => 'source_id']];
        }

        $asked = ['from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'location_id' => '@location_id', 'salesman_id' => '@salesman_id'];

        return [
            'route' => 'sales.report.show',
            'params' => ['slug' => '='.$slug, ...$asked, ...$row],
            'total' => ['slug' => '='.$slug, ...$asked],
            'label' => 'sales::area_sales.drill_down',
        ];
    }

    /** এক স্তরের সারি — জায়গার id, নাম, উপরের ধাপ আর গ্রাহকের পাতার সূত্র; `$sums` যোগের ঘর */
    private static function level(array $f, string $level, string $sums, string $order): Builder
    {
        $q = AreaSalesReports::rows($f);
        $g = SalesPlace::grouped($q, 'r', $level);

        return $q->selectRaw("{$g['id']} as place_id, ".DB::getPdo()->quote('customer')." as source_type, {$g['id']} as source_id, "
            ."{$g['place']} as place, {$g['parent']} as parent, {$sums}")
            ->orderByRaw($order)->orderByRaw($g['order']);
    }

    /** @return list<array<string, mixed>> জায়গার নাম আর (এরিয়ার নিচে) উপরের ধাপ */
    private static function placeColumns(string $level): array
    {
        return [
            ['key' => 'place', 'label' => 'sales::area_sales.level_'.$level],
            ...($level === Location::AREA ? [] : [['key' => 'parent', 'label' => 'sales::area_sales.parent_'.$level]]),
        ];
    }

    // ── ৪. লক্ষ্য বনাম বিক্রয় ──────────────────────────────────────────────────

    /** লক্ষ্য, নিট বিক্রি, ঘাটতি বা বাড়তি, আর মাসের বাকি দিনে রোজ কত দরকার — সারির যোগের SQL (`$t` সারির উপনাম) */
    private static function targetSums(string $target, string $net, string $to): string
    {
        $left = max(0, (int) Carbon::parse($to)->diffInDays(Carbon::parse($to)->endOfMonth()->startOfDay()));
        // ⓘ মাস শেষ বা লক্ষ্য পূরণ — রোজের দরকার নেই, ঘর শূন্য
        $daily = $left > 0 ? "CASE WHEN {$target} > {$net} THEN ROUND(({$target} - {$net}) / {$left}, 2) ELSE 0 END" : '0';

        return "{$target} as target, {$net} as net_sales, "
            ."CASE WHEN {$target} > 0 THEN ROUND({$net} * 100 / {$target}, 2) END as achieved, "
            ."{$net} - {$target} as gap, {$daily} as daily_need";
    }

    /** @return list<array<string, mixed>> */
    private static function targetColumns(array $door): array
    {
        return [
            ['key' => 'target', 'label' => 'sales::customer_insight.target', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ['key' => 'achieved', 'label' => 'sales::customer_insight.achieved', 'type' => ReportColumn::PERCENT, 'ratio' => ['net_sales', 'target'], 'width' => '7rem'],
            ['key' => 'gap', 'label' => 'sales::customer_insight.gap', 'type' => ReportColumn::MONEY, 'drill' => $door],
            // ⓘ মোটে যোগ নয়: সারির ঘাটতিগুলোর যোগ আর মোটের ঘাটতি এক নয় (কারও বাড়তি অন্যের ঘাটতি ঢাকে না)
            ['key' => 'daily_need', 'label' => 'sales::customer_insight.daily_need', 'type' => ReportColumn::MONEY, 'total' => false, 'drill' => $door],
            ['key' => 'prev_net', 'label' => 'sales::area_sales.prev_net', 'type' => ReportColumn::MONEY, 'drill' => $door],
        ];
    }

    private static function targetByLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);

        return new ReportDefinition(
            key: self::targetKey($level),
            permission: 'sales.report',
            title: 'sales::customer_insight.target_title_'.$level,
            filters: self::FILTERS,
            groupBy: 'place_id',
            rankBy: 'net_sales',
            splitByBranch: false,
            query: fn (array $f): Builder => self::level($f, $level,
                'COUNT(*) as customers, '.self::targetSums('SUM(r.target)', '(SUM(r.sales) - SUM(r.returns))', (string) $f['to']).', SUM(r.prev_net) as prev_net',
                'SUM(r.sales) - SUM(r.returns) DESC'),
            columns: [
                ...self::placeColumns($level),
                ['key' => 'customers', 'label' => 'sales::area_sales.customers', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                ...self::targetColumns(self::door($below === null ? null : self::targetSlug($below))),
            ],
            chart: ['type' => 'pair', 'label' => 'place', 'series' => ['target', 'net_sales'], 'title' => 'sales::customer_insight.chart_target'],
        );
    }

    /**
     * বিক্রয়কর্মী ধরে — কর্মীর মাসিক লক্ষ্য (`sal_targets`) বনাম তাঁর নামে লেখা বিক্রি (বিলের `sales_person_id`, ফেরতে ফেরতের ঘর)।
     * ⓘ দলের ভাগ [[SalespersonReports::teamsFor()]]-এর — DSM-এর নিচে সবাই; সর্বমোট মানুষ ধরে একবার।
     */
    private static function targetByPerson(): ReportDefinition
    {
        $door = ['route' => 'sales.report.show',
            'params' => ['slug' => '=by-salesperson', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'total' => ['slug' => '=by-salesperson', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::customer_insight.drill_person'];

        return new ReportDefinition(
            key: self::TARGET_PERSON,
            permission: 'sales.report',
            title: 'sales::customer_insight.target_title_person',
            filters: ['date_range', 'branch'],
            groupBy: 'person_id',
            rankBy: 'net_sales',
            splitByBranch: false,
            sections: fn (array $f): array => SalespersonReports::teamsFor($f),
            query: function (array $f): Builder {
                $company = (int) $f['company_id'];
                [$from, $to] = [(string) $f['from'], (string) $f['to']];
                $team = function (Builder $q, string $column) use ($f): void {
                    if (isset($f['team_ids'])) {
                        $q->whereIn($column, $f['team_ids'] === [] ? [0] : $f['team_ids']);
                    }
                    if (isset($f['team_outside'])) {
                        $q->where(fn ($w) => $w->whereNull($column)->when($f['team_outside'] !== [], fn ($n) => $n->orWhereNotIn($column, $f['team_outside'])));
                    }
                };

                $sold = DB::table('sal_invoices as i')
                    ->where('i.company_id', $company)->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)->whereBetween('i.trx_date', [$from, $to])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->tap(fn (Builder $q) => $team($q, 'i.sales_person_id'))
                    ->selectRaw('i.sales_person_id as person_id, '.SalesMetrics::billNetSql('i').' as sold, 0 as target');
                $back = DB::table('sal_returns as sr')
                    ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
                    ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$from, $to])
                    ->tap(ReportEngine::branchWall($f, 'sr.branch_id'))->tap(ReportEngine::dealerWall($f, 'sr.customer_id'))
                    ->tap(fn (Builder $q) => $team($q, 'sr.sales_person_id'))
                    ->selectRaw('sr.sales_person_id as person_id, 0 - '.SalesMetrics::returnNetSql('sr').' as sold, 0 as target');
                $targets = DB::table('sal_targets as tg')
                    ->where('tg.company_id', $company)
                    ->whereBetween('tg.month', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->startOfMonth()->toDateString()])
                    ->tap(ReportEngine::branchWall($f, 'tg.branch_id'))
                    // ⛔ বিক্রয়কর্মী কেবল নিজের লক্ষ্য দেখেন — অন্যের লক্ষ্য তাঁর দেয়ালের বাইরে
                    ->when(! empty($f['dealer_walled']), fn (Builder $q) => $q->where('tg.user_id', (int) auth()->id()))
                    ->tap(fn (Builder $q) => $team($q, 'tg.user_id'))
                    ->selectRaw('tg.user_id as person_id, 0 as sold, tg.amount as target');
                $nobody = DB::getPdo()->quote((string) __('sales::money_in.no_person'));

                return DB::query()->fromSub($sold->unionAll($back)->unionAll($targets), 'x')
                    ->leftJoin('users as u', 'u.id', '=', 'x.person_id')
                    ->groupBy('x.person_id', 'u.name')
                    ->selectRaw("x.person_id as person_id, COALESCE(u.name, {$nobody}) as person, "
                        .self::targetSums('SUM(x.target)', 'SUM(x.sold)', $to))
                    ->orderByRaw('SUM(x.sold) DESC')->orderBy('x.person_id');
            },
            columns: [
                ['key' => 'person', 'label' => 'sales::money_in.person'],
                ...array_values(array_filter(self::targetColumns($door), fn (array $c) => $c['key'] !== 'prev_net')),
            ],
            chart: ['type' => 'pair', 'label' => 'person', 'series' => ['target', 'net_sales'], 'title' => 'sales::customer_insight.chart_target'],
        );
    }

    // ── ৯. আদায়ের দক্ষতা আর DSO ───────────────────────────────────────────────

    /** শুরুর বাকি, নিট বিক্রি, আদায় করার মতো টাকা, আদায়, শেষ বাকি, হার আর DSO — সারির যোগের SQL */
    private static function efficiencySums(array $f): string
    {
        $days = (int) Carbon::parse((string) $f['from'])->diffInDays(Carbon::parse((string) $f['to'])) + 1;
        $net = '(SUM(r.sales) - SUM(r.returns))';

        return "COUNT(*) as customers, SUM(r.opening) as opening, {$net} as net_sales, SUM(r.opening) + {$net} as collectible, "
            .'SUM(r.collected) as collected, SUM(r.due) as due, '
            ."CASE WHEN SUM(r.opening) + {$net} > 0 THEN ROUND(SUM(r.collected) * 100 / (SUM(r.opening) + {$net}), 2) END as efficiency, "
            // ⓘ DSO — শেষ বাকি ÷ নিট বিক্রি × সময়ের দিন; বিক্রি না থাকলে মাপা যায় না, ঘর খালি
            ."CASE WHEN {$net} > 0 THEN ROUND(SUM(r.due) / {$net} * {$days}, 0) END as dso_days";
    }

    /** @return list<array<string, mixed>> */
    private static function efficiencyColumns(array $door): array
    {
        $money = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'type' => ReportColumn::MONEY, 'drill' => $door];

        return [
            ['key' => 'customers', 'label' => 'sales::area_sales.customers', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
            $money('opening', 'sales::customer_insight.opening'),
            $money('net_sales', 'sales::area_sales.net_sales'),
            $money('collectible', 'sales::customer_insight.collectible'),
            $money('collected', 'sales::area_sales.collected'),
            $money('due', 'sales::customer_insight.closing'),
            ['key' => 'efficiency', 'label' => 'sales::customer_insight.efficiency', 'type' => ReportColumn::PERCENT, 'ratio' => ['collected', 'collectible'], 'width' => '8rem'],
            // ⓘ দিনের ঘর যোগ হয় না — মোটের সারিতে খালি (চাবিতে `days`, [[ReportColumn]]-এর নিয়ম)
            ['key' => 'dso_days', 'label' => 'sales::customer_insight.dso', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '6rem'],
        ];
    }

    private static function efficiencyByLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);

        return new ReportDefinition(
            key: self::efficiencyKey($level),
            permission: 'sales.report',
            title: 'sales::customer_insight.efficiency_title_'.$level,
            filters: self::FILTERS,
            groupBy: 'place_id',
            rankBy: 'collected',
            splitByBranch: false,
            query: fn (array $f): Builder => self::level($f, $level, self::efficiencySums($f), 'SUM(r.collected) DESC'),
            columns: [
                ...self::placeColumns($level),
                ...self::efficiencyColumns(self::door($below === null ? null : self::efficiencySlug($below))),
            ],
            chart: ['type' => 'pair', 'label' => 'place', 'series' => ['collectible', 'collected'], 'title' => 'sales::customer_insight.chart_efficiency'],
        );
    }

    private static function efficiencyByBranch(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::EFFICIENCY_BRANCH,
            permission: 'sales.report',
            title: 'sales::customer_insight.efficiency_title_branch',
            filters: self::FILTERS,
            groupBy: 'place_id',
            rankBy: 'collected',
            splitByBranch: false,
            query: function (array $f): Builder {
                $none = DB::getPdo()->quote((string) __('sales::area_sales.no_branch'));

                return AreaSalesReports::rows($f)->leftJoin('branches as br', 'br.id', '=', 'r.branch_id')
                    ->groupBy('r.branch_id', 'br.name_bn', 'br.name_en')
                    ->selectRaw('r.branch_id as place_id, COALESCE('.SalesPlace::named('br').", {$none}) as place, ".self::efficiencySums($f))
                    ->orderByRaw('SUM(r.collected) DESC')->orderBy('r.branch_id');
            },
            columns: [
                ['key' => 'place', 'label' => 'sales::area_sales.branch'],
                ...self::efficiencyColumns(self::door(self::efficiencySlug(Location::AREA), ['branch_id' => 'place_id'])),
            ],
            chart: ['type' => 'pair', 'label' => 'place', 'series' => ['collectible', 'collected'], 'title' => 'sales::customer_insight.chart_efficiency'],
        );
    }

    // ── ৭. গ্রাহকের ABC ───────────────────────────────────────────────────────

    private static function abc(): ReportDefinition
    {
        $door = self::door(null);

        return new ReportDefinition(
            key: self::ABC,
            permission: 'sales.report',
            title: 'sales::customer_insight.abc_title',
            filters: self::FILTERS,
            splitByBranch: false,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                // ⓘ ভিতরে নিট বিক্রি আছে এমন গ্রাহক, বাইরে ক্রম, অংশ আর জমা অংশ — জানালার যোগ গোটা ফলের উপর, পাতার উপর নয়
                $with = AreaSalesReports::rows($f)->whereRaw('r.sales - r.returns > 0')
                    ->selectRaw('r.customer_id, r.customer, r.point, r.due, r.sales - r.returns as net_sales');
                $ranked = DB::query()->fromSub($with, 'n')
                    ->selectRaw('n.*, SUM(n.net_sales) OVER () as all_net, '
                        .'SUM(n.net_sales) OVER (ORDER BY n.net_sales DESC, n.customer_id) as running, '
                        .'ROW_NUMBER() OVER (ORDER BY n.net_sales DESC, n.customer_id) as sl');

                return DB::query()->fromSub($ranked, 'a')
                    ->selectRaw('a.sl, a.customer, '.$pdo->quote('customer').' as source_type, a.customer_id as source_id, a.point, a.net_sales, '
                        .'ROUND(a.net_sales * 100 / a.all_net, 2) as share, ROUND(a.running * 100 / a.all_net, 2) as cumulative, '
                        // ⓘ শ্রেণি ঠিক হয় গ্রাহকের **আগের** জমা অংশ দেখে — যে গ্রাহক ৮০% পার করান তিনিও A
                        .'CASE WHEN (a.running - a.net_sales) * 100 / a.all_net < '.self::A_UP_TO." THEN 'A'"
                        .' WHEN (a.running - a.net_sales) * 100 / a.all_net < '.self::B_UP_TO." THEN 'B' ELSE 'C' END as class, a.due")
                    ->orderBy('a.sl');
            },
            columns: [
                ['key' => 'sl', 'label' => 'sales::customer_insight.sl', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '4rem'],
                ['key' => 'customer', 'label' => 'sales::area_sales.level_customer', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'point', 'label' => 'sales::customer_insight.place', 'place' => ['customer', 'source_id', 'branch']],
                ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $door],
                ['key' => 'share', 'label' => 'sales::customer_insight.share', 'type' => ReportColumn::PERCENT, 'width' => '6rem'],
                ['key' => 'cumulative', 'label' => 'sales::customer_insight.cumulative', 'type' => ReportColumn::PERCENT, 'width' => '7rem'],
                ['key' => 'class', 'label' => 'sales::customer_insight.class', 'width' => '5rem'],
                ['key' => 'due', 'label' => 'sales::area_sales.due', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ],
            chart: ['type' => 'bars', 'label' => 'customer', 'series' => ['net_sales'], 'title' => 'sales::customer_insight.chart_abc'],
        );
    }

    // ── ৮. নতুন, ঘুমন্ত আর হারানো গ্রাহক ─────────────────────────────────────────

    /**
     * প্রতি গ্রাহকের প্রথম আর শেষ বিল (শেষ তারিখ পর্যন্ত), এই সময়ের নিট বিক্রি আর শেষ তারিখের বাকি — দেয়াল আর ছাঁকনিসহ।
     * ঘর: `customer_id, customer, point, person, first_bill, last_bill, idle_days, bills, net_sales, due`।
     *
     * @param  array<string, mixed>  $f
     */
    private static function history(array $f): Builder
    {
        $company = (int) $f['company_id'];
        [$from, $to] = [(string) $f['from'], (string) $f['to']];
        $pdo = DB::getPdo();
        $person = ResponsibleSql::person($company, 'cu.id', $pdo->quote($to));

        $bills = DB::table('sal_invoices as i')
            ->where('i.company_id', $company)->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)->where('i.trx_date', '<=', $to)
            ->groupBy('i.customer_id')
            ->selectRaw('i.customer_id as customer_id, MIN(i.trx_date) as first_bill, MAX(i.trx_date) as last_bill, '
                .'SUM(CASE WHEN i.trx_date >= '.$pdo->quote($from).' THEN 1 ELSE 0 END) as bills, '
                .'SUM(CASE WHEN i.trx_date >= '.$pdo->quote($from).' THEN '.SalesMetrics::billNetSql('i').' ELSE 0 END) as sold');
        $back = DB::table('sal_returns as sr')
            ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
            ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$from, $to])
            ->groupBy('sr.customer_id')
            ->selectRaw('sr.customer_id as customer_id, SUM('.SalesMetrics::returnNetSql('sr').') as v');
        $due = DB::table('ledger_entries as d')
            ->where('d.company_id', $company)->where('d.party_type', 'customer')->where('d.trx_date', '<=', $to)
            ->groupBy('d.party_id')
            ->selectRaw('d.party_id as customer_id, GREATEST(SUM(d.debit) - SUM(d.credit), 0) as v');

        $inner = DB::table('customers as cu')
            ->where('cu.company_id', $company)
            ->whereNull('cu.deleted_at')
            // ⓘ কখনো বিল হয়নি এমন গ্রাহক নতুনও নন, ঘুমন্তও নন — তালিকায় আসেন না
            ->joinSub($bills, 'h', 'h.customer_id', '=', 'cu.id')
            ->leftJoinSub($back, 'b', 'b.customer_id', '=', 'cu.id')
            ->leftJoinSub($due, 'd', 'd.customer_id', '=', 'cu.id')
            ->tap(fn (Builder $q) => SalesPlace::join($q))
            ->tap(ReportEngine::branchWall($f, 'cu.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->when(! empty($f['location_id']), fn (Builder $q) => SalesPlace::under($q, (int) $f['location_id']))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            ->when(! empty($f['salesman_id']), fn (Builder $q) => $q->whereRaw($person.' = ?', [(int) $f['salesman_id']]))
            ->selectRaw('cu.id as customer_id, '.SalesPlace::named('cu').' as customer, '.SalesPlace::first(Location::POINT, 'name').' as point, '
                ."{$person} as person_id, h.first_bill, h.last_bill, DATEDIFF(".$pdo->quote($to).', h.last_bill) as idle_days, '
                .'h.bills, h.sold - COALESCE(b.v, 0) as net_sales, COALESCE(d.v, 0) as due');

        return DB::query()->fromSub($inner, 'c')->leftJoin('users as u', 'u.id', '=', 'c.person_id');
    }

    /** @return list<array<string, mixed>> গ্রাহক, জায়গা, দায়িত্বপ্রাপ্ত জন */
    private static function whoColumns(): array
    {
        return [
            ['key' => 'customer', 'label' => 'sales::area_sales.level_customer', 'type' => ReportColumn::DOCUMENT,
                'source_type' => 'source_type', 'source_id' => 'source_id'],
            ['key' => 'point', 'label' => 'sales::customer_insight.place', 'place' => ['customer', 'source_id', 'branch']],
            ['key' => 'person', 'label' => 'sales::money_in.person'],
        ];
    }

    private static function who(): string
    {
        $pdo = DB::getPdo();

        return 'c.customer, '.$pdo->quote('customer').' as source_type, c.customer_id as source_id, c.point, '
            .'COALESCE(u.name, '.$pdo->quote((string) __('sales::money_in.no_person')).') as person';
    }

    /** নতুন গ্রাহক — যাঁদের জীবনের প্রথম বিল এই সময়ে */
    private static function newCustomers(): ReportDefinition
    {
        $door = self::door(null);

        return new ReportDefinition(
            key: self::NEW,
            permission: 'sales.report',
            title: 'sales::customer_insight.new_title',
            filters: self::FILTERS,
            splitByBranch: false,
            query: fn (array $f): Builder => self::history($f)->where('c.first_bill', '>=', $f['from'])
                ->selectRaw(self::who().', c.first_bill, c.bills, c.net_sales, c.due')
                ->orderByDesc('c.net_sales')->orderBy('c.customer_id'),
            columns: [
                ...self::whoColumns(),
                ['key' => 'first_bill', 'label' => 'sales::customer_insight.first_bill', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $door],
                ['key' => 'due', 'label' => 'sales::area_sales.due', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ],
        );
    }

    /** ঘুমন্ত — শেষ বিলের পরে `$over` দিনের বেশি, `$upTo` দিন পর্যন্ত (শেষ ধাপে সীমা নেই: হারানো) */
    private static function idle(int $over, ?int $upTo): ReportDefinition
    {
        $door = self::door(null);

        return new ReportDefinition(
            key: self::idleKey($over),
            permission: 'sales.report',
            title: 'sales::customer_insight.idle_title_'.$over,
            filters: self::FILTERS,
            // ⓘ "কত দিন কেনেননি" শেষ তারিখ থেকে গোনা — শুরুর তারিখ এখানে কিছু বদলায় না
            asOfDate: true,
            splitByBranch: false,
            query: fn (array $f): Builder => self::history($f)->where('c.idle_days', '>', $over)
                ->when($upTo !== null, fn (Builder $q) => $q->where('c.idle_days', '<=', $upTo))
                ->selectRaw(self::who().', c.last_bill, c.idle_days, c.due')
                ->orderByDesc('c.idle_days')->orderBy('c.customer_id'),
            columns: [
                ...self::whoColumns(),
                ['key' => 'last_bill', 'label' => 'sales::customer_insight.last_bill', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'idle_days', 'label' => 'sales::customer_insight.idle_days', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'due', 'label' => 'sales::area_sales.due', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ],
        );
    }
}
