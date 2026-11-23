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
 * ⭐ দৈনিক বিক্রি ও আদায়ের সারাংশ, শাখা ধরে — রোজ সন্ধ্যার এক পাতার হিসাব (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ১৫;
 * অনুমোদিত নমুনা claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * এক দিন (বাছা তারিখ), প্রতি শাখার এক সারি: বিল, বিক্রি, ফেরত, নিট বিক্রি; আসা টাকা নগদ / ব্যাংক / মোবাইল ব্যাংকিং আর মোট; মাসের
 * ১ তারিখ থেকে সেই দিন পর্যন্ত নিট বিক্রি আর আসা টাকা; আর দিন শেষে গ্রাহকদের বাকি।
 *
 * ⛔ দ্বিতীয় কোনো গণনা নয়: বিক্রি আর ফেরত এলাকা ধরে বিক্রয়ের সংজ্ঞায় ([[SalesMetrics]]), আসা টাকা "টাকা আসা"র একই সারি
 * ([[MoneyInReports::rows()]]) — তাই সেই দিনের মোট ওই রিপোর্টের সেই দিনের সারির সমান।
 * ⓘ শাখা কাগজের নিজের (বিল, ফেরত, খাতার সারি); কোনো নড়াচড়া আর বাকি নেই এমন শাখার সারি আসে না।
 */
final class DailySummaryReports
{
    public const KEY = 'sales.daily_summary';

    private const KINDS = ['cash', 'bank', 'mfs'];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::summary());
    }

    private static function summary(): ReportDefinition
    {
        $door = fn (string $slug, string $from): array => ['route' => 'sales.report.show',
            'params' => ['slug' => '='.$slug, 'from' => $from, 'to' => '@to', 'branch_id' => 'branch_id'],
            'total' => ['slug' => '='.$slug, 'from' => $from === 'month_from' ? '@to' : $from, 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::daily_summary.drill'];
        // ⓘ দিনের অঙ্ক সেই দিনের পাতা খোলে (থেকে = পর্যন্ত); মাসের অঙ্ক মাসের ১ তারিখ থেকে — মোটের সারিতে সারির ঘর নেই, তাই সেখানে দিনটাই
        $day = fn (string $slug): array => $door($slug, '@to');
        $money = fn (string $key, array $drill): array => ['key' => $key, 'label' => 'sales::daily_summary.'.$key, 'type' => ReportColumn::MONEY, 'drill' => $drill];
        $sales = AreaSalesReports::levelSlug('area');
        $papers = MoneyInReports::SLUG[MoneyInReports::PAPERS];

        return new ReportDefinition(
            key: self::KEY,
            permission: 'sales.report',
            title: 'sales::daily_summary.title',
            filters: ['date_range', 'branch'],
            // ⓘ একটাই তারিখ — "কোন দিনের সারাংশ"
            asOfDate: true,
            groupBy: 'branch_id',
            rankBy: 'net_sales',
            splitByBranch: false,
            query: function (array $f): Builder {
                $company = (int) $f['company_id'];
                $to = (string) $f['to'];
                $month = Carbon::parse($to)->startOfMonth()->toDateString();
                $pdo = DB::getPdo();
                $today = fn (string $date, string $value): string => "CASE WHEN {$date} = ".$pdo->quote($to)." THEN {$value} ELSE 0 END";
                // ঘরের ক্রম সব ভাগে এক
                $row = fn (string $branch, string $bills = '0', string $sold = '0', string $returned = '0', string $cash = '0', string $bank = '0',
                    string $mfs = '0', string $monthNet = '0', string $monthMoney = '0', string $due = '0'): string => "{$branch} as branch_id, {$bills} as bills, {$sold} as sold, {$returned} as returned, {$cash} as cash, {$bank} as bank, "
                    ."{$mfs} as mfs, {$monthNet} as month_net, {$monthMoney} as month_money, {$due} as due";

                $net = SalesMetrics::billNetSql('i');
                $back = SalesMetrics::returnNetSql('sr');

                $bills = DB::table('sal_invoices as i')
                    ->where('i.company_id', $company)->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)->whereBetween('i.trx_date', [$month, $to])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->selectRaw($row('i.branch_id', $today('i.trx_date', '1'), $today('i.trx_date', $net), monthNet: $net));
                $returns = DB::table('sal_returns as sr')
                    ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
                    ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$month, $to])
                    ->tap(ReportEngine::branchWall($f, 'sr.branch_id'))->tap(ReportEngine::dealerWall($f, 'sr.customer_id'))
                    ->selectRaw($row('sr.branch_id', returned: $today('sr.trx_date', $back), monthNet: "0 - {$back}"));
                // ⓘ আসা টাকা মাসের ১ তারিখ থেকে — একই সারি থেকে দিনের তিন মাধ্যম আর মাসের মোট
                $kind = fn (string $k): string => $today('m.trx_date', "CASE WHEN m.kind = '{$k}' THEN m.amount ELSE 0 END");
                $money = MoneyInReports::rows([...$f, 'from' => $month])
                    ->selectRaw($row('m.branch_id', cash: $kind('cash'), bank: $kind('bank'), mfs: $kind('mfs'), monthMoney: 'm.amount'));
                // দিন শেষে গ্রাহকদের বাকি — গ্রাহক ধরে জের, শূন্যের নিচে নয় (অগ্রিম বাকি কমায় না), তারপর শাখা ধরে যোগ
                $balances = DB::table('ledger_entries as d')
                    ->where('d.company_id', $company)->where('d.party_type', 'customer')->where('d.trx_date', '<=', $to)
                    ->tap(ReportEngine::branchWall($f, 'd.branch_id'))->tap(ReportEngine::dealerWall($f, 'd.party_id'))
                    ->groupBy('d.branch_id', 'd.party_id')
                    ->selectRaw('d.branch_id as branch_id, GREATEST(SUM(d.debit) - SUM(d.credit), 0) as v');
                $due = DB::query()->fromSub($balances, 'b')->selectRaw($row('b.branch_id', due: 'b.v'));

                $none = $pdo->quote((string) __('sales::area_sales.no_branch'));

                return DB::query()->fromSub($bills->unionAll($returns)->unionAll($money)->unionAll($due), 'x')
                    ->leftJoin('branches as br', 'br.id', '=', 'x.branch_id')
                    ->groupBy('x.branch_id', 'br.name_bn', 'br.name_en')
                    ->havingRaw('SUM(x.bills) <> 0 OR SUM(x.sold) <> 0 OR SUM(x.returned) <> 0 OR SUM(x.cash + x.bank + x.mfs) <> 0'
                        .' OR SUM(x.month_net) <> 0 OR SUM(x.month_money) <> 0 OR SUM(x.due) <> 0')
                    ->selectRaw('x.branch_id as branch_id, COALESCE('.SalesPlace::named('br').", {$none}) as branch, ".$pdo->quote($month).' as month_from, '
                        .'SUM(x.bills) as bills, SUM(x.sold) as sales, SUM(x.returned) as returns, SUM(x.sold) - SUM(x.returned) as net_sales, '
                        .'SUM(x.cash) as cash, SUM(x.bank) as bank, SUM(x.mfs) as mfs, SUM(x.cash + x.bank + x.mfs) as money_in, '
                        .'SUM(x.month_net) as month_net, SUM(x.month_money) as month_money, SUM(x.due) as due')
                    ->orderByRaw('SUM(x.sold) - SUM(x.returned) DESC')->orderBy('x.branch_id');
            },
            columns: [
                ['key' => 'branch', 'label' => 'sales::area_sales.branch'],
                ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                $money('sales', $day($sales)),
                $money('returns', $day($sales)),
                $money('net_sales', $day($sales)),
                ...array_map(fn (string $k) => ['key' => $k, 'label' => 'sales::money_in.kind_'.$k, 'type' => ReportColumn::MONEY,
                    'drill' => (function () use ($day, $papers, $k): array {
                        $d = $day($papers);
                        $d['params']['kind'] = '='.$k;
                        $d['total']['kind'] = '='.$k;

                        return $d;
                    })()], self::KINDS),
                $money('money_in', $day($papers)),
                $money('month_net', $door($sales, 'month_from')),
                $money('month_money', $door($papers, 'month_from')),
                $money('due', $day($sales)),
            ],
            chart: ['type' => 'pair', 'label' => 'branch', 'series' => ['net_sales', 'money_in'], 'title' => 'sales::daily_summary.chart'],
        );
    }
}
