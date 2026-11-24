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
 * ⭐ বিক্রয়কর্মীর কাজের হিসাব — মালিক, ২৩ নভেম্বর ২০২৬ (রোডম্যাপ §৩, রিপোর্ট ১৩; অনুমোদিত নমুনা claude.ai artifact
 * 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * প্রতি জনের এক সারি:
 *   দায়িত্বে গ্রাহক      শেষ তারিখে যে সচল গ্রাহকদের দায়িত্ব তাঁর ([[ResponsibleSql]])
 *   বিল হয়েছে এমন গ্রাহক  এই সময়ে তাঁর নামে বিল হয়েছে এমন গ্রাহক; "সক্রিয়" = এটা ÷ দায়িত্বে গ্রাহক
 *   বিল, নিট বিক্রি, গড় বিল  বিলে লেখা দায়িত্বপ্রাপ্ত জনের ঘর থেকে ([[SalespersonReports]]-এর একই ঘর); ফেরত ফেরতের ঘর থেকে
 *   আদায়              "টাকা আসা"র একই সারি, রসিদের তারিখে দায়িত্বপ্রাপ্ত জনের নামে ([[MoneyInReports::rows()]])
 *   লক্ষ্য অর্জন         নিট বিক্রি ÷ তাঁর মাসিক লক্ষ্য (`sal_targets`)
 *   নতুন গ্রাহক          এই সময়ে জীবনের প্রথম বিল, সেই বিলের জনের নামে
 *   ঘুমন্ত গ্রাহক         দায়িত্বে থাকা গ্রাহকের মধ্যে ৩০ দিনের বেশি কেনেননি (বা কখনোই না)
 *
 * ⚠️ "কত দোকানে গেলেন" এখানে নেই: ভিজিটের খাতা সিস্টেমে নেই। তার জায়গায় "বিল হয়েছে এমন গ্রাহক" — যা মাপা যায় তাই দেখানো হয়;
 * ভিজিটের খাতা এলে ঘরটা যোগ হবে।
 * ⓘ দলের ভাগ [[SalespersonReports::teamsFor()]]-এর — DSM-এর নিচে সবাই; সর্বমোট মানুষ ধরে একবার।
 */
final class SalesForceReports
{
    public const KEY = 'sales.salesperson_productivity';

    /** এত দিনের বেশি বিল না হলে গ্রাহক ঘুমন্ত — [[CustomerInsightReports::IDLE]]-এর প্রথম ধাপ */
    private const IDLE_AFTER = 30;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::productivity());
    }

    private static function productivity(): ReportDefinition
    {
        $door = fn (string $slug): array => ['route' => 'sales.report.show',
            'params' => ['slug' => '='.$slug, 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'total' => ['slug' => '='.$slug, 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::customer_insight.drill_person'];
        $sales = $door('by-salesperson');
        $count = fn (string $key): array => ['key' => $key, 'label' => 'sales::sales_force.'.$key, 'type' => ReportColumn::QUANTITY, 'width' => '6rem'];

        return new ReportDefinition(
            key: self::KEY,
            permission: 'sales.report',
            title: 'sales::sales_force.title',
            filters: ['date_range', 'branch'],
            groupBy: 'person_id',
            rankBy: 'net_sales',
            splitByBranch: false,
            sections: fn (array $f): array => SalespersonReports::teamsFor($f),
            query: function (array $f): Builder {
                $company = (int) $f['company_id'];
                [$from, $to] = [(string) $f['from'], (string) $f['to']];
                $pdo = DB::getPdo();
                // ঘরের ক্রম সব ভাগে এক: জন, বিল হওয়া গ্রাহক, বিল, বিক্রি, আদায়, লক্ষ্য, দায়িত্বে, ঘুমন্ত, নতুন গ্রাহক
                $row = fn (string $person, string $customer = 'NULL', string $bill = 'NULL', string $sold = '0', string $money = '0',
                    string $target = '0', string $held = '0', string $idle = '0', string $fresh = 'NULL'): string => "{$person} as person_id, {$customer} as customer_id, {$bill} as invoice_id, {$sold} as sold, {$money} as money, "
                    ."{$target} as target, {$held} as held, {$idle} as idle, {$fresh} as fresh_id";

                // ⓘ নতুন গ্রাহক — এই বিলের আগে গ্রাহকের খাতায় বসা আর কোনো বিল নেই
                $first = 'CASE WHEN NOT EXISTS (SELECT 1 FROM sal_invoices AS e WHERE e.company_id = i.company_id AND e.customer_id = i.customer_id'
                    .' AND e.deleted_at IS NULL AND e.status IN ('.implode(', ', array_map(fn (string $s) => $pdo->quote($s), DocumentStatus::POSTED)).')'
                    .' AND e.trx_date < '.$pdo->quote($from).') THEN i.customer_id END';

                $bills = DB::table('sal_invoices as i')
                    ->where('i.company_id', $company)->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)->whereBetween('i.trx_date', [$from, $to])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->selectRaw($row('i.sales_person_id', 'i.customer_id', 'i.id', SalesMetrics::billNetSql('i'), fresh: $first));
                $returns = DB::table('sal_returns as sr')
                    ->where('sr.company_id', $company)->whereNull('sr.deleted_at')
                    ->whereIn('sr.status', DocumentStatus::POSTED)->whereBetween('sr.trx_date', [$from, $to])
                    ->tap(ReportEngine::branchWall($f, 'sr.branch_id'))->tap(ReportEngine::dealerWall($f, 'sr.customer_id'))
                    ->selectRaw($row('sr.sales_person_id', sold: '0 - '.SalesMetrics::returnNetSql('sr')));
                $money = MoneyInReports::rows($f)->selectRaw($row('m.person_id', money: 'm.amount'));
                $targets = DB::table('sal_targets as tg')
                    ->where('tg.company_id', $company)
                    ->whereBetween('tg.month', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->startOfMonth()->toDateString()])
                    ->tap(ReportEngine::branchWall($f, 'tg.branch_id'))
                    // ⛔ বিক্রয়কর্মী কেবল নিজের লক্ষ্য দেখেন
                    ->when(! empty($f['dealer_walled']), fn (Builder $q) => $q->where('tg.user_id', (int) auth()->id()))
                    ->selectRaw($row('tg.user_id', target: 'tg.amount'));

                // দায়িত্বে থাকা সচল গ্রাহক, আর তাঁদের শেষ বিল — ঘুমন্ত গোনার জন্য
                $last = DB::table('sal_invoices as h')
                    ->where('h.company_id', $company)->whereNull('h.deleted_at')
                    ->whereIn('h.status', DocumentStatus::POSTED)->where('h.trx_date', '<=', $to)
                    ->groupBy('h.customer_id')->selectRaw('h.customer_id as customer_id, MAX(h.trx_date) as last_bill');
                $held = DB::table('customers as cu')
                    ->leftJoinSub($last, 'lb', 'lb.customer_id', '=', 'cu.id')
                    ->tap(fn (Builder $q) => SalesPlace::join($q))
                    ->where('cu.company_id', $company)->whereNull('cu.deleted_at')->where('cu.is_active', true)
                    ->tap(ReportEngine::branchWall($f, 'cu.branch_id'))->tap(ReportEngine::dealerWall($f, 'cu.id'))
                    ->selectRaw($row(ResponsibleSql::person($company, 'cu.id', $pdo->quote($to)), held: '1',
                        idle: 'CASE WHEN lb.last_bill IS NULL OR DATEDIFF('.$pdo->quote($to).', lb.last_bill) > '.self::IDLE_AFTER.' THEN 1 ELSE 0 END'));

                $nobody = $pdo->quote((string) __('sales::money_in.no_person'));

                return DB::query()->fromSub($bills->unionAll($returns)->unionAll($money)->unionAll($targets)->unionAll($held), 'x')
                    ->leftJoin('users as u', 'u.id', '=', 'x.person_id')
                    // ⓘ দলের ভাগ ([[SalespersonReports::teamsFor()]]) — এই জনেরা, বা এঁরা বাদে আর জন বসানো নেই এমন
                    ->when(isset($f['team_ids']), fn (Builder $q) => $q->whereIn('x.person_id', $f['team_ids'] === [] ? [0] : $f['team_ids']))
                    ->when(isset($f['team_outside']), fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('x.person_id')
                        ->when($f['team_outside'] !== [], fn ($n) => $n->orWhereNotIn('x.person_id', $f['team_outside']))))
                    ->groupBy('x.person_id', 'u.name')
                    ->selectRaw("x.person_id as person_id, COALESCE(u.name, {$nobody}) as person, SUM(x.held) as held, "
                        .'COUNT(DISTINCT x.customer_id) as billed, '
                        .'CASE WHEN SUM(x.held) > 0 THEN ROUND(COUNT(DISTINCT x.customer_id) * 100 / SUM(x.held), 2) END as active_share, '
                        .'COUNT(DISTINCT x.invoice_id) as bills, SUM(x.sold) as net_sales, '
                        .'CASE WHEN COUNT(DISTINCT x.invoice_id) > 0 THEN ROUND(SUM(x.sold) / COUNT(DISTINCT x.invoice_id), 2) END as avg_bill, '
                        .'SUM(x.money) as collected, SUM(x.target) as target, '
                        .'CASE WHEN SUM(x.target) > 0 THEN ROUND(SUM(x.sold) * 100 / SUM(x.target), 2) END as achieved, '
                        .'COUNT(DISTINCT x.fresh_id) as new_customers, SUM(x.idle) as idle_customers')
                    ->orderByRaw('SUM(x.sold) DESC')->orderBy('x.person_id');
            },
            columns: [
                ['key' => 'person', 'label' => 'sales::money_in.person'],
                $count('held'),
                $count('billed'),
                ['key' => 'active_share', 'label' => 'sales::sales_force.active_share', 'type' => ReportColumn::PERCENT, 'ratio' => ['billed', 'held'], 'width' => '6rem'],
                ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                // ⓘ গড়ের ঘর যোগ হয় না (চাবিতে `avg` — [[ReportColumn]]-এর নিয়ম); মোটের সারিতে খালি
                ['key' => 'avg_bill', 'label' => 'sales::sales_force.avg_bill', 'type' => ReportColumn::MONEY, 'drill' => $sales],
                ['key' => 'collected', 'label' => 'sales::area_sales.collected', 'type' => ReportColumn::MONEY,
                    'drill' => $door(MoneyInReports::SLUG[MoneyInReports::PERSON])],
                ['key' => 'target', 'label' => 'sales::customer_insight.target', 'type' => ReportColumn::MONEY,
                    'drill' => $door('target-sales-salesperson')],
                ['key' => 'achieved', 'label' => 'sales::customer_insight.achieved', 'type' => ReportColumn::PERCENT, 'ratio' => ['net_sales', 'target'], 'width' => '7rem'],
                $count('new_customers'),
                $count('idle_customers'),
            ],
            chart: ['type' => 'pair', 'label' => 'person', 'series' => ['target', 'net_sales', 'collected'], 'title' => 'sales::sales_force.chart'],
        );
    }
}
