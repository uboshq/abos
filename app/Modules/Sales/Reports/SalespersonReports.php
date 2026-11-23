<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রয়কর্মী ধরে বিক্রি — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (গ), ৬ অক্টোবর ২০২৬ (মালিকের আদেশ "Sales মডিউলের কাজ শেষ দাও",
 * সমন্বয়কের মারফত)।
 *
 * ── ⭐ কার বিক্রি — দায়িত্বপ্রাপ্ত জন, বিল যিনি লিখেছেন কখনো নন (মালিক, ২৩ নভেম্বর ২০২৬) ─────────────────
 * বিলে লেখা `sales_person_id` — নিশ্চিত করার মুহূর্তে ডিলারের দায়িত্বপ্রাপ্ত জন ([[SalesResponsibility::forInvoice()]]); ফেরতে
 * ফেরতের নিজের `sales_person_id` ([[SalesResponsibility::forReturn()]])। ⓘ [[SalesTargetService::achievedByUser()]] হুবহু একই
 * ঘর পড়ে, তাই "নিট বিক্রি" ঘর লক্ষ্যের স্কোরবোর্ডের অর্জনের সমান; দুই পর্দা কখনো আলাদা কথা বলে না।
 * ⛔ আগে দেয়াল বন্ধ থাকলে `created_by` (বিল যিনি কেটেছেন) আর ফেরতে `COALESCE(মূল বিলের লেখক, ফেরতের লেখক)` — অপারেটর
 * বিক্রির কৃতিত্ব পেতেন। সেই পথ আর নেই; কে কত বিল লিখেছেন তা আলাদা কাজের হিসাব ([[BillerReports]]), বিক্রি নয়।
 * ⓘ জন বসানো নেই এমন বিলের বিক্রি একটা আলাদা সারিতে ("দায়িত্ব বসানো নেই") — লুকানো নয়, কারণ ঠিক ওগুলোই সারাতে হবে।
 *
 * ── ⭐ দলের মোট — উপরওয়ালার গাছ ধরে (মালিক, ২৩ নভেম্বর ২০২৬) ───────────────────────────────────────
 * `staff_supervisors` (SM · TSM · RSM · DSM) থেকে: প্রত্যেক উপরওয়ালার একটা ভাগ — নিজের আর নিচের গোটা গাছের (সরাসরি ও পরোক্ষ)
 * সবার সারি, শেষে "দল মোট — নাম"। ⓘ ভাগ ইঞ্জিনের নিজের ([[ReportDefinition::$sections]]) — প্রতিটা ভাগের মোট নিজের কোয়েরিতে।
 * ⛔ একজন একাধিক দলে পড়েন (TSM-এর দল DSM-এর দলেরও অংশ), তাই দলের মোট যোগ করলে দুইবার গোনা হত — নিচের সর্বমোট তাই দলের
 * যোগ নয়, গোটা রিপোর্টের নিজের কোয়েরির (মানুষ ধরে একবার করে)। কোনো দলে নেই এমন জন আর "দায়িত্ব বসানো নেই" শেষ ভাগে।
 * ⓘ উপরওয়ালা না থাকলে ভাগ নেই — আগের মতো এক তালিকা। ⓘ দলের ভাগ শাখার ভাগের জায়গা নেয়; শাখার ছাঁকনি আগের মতোই।
 *
 * ── অঙ্ক ──────────────────────────────────────────────────────────────────────
 * খাতায় বসা বিলের লাইন, ভ্যাট বাদ (ভ্যাট সরকারের টাকা, কারও বিক্রি নয়)। ফেরত ফেরতের দিনে, ফেরতের জনের ঘর থেকে। নিট = বিক্রি − ফেরত।
 */
final class SalespersonReports
{
    public const KEY = 'sales.by_salesperson';

    /** ভাগের ছাঁকনি — এই জনেরা (দলের ভাগ) */
    private const ONLY = 'team_ids';

