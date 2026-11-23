<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ স্কিম ও ছাড়ের খরচ — অফার দিতে কত গেল আর বিক্রি কত বাড়ল (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ১০; অনুমোদিত নমুনা
 * claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * ── স্কিম ধরে ([[SCHEMES]]) ────────────────────────────────────────────────────
 * খাতায় বসা বিলে যে অফার খেটেছে (`promotion_applications`, বিলের) — অফার ধরে:
 *   স্কিমে বিক্রি     অফার পাওয়া লাইনগুলোর বিক্রি (ভ্যাট বাদ); গোটা বিলের অফারে বিলের নিট
 *   দামে ছাড়        শতাংশ বা টাকার সুবিধার মূল্য (`worth`)
 *   ফ্রি মালের দাম    মালের সুবিধার মূল্য
 *   অন্য সুবিধা       ক্রেডিট আর পয়েন্ট
 *   মোট খরচ          তিনটার যোগ; হার = খরচ ÷ স্কিমে বিক্রি
 *   আগের একই সময়ে   ঠিক আগের একই দৈর্ঘ্যের সময়ে ([[Trend::previous()]]) **এই অফারের পণ্যগুলোর** বিক্রি
 *   বাড়তি বিক্রি      স্কিমে বিক্রি − আগের একই সময়ে; আর প্রতি ১ টাকা খরচে কত বাড়তি
 * ⓘ সংখ্যা অফারের কাগজ থেকে নয়, বিলে জমে থাকা সারি থেকে — অফার পরে বদলালেও এই বিলের সুবিধা যা ছিল তাই।
 * ⚠️ "বাড়তি" মোটা দাগের মাপ: আগের সময়ে ওই পণ্যগুলোর সব বিক্রি, এ সময়ে কেবল অফার পাওয়া লাইন; পণ্য ছাড়া (গোটা বিলের) অফারে আগের ঘর শূন্য।
 *
 * ── বিলের ছাড়, বিক্রয়কর্মী ধরে ([[DISCOUNTS]]) ───────────────────────────────────
 * লাইনের ছাড় আর বিলের মাথার ছাড় — দায়িত্বপ্রাপ্ত জনের নামে (বিলের `sales_person_id`), উপরওয়ালার দলের ভাগসহ।
 *
 * ⓘ বিক্রয় মডিউল অফারের মডিউলের ক্লাস ধরে না (নির্ভরতার তালিকায় নেই) — কেবল তার জমে থাকা সারির টেবিল পড়ে।
 */
final class SchemeCostReports
{
    public const SCHEMES = 'sales.scheme_cost';

    public const BILLS = 'sales.scheme_cost_bills';

    public const DISCOUNTS = 'sales.discount_by_person';

    private const PRICE_KINDS = "'percent', 'amount'";

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::schemes());
        $engine->register(self::bills());
        $engine->register(self::discounts());
    }

    /**
     * বিলে খাটা অফারের প্রতিটা সারি — খাতায় বসা বিল, বাছা সময়, দুই দেয়াল।
     * ঘর: `promotion_id, invoice_id, customer_id, line_id, product_id, kind, worth, trx_date, document_no`।
     *
     * @param  array<string, mixed>  $f
     */
    private static function applied(array $f): Builder
    {
        return DB::table('promotion_applications as pa')
            ->join('sal_invoices as i', 'i.id', '=', 'pa.source_id')
            ->where('pa.company_id', $f['company_id'])
            ->where('pa.source_type', SalesInvoice::drillSourceType())
            ->where('i.company_id', $f['company_id'])->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereBetween('i.trx_date', [$f['from'], $f['to']])
            ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
            ->selectRaw('pa.promotion_id as promotion_id, i.id as invoice_id, i.customer_id as customer_id, pa.source_line_id as line_id, '
                .'pa.product_id as product_id, pa.benefit_kind as kind, pa.worth as worth, i.trx_date as trx_date, i.document_no as document_no');
    }

    private static function promotionName(string $table = 'p'): string
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF({$table}.name_bn, ''), {$table}.name_en)" : "COALESCE(NULLIF({$table}.name_en, ''), {$table}.name_bn)";

        return "CONCAT({$table}.code, ' - ', {$name})";
    }

    private static function schemes(): ReportDefinition
    {
        $door = [
            'route' => 'sales.report.show',
            // ⓘ অফারের কোড ধরে খোঁজা — বিলের তালিকার খোঁজার ঘরে বসে
            'params' => ['slug' => '=scheme-cost-bills', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'q' => 'code'],
            'total' => ['slug' => '=scheme-cost-bills', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::scheme_cost.drill_bills',
        ];
        $money = fn (string $key): array => ['key' => $key, 'label' => 'sales::scheme_cost.'.$key, 'type' => ReportColumn::MONEY, 'drill' => $door];

        return new ReportDefinition(
            key: self::SCHEMES,
            permission: 'sales.report',
            title: 'sales::scheme_cost.title',
            filters: ['date_range', 'branch'],
            groupBy: 'promotion_id',
            rankBy: 'cost',
            query: function (array $f): Builder {
                $company = (int) $f['company_id'];
                $before = Trend::previous(ReportEngine::COMPARE_PREVIOUS, (string) $f['from'], (string) $f['to']);

                $cost = DB::query()->fromSub(self::applied($f), 'a')->groupBy('a.promotion_id')
                    ->selectRaw('a.promotion_id, COUNT(DISTINCT a.invoice_id) as bills, COUNT(DISTINCT a.customer_id) as customers, '
                        .'SUM(CASE WHEN a.kind IN ('.self::PRICE_KINDS.') THEN a.worth ELSE 0 END) as price_cut, '
                        ."SUM(CASE WHEN a.kind = 'goods' THEN a.worth ELSE 0 END) as free_goods, "
                        .'SUM(CASE WHEN a.kind NOT IN ('.self::PRICE_KINDS.", 'goods') THEN a.worth ELSE 0 END) as other_benefit, SUM(a.worth) as cost");

                // ⓘ একটা লাইনে একই অফার কয়েক সারিতে বসতে পারে (ধাপ) — লাইনটা একবারই গোনা
                $lines = DB::query()->fromSub(self::applied($f)->whereNotNull('pa.source_line_id'), 'a')
                    ->select('a.promotion_id', 'a.line_id')->distinct();
                $lineSales = DB::query()->fromSub($lines, 'd')
                    ->join('sal_invoice_lines as il', 'il.id', '=', 'd.line_id')
                    ->groupBy('d.promotion_id')->selectRaw('d.promotion_id, SUM(il.amount - il.tax) as v');
                $whole = DB::query()->fromSub(self::applied($f)->whereNull('pa.source_line_id'), 'a')
                    ->select('a.promotion_id', 'a.invoice_id')->distinct();
                $billSales = DB::query()->fromSub($whole, 'd')
                    ->join('sal_invoices as i2', 'i2.id', '=', 'd.invoice_id')
                    ->groupBy('d.promotion_id')->selectRaw('d.promotion_id, SUM('.SalesMetrics::billNetSql('i2').') as v');

                // আগের একই সময়ে এই অফারের পণ্যগুলোর বিক্রি — একই দুই দেয়াল
                $products = DB::query()->fromSub(self::applied($f)->whereNotNull('pa.product_id'), 'a')
                    ->select('a.promotion_id', 'a.product_id')->distinct();
                $was = DB::query()->fromSub($products, 'd')
                    ->join('sal_invoice_lines as l0', 'l0.product_id', '=', 'd.product_id')
                    ->join('sal_invoices as i0', 'i0.id', '=', 'l0.sales_invoice_id')
                    ->where('i0.company_id', $company)->whereNull('i0.deleted_at')
                    ->whereIn('i0.status', DocumentStatus::POSTED)
                    ->whereBetween('i0.trx_date', [$before['from'], $before['to']])
                    ->tap(ReportEngine::branchWall($f, 'i0.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i0.customer_id'))
                    ->groupBy('d.promotion_id')->selectRaw('d.promotion_id, SUM(l0.amount - l0.tax) as v');

                $sales = '(COALESCE(ls.v, 0) + COALESCE(bs.v, 0))';

                return DB::query()->fromSub($cost, 'c')
                    ->join('promotions as p', 'p.id', '=', 'c.promotion_id')
                    ->leftJoinSub($lineSales, 'ls', 'ls.promotion_id', '=', 'c.promotion_id')
                    ->leftJoinSub($billSales, 'bs', 'bs.promotion_id', '=', 'c.promotion_id')
                    ->leftJoinSub($was, 'w', 'w.promotion_id', '=', 'c.promotion_id')
                    ->where('p.company_id', $company)
                    ->selectRaw('c.promotion_id as promotion_id, p.code as code, '.self::promotionName().' as scheme, p.starts_on as starts_on, p.ends_on as ends_on, '
                        ."c.bills, c.customers, {$sales} as scheme_sales, c.price_cut, c.free_goods, c.other_benefit, c.cost, "
                        ."CASE WHEN {$sales} > 0 THEN ROUND(c.cost * 100 / {$sales}, 2) END as cost_share, "
                        ."COALESCE(w.v, 0) as before_sales, {$sales} - COALESCE(w.v, 0) as extra_sales, "
                        ."CASE WHEN c.cost > 0 THEN ROUND(({$sales} - COALESCE(w.v, 0)) / c.cost, 2) END as extra_per_taka")
                    ->orderByDesc('c.cost')->orderBy('c.promotion_id');
            },
            columns: [
                ['key' => 'scheme', 'label' => 'sales::scheme_cost.scheme'],
                ['key' => 'starts_on', 'label' => 'sales::scheme_cost.starts_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'ends_on', 'label' => 'sales::scheme_cost.ends_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                $money('scheme_sales'),
                $money('price_cut'),
                $money('free_goods'),
                $money('other_benefit'),
                $money('cost'),
                ['key' => 'cost_share', 'label' => 'sales::scheme_cost.cost_share', 'type' => ReportColumn::PERCENT, 'ratio' => ['cost', 'scheme_sales'], 'width' => '6rem'],
                $money('before_sales'),
                $money('extra_sales'),
                // ⓘ হারের ঘর — যোগ হয় না; মোটের সারিতে খালি
                ['key' => 'extra_per_taka', 'label' => 'sales::scheme_cost.extra_per_taka', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
            ],
        );
    }

    /** অফার খাটা বিলের তালিকা — স্কিমের অঙ্কের উৎস; অঙ্কে চাপলে বিলটা */
    private static function bills(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::BILLS,
            permission: 'sales.report',
            title: 'sales::scheme_cost.title_bills',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $kind = 'CASE WHEN a.kind IN ('.self::PRICE_KINDS.') THEN '.$pdo->quote((string) __('sales::scheme_cost.price_cut'))
                    ." WHEN a.kind = 'goods' THEN ".$pdo->quote((string) __('sales::scheme_cost.free_goods'))
                    .' ELSE '.$pdo->quote((string) __('sales::scheme_cost.other_benefit')).' END';

                return DB::query()->fromSub(self::applied($f), 'a')
                    ->join('promotions as p', 'p.id', '=', 'a.promotion_id')
                    ->where('p.company_id', $f['company_id'])
                    ->selectRaw('a.trx_date, a.document_no, '.$pdo->quote(SalesInvoice::drillSourceType()).' as source_type, a.invoice_id as source_id, '
                        ."CONCAT('customer:', a.customer_id) as party_key, ".self::promotionName()." as scheme, {$kind} as kind_label, a.worth as worth")
                    ->orderBy('a.trx_date')->orderBy('a.invoice_id');
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id', 'width' => '11rem'],
                ['key' => 'party', 'label' => 'sales::area_sales.level_customer', 'party' => 'party_key', 'width' => '16rem'],
                ['key' => 'scheme', 'label' => 'sales::scheme_cost.scheme'],
                ['key' => 'kind_label', 'label' => 'sales::scheme_cost.kind', 'width' => '9rem'],
                ['key' => 'worth', 'label' => 'sales::scheme_cost.cost', 'type' => ReportColumn::MONEY,
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id']]],
            ],
        );
    }

    private static function discounts(): ReportDefinition
    {
        $door = ['route' => 'sales.report.show',
            'params' => ['slug' => '=by-salesperson', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'total' => ['slug' => '=by-salesperson', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
            'label' => 'sales::customer_insight.drill_person'];
        $money = fn (string $key): array => ['key' => $key, 'label' => 'sales::scheme_cost.'.$key, 'type' => ReportColumn::MONEY, 'drill' => $door];

        return new ReportDefinition(
            key: self::DISCOUNTS,
            permission: 'sales.report',
            title: 'sales::scheme_cost.title_discounts',
            filters: ['date_range', 'branch'],
            groupBy: 'person_id',
            rankBy: 'discount_total',
            sections: fn (array $f): array => SalespersonReports::teamsFor($f),
            query: function (array $f): Builder {
                $nobody = DB::getPdo()->quote((string) __('sales::money_in.no_person'));
                // ⓘ ছাড়ের আগের দাম = ভ্যাট-বাদ নিট + দুই ছাড়; লাইনের ছাড় `discount`, মাথার ছাড় `bill_discount`
                $gross = '('.SalesMetrics::billNetSql('i').' + i.discount + i.bill_discount)';

                return DB::table('sal_invoices as i')
                    ->leftJoin('users as u', 'u.id', '=', 'i.sales_person_id')
                    ->where('i.company_id', $f['company_id'])->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->when(isset($f['team_ids']), fn (Builder $q) => $q->whereIn('i.sales_person_id', $f['team_ids'] === [] ? [0] : $f['team_ids']))
                    ->when(isset($f['team_outside']), fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('i.sales_person_id')
                        ->when($f['team_outside'] !== [], fn ($n) => $n->orWhereNotIn('i.sales_person_id', $f['team_outside']))))
                    ->groupBy('i.sales_person_id', 'u.name')
                    ->selectRaw("i.sales_person_id as person_id, COALESCE(u.name, {$nobody}) as person, COUNT(*) as bills, "
                        ."SUM({$gross}) as gross, SUM(i.discount) as line_discount, SUM(i.bill_discount) as bill_discount, "
                        .'SUM(i.discount) + SUM(i.bill_discount) as discount_total, '
                        ."CASE WHEN SUM({$gross}) > 0 THEN ROUND((SUM(i.discount) + SUM(i.bill_discount)) * 100 / SUM({$gross}), 2) END as discount_share, "
                        .'SUM('.SalesMetrics::billNetSql('i').') as after_discount')
                    ->orderByRaw('SUM(i.discount) + SUM(i.bill_discount) DESC')->orderBy('i.sales_person_id');
            },
            columns: [
                ['key' => 'person', 'label' => 'sales::money_in.person'],
                ['key' => 'bills', 'label' => 'sales::area_sales.bills', 'type' => ReportColumn::QUANTITY, 'width' => '5rem'],
                $money('gross'),
                $money('line_discount'),
                $money('bill_discount'),
                $money('discount_total'),
                ['key' => 'discount_share', 'label' => 'sales::scheme_cost.discount_share', 'type' => ReportColumn::PERCENT, 'ratio' => ['discount_total', 'gross'], 'width' => '6rem'],
                $money('after_discount'),
            ],
        );
    }
}
