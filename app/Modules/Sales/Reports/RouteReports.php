<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\RouteMetrics;
use Illuminate\Support\Facades\DB;

/**
 * রুট ধরে খাতা — প্রতিটা রুটে কয়জন ডিলার, কত বিক্রি, কত আদায়, কত বাকি।
 *
 * ⓘ অঙ্কগুলো [[RouteMetrics::ledgerByRoute()]] থেকে — রুটের পর্দা যে সংজ্ঞা
 * পড়ে, রিপোর্টও সেটাই। ⛔ এখানে আলাদা করে SUM লিখলে একদিন রিপোর্ট আর
 * পর্দা দুই অঙ্ক দেখাত।
 *
 * ⓘ রুট মানে ডিলারের **আজকের** রুট — কারণটা [[RouteMetrics]]-এর মাথায়।
 */
final class RouteReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byRoute());
    }

    public static function byRoute(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.by_route',
            title: 'sales::route.report_title',
            filters: ['date_range', 'branch'],

            // ⓘ "সবচেয়ে বড় রুট" মানে বিক্রয় — অবদান % আর Top N এটা ধরেই
            rankBy: 'sales',

            // ⓘ বিক্রয়ের বাকি রিপোর্টগুলোর একই চাবি — রুটের অঙ্ক ডিলার ধরে বিক্রির মতোই স্পর্শকাতর
            permission: 'sales.report',

            query: function (array $f) {
                $metrics = app(RouteMetrics::class);
                $company = $f['company_id'];

                $customers = DB::table('customers')
                    ->where('company_id', $company)
                    // ⭐ বিক্রয়কর্মী কেবল নিজের ডিলার গোনেন; খাতার অঙ্ক RouteMetrics নিজে ছাঁকে (⛔১৬)
                    ->tap(ReportEngine::dealerWall($f, 'customers.id'))
                    ->whereNull('deleted_at')
                    ->groupBy('location_id')
                    ->select(['location_id', DB::raw('COUNT(*) as customer_count')]);

                /*
                 * শেষ তারিখে ছকে কে কে আছেন — একটা ঘরে, নাম কমা দিয়ে।
                 * ⓘ বাঁধন নয়, সাপ্তাহিক ছক (`sal_route_visits`)।
                 */
                $people = DB::table('sal_route_visits as v')
                    ->join('users as u', 'u.id', '=', 'v.user_id')
                    ->where('v.company_id', $company)
                    ->where('v.effective_from', '<=', $f['to'])
                    ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $f['to']))
                    ->groupBy('v.route_id')
                    ->select(['v.route_id', DB::raw("GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as salespeople")]);

                return DB::table('mdm_locations as r')
                    ->leftJoin('mdm_locations as p', 'p.id', '=', 'r.parent_id')
                    ->leftJoinSub($customers, 'cc', 'cc.location_id', '=', 'r.id')
                    ->leftJoinSub(
                        $metrics->ledgerByRoute($f['from'], $f['to'], null, $company)
                            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id')),
                        'lg', 'lg.route_id', '=', 'r.id',
                    )
                    ->leftJoinSub($people, 'sp', 'sp.route_id', '=', 'r.id')
                    ->where('r.company_id', $company)
                    ->where('r.level', Location::ROUTE)
                    ->whereNull('r.deleted_at')
                    ->orderByRaw('COALESCE(lg.sales, 0) DESC')
                    ->orderBy('r.code')
                    ->select([
                        'r.id as route_id',
                        'r.code as route_code',
                        DB::raw(self::localised('r').' as route_name'),
                        DB::raw(self::localised('p').' as point_name'),
                        DB::raw('COALESCE(cc.customer_count, 0) as customer_count'),
                        'sp.salespeople',
                        DB::raw('COALESCE(lg.opening, 0) as opening'),
                        DB::raw('COALESCE(lg.sales, 0) as sales'),
                        DB::raw('COALESCE(lg.returns, 0) as returns'),
                        DB::raw('COALESCE(lg.collections, 0) as collections'),
                        DB::raw('COALESCE(lg.other, 0) as other'),
                        DB::raw('COALESCE(lg.outstanding, 0) as outstanding'),
                    ]);
            },
            columns: [
                ['key' => 'route_code', 'label' => 'sales::route.code', 'type' => ReportColumn::TEXT],
                /*
                 * ⓘ লেখা, লিংক নয়: `location`-এর ড্রিল যায় এলাকার মাস্টারে
                 * (`master_data.view`), আর বিক্রয়ের রিপোর্ট যিনি দেখেন তাঁর
                 * ঐ চাবি না-ও থাকতে পারে — তখন নামটা জীবন্ত দেখাত অথচ ৪০৩ দিত।
                 * রুটের পুরো খাতা রুটের পর্দায় (`sales.route.show`)।
                 */
                ['key' => 'route_name', 'label' => 'sales::route.route', 'type' => ReportColumn::TEXT],
                ['key' => 'point_name', 'label' => 'master_data::level.point', 'type' => ReportColumn::TEXT],
                ['key' => 'customer_count', 'label' => 'sales::route.customers'],
                ['key' => 'salespeople', 'label' => 'sales::route.salespeople', 'type' => ReportColumn::TEXT],
                ['key' => 'opening', 'label' => 'sales::route.opening', 'type' => ReportColumn::MONEY],
                ['key' => 'sales', 'label' => 'sales::route.sales', 'type' => ReportColumn::MONEY],
                ['key' => 'returns', 'label' => 'sales::route.returns', 'type' => ReportColumn::MONEY],
                ['key' => 'collections', 'label' => 'sales::route.collections', 'type' => ReportColumn::MONEY],
                ['key' => 'other', 'label' => 'sales::route.other', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'sales::route.outstanding', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    private static function localised(string $table): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$table}.name_bn, ''), {$table}.name_en)"
            : "COALESCE(NULLIF({$table}.name_en, ''), {$table}.name_bn)";
    }
}