    /** ভাগের ছাঁকনি — এই জনেরা বাদে, আর জন বসানো নেই এমন (দলের বাইরের ভাগ) */
    private const OUTSIDE = 'team_outside';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::bySalesperson());
    }

    private static function bySalesperson(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⓘ বিক্রয়ের বাকি রিপোর্টগুলোর একই চাবি — ওয়েবের দরজা যেটা দেখে
            permission: 'sales.report',
            title: 'sales::salesperson_report.title',
            filters: ['date_range', 'branch'],
            rankBy: 'net_sales',
            query: function (array $f): Builder {
                $sales = DB::table('sal_invoices as i')
                    ->join('sal_invoice_lines as il', 'il.sales_invoice_id', '=', 'i.id')
                    /*
                     * ⛔ বিলের মাথার ছাড় আর রাউন্ডিংয়ের ভাগ সারিতে ([[LineNetSale]]) — লক্ষ্যের অর্জন আর পণ্য ধরে বিক্রির একই
                     * সংজ্ঞা। আগে `amount − tax`: ৫০০ ছাড়ের ১০,০০০ টাকার বিল কর্মীর নামে ১০,০০০ (ec, Executive লাইন-নিরীক্ষা,
                     * ১৪ অক্টোবর ২০২৬; [[TheSalespeopleCardCountsTheBillDiscountTest]])। ⓘ ফেরতের সারি ভাগটা নিজেই বহন করে।
                     */
                    ->tap(fn ($q) => LineNetSale::joinBasis($q, (int) $f['company_id'], $f['from'], $f['to']))
                    ->where('i.company_id', $f['company_id'])
                    ->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->tap(self::teamWall($f, 'i.sales_person_id'))
                    // ⭐ বিলে লেখা দায়িত্বপ্রাপ্ত জন — `created_by` নয় (মালিক, ২৩ নভেম্বর ২০২৬)
                    ->selectRaw('i.sales_person_id as seller_id, i.customer_id as customer_id, i.id as invoice_id, '
                        .LineNetSale::sql().' as sold, 0 as returned');

                $returns = DB::table('sal_returns as r')
                    ->join('sal_return_lines as rl', 'rl.sales_return_id', '=', 'r.id')
                    ->where('r.company_id', $f['company_id'])
                    ->whereNull('r.deleted_at')
                    ->whereIn('r.status', DocumentStatus::POSTED)
                    ->whereBetween('r.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'r.customer_id'))
                    ->tap(self::teamWall($f, 'r.sales_person_id'))
                    /*
                     * ⭐ ফেরতে লেখা দায়িত্বপ্রাপ্ত জন (মালিক, ২৩ নভেম্বর ২০২৬)। ⛔ ফেরতের সারির `amount` আগেই ভ্যাট বাদে
                     * ([[SalesReturnService::replaceLines()]]: `amount` = ভাগ − ভ্যাট) — আগে `amount − tax` ভ্যাট দুইবার কাটত
                     * (fe-র নিট বিক্রি, ২০ অক্টোবর ২০২৬)। ⓘ সারির যোগ = ফেরতের `total − tax` = লক্ষ্যের অর্জনে কাটা ফেরত।
                     */
                    ->selectRaw('r.sales_person_id as seller_id, r.customer_id as customer_id, NULL as invoice_id, 0 as sold, rl.amount as returned');

                $nobody = DB::getPdo()->quote((string) __('sales::salesperson_report.nobody'));

                // ⓘ ভিতরে সারি-প্রতি বিক্রেতা, বাইরে যোগ — ONLY_FULL_GROUP_BY-তে নিরাপদ
                return DB::query()->fromSub($sales->unionAll($returns), 'x')
                    ->leftJoin('users as u', 'u.id', '=', 'x.seller_id')
                    ->groupBy('x.seller_id', 'u.name')
                    ->selectRaw("x.seller_id as seller_id, COALESCE(u.name, {$nobody}) as salesperson, "
                        .'COUNT(DISTINCT x.customer_id) as dealer_count, COUNT(DISTINCT x.invoice_id) as invoice_count, '
                        .'SUM(x.sold) as sales, SUM(x.returned) as returns, SUM(x.sold) - SUM(x.returned) as net_sales')
                    ->orderByRaw('SUM(x.sold) - SUM(x.returned) DESC')
                    ->orderBy('x.seller_id');
            },
            sections: fn (array $f): array => self::teamsFor($f),
            columns: [
                ['key' => 'salesperson', 'label' => 'sales::salesperson_report.salesperson'],
                ['key' => 'dealer_count', 'label' => 'sales::salesperson_report.dealers', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'invoice_count', 'label' => 'sales::salesperson_report.invoices', 'type' => ReportColumn::QUANTITY],
                ['key' => 'sales', 'label' => 'sales::salesperson_report.sales', 'type' => ReportColumn::MONEY],
                ['key' => 'returns', 'label' => 'sales::salesperson_report.returns', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'sales::salesperson_report.net_sales', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * দলের ভাগের ছাঁকনি — কেবল ভাগের কোয়েরিতে আসে ([[teamsFor()]]); ⓘ ঠিকানা থেকে আসে না, কারণ ঘোষিত ছাঁকনি নয়।
     *
     * @param  array<string, mixed>  $f
     */
    private static function teamWall(array $f, string $column): Closure
    {
        return function ($q) use ($f, $column): void {
            if (isset($f[self::ONLY])) {
                $q->whereIn($column, $f[self::ONLY] === [] ? [0] : $f[self::ONLY]);
            }

            if (isset($f[self::OUTSIDE])) {
                $outside = $f[self::OUTSIDE];
                $q->where(fn ($w) => $w->whereNull($column)->when($outside !== [], fn ($n) => $n->orWhereNotIn($column, $outside)));
            }
        };
    }

    /**
     * ⭐ উপরওয়ালার গাছ ধরে ভাগ — প্রত্যেক উপরওয়ালা (যাঁর নিচে কেউ আছেন) একটা ভাগ: নিজে আর নিচের সবাই, সরাসরি ও পরোক্ষ।
     * ক্রম গাছের — শীর্ষ (DSM) আগে, তারপর তাঁর নিচের উপরওয়ালারা; একই স্তরে নাম ধরে। শেষ ভাগ "কোনো দলে নয়" (দলের বাইরের জন
     * আর জন বসানো নেই এমন বিল)। ⛔ চক্র (ভুল ডেটা) থাকলে একজন দুইবার ঘোরা হয় না। ⓘ উপরওয়ালা নেই = ভাগ নেই।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{id: ?int, name: string, total_label: string, filters: array<string, mixed>}>
     */
    public static function teamsFor(array $f): array
    {
        $links = DB::table('staff_supervisors')
            ->where('company_id', $f['company_id'])
            ->get(['user_id', 'supervisor_id']);

        if ($links->isEmpty()) {
            return [];
        }

        /** @var array<int, list<int>> $children */
        $children = [];

        foreach ($links as $link) {
            $children[(int) $link->supervisor_id][] = (int) $link->user_id;
        }

        $bosses = array_keys($children);
        $people = array_values(array_unique([...$bosses, ...array_merge(...array_values($children))]));
        $names = User::query()->whereIn('id', $people)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        $byName = fn (int $a, int $b): int => strcmp($names[$a] ?? '', $names[$b] ?? '') ?: $a <=> $b;

        $under = array_fill_keys($links->pluck('user_id')->map(fn ($id) => (int) $id)->all(), true);
        $roots = array_values(array_filter($bosses, fn (int $id) => ! isset($under[$id])));
        // ⓘ সবাই কারও নিচে = চক্র; তখন প্রত্যেক উপরওয়ালাই শুরু হতে পারেন — দেখা-তালিকা দুইবার আটকায়
        $roots = $roots === [] ? $bosses : $roots;
        usort($roots, $byName);

        $order = [];
        $seen = [];
        $walk = function (int $boss) use (&$walk, &$order, &$seen, $children, $byName): void {
            if (isset($seen[$boss])) {
                return;
            }

            $seen[$boss] = true;
            $order[] = $boss;
            $subs = array_values(array_filter($children[$boss] ?? [], fn (int $id) => isset($children[$id])));
            usort($subs, $byName);

            foreach ($subs as $sub) {
                $walk($sub);
            }
        };

        foreach ($roots as $root) {
            $walk($root);
        }

        $plan = [];
        $everyone = [];

        foreach ($order as $boss) {
            $team = self::teamOf($boss, $children);
            $everyone = [...$everyone, ...$team];
            $name = $names[$boss] ?? ('#'.$boss);

            $plan[] = [
                'id' => $boss,
                'name' => (string) __('sales::salesperson_report.team', ['name' => $name]),
                'total_label' => (string) __('sales::salesperson_report.team_total', ['name' => $name]),
                'filters' => [...$f, self::ONLY => $team],
            ];
        }

        $plan[] = [
            'id' => null,
            'name' => (string) __('sales::salesperson_report.no_team'),
            'total_label' => (string) __('sales::salesperson_report.no_team_total'),
            'filters' => [...$f, self::OUTSIDE => array_values(array_unique($everyone))],
        ];

        return $plan;
    }

    /**
     * একজন উপরওয়ালা আর তাঁর নিচের গোটা গাছ — সরাসরি ও পরোক্ষ; ⛔ চক্রে আটকায় না।
     *
     * @param  array<int, list<int>>  $children
     * @return list<int>
     */
    private static function teamOf(int $boss, array $children): array
    {
        $team = [$boss => true];
        $queue = [$boss];

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($children[$id] ?? [] as $child) {
                if (! isset($team[$child])) {
                    $team[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_keys($team);
    }
}
