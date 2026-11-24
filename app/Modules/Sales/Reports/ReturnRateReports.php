<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ফেরতের হার — পণ্য ধরে আর এলাকা › টেরিটরি › পয়েন্ট › গ্রাহক ধরে (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ১২; অনুমোদিত
 * নমুনা claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)। আগে ফেরত কেবল কারণ ধরে দেখা যেত ([[SalesReturnReasonReports]])।
 *
 * হার = ফেরত ÷ বিক্রি (দুটোই ভ্যাট বাদ, ফেরত ফেরতের দিনে); মোটে মোট ÷ মোট। পাশে সবচেয়ে বেশি যে কারণে — টাকার অঙ্কে বড় কারণটা
 * (লাইনের কারণ, না থাকলে ফেরতের কাগজের কারণ)।
 *
 * ⛔ দ্বিতীয় কোনো গণনা নয়: পণ্যের সারি পণ্যভিত্তিক মুনাফার ([[ProductInsightReports::rows()]]), জায়গা আর গ্রাহকের সারি এলাকা ধরে
 * বিক্রয়ের ([[AreaSalesReports::rows()]])।
 */
final class ReturnRateReports
{
    public const PRODUCT = 'sales.return_rate_product';

    public static function levelKey(string $level): string
    {
        return 'sales.return_rate_'.$level;
    }

