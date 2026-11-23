<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Support\DocumentStatus;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\CustomerTargetService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ এলাকা ধরে বিক্রয় আর শাখা ধরে বিক্রয় — মালিক, ২৩ নভেম্বর ২০২৬ (রোডম্যাপ §৩, রিপোর্ট ১ ও ২; অনুমোদিত নমুনা claude.ai artifact
 * 7jU3Q2Gz2WSGQp6Bt6QhSL সংস্করণ ২)। মালিক যেটাকে "পয়েন্ট ধরে বিক্রয়" ভাবতেন সেটা রুটের রিপোর্ট ছিল — পয়েন্ট, টেরিটরি বা এরিয়া ধরে
 * যোগফলের কোনো রিপোর্ট ছিল না।
 *
 * ── স্তর ────────────────────────────────────────────────────────────────────
 * এরিয়া › টেরিটরি › পয়েন্ট › গ্রাহক ([[SalesPlace::LEVELS]]) — প্রতিটা স্তরের নিজের রিপোর্ট, পাতায় ট্যাব; অঙ্কে চাপলে এক স্তর নিচে,
 * গ্রাহকে পৌঁছে গ্রাহকের পাতা। ⓘ ডিভিশন বা অঞ্চল কোম্পানিতে নেই বলে দেখানো হয় না; হলে [[SalesPlace::LEVELS]]-এ এক লাইন।
 *
 * ── ঘর, আর কোনটা কোথা থেকে (দ্বিতীয় কোনো গণনা নয়) ─────────────────────────────
 *   বিক্রি          খাতায় বসা বিল, ভ্যাট আর গাড়িভাড়া বাদ ([[SalesMetrics::billNetSql()]]) — বাতিল বিল নয়
 *   ফেরত           খাতায় বসা ফেরত, ভ্যাট বাদ ([[SalesMetrics::returnNetSql()]]), ফেরতের দিনে
 *   নিট বিক্রি       বিক্রি − ফেরত
 *   আদায়           আসা টাকা — লক্ষ্যের পাতার একই নিয়ম ([[CustomerTargetService::achievedQuery()]])
 *   বাকি            শেষ তারিখে গ্রাহকের খাতার জের (ডেবিট − ক্রেডিট, শূন্যের নিচে নয় — অগ্রিম বাকি কমায় না)
 *   আগের একই সময়  একই দৈর্ঘ্যের ঠিক আগের সময়ের নিট ([[Trend::previous()]] — রিপোর্টের "তুলনা"-র একই জানালা)
 *   গত বছরের একই সময়  এক বছর আগের একই তারিখগুলোর নিট; তথ্য না থাকলে শূন্য
 * ⓘ বেড়েছে না কমেছে (%) — পাতার "তুলনা" বাছলে ইঞ্জিন নিজেই দেখায়, নিট বিক্রি ধরে।
 *
 * ⓘ শাখা — গ্রাহকের নিজের শাখা (হেডারের বাছা মানে); বিক্রয়কর্মী কেবল নিজের ডিলার। ⓘ সারিতে আসেন যাঁদের এই সময়ে বিক্রি, ফেরত বা
 * আদায় আছে, বাকি আছে, বা তুলনার সময়ে বিক্রি ছিল — নড়াচড়াহীন গ্রাহক নয়।
 */
final class AreaSalesReports
{
    public const BRANCH = 'sales.branch_sales';

    public const BRANCH_SLUG = 'branch-sales';

    private const FILTERS = ['date_range', 'branch', 'location_id', 'salesman_id', 'customer_id'];

    private const MONEY = ['sales', 'returns', 'net_sales', 'collected', 'due', 'prev_net', 'ly_net'];

    public static function levelKey(string $level): string
    {
        return 'sales.area_sales_'.$level;
    }

    public static function levelSlug(string $level): string
    {
        return $level === Location::AREA ? 'area-sales' : 'area-sales-'.$level;
    }

    public static function registerAll(ReportEngine $engine): void
    {
        foreach (SalesPlace::LEVELS as $level) {
            $engine->register(self::byLevel($level));
        }

        $engine->register(self::byBranch());
    }

