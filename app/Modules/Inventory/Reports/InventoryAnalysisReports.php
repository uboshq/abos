<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Services\StandardChart;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
        $engine->register(self::monthly());
    }

    /**
     * মাসওয়ারি মাল আসা-যাওয়া — মালিক, ১ অক্টোবর ২০২৬: *"ekhoni lagbe"*।
     *
     * সারি = সময়ের প্রতিটা মাস (চলাচল না থাকলেও — "এই মাসে কিছুই হয়নি" জানাটাও উত্তর)।
     *   শুরুতে  মাসের প্রথম দিনের আগে হাতে যা ছিল
     *   এল      মাসে যত ঢুকেছে — কেনা, বিক্রয়-ফেরত, স্থানান্তরে আসা, সমন্বয়ে বাড়া (সব ধনাত্মক চলাচল)
     *   গেল     মাসে যত বেরিয়েছে — বিক্রি, ক্রয়-ফেরত, স্থানান্তরে যাওয়া, সমন্বয়ে কমা (সব ঋণাত্মক চলাচল)
     *   শেষে   শুরু + এল − গেল (পরের মাসের শুরু)
     * ⓘ পরিমাণ "হাতে"-র মাপে (তাকে + বসানো বাকি + আটকে), তাই বসানো বা আটকানো এল-গেল নয়।
     *
     * ── টাকা — যে দাম সত্যিই বসেছিল, আজকের দর নয় (সমন্বয়ক, ১ অক্টোবর ২০২৬) ─────────────────────────
     * ⛔ আজকের গড় দরে মাপলে দাম বদলালেই জানুয়ারির কলাম বদলে যেত। তাই:
     *   এল টাকা   সেই মাসে জন্মানো খরচের স্তর, নিজের দরে (মাল-গ্রহণ, খোলা মজুদ, সমন্বয়ে বাড়া …)
     *   গেল টাকা  সেই মাসে স্তর থেকে যা টানা হলো — খরচে যা সত্যিই বসেছে, ফেরত বাদ দিয়ে ([[CostLayerService]])
     *   শুরু/শেষ  মজুদ খাতের (১১২০) জের মাসের শুরুতে আর শেষে, হেডারে বাছা শাখায়
     * ⚠️ দুইটা সীমা, কলামের নামেই লেখা: স্তরে শাখা নেই, তাই এল/গেল টাকা গোটা কোম্পানির; আর খাতে পণ্য নেই, তাই
     * পণ্য/ব্র্যান্ড/ক্যাটাগরি/গুদাম বাছলে শুরু/শেষ টাকা খালি (একটা ভুল সংখ্যার চেয়ে খালি ভালো)।
     * ⓘ দেখেন কেবল যাঁর দাম দেখার চাবি আছে (`inventory.cost.view`)।
     */
    public static function monthly(): ReportDefinition
    {
        $qty = '(m.floor_change + m.unplaced_change + m.hold_change)';

        return new ReportDefinition(
            key: 'inventory.monthly_movement',
            permission: 'inventory.report',
            title: 'inventory::stockview.monthly',
            filters: ['date_range', 'branch', 'warehouse_id', 'product_id', 'brand_id', 'category_id'],
            query: function (array $f) use ($qty) {
                $months = collect(CarbonPeriod::create(
                    Carbon::parse($f['from'])->startOfMonth(), '1 month', Carbon::parse($f['to'])->startOfMonth(),
                ))->map(fn ($d) => $d->format('Y-m'))->values();

                $calendar = $months->skip(1)->reduce(
                    fn ($q, string $ym) => $q->unionAll(DB::query()->selectRaw('? as ym', [$ym])),
                    DB::query()->selectRaw('? as ym', [$months->first()]),
                );

                $inMonth = self::movements($f)
                    ->whereBetween('m.trx_date', [$f['from'], $f['to']])
                    ->groupByRaw("DATE_FORMAT(m.trx_date, '%Y-%m')")
                    ->selectRaw("DATE_FORMAT(m.trx_date, '%Y-%m') as ym")
                    ->selectRaw("SUM(CASE WHEN {$qty} > 0 THEN {$qty} ELSE 0 END) as in_qty")
                    ->selectRaw("SUM(CASE WHEN {$qty} < 0 THEN -{$qty} ELSE 0 END) as out_qty");

                // ⓘ এল টাকা — মাসে জন্মানো স্তর, নিজের দরে (স্তরে শাখা নেই: গোটা কোম্পানি)
                $layersIn = self::costed($f, 'inv_cost_layers as c', 'c')
                    ->groupByRaw("DATE_FORMAT(c.trx_date, '%Y-%m')")
                    ->selectRaw("DATE_FORMAT(c.trx_date, '%Y-%m') as ym")
                    ->selectRaw('SUM(c.qty_in * c.unit_cost) as in_value');

                // ⓘ গেল টাকা — মাসে স্তর থেকে টানা, ফেরতের ঋণাত্মক টান বাদ দিয়ে
                $layersOut = self::costed($f, 'inv_cost_layer_uses as c', 'c')
                    ->groupByRaw("DATE_FORMAT(c.trx_date, '%Y-%m')")
                    ->selectRaw("DATE_FORMAT(c.trx_date, '%Y-%m') as ym")
                    ->selectRaw('SUM(c.amount) as out_value');

                $openingQty = self::movements($f)
                    ->whereRaw("m.trx_date < CONCAT(cal.ym, '-01')")
                    ->selectRaw("COALESCE(SUM({$qty}), 0)");
                // ⓘ শুরু/শেষ টাকা — মজুদ খাতের জের; পণ্য-স্তরের ছাঁকনি থাকলে খাতা উত্তর দিতে পারে না, তাই খালি
                $byGoods = ! empty($f['warehouse_id']) || ! empty($f['product_id']) || ! empty($f['brand_id']) || ! empty($f['category_id']);
                $ledger = fn (string $before) => DB::table('ledger_entries as le')
                    ->join('accounts as a', 'a.id', '=', 'le.account_id')
                    ->where('le.company_id', $f['company_id'])
                    ->where('a.code', StandardChart::INVENTORY)
                    ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
                    ->whereRaw("le.trx_date < {$before}")
                    ->selectRaw('COALESCE(SUM(le.debit - le.credit), 0)');

                $rows = DB::query()->fromSub($calendar, 'cal')
                    ->leftJoinSub($inMonth, 'x', 'x.ym', '=', 'cal.ym')
                    ->leftJoinSub($layersIn, 'li', 'li.ym', '=', 'cal.ym')
                    ->leftJoinSub($layersOut, 'lo', 'lo.ym', '=', 'cal.ym')
                    ->select('cal.ym')
                    ->selectSub($openingQty, 'opening_qty')
                    ->selectRaw('COALESCE(x.in_qty, 0) as in_qty')
                    ->selectRaw('COALESCE(x.out_qty, 0) as out_qty')
                    ->when(! $byGoods, fn ($q) => $q
                        ->selectSub($ledger("CONCAT(cal.ym, '-01')"), 'opening_value')
                        ->selectSub($ledger("DATE_ADD(CONCAT(cal.ym, '-01'), INTERVAL 1 MONTH)"), 'closing_value'))
                    ->when($byGoods, fn ($q) => $q->selectRaw('NULL as opening_value, NULL as closing_value'))
                    ->selectRaw('COALESCE(li.in_value, 0) as in_value')
                    ->selectRaw('COALESCE(lo.out_value, 0) as out_value');

                return DB::query()->fromSub($rows, 'r')
                    ->orderBy('r.ym')
                    ->select('r.ym', 'r.opening_qty', 'r.in_qty', 'r.out_qty')
                    ->selectRaw('r.opening_qty + r.in_qty - r.out_qty as closing_qty')
                    ->addSelect('r.opening_value', 'r.in_value', 'r.out_value', 'r.closing_value');
            },
            columns: [
                ['key' => 'ym', 'label' => 'inventory::stockview.month', 'width' => '7rem'],
                ['key' => 'opening_qty', 'label' => 'inventory::stockview.opening', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'in_qty', 'label' => 'inventory::stockview.came_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'out_qty', 'label' => 'inventory::stockview.went_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'closing_qty', 'label' => 'inventory::stockview.closing', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'opening_value', 'label' => 'inventory::stockview.opening_value', 'type' => ReportColumn::MONEY, 'total' => false, 'permission' => 'inventory.cost.view'],
                ['key' => 'in_value', 'label' => 'inventory::stockview.in_value', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.cost.view'],
                ['key' => 'out_value', 'label' => 'inventory::stockview.out_value', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.cost.view'],
                ['key' => 'closing_value', 'label' => 'inventory::stockview.closing_value', 'type' => ReportColumn::MONEY, 'total' => false, 'permission' => 'inventory.cost.view'],
            ],
        );
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

    /**
     * খরচের স্তর বা তার টান, পণ্যের ছাঁকনিসহ — মাসওয়ারি টাকার জন্য।
     * ⓘ শাখার দেয়াল নেই, কারণ স্তরে শাখা নেই (কোম্পানির); গুদামেরও নেই — কলামের নামেই লেখা।
     */
    private static function costed(array $f, string $table, string $a): Builder
    {
        return DB::table($table)
            ->join('inv_products as p', 'p.id', '=', "{$a}.product_id")
            ->where("{$a}.company_id", $f['company_id'])
            ->whereBetween("{$a}.trx_date", [$f['from'], $f['to']])
            ->when(! empty($f['product_id']), fn ($q) => $q->where("{$a}.product_id", (int) $f['product_id']))
            ->when(! empty($f['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $f['brand_id']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('p.category_id', (int) $f['category_id']));
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
