<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Customer\Reports\PartyReports;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ এলাকা ধরে বাকির বয়স — কোন এলাকায় টাকা আটকে আছে (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ১৪; অনুমোদিত নমুনা
 * claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * ⛔ বয়সের হিসাব এখানে নতুন করে কষা হয় না: গ্রাহকের বকেয়ার বয়সের রিপোর্টের ([[PartyReports::ageing()]], `customer.ageing`) হুবহু একই
 * কোয়েরি — আদায় সবচেয়ে পুরনো বকেয়া থেকে কাটা (FIFO), অগ্রিম বাকিতে মেশে না, বয়স (থেকে, পর্যন্ত] — গ্রাহক-প্রতি এক সারি; এখানে কেবল
 * জায়গার সিঁড়ি ধরে যোগ। তাই এরিয়ার মোট সেই রিপোর্টের মোটের সমান।
 *
 * ঘর: ০–৩০, ৩১–৬০, ৬১–৯০, ৯০ দিনের বেশি, মোট বাকি, ৬০ দিনের বেশি (শেষ দুই ঘরের যোগ) আর তা মোটের কত ভাগ।
 * ⓘ শাখা খাতার সারির (বয়সের রিপোর্টের একই দেয়াল); বিক্রয়কর্মী কেবল নিজের ডিলার। তারিখ একটাই — "কোন দিনের জের"।
 */
final class DueAgeingReports
{
    private const BUCKETS = ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90'];

    public static function levelKey(string $level): string
    {
        return 'sales.due_ageing_'.$level;
    }

    public static function levelSlug(string $level): string
    {
        return $level === Location::AREA ? 'due-ageing' : 'due-ageing-'.$level;
    }

    public static function registerAll(ReportEngine $engine): void
    {
        foreach (SalesPlace::LEVELS as $level) {
            $engine->register(self::byLevel($level));
        }
    }

    /**
     * গ্রাহক-প্রতি বয়সের সারি, জায়গার সিঁড়িসহ — ঘর [[SalesPlace::grouped()]] যা চায়, আর চার বালতি ও মোট।
     *
     * @param  array<string, mixed>  $f
     */
    private static function rows(array $f): Builder
    {
        $company = (int) $f['company_id'];
        // ⓘ বয়সের রিপোর্টের নিজের কোয়েরি — শাখার আর ডিলারের দেয়াল ওর ভিতরেই
        $ageing = (PartyReports::ageing()->query)([...$f, 'party_type_id' => $f['party_type_id'] ?? null]);

        $inner = DB::query()->fromSub($ageing, 'g')
            ->join('customers as cu', 'cu.id', '=', 'g.party_id')
            ->tap(fn (Builder $q) => SalesPlace::join($q))
            ->where('cu.company_id', $company)
            ->when(! empty($f['location_id']), fn (Builder $q) => SalesPlace::under($q, (int) $f['location_id']))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            ->when(! empty($f['salesman_id']), fn (Builder $q) => $q->whereRaw(
                ResponsibleSql::person($company, 'cu.id', DB::getPdo()->quote((string) $f['to'])).' = ?', [(int) $f['salesman_id']]))
            ->where('g.outstanding', '>', 0)
            ->selectRaw('cu.id as customer_id, '.SalesPlace::named('cu').' as customer, '
                .SalesPlace::first(Location::AREA, 'id').' as area_id, '.SalesPlace::first(Location::AREA, 'name').' as area, '
                .SalesPlace::first(Location::AREA, 'branch_id').' as area_branch_id, '
                .SalesPlace::first(Location::TERRITORY, 'id').' as territory_id, '.SalesPlace::first(Location::TERRITORY, 'name').' as territory, '
                .SalesPlace::first(Location::POINT, 'id').' as point_id, '.SalesPlace::first(Location::POINT, 'name').' as point, '
                .'g.bucket_current, g.bucket_30, g.bucket_60, g.bucket_90, g.outstanding');

        return DB::query()->fromSub($inner, 'r');
    }

    private static function byLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);
        $door = $below === null
            ? ['source' => ['type' => 'source_type', 'id' => 'source_id']]
            : ['route' => 'sales.report.show',
                'params' => ['slug' => '='.self::levelSlug($below), 'to' => '@to', 'branch_id' => '@branch_id', 'salesman_id' => '@salesman_id', 'location_id' => 'place_id'],
                'total' => ['slug' => '='.self::levelSlug($below), 'to' => '@to', 'branch_id' => '@branch_id', 'salesman_id' => '@salesman_id', 'location_id' => '@location_id'],
                'label' => 'sales::area_sales.drill_down'];
        $money = fn (string $key): array => ['key' => $key, 'label' => 'sales::due_ageing.'.$key, 'type' => ReportColumn::MONEY, 'drill' => $door];

        return new ReportDefinition(
            key: self::levelKey($level),
            permission: 'sales.report',
            title: 'sales::due_ageing.title_'.$level,
            filters: ['date_range', 'branch', 'location_id', 'salesman_id', 'customer_id'],
            asOfDate: true,
            groupBy: 'place_id',
            rankBy: 'outstanding',
            splitByBranch: false,
            query: function (array $f) use ($level): Builder {
                $q = self::rows($f);
                $g = SalesPlace::grouped($q, 'r', $level);
                $sums = implode(', ', array_map(fn (string $b) => "SUM(r.{$b}) as {$b}", self::BUCKETS));

                return $q->selectRaw("{$g['id']} as place_id, ".DB::getPdo()->quote('customer')." as source_type, {$g['id']} as source_id, "
                    ."{$g['place']} as place, {$g['parent']} as parent, COUNT(*) as customers, {$sums}, SUM(r.outstanding) as outstanding, "
                    .'SUM(r.bucket_60) + SUM(r.bucket_90) as over_60, '
                    .'CASE WHEN SUM(r.outstanding) > 0 THEN ROUND((SUM(r.bucket_60) + SUM(r.bucket_90)) * 100 / SUM(r.outstanding), 2) END as over_60_share')
                    ->orderByRaw('SUM(r.outstanding) DESC')->orderByRaw($g['order']);
            },
            columns: [
                ['key' => 'place', 'label' => 'sales::area_sales.level_'.$level],
                ...($level === Location::AREA ? [] : [['key' => 'parent', 'label' => 'sales::area_sales.parent_'.$level]]),
                ['key' => 'customers', 'label' => 'sales::area_sales.customers', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                ...array_map($money, self::BUCKETS),
                $money('outstanding'),
                $money('over_60'),
                ['key' => 'over_60_share', 'label' => 'sales::due_ageing.over_60_share', 'type' => ReportColumn::PERCENT, 'ratio' => ['over_60', 'outstanding'], 'width' => '7rem'],
            ],
            chart: ['type' => 'pair', 'label' => 'place', 'series' => ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90'], 'title' => 'sales::due_ageing.chart'],
        );
    }
}