    public static function levelSlug(string $level): string
    {
        return 'return-rate-'.$level;
    }

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byProduct());

        foreach (SalesPlace::LEVELS as $level) {
            $engine->register(self::byLevel($level));
        }
    }

    /**
     * সবচেয়ে বেশি যে কারণে ফেরত — টাকার অঙ্কে বড় কারণের নাম; `$match` সারির সাথে মেলানোর শর্ত (যেমন `rl2.product_id = n.product_id`)।
     *
     * @param  array<string, mixed>  $f
     */
    private static function topReason(array $f, string $match): string
    {
        $pdo = DB::getPdo();
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(rc.name_bn, ''), rc.name_en)" : "COALESCE(NULLIF(rc.name_en, ''), rc.name_bn)";
        $posted = implode(', ', array_map(fn (string $s) => $pdo->quote($s), DocumentStatus::POSTED));

        return "(SELECT {$name} FROM sal_return_lines AS rl2 JOIN sal_returns AS r2 ON r2.id = rl2.sales_return_id"
            .' JOIN mdm_reason_codes AS rc ON rc.id = COALESCE(rl2.reason_code_id, r2.reason_code_id)'
            .' WHERE r2.company_id = '.(int) $f['company_id']." AND r2.deleted_at IS NULL AND r2.status IN ({$posted})"
            .' AND r2.trx_date BETWEEN '.$pdo->quote((string) $f['from']).' AND '.$pdo->quote((string) $f['to'])." AND {$match}"
            .' GROUP BY rc.id, rc.name_bn, rc.name_en ORDER BY SUM(rl2.amount) DESC, rc.id LIMIT 1)';
    }

    /** @return list<array<string, mixed>> বিক্রি, ফেরত, হার, নিট — সব রূপে একই চার ঘর */
    private static function figures(array $door): array
    {
        return [
            ['key' => 'sales', 'label' => 'sales::area_sales.sales', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ['key' => 'returned', 'label' => 'sales::area_sales.returns', 'type' => ReportColumn::MONEY, 'drill' => $door],
            ['key' => 'return_rate', 'label' => 'sales::return_rate.rate', 'type' => ReportColumn::PERCENT, 'ratio' => ['returned', 'sales'], 'width' => '7rem'],
            ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $door],
        ];
    }

    private static function byProduct(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::PRODUCT,
            permission: 'sales.report',
            title: 'sales::return_rate.title_product',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            rankBy: 'returned',
            query: fn (array $f): Builder => DB::query()->fromSub(ProductInsightReports::rows($f), 'n')
                ->selectRaw('n.product_id as product_id, '.DB::getPdo()->quote(Product::drillSourceType()).' as source_type, n.product, n.sales, n.returned, '
                    .'CASE WHEN n.sales > 0 THEN ROUND(n.returned * 100 / n.sales, 2) END as return_rate, n.net_sales, '
                    .self::topReason($f, 'rl2.product_id = n.product_id').' as reason')
                // ⓘ হার বড় আগে; বিক্রি ছাড়া ফেরত (আগের মাসের মাল) সবার উপরে
                ->orderByRaw('CASE WHEN n.sales > 0 THEN n.returned / n.sales ELSE 999 END DESC')->orderByDesc('n.returned')->orderBy('n.product_id'),
            columns: [
                ['key' => 'product', 'label' => 'sales::field.product', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'product_id'],
                ...self::figures(ProductInsightReports::door()),
                ['key' => 'reason', 'label' => 'sales::return_rate.reason'],
            ],
            chart: ['type' => 'bars', 'label' => 'product', 'series' => ['returned'], 'title' => 'sales::area_sales.returns'],
        );
    }

    private static function byLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);
        $door = $below === null
            ? ['source' => ['type' => 'source_type', 'id' => 'source_id']]
            : ['route' => 'sales.report.show',
                'params' => ['slug' => '='.self::levelSlug($below), 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id',
                    'salesman_id' => '@salesman_id', 'location_id' => 'place_id'],
                'total' => ['slug' => '='.self::levelSlug($below), 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id',
                    'salesman_id' => '@salesman_id', 'location_id' => '@location_id'],
                'label' => 'sales::area_sales.drill_down'];

        return new ReportDefinition(
            key: self::levelKey($level),
            permission: 'sales.report',
            title: 'sales::return_rate.title_'.$level,
            filters: ['date_range', 'branch', 'location_id', 'salesman_id', 'customer_id'],
            groupBy: 'place_id',
            rankBy: 'returned',
            splitByBranch: false,
            query: function (array $f) use ($level): Builder {
                $q = AreaSalesReports::rows($f)->whereRaw('(r.sales <> 0 OR r.returns <> 0)');
                $g = SalesPlace::grouped($q, 'r', $level);
                // ⓘ কারণ কেবল গ্রাহকের স্তরে — উপরের স্তরে এক সারিতে অনেক গ্রাহকের অনেক কারণ
                $reason = $level === SalesPlace::CUSTOMER ? self::topReason($f, 'r2.customer_id = r.customer_id') : 'NULL';

                return $q->selectRaw("{$g['id']} as place_id, ".DB::getPdo()->quote('customer')." as source_type, {$g['id']} as source_id, "
                    ."{$g['place']} as place, {$g['parent']} as parent, COUNT(*) as customers, SUM(r.sales) as sales, SUM(r.returns) as returned, "
                    .'CASE WHEN SUM(r.sales) > 0 THEN ROUND(SUM(r.returns) * 100 / SUM(r.sales), 2) END as return_rate, '
                    ."SUM(r.sales) - SUM(r.returns) as net_sales, {$reason} as reason")
                    ->orderByRaw('CASE WHEN SUM(r.sales) > 0 THEN SUM(r.returns) / SUM(r.sales) ELSE 999 END DESC')->orderByRaw($g['order']);
            },
            columns: [
                ['key' => 'place', 'label' => 'sales::area_sales.level_'.$level],
                ...($level === Location::AREA ? [] : [['key' => 'parent', 'label' => 'sales::area_sales.parent_'.$level]]),
                ...self::figures($door),
                ...($level === SalesPlace::CUSTOMER ? [['key' => 'reason', 'label' => 'sales::return_rate.reason']] : []),
            ],
            chart: ['type' => 'bars', 'label' => 'place', 'series' => ['returned'], 'title' => 'sales::area_sales.returns'],
        );
    }
}
