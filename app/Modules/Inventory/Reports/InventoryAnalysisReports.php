<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * মজুদের অবস্থা ও চলাচল — রিপোর্ট সেন্টার ধাপ ৩ (মালিক, ১ অক্টোবর ২০২৬):
 * *"মজুদের অবস্থা: গুদাম/পণ্য/ক্যাটাগরি/ব্র্যান্ড/লট — হাতে, আটকানো, বসানো বাকি, বেচা যাবে, মূল্য"* আর
 * *"মালের চলাচল: ঢোকা/বেরোনো/স্থানান্তর/সমন্বয়"*।
 *
 * ⓘ দুইটাই চলাচলের সারি থেকে গোনা, হেডারে বাছা শাখার গুদামে ([[ReportEngine::branchWall()]] গুদামের শাখায়)।
 * ⓘ "হাতে" = তাকে + বসানো বাকি + আটকে — গুদামে যা আছে সব; "বেচা যাবে" = তাকে − ধরা − আটকে।
 * ⓘ মূল্য = হাতে × পণ্যের খোলা স্তরের গড় দর — স্তর কোম্পানির, শাখার নয় ([[CostLayerService]])।
 */
final class InventoryAnalysisReports
{
    public const GROUPS = ['product', 'warehouse', 'category', 'brand', 'lot'];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::position());
        $engine->register(self::movement());
    }

    public static function position(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.stock_position',
            permission: 'inventory.report',
            title: 'inventory::stockview.position',
            filters: ['branch', 'warehouse_id', 'product_id', 'brand_id', 'category_id', 'group_by'],
            groupBy: 'group_key',
            query: function (array $f) {
                $group = in_array($f['group_by'] ?? null, self::GROUPS, true) ? $f['group_by'] : 'product';

                [$key, $label] = match ($group) {
                    'warehouse' => ['m.warehouse_id', self::name('w')],
                    'category' => ['p.category_id', self::name('pc')],
                    'brand' => ['p.brand_id', self::name('br')],
                    'lot' => ["CONCAT(p.id, '|', COALESCE(m.batch_id, 0))", "CONCAT(p.code, ' · ', COALESCE(b.batch_no, '—'))"],
                    default => ['p.id', "CONCAT(p.code, ' - ', ".self::name('p').')'],
                };

                return self::movements($f)
                    ->leftJoin('inv_batches as b', 'b.id', '=', 'm.batch_id')
                    ->leftJoin('mdm_brands as br', 'br.id', '=', 'p.brand_id')
                    ->leftJoin('mdm_product_categories as pc', 'pc.id', '=', 'p.category_id')
                    ->groupByRaw("COALESCE({$key}, 0)")
                    ->havingRaw('SUM(m.floor_change + m.unplaced_change + m.hold_change) <> 0 OR SUM(m.reserved_change) <> 0')
                    ->orderByRaw("MAX(COALESCE({$label}, '—'))")
                    ->selectRaw("COALESCE({$key}, 0) as group_key")
                    ->selectRaw("MAX(COALESCE({$label}, '—')) as group_label")
                    ->selectRaw('SUM(m.floor_change) as floor')
                    ->selectRaw('SUM(m.unplaced_change) as unplaced')
                    ->selectRaw('SUM(m.hold_change) as held')
                    ->selectRaw('SUM(m.reserved_change) as reserved')
                    ->selectRaw('SUM(m.floor_change + m.unplaced_change + m.hold_change) as on_hand')
                    ->selectRaw('SUM(m.floor_change - m.reserved_change - m.hold_change) as sellable')
                    ->selectRaw('SUM((m.floor_change + m.unplaced_change + m.hold_change) * COALESCE(('.self::avgCost().'), 0)) as value');
            },
            columns: [
                ['key' => 'group_label', 'label' => 'inventory::stockview.group'],
                ['key' => 'on_hand', 'label' => 'inventory::stockview.on_hand', 'type' => ReportColumn::QUANTITY],
                ['key' => 'floor', 'label' => 'inventory::field.floor', 'type' => ReportColumn::QUANTITY],
                ['key' => 'held', 'label' => 'inventory::stockview.held', 'type' => ReportColumn::QUANTITY],
                ['key' => 'unplaced', 'label' => 'inventory::field.unplaced', 'type' => ReportColumn::QUANTITY],
                ['key' => 'reserved', 'label' => 'inventory::field.reserved', 'type' => ReportColumn::QUANTITY],
                ['key' => 'sellable', 'label' => 'inventory::stockview.sellable', 'type' => ReportColumn::QUANTITY],
                ['key' => 'value', 'label' => 'inventory::stockview.value', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * মালের চলাচল — পণ্য প্রতি: শুরুতে, কেনা, বেচা, ফেরত এল, ফেরত গেল, স্থানান্তরে এল/গেল, সমন্বয়, অন্য, শেষে।
     *
     * ⓘ সব "হাতে"-র মাপে (তাকে + বসানো বাকি + আটকে) — তাই বসানো বা আটকানো নিজে কোনো চলাচল নয়, শূন্যে মেলে।
     * ⓘ "অন্য" = উপরের কোনোটায় না পড়া উৎস (যেমন রান্না); শুরু + সব = শেষ, প্রতিটা সারিতে।
     */
    public static function movement(): ReportDefinition
    {
        $qty = '(m.floor_change + m.unplaced_change + m.hold_change)';
        $in = fn (array $sources) => "SUM(CASE WHEN m.trx_date >= ? AND m.source_type IN ('".implode("','", $sources)."') THEN {$qty} ELSE 0 END)";

        return new ReportDefinition(
            key: 'inventory.movement_summary',
            permission: 'inventory.report',
            title: 'inventory::stockview.movement',
            filters: ['date_range', 'branch', 'warehouse_id', 'product_id', 'brand_id', 'category_id'],
            groupBy: 'product_id',
            query: function (array $f) use ($qty, $in) {
                $from = $f['from'];
                $named = ['purchase_receipt', 'purchase_bill', 'opening_stock', 'delivery_challan', 'sales_invoice',
                    'sales_return', 'purchase_return', 'stock_transfer', 'stock_adjustment'];

                return self::movements($f)
                    ->where('m.trx_date', '<=', $f['to'])
                    ->groupBy('p.id')
                    ->orderByRaw('MAX(p.code)')
                    ->selectRaw('p.id as product_id')
                    ->selectRaw("MAX(CONCAT(p.code, ' - ', ".self::name('p').')) as product_name')
                    ->selectRaw("SUM(CASE WHEN m.trx_date < ? THEN {$qty} ELSE 0 END) as opening", [$from])
                    ->selectRaw($in(['purchase_receipt', 'purchase_bill', 'opening_stock']).' as bought', [$from])
                    ->selectRaw('-'.$in(['delivery_challan', 'sales_invoice']).' as sold', [$from])
                    ->selectRaw($in(['sales_return']).' as returned_in', [$from])
                    ->selectRaw('-'.$in(['purchase_return']).' as returned_out', [$from])
                    ->selectRaw("SUM(CASE WHEN m.trx_date >= ? AND m.source_type = 'stock_transfer' AND {$qty} > 0 THEN {$qty} ELSE 0 END) as transfer_in", [$from])
                    ->selectRaw("SUM(CASE WHEN m.trx_date >= ? AND m.source_type = 'stock_transfer' AND {$qty} < 0 THEN -{$qty} ELSE 0 END) as transfer_out", [$from])
                    ->selectRaw($in(['stock_adjustment']).' as adjusted', [$from])
                    ->selectRaw("SUM(CASE WHEN m.trx_date >= ? AND m.source_type NOT IN ('".implode("','", $named)."') THEN {$qty} ELSE 0 END) as other", [$from])
                    ->selectRaw("SUM({$qty}) as closing");
            },
            columns: [
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'opening', 'label' => 'inventory::stockview.opening', 'type' => ReportColumn::QUANTITY],
                ['key' => 'bought', 'label' => 'inventory::stockview.bought', 'type' => ReportColumn::QUANTITY],
                ['key' => 'sold', 'label' => 'inventory::stockview.sold', 'type' => ReportColumn::QUANTITY],
                ['key' => 'returned_in', 'label' => 'inventory::stockview.returned_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'returned_out', 'label' => 'inventory::stockview.returned_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'transfer_in', 'label' => 'inventory::stockview.transfer_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'transfer_out', 'label' => 'inventory::stockview.transfer_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'adjusted', 'label' => 'inventory::stockview.adjusted', 'type' => ReportColumn::QUANTITY],
                ['key' => 'other', 'label' => 'inventory::stockview.other', 'type' => ReportColumn::QUANTITY],
                ['key' => 'closing', 'label' => 'inventory::stockview.closing', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /** চলাচলের সারি, শাখা আর ছাঁকনিসহ — দুই রিপোর্টের একই ভিত */
    private static function movements(array $f): Builder
    {
        return DB::table('inv_stock_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'w.branch_id'))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('m.warehouse_id', (int) $f['warehouse_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('m.product_id', (int) $f['product_id']))
            ->when(! empty($f['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $f['brand_id']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('p.category_id', (int) $f['category_id']));
    }

    /** পণ্যের খোলা স্তরের গড় দর — কাঁচা SQL, প্লেসহোল্ডার ছাড়া ([[PurchaseReports::pendingOrders()]]-এর কারণে) */
    private static function avgCost(): string
    {
        return 'select SUM(cl.qty_remaining * cl.unit_cost) / NULLIF(SUM(cl.qty_remaining), 0)
                from inv_cost_layers cl where cl.product_id = m.product_id and cl.qty_remaining > 0';
    }

    private static function name(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }
}