    /**
     * ⭐ প্রতি গ্রাহকের এক সারি — এই রিপোর্টগুলো আর পরের বিশ্লেষণগুলো (লক্ষ্য, আদায়ের দক্ষতা, বাকির বয়স) একই সারি পড়ে।
     * ঘর: `customer_id, customer, branch_id, area_id, area, area_branch_id, territory_id, territory, point_id, point, sales, bills,
     * returns, collected, due, opening` (শুরুর তারিখের আগের জের), `target` (ডিলারের লক্ষ্য), `prev_net, ly_net`।
     *
     * @param  array<string, mixed>  $f
     */
    public static function rows(array $f): Builder
    {
        $company = (int) $f['company_id'];
        [$from, $to] = [(string) $f['from'], (string) $f['to']];
        $before = Trend::previous(ReportEngine::COMPARE_PREVIOUS, $from, $to);
        $lastYear = Trend::previous(ReportEngine::COMPARE_LAST_YEAR, $from, $to);

        $sold = fn (string $a, string $b): Builder => DB::table('sal_invoices as i')
            ->where('i.company_id', $company)->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)->whereBetween('i.trx_date', [$a, $b])
            ->groupBy('i.customer_id')
            ->selectRaw('i.customer_id as customer_id, SUM('.SalesMetrics::billNetSql('i').') as v, COUNT(*) as n');
        $back = fn (string $a, string $b): Builder => DB::table('sal_returns as sr')
            ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
            ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$a, $b])
            ->groupBy('sr.customer_id')
            ->selectRaw('sr.customer_id as customer_id, SUM('.SalesMetrics::returnNetSql('sr').') as v');
        $balance = fn (string $operator, string $day): Builder => DB::table('ledger_entries as d')
            ->where('d.company_id', $company)->where('d.party_type', 'customer')->where('d.trx_date', $operator, $day)
            ->groupBy('d.party_id')
            ->selectRaw('d.party_id as customer_id, GREATEST(SUM(d.debit) - SUM(d.credit), 0) as v');
        $due = $balance('<=', $to);
        // ⓘ ডিলারের মাসিক লক্ষ্য — বাছা সময় যে মাসগুলো ছোঁয় তাদের যোগ ([[PartyTargetReport::base()]]-এর একই নিয়ম)
        $targets = DB::table('sal_customer_targets as st')
            ->where('st.company_id', $company)
            ->whereBetween('st.month', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->startOfMonth()->toDateString()])
            ->groupBy('st.customer_id')
            ->selectRaw('st.customer_id as customer_id, SUM(st.amount) as v');

        $inner = DB::table('customers as cu')
            ->where('cu.company_id', $company)
            ->whereNull('cu.deleted_at')
            ->leftJoinSub($sold($from, $to), 's', 's.customer_id', '=', 'cu.id')
            ->leftJoinSub($back($from, $to), 'b', 'b.customer_id', '=', 'cu.id')
            ->leftJoinSub(CustomerTargetService::achievedQuery($company, $from, $to), 'c', 'c.customer_id', '=', 'cu.id')
            ->leftJoinSub($due, 'd', 'd.customer_id', '=', 'cu.id')
            ->leftJoinSub($balance('<', $from), 'o', 'o.customer_id', '=', 'cu.id')
            ->leftJoinSub($targets, 't', 't.customer_id', '=', 'cu.id')
            ->leftJoinSub($sold($before['from'], $before['to']), 'ps', 'ps.customer_id', '=', 'cu.id')
            ->leftJoinSub($back($before['from'], $before['to']), 'pb', 'pb.customer_id', '=', 'cu.id')
            ->leftJoinSub($sold($lastYear['from'], $lastYear['to']), 'ys', 'ys.customer_id', '=', 'cu.id')
            ->leftJoinSub($back($lastYear['from'], $lastYear['to']), 'yb', 'yb.customer_id', '=', 'cu.id')
            ->tap(fn (Builder $q) => SalesPlace::join($q))
            ->tap(ReportEngine::branchWall($f, 'cu.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->when(! empty($f['location_id']), fn (Builder $q) => SalesPlace::under($q, (int) $f['location_id']))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            // ⭐ দায়িত্বপ্রাপ্ত জন — শেষ তারিখে যিনি এই গ্রাহকের দায়িত্বে ([[ResponsibleSql]]); বিল যিনি লিখেছেন তিনি নন
            ->when(! empty($f['salesman_id']), fn (Builder $q) => $q->whereRaw(
                ResponsibleSql::person($company, 'cu.id', DB::getPdo()->quote($to)).' = ?', [(int) $f['salesman_id']]))
            ->whereRaw('(COALESCE(s.v, 0) <> 0 OR COALESCE(b.v, 0) <> 0 OR COALESCE(c.achieved, 0) <> 0 OR COALESCE(d.v, 0) <> 0'
                .' OR COALESCE(ps.v, 0) <> 0 OR COALESCE(ys.v, 0) <> 0 OR COALESCE(o.v, 0) <> 0 OR COALESCE(t.v, 0) <> 0)')
            ->selectRaw('cu.id as customer_id, '.SalesPlace::named('cu').' as customer, cu.branch_id as branch_id, '
                .SalesPlace::first(Location::AREA, 'id').' as area_id, '.SalesPlace::first(Location::AREA, 'name').' as area, '
                .SalesPlace::first(Location::AREA, 'branch_id').' as area_branch_id, '
                .SalesPlace::first(Location::TERRITORY, 'id').' as territory_id, '.SalesPlace::first(Location::TERRITORY, 'name').' as territory, '
                .SalesPlace::first(Location::POINT, 'id').' as point_id, '.SalesPlace::first(Location::POINT, 'name').' as point, '
                .'COALESCE(s.v, 0) as sales, COALESCE(s.n, 0) as bills, COALESCE(b.v, 0) as returns, COALESCE(c.achieved, 0) as collected, '
                .'COALESCE(d.v, 0) as due, COALESCE(o.v, 0) as opening, COALESCE(t.v, 0) as target, '
                .'COALESCE(ps.v, 0) - COALESCE(pb.v, 0) as prev_net, COALESCE(ys.v, 0) - COALESCE(yb.v, 0) as ly_net');

        return DB::query()->fromSub($inner, 'r');
    }

    /** যোগের ঘরগুলো — সব স্তর আর শাখার রিপোর্টে একই */
    private static function sums(): string
    {
        $net = 'SUM(r.sales) - SUM(r.returns)';
        // ⓘ তুলনার সময়ে বিক্রি না থাকলে হার নেই — ঘর খালি, ০% নয়
        $change = fn (string $was): string => "CASE WHEN SUM(r.{$was}) > 0 THEN ROUND(({$net} - SUM(r.{$was})) * 100 / SUM(r.{$was}), 2) END";

        return 'COUNT(*) as customers, SUM(r.bills) as bills, SUM(r.sales) as sales, SUM(r.returns) as returns, '
            ."{$net} as net_sales, SUM(r.collected) as collected, SUM(r.due) as due, "
            .'SUM(r.prev_net) as prev_net, '.$change('prev_net').' as prev_net_change, '
            .'SUM(r.ly_net) as ly_net, '.$change('ly_net').' as ly_net_change';
    }

    /**
     * গোনা আর টাকার ঘর — প্রতিটা অঙ্কে একই দরজা (`$drill`): এক স্তর নিচের রিপোর্ট, বা গ্রাহকের পাতা।
     *
     * @param  array<string, mixed>  $drill
     * @return list<array<string, mixed>>
     */
    private static function figures(array $drill): array
    {
        $columns = [
            ['key' => 'customers', 'label' => 'sales::area_sales.customers', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
            ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
        ];

        foreach (self::MONEY as $key) {
            $columns[] = ['key' => $key, 'label' => 'sales::area_sales.'.$key, 'type' => ReportColumn::MONEY, 'drill' => $drill];

            // ⭐ তুলনার অঙ্কের পাশেই বাড়া-কমার হার — নিট বিক্রি সেই সময়ের চেয়ে কত % (নমুনার দুই % ঘর); মোটে মোট ÷ মোট
            if (in_array($key, ['prev_net', 'ly_net'], true)) {
                $columns[] = ['key' => $key.'_change', 'label' => 'sales::area_sales.'.$key.'_change', 'type' => ReportColumn::PERCENT,
                    'ratio' => ['net_sales', $key, 'change'], 'width' => '7rem'];
            }
        }

        return $columns;
    }

    /**
     * এক স্তর নিচের রিপোর্টের দরজা — একই সময় আর ছাঁকনি, সারির জায়গা বা শাখা ধরে।
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private static function down(string $slug, array $row): array
    {
        $asked = ['from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'location_id' => '@location_id', 'salesman_id' => '@salesman_id'];

        return [
            'route' => 'sales.report.show',
            'params' => ['slug' => '='.$slug, ...$asked, ...$row],
            'total' => ['slug' => '='.$slug, ...$asked],
            'label' => 'sales::area_sales.drill_down',
        ];
    }

    private static function define(string $key, string $title, \Closure $query, array $columns, bool $transpose = false, array $series = ['net_sales', 'collected', 'due']): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'sales.report',
            title: $title,
            filters: self::FILTERS,
            query: $query,
            columns: $columns,
            groupBy: 'place_id',
            rankBy: 'net_sales',
            // ⓘ শাখা গ্রাহকের নিজের — নিজেই ছাঁকে; শাখা ধরে ভাগ আলাদা রিপোর্ট ([[BRANCH]])
            splitByBranch: false,
            transpose: $transpose,
            // ⭐ নমুনার গ্রাফ — নিট বিক্রি, আদায় আর বাকি (শাখার রিপোর্টে গত মাসের নিটও) পাশাপাশি
            chart: ['type' => 'pair', 'label' => 'place', 'series' => $series, 'title' => 'sales::area_sales.chart'],
        );
    }

    private static function byLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);

        return self::define(self::levelKey($level), 'sales::area_sales.title_'.$level,
            function (array $f) use ($level): Builder {
                $pdo = DB::getPdo();
                $q = self::rows($f)->leftJoin('branches as ab', 'ab.id', '=', 'r.area_branch_id');
                // ⭐ এরিয়ার নাম "শাখা › এরিয়া" — ADI-র চার শাখার চার এরিয়ার একই নাম (মালিক, ২৩ নভেম্বর ২০২৬)
                $area = "CASE WHEN r.area_id IS NULL THEN NULL ELSE CONCAT_WS(' › ', ".SalesPlace::named('ab').', r.area) END';
                $q->groupBy('r.area_id', 'r.area', 'ab.name_bn', 'ab.name_en');

                [$id, $name, $parent, $none] = match ($level) {
                    Location::AREA => ['r.area_id', $area, 'NULL', 'no_place'],
                    Location::TERRITORY => ['r.territory_id', 'r.territory', $area, 'no_territory'],
                    Location::POINT => ['r.point_id', 'r.point', "CONCAT_WS(' · ', r.territory, {$area})", 'no_place'],
                    default => ['r.customer_id', 'r.customer', 'r.point', 'no_place'],
                };

                match ($level) {
                    Location::AREA => null,
                    Location::TERRITORY => $q->groupBy('r.territory_id', 'r.territory'),
                    Location::POINT => $q->groupBy('r.point_id', 'r.point', 'r.territory'),
                    default => $q->groupBy('r.customer_id', 'r.customer', 'r.point'),
                };

                // ⓘ টেরিটরি বসানো নেই এমন সারিতে চাপলে তার এরিয়ার পয়েন্টগুলো — নিচে নামার জায়গা এরিয়াই
                $place = $level === Location::TERRITORY ? 'COALESCE(r.territory_id, r.area_id)' : $id;

                return $q->selectRaw("{$place} as place_id, ".$pdo->quote('customer')." as source_type, {$place} as source_id, "
                    ."COALESCE({$name}, ".$pdo->quote((string) __('sales::area_sales.'.$none)).") as place, {$parent} as parent, ".self::sums())
                    ->orderByRaw('SUM(r.sales) - SUM(r.returns) DESC')->orderByRaw($id);
            },
            [
                ['key' => 'place', 'label' => 'sales::area_sales.level_'.$level],
                ...($level === Location::AREA ? [] : [['key' => 'parent', 'label' => 'sales::area_sales.parent_'.$level]]),
                ...self::figures($below === null
                    // ⓘ গ্রাহকের স্তরে অঙ্কের উৎস গ্রাহকের নিজের পাতা — বিল, ফেরত, আদায় আর খাতা সবই সেখানে
                    ? ['source' => ['type' => 'source_type', 'id' => 'source_id']]
                    : self::down(self::levelSlug($below), ['location_id' => 'place_id'])),
            ]);
    }

    /**
     * ⭐ শাখা ধরে বিক্রয় — শাখাগুলো পাশাপাশি কলামে, প্রতিটা হিসাব এক সারিতে, শেষে "সব" (অনুমোদিত নমুনা, ২৩ নভেম্বর ২০২৬;
     * [[ReportDefinition::$transpose]]); অঙ্কে চাপলে সেই শাখার এরিয়াগুলো।
     */
    private static function byBranch(): ReportDefinition
    {
        return self::define(self::BRANCH, 'sales::area_sales.title_branch',
            function (array $f): Builder {
                $none = DB::getPdo()->quote((string) __('sales::area_sales.no_branch'));

                return self::rows($f)->leftJoin('branches as br', 'br.id', '=', 'r.branch_id')
                    ->groupBy('r.branch_id', 'br.name_bn', 'br.name_en')
                    ->selectRaw('r.branch_id as place_id, COALESCE('.SalesPlace::named('br').", {$none}) as place, ".self::sums())
                    ->orderByRaw('SUM(r.sales) - SUM(r.returns) DESC')->orderBy('r.branch_id');
            },
            [
                ['key' => 'place', 'label' => 'sales::area_sales.branch'],
                ...self::figures(self::down(self::levelSlug(Location::AREA), ['branch_id' => 'place_id'])),
            ], transpose: true, series: ['net_sales', 'collected', 'due', 'prev_net']);
    }
}
