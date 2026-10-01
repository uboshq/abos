<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ক্রয় বিশ্লেষণ — রিপোর্ট সেন্টার ধাপ ৩ (মালিক, ১ অক্টোবর ২০২৬):
 * *"ক্রয় বিশ্লেষণ: সরবরাহকারী/পণ্য/ব্র্যান্ড/ক্যাটাগরি/গুদাম/শাখা × সময়; দামের বিশ্লেষণ (শেষ/গড়/কম/বেশি দাম, বৃদ্ধি)"*।
 *
 * ── দুইটা রিপোর্ট ──────────────────────────────────────────────────────
 *   purchase.analysis        কী ধরে (`group_by`), কোন সময়ের ভাগে (`period`) — কত কেনা, কত ফেরত, নিট; বৃদ্ধি আর
 *                            সেরা ১০ ইঞ্জিনের নিজের (`compare`, `top` — [[ReportEngine]])
 *   purchase.price_analysis  পণ্য প্রতি দর: শেষ, গড়, কম, বেশি, আর আগের সমান-দৈর্ঘ্যের সময়ের গড় থেকে কত বদলাল
 *
 * ⓘ সংখ্যা বিলের লাইন থেকে (পাকা বিল), ফেরত পাকা ফেরতের লাইন থেকে, ঋণাত্মক। মালের দাম = লাইনের টাকা − কর —
 * [[PurchaseBillService]] স্তরে যা বসায় তাই (ভ্যাট ফেরতযোগ্য, মালের দাম নয়)।
 * ⓘ ছাঁকনির নাম রিপোর্ট সেন্টারের ভাগ করা নাম (`supplier_id`, `product_id`, `brand_id`, `category_id`, `warehouse_id`)।
 */
final class PurchaseAnalysisReports
{
    public const DIMENSIONS = ['supplier', 'product', 'brand', 'category', 'warehouse', 'branch'];

