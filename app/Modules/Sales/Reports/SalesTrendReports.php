<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রির ধারা — মাস ধরে আর তিন মাস ধরে, গত বছরের একই সময়ের পাশে (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ৫;
 * অনুমোদিত নমুনা claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * প্রতি মাসের (বা তিন মাসের) এক সারি: বিক্রি, ফেরত, নিট বিক্রি, গত বছরের একই মাসের নিট আর তার চেয়ে কত %, আসা টাকা আর নিট বিক্রির
 * কত ভাগ আদায়। ⓘ কেবল যে মাসে কিছু আছে সে মাসের সারি — লাইভে তথ্য অক্টোবর ২০২৬ থেকে, তাই আগের মাস আর গত বছরের ঘর খালি।
 *
 * ⛔ দ্বিতীয় কোনো গণনা নয়: বিক্রি আর ফেরত এলাকা ধরে বিক্রয়ের একই সংজ্ঞায় ([[SalesMetrics::billNetSql()]], ফেরত ফেরতের দিনে); আসা
 * টাকা "টাকা আসা" রিপোর্টের একই সারি ([[MoneyInReports::rows()]]) — তাই সময় মিলিয়ে দিলে মোট ওই রিপোর্টগুলোর সমান।
 * ⓘ মাসওয়ারি বিক্রয় ([[MonthlySalesReport]]) আগের মতোই আছে — সেটা বিলের খাতা (ভ্যাট, ছাড়, বাতিলসহ); এটা তুলনার পাতা।
 */
final class SalesTrendReports
{
    public const MONTHLY = 'sales.trend_monthly';

    public const QUARTERLY = 'sales.trend_quarterly';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::trend(self::MONTHLY, 'sales::sales_trend.title_monthly', 1));
        $engine->register(self::trend(self::QUARTERLY, 'sales::sales_trend.title_quarterly', 3));
    }

    /** সময়ের ভাগের প্রথম দিন, `Y-m-01` — মাস, বা তিন মাসের ভাগ (জানুয়ারি, এপ্রিল, জুলাই, অক্টোবর থেকে) */
    private static function start(string $date, int $months): string
    {
        return $months === 1
            ? "DATE_FORMAT({$date}, '%Y-%m-01')"
            : "CONCAT(YEAR({$date}), '-', LPAD((QUARTER({$date}) - 1) * 3 + 1, 2, '0'), '-01')";
    }

    private static function trend(string $key, string $title, int $months): ReportDefinition
    {
        $door = fn (string $slug, string $from, string $to): array => [
            'route' => 'sales.report.show',
            'params' => ['slug' => '='.$slug, 'from' => $from, 'to' => $to, 'branch_id' => '@branch_id'],
            'total' => ['slug' => '='.$slug, 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::sales_trend.drill',
        ];
        $sales = $door(AreaSalesReports::levelSlug('area'), 'period_from', 'period_to');

        return new ReportDefinition(
            key: $key,
            permission: 'sales.report',
            title: $title,
            filters: ['date_range', 'branch'],
            // ⓘ খুললেই চলতি অর্থবছর — "এই মাস"-এ একটাই সারি আসত, ধারা দেখা যেত না
            defaultPeriod: ReportDefinition::PERIOD_FISCAL_YEAR,
            query: function (array $f) use ($months): Builder {
                $company = (int) $f['company_id'];
                [$from, $to] = [(string) $f['from'], (string) $f['to']];
                $lastFrom = Carbon::parse($from)->subYear()->toDateString();
                $lastTo = Carbon::parse($to)->subYear()->toDateString();
                $pdo = DB::getPdo();

                $bills = fn (string $a, string $b): Builder => DB::table('sal_invoices as i')
                    ->where('i.company_id', $company)->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)->whereBetween('i.trx_date', [$a, $b])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))->tap(ReportEngine::dealerWall($f, 'i.customer_id'));
                $returns = fn (string $a, string $b): Builder => DB::table('sal_returns as sr')
                    ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
                    ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$a, $b])
                    ->tap(ReportEngine::branchWall($f, 'sr.branch_id'))->tap(ReportEngine::dealerWall($f, 'sr.customer_id'));
                $net = SalesMetrics::billNetSql('i');
                $back = SalesMetrics::returnNetSql('sr');
                // ⓘ গত বছরের সারি এক বছর এগিয়ে এ বছরের একই ভাগে বসে
                $nextYear = fn (string $column): string => "DATE_ADD({$column}, INTERVAL 1 YEAR)";

                $union = $bills($from, $to)->selectRaw(self::start('i.trx_date', $months)." as start, {$net} as sold, 0 as returned, 0 as last_year, 0 as money")
                    ->unionAll($returns($from, $to)->selectRaw(self::start('sr.trx_date', $months)." as start, 0 as sold, {$back} as returned, 0 as last_year, 0 as money"))
                    ->unionAll($bills($lastFrom, $lastTo)->selectRaw(self::start($nextYear('i.trx_date'), $months)." as start, 0 as sold, 0 as returned, {$net} as last_year, 0 as money"))
                    ->unionAll($returns($lastFrom, $lastTo)->selectRaw(self::start($nextYear('sr.trx_date'), $months)." as start, 0 as sold, 0 as returned, 0 - {$back} as last_year, 0 as money"))
                    ->unionAll(MoneyInReports::rows($f)->selectRaw(self::start('m.trx_date', $months).' as start, 0 as sold, 0 as returned, 0 as last_year, m.amount as money'));

                $end = 'LAST_DAY(DATE_ADD(x.start, INTERVAL '.($months - 1).' MONTH))';
                $label = $months === 1 ? "DATE_FORMAT(x.start, '%Y-%m')" : "CONCAT(YEAR(x.start), ' Q', QUARTER(x.start))";
                $sold = 'SUM(x.sold) - SUM(x.returned)';

                return DB::query()->fromSub($union, 'x')
                    ->groupBy('x.start')
                    // ⓘ সারির সময় বাছা সময়ের ভেতরে কাটা — আধা মাস বাছলে অঙ্কে চাপলেও সেই আধা মাসই খোলে
                    ->selectRaw("{$label} as period, GREATEST(x.start, ".$pdo->quote($from).") as period_from, LEAST({$end}, ".$pdo->quote($to).') as period_to, '
                        .'SUM(x.sold) as sales, SUM(x.returned) as returns, '.$sold.' as net_sales, SUM(x.last_year) as ly_net, '
                        ."CASE WHEN SUM(x.last_year) > 0 THEN ROUND(({$sold} - SUM(x.last_year)) * 100 / SUM(x.last_year), 2) END as ly_net_change, "
                        ."SUM(x.money) as money_in, CASE WHEN {$sold} > 0 THEN ROUND(SUM(x.money) * 100 / ({$sold}), 2) END as money_share")
                    ->orderBy('x.start');
            },
            columns: [
                ['key' => 'period', 'label' => $months === 1 ? 'sales::sales_trend.month' : 'sales::sales_trend.quarter', 'width' => '8rem'],
                ['key' => 'sales', 'label' => 'sales::area_sales.sales', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                ['key' => 'returns', 'label' => 'sales::area_sales.returns', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                // ⓘ গত বছরের অঙ্কের উৎস পণ্য বা গ্রাহক ধরে একই পাতায় দেখা যায় না (সারি এক বছর সরানো) — এলাকার পাতা খোলে, সময়টা পাঠক পিছিয়ে নেন
                ['key' => 'ly_net', 'label' => 'sales::area_sales.ly_net', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                ['key' => 'ly_net_change', 'label' => 'sales::area_sales.ly_net_change', 'type' => ReportColumn::PERCENT,
                    'ratio' => ['net_sales', 'ly_net', 'change'], 'width' => '7rem'],
                ['key' => 'money_in', 'label' => 'sales::sales_trend.money_in', 'type' => ReportColumn::MONEY,
                    'drill' => $door(MoneyInReports::SLUG[MoneyInReports::DAY], 'period_from', 'period_to')],
                ['key' => 'money_share', 'label' => 'sales::sales_trend.money_share', 'type' => ReportColumn::PERCENT,
                    'ratio' => ['money_in', 'net_sales'], 'width' => '8rem'],
            ],
            chart: ['type' => 'pair', 'label' => 'period', 'series' => ['net_sales', 'ly_net', 'money_in'], 'title' => 'sales::sales_trend.chart'],
        );
    }
}