    public const PERIODS = ['day', 'week', 'month', 'year'];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::analysis());
        $engine->register(self::priceAnalysis());
    }

    public static function analysis(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.analysis',
            permission: 'purchase.report',
            title: 'purchase::analysis.title',
            filters: ['date_range', 'branch', 'supplier_id', 'product_id', 'brand_id', 'category_id', 'warehouse_id', 'group_by', 'period'],
            groupBy: 'dim_key',
            rankBy: 'net_value',
            query: function (array $f) {
                $lines = self::lines($f, 'bill')->unionAll(self::lines($f, 'return'));

                return DB::query()->fromSub($lines, 'x')
                    ->groupBy('x.dim_key')
                    ->orderByDesc('net_value')
                    ->selectRaw('x.dim_key')
                    ->selectRaw('MAX(x.dim_label) as dim_label')
                    ->selectRaw('SUM(CASE WHEN x.sign > 0 THEN x.qty ELSE 0 END) as bought_qty')
                    ->selectRaw('SUM(CASE WHEN x.sign > 0 THEN x.value ELSE 0 END) as bought_value')
                    ->selectRaw('SUM(CASE WHEN x.sign > 0 THEN x.discount ELSE 0 END) as discount')
                    ->selectRaw('SUM(CASE WHEN x.sign < 0 THEN x.qty ELSE 0 END) as returned_qty')
                    ->selectRaw('SUM(CASE WHEN x.sign < 0 THEN x.value ELSE 0 END) as returned_value')
                    ->selectRaw('SUM(x.sign * x.value) as net_value')
                    ->selectRaw('CASE WHEN SUM(CASE WHEN x.sign > 0 THEN x.qty ELSE 0 END) > 0
                        THEN SUM(CASE WHEN x.sign > 0 THEN x.value ELSE 0 END) / SUM(CASE WHEN x.sign > 0 THEN x.qty ELSE 0 END) END as avg_rate');
            },
            columns: [
                ['key' => 'dim_label', 'label' => 'purchase::analysis.dimension'],
                ['key' => 'bought_qty', 'label' => 'purchase::analysis.bought_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'bought_value', 'label' => 'purchase::analysis.bought_value', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'purchase::analysis.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'returned_qty', 'label' => 'purchase::analysis.returned_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'returned_value', 'label' => 'purchase::analysis.returned_value', 'type' => ReportColumn::MONEY],
                ['key' => 'net_value', 'label' => 'purchase::analysis.net_value', 'type' => ReportColumn::MONEY],
                ['key' => 'avg_rate', 'label' => 'purchase::analysis.avg_rate', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
    }

    public static function priceAnalysis(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.price_analysis',
            permission: 'purchase.report',
            title: 'purchase::analysis.price_title',
            filters: ['date_range', 'branch', 'supplier_id', 'product_id', 'brand_id', 'category_id', 'warehouse_id'],
            query: function (array $f) {
                [$prevFrom, $prevTo] = self::previous($f);

                $avg = fn (string $from, string $to) => self::billLines($f, $from, $to)
                    ->whereColumn('bl.product_id', 'outer_l.product_id')
                    ->selectRaw('CASE WHEN SUM(bl.qty) > 0 THEN SUM(bl.amount - bl.tax) / SUM(bl.qty) END');

                $last = self::billLines($f, $f['from'], $f['to'])
                    ->whereColumn('bl.product_id', 'outer_l.product_id')
                    ->orderByDesc('b.trx_date')
                    ->orderByDesc('bl.id')
                    ->limit(1)
                    ->select('bl.rate');

                return DB::query()
                    ->fromSub(self::billLines($f, $f['from'], $f['to'])
                        ->selectRaw('bl.product_id, bl.rate, bl.qty, bl.amount - bl.tax as value'), 'outer_l')
                    ->join('inv_products as p', 'p.id', '=', 'outer_l.product_id')
                    ->groupBy('outer_l.product_id')
                    ->orderByRaw('MAX(p.code)')
                    ->selectRaw("MAX(CONCAT(p.code, ' - ', ".self::name('p').')) as product_name')
                    ->selectRaw('SUM(outer_l.qty) as bought_qty')
                    ->selectSub($last, 'last_rate')
                    ->selectRaw('SUM(outer_l.value) / NULLIF(SUM(outer_l.qty), 0) as avg_rate')
                    ->selectRaw('MIN(outer_l.rate) as min_rate')
                    ->selectRaw('MAX(outer_l.rate) as max_rate')
                    ->selectSub($avg($prevFrom, $prevTo), 'previous_avg');
            },
            columns: [
                ['key' => 'product_name', 'label' => 'purchase::field.product'],
                ['key' => 'bought_qty', 'label' => 'purchase::analysis.bought_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'last_rate', 'label' => 'purchase::analysis.last_rate', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'avg_rate', 'label' => 'purchase::analysis.avg_rate', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'min_rate', 'label' => 'purchase::analysis.min_rate', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'max_rate', 'label' => 'purchase::analysis.max_rate', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'previous_avg', 'label' => 'purchase::analysis.previous_avg', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
    }

    /** আগের সমান-দৈর্ঘ্যের সময় — [[ReportEngine::comparisonPeriod()]]-এর "আগের" নিয়মে */
    private static function previous(array $f): array
    {
        $from = Carbon::parse($f['from']);
        $to = Carbon::parse($f['to']);

        return [
            $from->copy()->subDays($from->diffInDays($to) + 1)->toDateString(),
            $from->copy()->subDay()->toDateString(),
        ];
    }

    /** পাকা বিলের লাইন, ছাঁকনিসহ — দর বিশ্লেষণের দুই অংশ একই উৎস পড়ে */
    private static function billLines(array $f, string $from, string $to): Builder
    {
        return DB::table('pur_bill_lines as bl')
            ->join('pur_bills as b', 'b.id', '=', 'bl.purchase_bill_id')
            ->join('inv_products as bp', 'bp.id', '=', 'bl.product_id')
            ->where('b.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'b.branch_id'))
            ->whereIn('b.status', DocumentStatus::POSTED)
            ->whereNull('b.deleted_at')
            ->whereBetween('b.trx_date', [$from, $to])
            ->where('bl.qty', '>', 0)
            ->when(! empty($f['supplier_id']), fn ($q) => $q->where('b.supplier_id', (int) $f['supplier_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('bl.product_id', (int) $f['product_id']))
            ->when(! empty($f['brand_id']), fn ($q) => $q->where('bp.brand_id', (int) $f['brand_id']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('bp.category_id', (int) $f['category_id']))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('b.warehouse_id', (int) $f['warehouse_id']));
    }

    /**
     * বিল (+) বা ফেরত (−)-এর লাইন, এক মাপে: কী ধরে, কোন সময়ের ভাগে, কত, কত টাকা।
     */
    private static function lines(array $f, string $kind): Builder
    {
        $bill = $kind === 'bill';
        [$doc, $line, $fk] = $bill
            ? ['pur_bills', 'pur_bill_lines', 'purchase_bill_id']
            : ['pur_returns', 'pur_return_lines', 'purchase_return_id'];

        $dimension = in_array($f['group_by'] ?? null, self::DIMENSIONS, true) ? $f['group_by'] : 'supplier';
        $period = in_array($f['period'] ?? null, self::PERIODS, true) ? $f['period'] : null;

        [$key, $label] = match ($dimension) {
            'product' => ['p.id', "CONCAT(p.code, ' - ', ".self::name('p').')'],
            'brand' => ['p.brand_id', self::name('br')],
            'category' => ['p.category_id', self::name('pc')],
            'warehouse' => ['d.warehouse_id', self::name('w')],
            'branch' => ['d.branch_id', self::name('bh')],
            default => ['d.supplier_id', self::name('s')],
        };

        $bucket = match ($period) {
            'day' => "DATE_FORMAT(d.trx_date, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(d.trx_date, '%x-W%v')",
            'month' => "DATE_FORMAT(d.trx_date, '%Y-%m')",
            'year' => 'YEAR(d.trx_date)',
            default => null,
        };

        // ⓘ সময়ের ভাগ চাইলে চাবিতেও — একই সরবরাহকারীর প্রতি মাস আলাদা সারি
        $dimKey = $bucket === null ? "COALESCE({$key}, 0)" : "CONCAT(COALESCE({$key}, 0), '|', {$bucket})";
        $dimLabel = $bucket === null
            ? "COALESCE({$label}, '—')"
            : "CONCAT(COALESCE({$label}, '—'), ' · ', {$bucket})";

        return DB::table("{$line} as l")
            ->join("{$doc} as d", 'd.id', '=', "l.{$fk}")
            ->join('inv_products as p', 'p.id', '=', 'l.product_id')
            ->join('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('mdm_brands as br', 'br.id', '=', 'p.brand_id')
            ->leftJoin('mdm_product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin('inv_warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('branches as bh', 'bh.id', '=', 'd.branch_id')
            ->where('d.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'd.branch_id'))
            ->whereIn('d.status', DocumentStatus::POSTED)
            ->whereNull('d.deleted_at')
            ->whereBetween('d.trx_date', [$f['from'], $f['to']])
            ->when(! empty($f['supplier_id']), fn ($q) => $q->where('d.supplier_id', (int) $f['supplier_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('l.product_id', (int) $f['product_id']))
            ->when(! empty($f['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $f['brand_id']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('p.category_id', (int) $f['category_id']))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('d.warehouse_id', (int) $f['warehouse_id']))
            ->selectRaw("{$dimKey} as dim_key")
            ->selectRaw("{$dimLabel} as dim_label")
            ->selectRaw(($bill ? '1' : '-1').' as sign')
            ->selectRaw('l.qty')
            ->selectRaw('l.amount - l.tax as value')
            ->selectRaw(($bill ? 'l.discount' : '0').' as discount');
    }

    private static function name(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }
}
