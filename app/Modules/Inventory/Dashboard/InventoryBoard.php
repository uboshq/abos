<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Core\Contracts\StockDemand;
use App\Core\Contracts\StockSupply;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ মজুদের ড্যাশবোর্ডের অঙ্ক — মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * ⓘ বেশির ভাগ ঘর এক ছবি থেকে ([[StockFacts::snapshot()]]) — তাই সমস্যা কেন্দ্র, পুনঃঅর্ডার, অচল মজুদ আর মূল্যের কোণা একই সংখ্যা পড়ে।
 * ⓘ বিক্রয়/ক্রয়ের অঙ্ক চুক্তি দিয়ে ([[StockDemand]], [[StockSupply]]) — মজুদ ওদের চেনে না।
 * ⓘ টাকা bcmath-এ string; পর্দায় ইংরেজি অঙ্ক (নমুনার মতো)।
 */
final class InventoryBoard
{
    /** @var Collection<int, Product>|null */
    private ?Collection $snap = null;

    public function __construct(
        private readonly StockFacts $facts,
        private readonly StockDemand $demand,
        private readonly StockSupply $supply,
    ) {}

    /**
     * ⭐ ছাঁকনির সারির বাছাই — ঠিকানা থেকে (?warehouse= ?brand= ?category= ?abc=); প্রতিটা ঘর এটা মানে।
     *
     * @return array{warehouse: ?int, brand: ?int, category: ?int, abc: ?string}
     */
    public function picked(): array
    {
        $id = fn (string $k): ?int => ctype_digit((string) request()->query($k, '')) && (int) request()->query($k) > 0 ? (int) request()->query($k) : null;
        $abc = strtoupper((string) request()->query('abc', ''));

        return ['warehouse' => $id('warehouse'), 'brand' => $id('brand'), 'category' => $id('category'), 'abc' => in_array($abc, ['A', 'B', 'C'], true) ? $abc : null];
    }

    /** @return Collection<int, Product> */
    public function snapshot(): Collection
    {
        if ($this->snap !== null) {
            return $this->snap;
        }

        $p = $this->picked();
        $snap = ($p['warehouse'] !== null ? $this->facts->forWarehouse($p['warehouse']) : $this->facts)->snapshot()
            ->when($p['brand'] !== null, fn (Collection $c) => $c->where('brand_id', $p['brand']))
            ->when($p['category'] !== null, fn (Collection $c) => $c->where('category_id', $p['category']))
            ->values();

        if ($p['abc'] !== null) {
            $classes = self::abcOf($snap);
            $snap = $snap->filter(fn (Product $x) => ($classes[(int) $x->id] ?? 'C') === $p['abc'])->values();
        }

        return $this->snap = $snap;
    }

    /**
     * ⭐ ABC — গত 30 দিনের বেরোনো মালের টাকায় বড় থেকে ছোট; জমা 70% পর্যন্ত A, 90% পর্যন্ত B, বাকি C (আন্তর্জাতিক প্রচলিত ভাগ)।
     *
     * @param  Collection<int, Product>  $snap
     * @return array<int, string>
     */
    public static function abcOf(Collection $snap): array
    {
        $values = $snap->mapWithKeys(fn (Product $p) => [(int) $p->id => self::valueOf($p, (string) $p->getAttribute('out_30'))])
            ->sort(fn (string $a, string $b) => bccomp($b, $a, 4));
        $total = $values->reduce(fn (string $c, string $v) => bcadd($c, $v, 4), '0');
        $out = [];
        $cum = '0';

        foreach ($values as $id => $v) {
            $share = bccomp($total, '0', 4) > 0 ? bcdiv(bcmul($cum, '100', 4), $total, 4) : '100';
            $out[$id] = bccomp($share, '70', 4) < 0 && bccomp($v, '0', 4) > 0 ? 'A' : (bccomp($share, '90', 4) < 0 && bccomp($v, '0', 4) > 0 ? 'B' : 'C');
            $cum = bcadd($cum, $v, 4);
        }

        return $out;
    }

    /**
     * ⭐ ছাঁকনির সারি — নমুনার ক্রমে।
     *
     * @return array{today: string, updated: string, export: ?string, filters: list<array<string, mixed>>, create: list<array{label: string, href: string}>}
     */
    public function toolbar(): array
    {
        $p = $this->picked();
        $all = $this->facts->snapshot();
        $months = __('inventory::board.months');
        $today = Carbon::today();
        $opt = fn (string $key, ?int $value, string $label, bool $on) => ['label' => $label, 'on' => $on, 'href' => request()->fullUrlWithQuery([$key => $value])];

        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('id')->get();
        $brandIds = $all->pluck('brand_id')->filter()->unique()->all();
        $categoryIds = $all->pluck('category_id')->filter()->unique()->all();
        $brands = \App\Modules\MasterData\Models\Brand::query()->whereIn('id', $brandIds)->get();
        $categories = \App\Modules\MasterData\Models\ProductCategory::query()->whereIn('id', $categoryIds)->get();
        $nameOf = fn ($m) => method_exists($m, 'name') ? (string) $m->name() : (string) ($m->name_bn ?? $m->name_en ?? $m->name ?? '');

        $filter = function (string $key, string $allLabel, Collection $rows, ?int $picked) use ($opt, $nameOf): array {
            $label = $picked === null ? $allLabel : (string) ($rows->firstWhere('id', $picked) ? $nameOf($rows->firstWhere('id', $picked)) : $allLabel);
            $options = [$opt($key, null, $allLabel, $picked === null)];

            foreach ($rows as $r) {
                $options[] = $opt($key, (int) $r->id, $nameOf($r), $picked === (int) $r->id);
            }

            return ['key' => $key, 'label' => $label, 'active' => $picked !== null, 'options' => $options];
        };

        $abc = ['key' => 'abc', 'label' => 'ABC: '.($p['abc'] ?? __('inventory::board.t.all')), 'active' => $p['abc'] !== null,
            'options' => array_map(fn (?string $c) => ['label' => $c ?? __('inventory::board.t.all'), 'on' => $p['abc'] === $c, 'href' => request()->fullUrlWithQuery(['abc' => $c])], [null, 'A', 'B', 'C'])];

        $can = fn (string $ability) => auth()->user()?->can($ability) ?? false;
        $create = array_values(array_filter([
            Route::has('purchase.receipt.create') && $can('purchase.receipt.create') ? ['label' => __('inventory::board.t.receive'), 'href' => route('purchase.receipt.create')] : null,
            Route::has('inventory.transfer.create') && $can('inventory.transfer.create') ? ['label' => __('inventory::board.t.transfer'), 'href' => route('inventory.transfer.create')] : null,
            Route::has('inventory.count.create') && $can('inventory.count.create') ? ['label' => __('inventory::board.t.count'), 'href' => route('inventory.count.create')] : null,
            Route::has('inventory.stock.adjust') && $can('inventory.stock.adjust') ? ['label' => __('inventory::board.t.adjust'), 'href' => route('inventory.stock.adjust')] : null,
        ]));

        return [
            'today' => __('inventory::board.t.today', ['date' => $today->day.' '.($months[$today->month] ?? '').' '.$today->year]).' ▾',
            'updated' => __('inventory::board.t.updated', ['time' => Carbon::now()->format('H:i')]),
            'export' => Route::has('inventory.report.show') && $can('inventory.report') ? route('inventory.report.show', ['slug' => 'stock-value']) : null,
            'filters' => [
                $filter('warehouse', __('inventory::board.t.all_warehouses'), $warehouses, $p['warehouse']),
                $filter('brand', __('inventory::board.t.all_brands'), $brands, $p['brand']),
                $filter('category', __('inventory::board.t.all_categories'), $categories, $p['category']),
                $abc,
            ],
            'create' => $create,
        ];
    }

    /**
     * ⭐ সমস্যা কেন্দ্র — নমুনার ক্রমে দশ ঘর। ঘর শূন্য হলে অবস্থা "ঠিক আছে" (সবুজ), নইলে নমুনার রং।
     *
     * @return list<array{key: string, label: string, count: string, value: string, text: string, action: string, href: string, severity: string}>
     */
    public function problems(): array
    {
        $snap = $this->snapshot();
        $today = Carbon::today();

        // 1. মজুদ শেষ — বিক্রিযোগ্য শূন্য, অথচ খোলা আদেশে চাহিদা আছে
        $open = $this->demand->openByProduct();
        $out = $snap->filter(fn (Product $p) => bccomp((string) $p->getAttribute('available_qty'), '0', 4) <= 0 && isset($open[(int) $p->id]));
        $blocked = $out->reduce(fn (string $c, Product $p) => bcadd($c, $open[(int) $p->id]['value'], 4), '0');
        $lostAll = $this->demand->lostByProduct($today->copy()->subDays(30)->toDateString());
        $lost = $snap->filter(fn (Product $p) => bccomp((string) $p->getAttribute('available_qty'), '0', 4) <= 0)
            ->reduce(fn (string $c, Product $p) => bcadd($c, $lostAll[(int) $p->id] ?? '0', 4), '0');

        // 2. অর্ডার সীমার নিচে — আর তার মধ্যে কয়টা 7 দিনে ফুরাবে (দিনে বিক্রি = গত 30 দিনের বেরোনো ÷ 30)
        $below = $snap->filter(fn (Product $p) => bccomp((string) ($p->reorder_level ?? '0'), '0', 4) > 0
            && bccomp((string) $p->getAttribute('available_qty'), (string) $p->reorder_level, 4) < 0);
        $soon = $below->filter(fn (Product $p) => ($d = self::daysOfCover($p)) !== null && bccomp($d, '7', 2) <= 0)->count();

        // 3. মাইনাস মজুদ — গুদাম ধরে ([[InventoryDashboard::negativeStock()]]-এর একই সংজ্ঞা)
        $negative = InventoryDashboard::negativeStock();

        // 4. মেয়াদ 60 দিনে শেষ — মাল থাকা লট
        $expiry = $this->expiringLots(60);
        $within20 = collect($expiry)->filter(fn (array $l) => $l['days'] <= 20)->count();
        $expiryValue = collect($expiry)->reduce(fn (string $c, array $l) => bcadd($c, $l['value'], 4), '0');

        // 5. গণনায় পার্থক্য — প্রতিটা গুদামের শেষ গণনা
        $count = $this->lastCountGap();

        // 6. বিল ছাড়া মাল
        $unbilled = $this->supply->unbilledReceipts();

        // 7. পথে আটকে বদলি — রওনা হয়েছে, 2 দিনের বেশি, পৌঁছায়নি
        $stuck = $this->stuckTransfers(2);

        // 8. 90 দিন নড়েনি — মাল আছে, শেষ বেরোনো 90 দিনের বেশি আগে (বা কখনো না)
        $dead = $this->notMoved(90);

        // 9. বেশি মজুদ — সর্বোচ্চ সীমার ওপরে
        $over = $snap->filter(fn (Product $p) => bccomp((string) ($p->max_level ?? '0'), '0', 4) > 0
            && bccomp((string) $p->getAttribute('on_hand_qty'), (string) $p->max_level, 4) > 0);
        $overValue = $over->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, bcsub((string) $p->getAttribute('on_hand_qty'), (string) $p->max_level, 4)), 4), '0');

        // 10. নষ্ট ও মেয়াদোত্তীর্ণ — এ মাসের মজুদ-সমন্বয়ে কমা মাল, আর গত মাসের তুলনা
        [$damageNow, $damageLast] = $this->writeOffs();

        $sev = fn (string $when, bool $any) => $any ? $when : 'ok';
        $none = __('inventory::board.none');

        return [
            ['key' => 'stock_out', 'label' => __('inventory::board.p.stock_out'), 'count' => (string) $out->count(),
                'value' => __('inventory::board.p.lost', ['amount' => self::short($lost)]),
                'text' => __('inventory::board.p.stock_out_text', ['amount' => self::short($blocked)]),
                'action' => __('inventory::board.a.urgent_buy'), 'href' => self::link('inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'below']),
                'severity' => $sev('urgent', $out->isNotEmpty())],
            ['key' => 'below_reorder', 'label' => __('inventory::board.p.below_reorder'), 'count' => (string) $below->count(),
                'value' => __('inventory::board.p.soon', ['n' => $soon]), 'text' => __('inventory::board.p.below_reorder_text'),
                'action' => __('inventory::board.a.draft_purchase'), 'href' => self::link('inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'below']),
                'severity' => $sev('urgent', $below->isNotEmpty())],
            ['key' => 'negative', 'label' => __('inventory::board.p.negative'), 'count' => (string) $negative,
                'value' => __('inventory::board.p.negative_value'), 'text' => __('inventory::board.p.negative_text'),
                'action' => __('inventory::board.a.fix'), 'href' => self::link('inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'negative']),
                'severity' => $sev('urgent', $negative > 0)],
            ['key' => 'expiry', 'label' => __('inventory::board.p.expiry'), 'count' => (string) count($expiry),
                'value' => count($expiry) === 0 ? $none : self::short($expiryValue).' · '.__('inventory::board.p.lots_in_20', ['n' => $within20]),
                'text' => __('inventory::board.p.expiry_text'), 'action' => __('inventory::board.a.sell_first'),
                'href' => self::link('inventory.report.show', ['slug' => 'expiring']), 'severity' => $sev('today', $expiry !== [])],
            ['key' => 'count_gap', 'label' => __('inventory::board.p.count_gap'), 'count' => (string) $count['lines'],
                'value' => $count['lines'] === 0 ? $none : __('inventory::board.p.count_gap_value', ['where' => $count['warehouse'], 'amount' => self::short(ltrim($count['value'], '-')), 'dir' => __(bccomp($count['value'], '0', 4) < 0 ? 'inventory::board.less' : 'inventory::board.more')]),
                'text' => $count['date'] === null ? __('inventory::board.p.no_count') : __('inventory::board.p.count_gap_text', ['date' => $count['date'], 'state' => __($count['approved'] ? 'inventory::board.approved' : 'inventory::board.approval_due')]),
                'action' => __('inventory::board.a.recount'), 'href' => self::link('inventory.count.index'), 'severity' => $sev('today', $count['lines'] > 0)],
            ['key' => 'unbilled', 'label' => __('inventory::board.p.unbilled'), 'count' => (string) $unbilled['receipts'],
                'value' => $unbilled['receipts'] === 0 ? $none : self::short($unbilled['value']).($unbilled['top_name'] !== '' ? ' · '.$unbilled['top_name'] : ''),
                'text' => __('inventory::board.p.unbilled_text'), 'action' => __('inventory::board.a.ask_bill'),
                'href' => self::link('purchase.receipt.index'), 'severity' => $sev('today', $unbilled['receipts'] > 0)],
            ['key' => 'transfer_stuck', 'label' => __('inventory::board.p.transfer_stuck'), 'count' => (string) $stuck['count'],
                'value' => $stuck['count'] === 0 ? $none : __('inventory::board.p.transfer_stuck_value', ['days' => $stuck['days'], 'amount' => self::short($stuck['value'])]),
                'text' => $stuck['routes'], 'action' => __('inventory::board.a.receive'),
                'href' => self::link('inventory.transfer.index'), 'severity' => $sev('week', $stuck['count'] > 0)],
            ['key' => 'dead', 'label' => __('inventory::board.p.dead'), 'count' => (string) $dead['count'],
                'value' => $dead['count'] === 0 ? $none : __('inventory::board.p.dead_value', ['amount' => self::short($dead['value'])]),
                'text' => __('inventory::board.p.dead_text'), 'action' => __('inventory::board.a.discount_sale'),
                'href' => self::link('inventory.report.show', ['slug' => 'slow-dead', 'status' => 'dead']), 'severity' => $sev('week', $dead['count'] > 0)],
            ['key' => 'over', 'label' => __('inventory::board.p.over'), 'count' => (string) $over->count(),
                'value' => $over->isEmpty() ? $none : self::short($overValue), 'text' => __('inventory::board.p.over_text'),
                'action' => __('inventory::board.a.cut_orders'), 'href' => self::link('inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'over']),
                'severity' => $sev('week', $over->isNotEmpty())],
            ['key' => 'damage', 'label' => __('inventory::board.p.damage'), 'count' => self::short($damageNow),
                'value' => bccomp($damageLast, '0', 4) > 0 ? __('inventory::board.p.damage_vs', ['pct' => bcdiv(bcmul(bcsub($damageNow, $damageLast, 4), '100', 4), $damageLast, 0)]) : __('inventory::board.p.damage_first'),
                'text' => __('inventory::board.p.damage_text'), 'action' => __('inventory::board.a.see_reason'),
                'href' => self::link('inventory.stock.movement'), 'severity' => $sev('week', bccomp($damageNow, '0', 4) > 0)],
        ];
    }

    /**
     * ⭐ মূল্যের কোণা — মজুদের দাম (কেনা দামে) বড় করে, তার নিচে আটটা মাপ।
     *
     * @return array{cost: string, sub: string, rows: list<array{label: string, value: string, tone: string}>}
     */
    public function corner(): array
    {
        $snap = $this->snapshot();
        $cost = $snap->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0');
        $sale = $snap->reduce(fn (string $c, Product $p) => bcadd($c, bcmul((string) $p->getAttribute('on_hand_qty'), (string) ($p->sale_price ?? '0'), 4), 4), '0');
        $out30 = $snap->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('out_30')), 4), '0');
        $dio = bccomp($out30, '0', 4) > 0 ? bcdiv(bcmul($cost, '30', 4), $out30, 0) : null;
        $turns = $dio !== null && bccomp($dio, '0', 0) > 0 ? bcdiv('365', $dio, 1) : null;
        $risk = bcadd(collect($this->expiringLots(60))->reduce(fn (string $c, array $l) => bcadd($c, $l['value'], 4), '0'), $this->notMoved(90)['value'], 4);
        $warehouses = Warehouse::query()->where('is_active', true)->count();
        $costShown = \App\Core\Security\FieldSecurity::visible(StockMovement::class, 'unit_cost')
            && $snap->first()?->getAttributes() !== null && array_key_exists('avg_cost', $snap->first()->getAttributes());

        $today = Carbon::today();
        $fill = $this->demand->fillRate($today->copy()->subDays(30)->toDateString(), $today->toDateString());
        $ira = $this->recordAccuracy();
        // ⓘ GMROI = গত 365 দিনের গ্রস লাভ ÷ আজকের মজুদ (কেনা দামে) — গড় মজুদের সবচেয়ে কাছের সহজ মাপ
        $gp = $this->demand->grossProfit($today->copy()->subDays(365)->toDateString(), $today->toDateString());
        // ⛔ লাভ ÷ কেনা দাম — খরচ ছাড়া এটাও খরচের কথা বলে দেয়
        $gmroi = ! $costShown ? \App\Core\Security\FieldSecurity::mask() : (bccomp($cost, '0', 4) > 0 ? bcdiv($gp, $cost, 2) : null);

        $rows = [
            ['label' => __('inventory::board.v.products'), 'value' => $snap->count().' · '.$warehouses, 'tone' => 'var(--color-dash-ffffff)'],
            ['label' => __('inventory::board.v.dio'), 'value' => $dio === null ? '—' : __('inventory::board.days', ['n' => $dio]), 'tone' => 'var(--color-dash-a5f3fc)'],
            ['label' => __('inventory::board.v.turns'), 'value' => $turns === null ? '—' : __('inventory::board.times', ['n' => $turns]), 'tone' => 'var(--color-dash-a5f3fc)'],
            ['label' => __('inventory::board.v.fill'), 'value' => $fill === null ? '—' : $fill.'%', 'tone' => 'var(--color-dash-86efac)'],
            ['label' => __('inventory::board.v.ira'), 'value' => $ira === null ? '—' : $ira.'%', 'tone' => 'var(--color-dash-86efac)'],
            ['label' => __('inventory::board.v.gmroi'), 'value' => $gmroi ?? '—', 'tone' => 'var(--color-dash-86efac)'],
            ['label' => __('inventory::board.v.carrying'), 'value' => self::short(bcdiv(bcmul($cost, '0.18', 4), '12', 4)).__('inventory::board.per_month'), 'tone' => 'var(--color-dash-fde68a)'],
            ['label' => __('inventory::board.v.risk'), 'value' => self::short($risk), 'tone' => 'var(--color-dash-fda4af)'],
        ];

        return [
            'cost' => $costShown ? self::short($cost) : '—',
            'sub' => $costShown ? __('inventory::board.v.sub', ['sale' => self::short($sale)]) : __('inventory::board.v.no_cost'),
            'rows' => $rows,
        ];
    }

    /**
     * ⭐ আবার অর্ডার দিতে হবে — নমুনার ছক: হাতে, দিনে বিক্রি, কত দিন চলবে, লিড টাইম, নিরাপত্তা মজুদ, অর্ডার সীমা, পথে আছে, প্রস্তাবিত।
     *
     * ⓘ নিরাপত্তা মজুদ = 1.65 × দিনের বিক্রির ওঠানামা (গত 90 দিন) × √লিড টাইম — 95% সেবার মান (আন্তর্জাতিক প্রচলিত সূত্র)।
     * ⓘ অর্ডার সীমা = দিনে বিক্রি × লিড টাইম + নিরাপত্তা মজুদ; পণ্যের নিজের সীমা বেশি হলে সেটাই।
     * ⓘ প্রস্তাবিত = (সর্বোচ্চ সীমা, না থাকলে অর্ডার সীমার দ্বিগুণ) − হাতে − পথে; পণ্যের নিজের পুনঃঅর্ডার পরিমাণ থাকলে সেটা।
     *
     * @return array{count: int, rows: list<array<string, mixed>>, lost: string, blocked: string, lost_count: int}
     */
    public function reorder(int $limit = 6): array
    {
        $snap = $this->snapshot();
        $snap->loadMissing('brandRow');
        $onOrder = $this->supply->onOrderByProduct();
        $sigma = $this->dailySpread($snap->pluck('id')->all());
        $months = __('inventory::board.months_short');

        $rows = $snap->map(function (Product $p) use ($sigma, $onOrder, $months) {
            $daily = bcdiv((string) $p->getAttribute('out_30'), '30', 4);
            $lead = (int) ($p->lead_days ?? 0);
            $ss = bcmul(bcmul('1.65', $sigma[(int) $p->id] ?? '0', 4), (string) sqrt(max($lead, 1)), 4);
            $rop = bcadd(bcmul($daily, (string) $lead, 4), $ss, 4);
            $rop = bccomp((string) ($p->reorder_level ?? '0'), $rop, 4) > 0 ? (string) $p->reorder_level : $rop;
            $have = (string) $p->getAttribute('available_qty');

            if (bccomp($rop, '0', 4) <= 0 || bccomp($have, $rop, 4) > 0) {
                return null;
            }

            $coming = $onOrder[(int) $p->id] ?? null;
            $target = bccomp((string) ($p->max_level ?? '0'), '0', 4) > 0 ? (string) $p->max_level : bcmul($rop, '2', 4);
            $suggest = bccomp((string) ($p->reorder_qty ?? '0'), '0', 4) > 0 ? (string) $p->reorder_qty
                : bcsub(bcsub($target, bccomp($have, '0', 4) > 0 ? $have : '0', 4), $coming['qty'] ?? '0', 4);
            $days = self::daysOfCover($p);
            $out = bccomp($have, '0', 4) <= 0;

            return [
                'id' => (int) $p->id, 'name' => $p->name(), 'brand' => (string) ($p->brandRow?->drillLabel() ?? ''),
                'have' => self::qty($have), 'rate' => self::qty($daily), 'lead' => __('inventory::board.days', ['n' => $lead]),
                'ss' => self::qty($ss), 'rop' => self::qty($rop),
                'po' => $coming === null ? '—' : self::qty($coming['qty']).($coming['expected_on'] ? ' · '.Carbon::parse($coming['expected_on'])->day.' '.($months[Carbon::parse($coming['expected_on'])->month] ?? '') : ''),
                'sug' => $coming !== null && bccomp($suggest, '0', 4) <= 0 ? '—' : self::qty(bccomp($suggest, '0', 4) > 0 ? $suggest : '0'),
                'days' => $out ? __('inventory::board.r.out') : ($days === null ? '—' : __('inventory::board.days', ['n' => $days])),
                'cover' => $out ? '0' : ($days ?? '99'),
                'out' => $out,
            ];
        })->filter()->sortBy(fn (array $r) => (float) $r['cover'])->values();

        $lostAll = $this->demand->lostByProduct(Carbon::today()->subDays(30)->toDateString());
        $open = $this->demand->openByProduct();
        $outs = $snap->filter(fn (Product $p) => bccomp((string) $p->getAttribute('available_qty'), '0', 4) <= 0);

        return [
            'count' => $rows->count(),
            'rows' => $rows->take($limit)->map(function (array $r) {
                $c = (float) $r['cover'];
                $r['tone'] = $r['out'] ? 'var(--color-dash-dc2626)' : ($c <= 3 ? 'var(--color-dash-ef4444)' : ($c <= 7 ? 'var(--color-dash-f97316)' : ($c <= 10 ? 'var(--color-dash-eab308)' : 'var(--color-dash-84cc16)')));
                $r['width'] = $r['out'] ? '0%' : min(100, (int) round($c / 20 * 100)).'%';
                $r['row'] = $r['out'] ? 'var(--color-dash-fef2f2)' : 'transparent';

                return $r;
            })->all(),
            'lost' => $outs->reduce(fn (string $c, Product $p) => bcadd($c, $lostAll[(int) $p->id] ?? '0', 4), '0'),
            'blocked' => $outs->reduce(fn (string $c, Product $p) => bcadd($c, $open[(int) $p->id]['value'] ?? '0', 4), '0'),
            'lost_count' => $outs->filter(fn (Product $p) => isset($open[(int) $p->id]) || isset($lostAll[(int) $p->id]))->count(),
        ];
    }

    /**
     * ⭐ ধীর ও অচল মজুদ — কত দিন নড়েনি তার পাঁচ ভাগ (টাকায়), বাছাই (সব / 15+ / 30+ / 60+ / 90+) আর সবচেয়ে পুরনো ছয়টা পণ্য।
     *
     * ⓘ "নড়েনি" = শেষ বেরোনোর পর থেকে দিন (কখনো না বেরোলে সবচেয়ে পুরনো); মাল আছে এমন পণ্যই গোনা।
     * ⓘ "মেয়াদ কম" = পণ্যের কোনো লটে তৈরি থেকে মেয়াদ 120 দিনের মধ্যে (বিস্কুট-কেকের মতো)।
     *
     * @return array<string, mixed>
     */
    public function slow(): array
    {
        $pick = (int) request()->query('idle', '0');
        $pick = in_array($pick, [15, 30, 60, 90], true) ? $pick : 0;
        $today = Carbon::today();
        $held = $this->snapshot()->filter(fn (Product $p) => bccomp((string) $p->getAttribute('on_hand_qty'), '0', 4) > 0);

        $idle = fn (Product $p): int => $p->getAttribute('last_out') === null ? 9999 : (int) Carbon::parse((string) $p->getAttribute('last_out'))->diffInDays($today);
        $value = fn (Product $p): string => self::valueOf($p, (string) $p->getAttribute('on_hand_qty'));

        $bands = ['0' => '0', '15' => '0', '30' => '0', '60' => '0', '90' => '0'];

        foreach ($held as $p) {
            $d = $idle($p);
            $key = $d > 90 ? '90' : ($d > 60 ? '60' : ($d > 30 ? '30' : ($d >= 15 ? '15' : '0')));
            $bands[$key] = bcadd($bands[$key], $value($p), 4);
        }

        $totalHeld = array_reduce($bands, fn (string $c, string $v) => bcadd($c, $v, 4), '0');
        $edge = $pick === 0 ? 15 : $pick;
        $over = $held->filter(fn (Product $p) => $idle($p) >= $edge);
        $overValue = $over->reduce(fn (string $c, Product $p) => bcadd($c, $value($p), 4), '0');

        $shortLife = Batch::query()->whereNotNull('expiry_date')->whereNotNull('manufactured_on')
            ->whereIn('product_id', $over->pluck('id')->all() ?: [0])
            ->whereRaw('DATEDIFF(expiry_date, manufactured_on) <= 120')
            ->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        $top = $over->sortByDesc($idle)->take(6);
        $home = $this->biggestWarehouse($top->pluck('id')->all());

        return [
            'pick' => $pick,
            'value' => self::short($overValue),
            'summary' => $pick === 0
                ? __('inventory::board.s.summary_all', ['n' => $over->count(), 'amount' => self::short($overValue)])
                : __('inventory::board.s.summary', ['days' => $pick, 'n' => $over->count(), 'amount' => self::short($overValue)]),
            'bands' => collect($bands)->map(fn (string $v) => bccomp($totalHeld, '0', 4) > 0 ? max(1, (int) round((float) bcdiv(bcmul($v, '1000', 4), $totalHeld, 4))) : 0)->all(),
            'picks' => collect([0, 15, 30, 60, 90])->map(fn (int $d) => [
                'label' => $d === 0 ? __('inventory::board.t.all') : ($d === 15 ? __('inventory::board.s.days_plus', ['n' => $d]) : $d.'+'),
                'on' => $d === $pick,
                'href' => request()->fullUrlWithQuery(['idle' => $d === 0 ? null : $d]),
            ])->all(),
            'rows' => $top->map(function (Product $p) use ($idle, $value, $shortLife, $home) {
                $d = $idle($p);

                return [
                    'name' => $p->name(), 'warehouse' => $home[(int) $p->id] ?? '',
                    'days' => $d === 9999 ? __('inventory::board.s.never') : __('inventory::board.days', ['n' => $d]),
                    'value' => self::short($value($p)), 'short' => in_array((int) $p->id, $shortLife, true),
                    'tone' => $d > 90 ? 'var(--color-dash-475569)' : ($d > 60 ? 'var(--color-dash-dc2626)' : ($d > 30 ? 'var(--color-dash-ea580c)' : 'var(--color-dash-ca8a04)')),
                ];
            })->values()->all(),
        ];
    }

    /**
     * ⭐ মজুদের হিসাব-মিলান · এ মাস (কোটি) — শুরু + ঢুকল + ফেরত এল − বিক্রিতে গেল − নষ্ট − গণনায় কম = আজ।
     *
     * ⓘ ঢোকা খরচ-স্তর থেকে (কেনা = গ্রহণ/বিল, ফেরত = বিক্রয় ফেরত), বেরোনো স্তর-ব্যবহার থেকে (বিক্রি = চালান/বিল/DO); নষ্ট =
     * [[writeOffs()]]; বাকি সমন্বয় "গণনায় কম"। আজ = এখনকার মজুদ (কেনা দামে); শুরু পেছন থেকে মেলানো — তাই সেতুটা সবসময় মেলে।
     * ⓘ খাতার সাথে মিল: আজকের মজুদ বনাম মজুদের খাতা (1120)। ⓘ স্তরে গুদাম নেই, তাই এটা গোটা কোম্পানির (পণ্যের ছাঁকনি মানে)।
     *
     * @return array{steps: list<array{key: string, value: string}>, matches: ?bool, gap: string}
     */
    public function rollForward(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $ids = $this->snapshot()->pluck('id')->all() ?: [0];
        $sum = fn (string $table, string $expr, array $types, bool $not = false) => (string) (\Illuminate\Support\Facades\DB::table($table)
            ->where('company_id', \App\Core\Support\CompanyContext::id())
            ->whereBetween('trx_date', [$from, $to])->whereIn('product_id', $ids)
            ->where('source_type', '<>', 'opening')
            ->when($types !== [], fn ($q) => $not ? $q->whereNotIn('source_type', $types) : $q->whereIn('source_type', $types))
            ->selectRaw("COALESCE(SUM({$expr}), 0) as v")->value('v') ?? '0');

        $buy = ['purchase_receipt', 'purchase_bill'];
        $back = ['sales_return'];
        $sold = ['delivery_challan', 'sales_invoice', 'delivery_order'];

        $in = $sum('inv_cost_layers', 'qty_in * unit_cost', $buy);
        $returned = $sum('inv_cost_layers', 'qty_in * unit_cost', $back);
        $otherIn = $sum('inv_cost_layers', 'qty_in * unit_cost', array_merge($buy, $back), true);
        $out = $sum('inv_cost_layer_uses', 'qty * unit_cost', $sold);
        $otherOut = $sum('inv_cost_layer_uses', 'qty * unit_cost', $sold, true);
        $damage = $this->writeOffs()[0];
        $counted = bcsub(bcsub($otherOut, $damage, 4), $otherIn, 4);
        $counted = bccomp($counted, '0', 4) > 0 ? $counted : '0';

        $close = $this->snapshot()->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0');
        $open = bcsub(bcadd(bcadd(bcadd($close, $out, 4), $damage, 4), $counted, 4), bcadd($in, $returned, 4), 4);

        $ledger = $this->facts->value();
        $filtered = array_filter($this->picked(), fn ($v) => $v !== null) !== [];

        return [
            'steps' => [
                ['key' => 'open', 'value' => $open], ['key' => 'in', 'value' => $in], ['key' => 'returned', 'value' => $returned],
                ['key' => 'sold', 'value' => '-'.$out], ['key' => 'damage', 'value' => '-'.$damage], ['key' => 'counted', 'value' => '-'.$counted],
                ['key' => 'close', 'value' => $close],
            ],
            'matches' => $ledger === null || $filtered ? null : bccomp(bcsub($ledger, $close, 0), '0', 0) === 0,
            'gap' => $ledger === null ? '0' : bcsub($ledger, $close, 4),
        ];
    }

    /**
     * ⭐ কত দিনের মজুদ আছে — সাতটা ভাগে পণ্যের সংখ্যা (0, 1–7, 8–14, 15–30, 31–45, 46–60, 60+)।
     * ⓘ মাল নেই = 0; মাল আছে অথচ 30 দিনে বিক্রি নেই = 60+।
     *
     * @return array{buckets: list<array{label: string, n: int}>, total: int, short: int, long: int}
     */
    public function coverHistogram(): array
    {
        $labels = ['0', '1–7', '8–14', '15–30', '31–45', '46–60', '60+'];
        $n = array_fill(0, 7, 0);

        foreach ($this->snapshot() as $p) {
            if (bccomp((string) $p->getAttribute('available_qty'), '0', 4) <= 0) {
                $n[0]++;

                continue;
            }

            $c = self::daysOfCover($p);
            $d = $c === null ? 9999.0 : (float) $c;
            $i = match (true) {
                $d <= 7 => 1, $d <= 14 => 2, $d <= 30 => 3, $d <= 45 => 4, $d <= 60 => 5, default => 6,
            };
            $n[$i]++;
        }

        return [
            'buckets' => array_map(fn (int $i) => ['label' => $labels[$i], 'n' => $n[$i]], range(0, 6)),
            'total' => array_sum($n), 'short' => $n[0] + $n[1], 'long' => $n[6],
        ];
    }

    /**
     * ⭐ ABC-XYZ — টাকায় গুরুত্ব (ABC, [[abcOf()]]) × বিক্রি কতটা স্থির (XYZ: 12 সপ্তাহের বেরোনোর ওঠানামার হার CV — 0.5-এর নিচে X,
     * 1-এর নিচে Y, বাকি Z)। প্রতিটা ঘরে পণ্যের সংখ্যা আর মজুদের মূল্যের ভাগ।
     *
     * @return array<string, array{n: int, share: string}>
     */
    public function abcXyz(): array
    {
        $snap = $this->snapshot();
        $abc = self::abcOf($snap);
        $from = Carbon::today()->subWeeks(12)->startOfWeek();
        $weekly = StockMovement::query()->whereIn('product_id', $snap->pluck('id')->all() ?: [0])
            ->where('floor_change', '<', 0)->where('trx_date', '>=', $from->toDateString())
            ->tap(fn ($q) => $this->narrow($q))
            ->selectRaw('product_id, YEARWEEK(trx_date, 1) as wk, SUM(-floor_change) as qty')
            ->groupBy('product_id', 'wk')->get()->groupBy('product_id');

        $total = $snap->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0');
        $cells = [];

        foreach ($snap as $p) {
            $w = array_pad($weekly->get($p->id, collect())->map(fn ($r) => (float) $r->qty)->values()->all(), 12, 0.0);
            $mean = array_sum($w) / 12;
            $cv = $mean > 0 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $w)) / 12) / $mean : 9.0;
            $key = ($abc[(int) $p->id] ?? 'C').($cv < 0.5 ? 'X' : ($cv < 1.0 ? 'Y' : 'Z'));
            $cells[$key] ??= ['n' => 0, 'value' => '0'];
            $cells[$key]['n']++;
            $cells[$key]['value'] = bcadd($cells[$key]['value'], self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4);
        }

        $out = [];

        foreach (['A', 'B', 'C'] as $a) {
            foreach (['X', 'Y', 'Z'] as $x) {
                $c = $cells[$a.$x] ?? ['n' => 0, 'value' => '0'];
                $out[$a.$x] = ['n' => $c['n'], 'share' => bccomp($total, '0', 4) > 0 ? bcdiv(bcmul($c['value'], '100', 4), $total, 0) : '0'];
            }
        }

        return $out;
    }

    /**
     * ⭐ মেয়াদ শেষের পথে (FEFO) — বুদবুদ: বাঁ থেকে ডানে দিন (0–60), আকারে টাকা; নিচে সবচেয়ে কাছের তিনটা লট।
     *
     * @return array{total: string, bubbles: list<array{x: int, y: int, r: int, c: string}>, rows: list<array<string, string>>}
     */
    public function expiry(): array
    {
        $lots = $this->expiringLots(60);
        $max = collect($lots)->reduce(fn (string $c, array $l) => bccomp($l['value'], $c, 4) > 0 ? $l['value'] : $c, '0');
        $months = __('inventory::board.months_short');

        return [
            'total' => self::short(collect($lots)->reduce(fn (string $c, array $l) => bcadd($c, $l['value'], 4), '0')),
            'bubbles' => collect($lots)->take(24)->values()->map(fn (array $l, int $i) => [
                'x' => 12 + (int) round(min($l['days'], 60) / 60 * 336),
                'y' => 26 + ($i * 37) % 96,
                'r' => bccomp($max, '0', 4) > 0 ? 8 + (int) round(sqrt((float) bcdiv($l['value'], $max, 4)) * 12) : 10,
                'c' => $l['days'] <= 20 ? 'var(--color-dash-ef4444)' : ($l['days'] <= 40 ? 'var(--color-dash-f97316)' : 'var(--color-dash-eab308)'),
            ])->all(),
            'rows' => collect($lots)->take(3)->map(fn (array $l) => [
                'name' => $l['product'], 'lot' => $l['batch'], 'warehouse' => $l['warehouse'],
                'when' => __('inventory::board.days', ['n' => $l['days']]).' · '.self::short($l['value']),
                'tone' => $l['days'] <= 20 ? 'var(--color-dash-dc2626)' : 'var(--color-dash-ea580c)',
            ])->all(),
        ];
    }

    /**
     * ⭐ গুদাম × অবস্থা — প্রতিটা গুদামে কয়টা পণ্য ঠিক আছে, কম, শেষ, বেশি, মেয়াদ ঝুঁকিতে, নড়েনি (90 দিন)।
     * ⓘ "শেষ" কেবল সেই পণ্য যা ঐ গুদামে কোনোদিন ছিল — গুদামে কখনো না ঢোকা পণ্য শেষ নয়।
     *
     * @return list<array{name: string, counts: list<int>}>
     */
    public function warehouseHealth(): array
    {
        $picked = $this->picked()['warehouse'];
        $ids = $this->snapshot()->pluck('id')->flip();
        $edge = Carbon::today()->subDays(90)->toDateString();
        $soon = collect($this->expiringLots(60));
        $out = [];

        foreach ($this->warehouses() as $w) {
            $snap = $this->warehouseSnapshot((int) $w->id)->filter(fn (Product $p) => $ids->has($p->id));
            $seen = StockMovement::query()->where('warehouse_id', $w->id)->distinct()->pluck('product_id')->flip();
            $c = [0, 0, 0, 0, 0, 0];

            foreach ($snap as $p) {
                if (! $seen->has($p->id)) {
                    continue;
                }

                $have = (string) $p->getAttribute('available_qty');
                $onHand = (string) $p->getAttribute('on_hand_qty');
                $level = (string) ($p->reorder_level ?? '0');
                $max = (string) ($p->max_level ?? '0');

                if (bccomp($have, '0', 4) <= 0) {
                    $c[2]++;
                } elseif (bccomp($level, '0', 4) > 0 && bccomp($have, $level, 4) < 0) {
                    $c[1]++;
                } elseif (bccomp($max, '0', 4) > 0 && bccomp($onHand, $max, 4) > 0) {
                    $c[3]++;
                } else {
                    $c[0]++;
                }

                if (bccomp($onHand, '0', 4) > 0 && ($p->getAttribute('last_out') === null || (string) $p->getAttribute('last_out') < $edge)) {
                    $c[5]++;
                }
            }

            $c[4] = $soon->where('warehouse', (string) $w->drillLabel())->pluck('product')->unique()->count();
            $out[] = ['name' => (string) $w->drillLabel(), 'counts' => $c];
        }

        return $out;
    }

    /**
     * ⭐ গণনার নির্ভুলতা ও ঘাটতি — শেষ ছয় গণনার টাকার পার্থক্য (ওপরে বেশি, নিচে কম), এ অর্থবছরের মোট ঘাটতি আর তা মজুদের কত শতাংশ।
     *
     * @return array{ira: ?string, counts: list<array{label: string, value: string}>, short: string, share: string, last: ?array{warehouse: string, value: string}}
     */
    public function countAccuracy(): array
    {
        $counts = StockCount::query()->with(['lines', 'warehouse'])->where('status', '<>', DocumentStatus::CANCELLED)
            ->when($this->picked()['warehouse'] !== null, fn ($q) => $q->where('warehouse_id', $this->picked()['warehouse']))
            ->orderByDesc('count_date')->orderByDesc('id')->limit(6)->get()->reverse()->values();
        $months = __('inventory::board.months_short');
        $value = fn (StockCount $c) => $c->lines->reduce(fn (string $s, $l) => bcadd($s, bcmul((string) $l->difference, (string) ($l->unit_cost ?? '0'), 4), 4), '0');

        $fyStart = Carbon::today()->month >= 7 ? Carbon::create(Carbon::today()->year, 7, 1) : Carbon::create(Carbon::today()->year - 1, 7, 1);
        $short = StockCount::query()->with('lines')->where('status', DocumentStatus::CONFIRMED)->where('count_date', '>=', $fyStart->toDateString())
            ->get()->reduce(fn (string $s, StockCount $c) => bcadd($s, $value($c), 4), '0');
        $stock = $this->snapshot()->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0');
        $last = $counts->last();

        return [
            'ira' => $this->recordAccuracy(),
            'counts' => $counts->map(fn (StockCount $c) => ['label' => Carbon::parse($c->count_date)->day.' '.($months[Carbon::parse($c->count_date)->month] ?? ''), 'value' => $value($c)])->all(),
            'short' => bccomp($short, '0', 4) < 0 ? ltrim($short, '-') : '0',
            'share' => bccomp($stock, '0', 4) > 0 && bccomp($short, '0', 4) < 0 ? bcdiv(bcmul(ltrim($short, '-'), '100', 4), $stock, 2) : '0',
            'last' => $last === null ? null : ['warehouse' => (string) ($last->warehouse?->drillLabel() ?? ''), 'value' => $value($last)],
        ];
    }

    /** @var array<int, Collection<int, Product>> */
    private array $byWarehouse = [];

    /** @return Collection<int, Warehouse> */
    private function warehouses(): Collection
    {
        $picked = $this->picked()['warehouse'];

        return Warehouse::query()->where('is_active', true)->when($picked !== null, fn ($q) => $q->whereKey($picked))->orderBy('id')->get();
    }

    /** @return Collection<int, Product> এক গুদামের ছবি — একবারই তোলা */
    private function warehouseSnapshot(int $id): Collection
    {
        return $this->byWarehouse[$id] ??= $this->facts->forWarehouse($id)->snapshot();
    }

    /**
     * ⭐ মজুদের দাম ও মজুদে কত দিন · 12 মাস — মাস-শেষের মজুদ (মজুদের খাতা 1120) আর DIO = মাস-শেষের মজুদ ÷ ঐ মাসে বেরোনো × 30।
     * ⓘ চলতি মাসের DIO চলতি দিন ধরে (বেরোনো ÷ পেরোনো দিন)।
     *
     * @return list<array{month: string, value: string, dio: ?int}>|null
     */
    public function trend(): ?array
    {
        if (! \App\Core\Security\FieldSecurity::visible(StockMovement::class, 'unit_cost')) {
            return null;
        }

        $account = \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::INVENTORY);

        if ($account === null) {
            return null;
        }

        $from = Carbon::today()->startOfMonth()->subMonths(11);
        $q = fn () => \App\Core\Support\ViewedBranch::narrow(\App\Models\LedgerEntry::query(), 'ledger_entries.branch_id')
            ->where('ledger_entries.company_id', \App\Core\Support\CompanyContext::id())->where('account_id', $account->id);

        $before = (string) ($q()->where('trx_date', '<', $from->toDateString())->selectRaw('COALESCE(SUM(debit - credit), 0) as b')->value('b') ?? '0');
        $rows = $q()->where('trx_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(trx_date, '%Y-%m') as ym, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c")
            ->groupBy('ym')->get()->keyBy('ym');

        $months = __('inventory::board.months_short');
        $balance = $before;
        $out = [];

        for ($i = 0, $m = $from->copy(); $i < 12; $i++, $m->addMonth()) {
            $row = $rows->get($m->format('Y-m'));
            $balance = bcadd($balance, bcsub((string) ($row->d ?? '0'), (string) ($row->c ?? '0'), 4), 4);
            $days = $m->isSameMonth(Carbon::today()) ? Carbon::today()->day : $m->daysInMonth;
            $outflow = (string) ($row->c ?? '0');
            $out[] = [
                'month' => $months[$m->month] ?? '',
                'value' => $balance,
                'dio' => bccomp($outflow, '0', 4) > 0 ? (int) bcdiv(bcmul($balance, (string) $days, 4), $outflow, 0) : null,
            ];
        }

        return $out;
    }

    /**
     * ⭐ গুদাম ধরে মজুদ — প্রতিটা গুদামের মাল কেনা দামে, আর পথে থাকা বদলি আলাদা ভাগে।
     *
     * @return list<array{name: string, value: string}>
     */
    public function byWarehouse(): array
    {
        $ids = $this->snapshot()->keyBy('id');
        $out = [];

        foreach ($this->warehouses() as $w) {
            $v = $this->warehouseSnapshot((int) $w->id)->reduce(function (string $c, Product $p) use ($ids): string {
                $base = $ids[(int) $p->id] ?? null;

                return $base === null ? $c : bcadd($c, self::valueOf($base, (string) $p->getAttribute('on_hand_qty')), 4);
            }, '0');
            $out[] = ['name' => (string) $w->drillLabel(), 'value' => $v];
        }

        $moving = '0';

        foreach (StockTransfer::query()->onTheWay()->with('lines')->get() as $t) {
            foreach ($t->lines as $line) {
                $p = $ids[(int) $line->product_id] ?? null;
                $moving = bcadd($moving, $p instanceof Product ? self::valueOf($p, (string) $line->qty) : '0', 4);
            }
        }

        if (bccomp($moving, '0', 4) > 0) {
            $out[] = ['name' => __('inventory::board.w.moving'), 'value' => $moving];
        }

        return $out;
    }

    /**
     * ⭐ ব্র্যান্ড ধরে মজুদ (ট্রিম্যাপ) — বড় ছয়টা ব্র্যান্ড আর বাকি "অন্যান্য"; ঘরের মাপ সারি-ভাগ পদ্ধতিতে (slice-and-dice), শতাংশে।
     *
     * @return list<array{name: string, value: string, x: float, y: float, w: float, h: float}>
     */
    public function byBrand(): array
    {
        $snap = $this->snapshot();
        $snap->loadMissing('brandRow');
        $groups = $snap->groupBy(fn (Product $p) => (int) ($p->brand_id ?? 0))
            ->map(fn (Collection $ps) => ['name' => (string) ($ps->first()->brandRow?->drillLabel() ?? __('inventory::board.w.no_brand')),
                'value' => $ps->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0')])
            ->filter(fn (array $g) => bccomp($g['value'], '0', 4) > 0)
            ->sort(fn (array $a, array $b) => bccomp($b['value'], $a['value'], 4))->values();

        $top = $groups->take(6)->all();
        $rest = $groups->slice(6)->reduce(fn (string $c, array $g) => bcadd($c, $g['value'], 4), '0');

        if (bccomp($rest, '0', 4) > 0) {
            $top[] = ['name' => __('inventory::board.w.others'), 'value' => $rest];
        }

        $total = array_reduce($top, fn (string $c, array $g) => bcadd($c, $g['value'], 4), '0');

        if (bccomp($total, '0', 4) <= 0) {
            return [];
        }

        // ⓘ সারি-ভাগ: প্রথমটা বাঁয়ে পুরো উচ্চতায়, বাকিগুলো ডানে — পালা করে উল্লম্ব/আনুভূমিক
        $out = [];
        $box = ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0];
        $left = (float) $total;
        $vertical = true;

        foreach ($top as $i => $g) {
            $share = $left > 0 ? (float) $g['value'] / $left : 1.0;
            $last = $i === count($top) - 1;

            if ($vertical) {
                $w = $last ? $box['w'] : $box['w'] * $share;
                $out[] = $g + ['x' => $box['x'], 'y' => $box['y'], 'w' => $w, 'h' => $box['h']];
                $box['x'] += $w;
                $box['w'] -= $w;
            } else {
                $h = $last ? $box['h'] : $box['h'] * $share;
                $out[] = $g + ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $h];
                $box['y'] += $h;
                $box['h'] -= $h;
            }

            $left -= (float) $g['value'];
            $vertical = ! $vertical;
        }

        return $out;
    }

    /**
     * ⓘ প্রতিটা পণ্যের দিনের বেরোনোর ওঠানামা (আদর্শ বিচ্যুতি) — গত 90 দিন, যেদিন বেরোয়নি সেদিন 0 ধরে।
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function dailySpread(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $from = Carbon::today()->subDays(90);
        $byDay = StockMovement::query()
            ->whereIn('product_id', $ids)->where('floor_change', '<', 0)
            ->where('trx_date', '>=', $from->toDateString())
            ->tap(fn ($q) => $this->narrow($q))
            ->groupBy('product_id', 'trx_date')
            ->selectRaw('product_id, trx_date, SUM(-floor_change) as qty')
            ->get()->groupBy('product_id');

        $out = [];

        foreach ($byDay as $id => $days) {
            $values = $days->map(fn ($d) => (float) $d->qty)->all();
            $values = array_pad($values, 90, 0.0);
            $mean = array_sum($values) / 90;
            $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / 90;
            $out[(int) $id] = number_format(sqrt($var), 4, '.', '');
        }

        return $out;
    }

    /**
     * ⓘ পণ্যটা সবচেয়ে বেশি কোন গুদামে আছে — অচল মজুদের তালিকায় "কোথায়"।
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function biggestWarehouse(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = Warehouse::query()->get()->mapWithKeys(fn (Warehouse $w) => [(int) $w->id => (string) $w->drillLabel()]);

        return StockMovement::query()->whereIn('product_id', $ids)
            ->tap(fn ($q) => $this->narrow($q))
            ->groupBy('product_id', 'warehouse_id')
            ->selectRaw('product_id, warehouse_id, SUM(floor_change + unplaced_change) as qty')
            ->get()->groupBy('product_id')
            ->map(fn ($rows) => $names[(int) $rows->sortByDesc(fn ($r) => (float) $r->qty)->first()->warehouse_id] ?? '')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
    }

    /** ⓘ পরিমাণ — দশমিক কেবল দরকার হলে (12, 2.5) */
    private static function qty(string $q): string
    {
        $r = bcadd($q, '0', 1);

        return str_ends_with($r, '.0') ? substr($r, 0, -2) : $r;
    }

    /** ⓘ দিনে বিক্রি = গত 30 দিনের বেরোনো ÷ 30; কত দিন চলবে = বিক্রিযোগ্য ÷ দিনে বিক্রি। বিক্রি না থাকলে null। */
    public static function daysOfCover(Product $p): ?string
    {
        $daily = bcdiv((string) $p->getAttribute('out_30'), '30', 4);

        if (bccomp($daily, '0', 4) <= 0) {
            return null;
        }

        $have = (string) $p->getAttribute('available_qty');

        return bccomp($have, '0', 4) <= 0 ? '0' : bcdiv($have, $daily, 1);
    }

    /** মালের টাকা কেনা দামে — চালু স্তরের গড় খরচ; খরচ দেখার অনুমতি না থাকলে বা স্তর না থাকলে কেনা দাম */
    public static function valueOf(Product $p, string $qty): string
    {
        $cost = $p->getAttribute('avg_cost');
        $cost = $cost === null ? (string) ($p->purchase_price ?? '0') : (string) $cost;

        return bcmul($qty, $cost, 4);
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳8.50 কো", লাখের ওপরে "৳4.6 লা", নিচে হাজারে "৳38k" — ইংরেজি অঙ্ক */
    public static function short(string $amount): string
    {
        // ⛔ এই বোর্ডের প্রতিটা টাকা কেনা দামে — খরচের চাবি ছাড়া অঙ্কের জায়গায় "আছে, কিন্তু আপনার জন্য নয়" চিহ্ন ([[FieldSecurity::mask()]])
        if (! \App\Core\Security\FieldSecurity::visible(StockMovement::class, 'unit_cost')) {
            return \App\Core\Security\FieldSecurity::mask();
        }

        $neg = bccomp($amount, '0', 4) < 0;
        $a = ltrim($amount, '-');
        $sign = $neg ? '−' : '';

        if (bccomp($a, '10000000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('inventory::board.crore');
        }

        if (bccomp($a, '100000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '100000', 1).' '.__('inventory::board.lakh');
        }

        if (bccomp($a, '1000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '1000', 0).'k';
        }

        return $sign.'৳'.bcadd($a, '0', 0);
    }

    /**
     * মাল থাকা লট যাদের মেয়াদ এই কদিনের মধ্যে — দিন, পরিমাণ, টাকা, গুদাম।
     *
     * @return list<array{batch: string, product: string, warehouse: string, days: int, qty: string, value: string}>
     */
    public function expiringLots(int $days): array
    {
        $today = Carbon::today();
        $snap = $this->snapshot()->keyBy('id');

        /*
         * ⓘ লটের নিজের গুদাম নেই — লটের মাল গুদামে বসে চলাচলের সারি দিয়ে। তাই চলাচল থেকে লট আর গুদাম ধরে যোগ: একই লট দুই গুদামে
         *   থাকলে দুই সারি, প্রতিটা নিজের গুদামের নামে; বাছা গুদাম আর শাখার দেয়াল চলাচলের ওপরেই খাটে।
         */
        $rows = StockMovement::query()
            ->tap(fn ($q) => $this->narrow($q))
            ->whereIn('batch_id', Batch::query()->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays($days)->toDateString()])->select('id'))
            ->groupBy('batch_id', 'warehouse_id')
            ->selectRaw('batch_id, warehouse_id, SUM(floor_change) as qty')
            ->havingRaw('SUM(floor_change) > 0')
            ->get()->toBase();
        $batches = Batch::query()->with('product')->whereIn('id', $rows->pluck('batch_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', $rows->pluck('warehouse_id')->unique()->all() ?: [0])->get()->keyBy('id');

        return $rows
            ->filter(fn ($r) => $batches->has((int) $r->batch_id))
            ->map(function ($r) use ($today, $snap, $batches, $warehouses): array {
                $b = $batches[(int) $r->batch_id];
                $qty = (string) $r->qty;
                $p = $snap[(int) $b->product_id] ?? $b->product;

                return [
                    'batch' => (string) $b->batch_no,
                    'product' => (string) ($b->product?->name() ?? ''),
                    'warehouse' => (string) ($warehouses[(int) $r->warehouse_id] ?? null)?->drillLabel(),
                    'days' => (int) $today->diffInDays(Carbon::parse($b->expiry_date)),
                    'qty' => $qty,
                    'value' => $p instanceof Product ? self::valueOf($p, $qty) : '0',
                ];
            })
            ->sortBy('days')->values()->all();
    }

    /**
     * মাল আছে, শেষ বেরোনো এই কদিনের বেশি আগে (বা কখনো বেরোয়নি)।
     *
     * @return array{count: int, value: string, rows: Collection<int, Product>}
     */
    public function notMoved(int $days): array
    {
        $edge = Carbon::today()->subDays($days)->toDateString();
        $rows = $this->snapshot()->filter(fn (Product $p) => bccomp((string) $p->getAttribute('on_hand_qty'), '0', 4) > 0
            && ($p->getAttribute('last_out') === null || (string) $p->getAttribute('last_out') < $edge));

        return [
            'count' => $rows->count(),
            'value' => $rows->reduce(fn (string $c, Product $p) => bcadd($c, self::valueOf($p, (string) $p->getAttribute('on_hand_qty')), 4), '0'),
            'rows' => $rows->values(),
        ];
    }

    /**
     * প্রতিটা গুদামের শেষ গণনায় যে লাইনগুলো মেলেনি — সবচেয়ে বড় ঘাটতির গুদাম, টাকা, দিন, অনুমোদন।
     *
     * @return array{lines: int, warehouse: string, value: string, date: ?string, approved: bool}
     */
    public function lastCountGap(): array
    {
        $latest = StockCount::query()->with(['lines', 'warehouse'])
            ->when($this->picked()['warehouse'] !== null, fn ($q) => $q->where('warehouse_id', $this->picked()['warehouse']))
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->orderByDesc('count_date')->orderByDesc('id')->get()
            ->unique('warehouse_id');

        $lines = 0;
        $worst = null;

        foreach ($latest as $count) {
            $gaps = $count->variances();
            $lines += $gaps->count();
            $value = $gaps->reduce(fn (string $c, $l) => bcadd($c, bcmul((string) $l->difference, (string) ($l->unit_cost ?? '0'), 4), 4), '0');

            if ($gaps->isNotEmpty() && ($worst === null || bccomp(ltrim($value, '-'), ltrim($worst['value'], '-'), 4) > 0)) {
                $worst = ['warehouse' => (string) ($count->warehouse?->drillLabel() ?? ''), 'value' => $value,
                    'date' => Carbon::parse($count->count_date)->format('j M'), 'approved' => $count->isApproved()];
            }
        }

        return ['lines' => $lines] + ($worst ?? ['warehouse' => '', 'value' => '0', 'date' => $latest->first() ? Carbon::parse($latest->first()->count_date)->format('j M') : null, 'approved' => true]);
    }

    /**
     * ⓘ মজুদের নির্ভুলতা (IRA) — প্রতিটা গুদামের শেষ গণনায় যত লাইন গোনা হয়েছে, তার কত শতাংশ খাতার সাথে হুবহু মিলেছে।
     */
    public function recordAccuracy(): ?string
    {
        $latest = StockCount::query()->with('lines')->where('status', '<>', DocumentStatus::CANCELLED)
            ->when($this->picked()['warehouse'] !== null, fn ($q) => $q->where('warehouse_id', $this->picked()['warehouse']))
            ->orderByDesc('count_date')->orderByDesc('id')->get()->unique('warehouse_id');

        $total = $latest->sum(fn (StockCount $c) => $c->lines->count());

        if ($total === 0) {
            return null;
        }

        $gaps = $latest->sum(fn (StockCount $c) => $c->variances()->count());

        return bcdiv(bcmul((string) ($total - $gaps), '100', 4), (string) $total, 1);
    }

    /**
     * রওনা হয়ে এই কদিনের বেশি পথে থাকা বদলি — সংখ্যা, সবচেয়ে পুরনোর দিন, টাকা, পথগুলো।
     *
     * @return array{count: int, days: int, value: string, routes: string}
     */
    public function stuckTransfers(int $days): array
    {
        $edge = Carbon::now()->subDays($days);
        $snap = $this->snapshot()->keyBy('id');
        $w = $this->picked()['warehouse'];
        $stuck = StockTransfer::query()->onTheWay()->with(['lines', 'fromWarehouse', 'toWarehouse'])
            ->when($w !== null, fn ($q) => $q->where(fn ($x) => $x->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w)))
            ->whereNotNull('dispatched_at')->where('dispatched_at', '<=', $edge)->get();

        $value = '0';

        foreach ($stuck as $t) {
            foreach ($t->lines as $line) {
                $p = $snap[(int) $line->product_id] ?? null;
                $value = bcadd($value, $p instanceof Product ? self::valueOf($p, (string) $line->qty) : '0', 4);
            }
        }

        return [
            'count' => $stuck->count(),
            'days' => (int) ($stuck->min('dispatched_at') ? Carbon::parse($stuck->min('dispatched_at'))->diffInDays(Carbon::now()) : 0),
            'value' => $value,
            'routes' => $stuck->take(2)->map(fn (StockTransfer $t) => ($t->fromWarehouse?->drillLabel() ?? '').' → '.($t->toWarehouse?->drillLabel() ?? ''))->implode(', '),
        ];
    }

    /**
     * এ মাসে আর গত মাসে মজুদ-সমন্বয়ে (নষ্ট, মেয়াদ, ভাঙা) কমা মালের টাকা — কেনা দামে।
     *
     * @return array{0: string, 1: string}
     */
    public function writeOffs(): array
    {
        $reasons = ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->pluck('id');
        $snap = $this->snapshot()->keyBy('id');
        $month = Carbon::today()->startOfMonth();

        $sum = function (Carbon $from, Carbon $to) use ($reasons, $snap): string {
            return StockMovement::query()
                ->whereIn('reason_code_id', $reasons->all())
                ->tap(fn ($q) => $this->narrow($q))
                ->where('floor_change', '<', 0)
                ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
                ->get(['product_id', 'floor_change'])
                ->reduce(function (string $c, StockMovement $m) use ($snap): string {
                    $p = $snap[(int) $m->product_id] ?? null;

                    return bcadd($c, $p instanceof Product ? self::valueOf($p, ltrim((string) $m->floor_change, '-')) : '0', 4);
                }, '0');
        };

        return [
            $sum($month, Carbon::today()),
            $sum($month->copy()->subMonth(), $month->copy()->subMonth()->endOfMonth()),
        ];
    }

    /**
     * ⭐ মজুদের বয়স · গুদাম ধরে — আজকের মাল কবে ঢুকেছিল, FIFO ধরে: যা আছে তা সবচেয়ে নতুন ঢোকাগুলো থেকে (আন্তর্জাতিক প্রচলিত অনুমান)।
     * ⓘ ভাগ টাকায় (কেনা দামে): 0–30, 31–60, 61–90, 90+ দিন; শতাংশ পূর্ণসংখ্যায়, যোগফল 100।
     *
     * @return list<array{name: string, parts: array{0: int, 1: int, 2: int, 3: int}}>
     */
    public function ageing(): array
    {
        $today = Carbon::today();
        $base = $this->snapshot()->keyBy('id');
        $out = [];

        foreach ($this->warehouses() as $w) {
            $have = $this->warehouseSnapshot((int) $w->id)
                ->filter(fn (Product $p) => $base->has((int) $p->id) && bccomp((string) $p->getAttribute('on_hand_qty'), '0', 4) > 0)
                ->mapWithKeys(fn (Product $p) => [(int) $p->id => (string) $p->getAttribute('on_hand_qty')]);

            if ($have->isEmpty()) {
                continue;
            }

            $ins = StockMovement::query()->where('warehouse_id', $w->id)->whereIn('product_id', $have->keys()->all())
                // ⓘ কেবল ঢোকা সারি — একই দিনের বেরোনোর সাথে নিট নয় (নিট করলে সেদিনের নতুন মাল কম দেখাত, পুরনো মাল বেশি)
                ->whereRaw('floor_change + unplaced_change > 0')
                ->groupBy('product_id', 'trx_date')
                ->selectRaw('product_id, trx_date, SUM(floor_change + unplaced_change) as qty')
                ->orderByDesc('trx_date')->get()->groupBy('product_id');

            $buckets = ['0', '0', '0', '0'];

            foreach ($have as $id => $left) {
                $p = $base[$id];

                foreach ($ins->get($id, collect()) as $in) {
                    if (bccomp($left, '0', 4) <= 0) {
                        break;
                    }

                    $take = bccomp((string) $in->qty, $left, 4) < 0 ? (string) $in->qty : $left;
                    $age = (int) Carbon::parse($in->trx_date)->diffInDays($today);
                    $k = $age <= 30 ? 0 : ($age <= 60 ? 1 : ($age <= 90 ? 2 : 3));
                    $buckets[$k] = bcadd($buckets[$k], self::valueOf($p, $take), 4);
                    $left = bcsub($left, $take, 4);
                }

                // ⓘ ঢোকার খাতা নেই এমন মাল (প্রারম্ভিক জের) — সবচেয়ে পুরনো ঘরে
                if (bccomp($left, '0', 4) > 0) {
                    $buckets[3] = bcadd($buckets[3], self::valueOf($p, $left), 4);
                }
            }

            $out[] = ['name' => (string) $w->drillLabel(), 'parts' => self::percents($buckets)];
        }

        return $out;
    }

    /**
     * ⭐ সরবরাহকারী — সময়মতো পুরো মাল (OTIF), লিড টাইম আর কেনার টাকা · গত 90 দিন ([[StockSupply::suppliers()]])।
     *
     * @return list<array{name: string, otif: ?int, lead: ?int, value: string}>
     */
    public function suppliers(): array
    {
        return $this->supply->suppliers(Carbon::today()->subDays(89)->toDateString(), Carbon::today()->toDateString());
    }

    /**
     * ⭐ ঢোকা ও বেরোনো · 30 দিন — দিনে কত টাকার মাল ঢুকল আর বেরোল (কেনা দামে)।
     * ⓘ "সব গুদাম" দেখলে গুদাম-বদলি বাদ — ভেতরের নড়াচড়া, ঢোকা-বেরোনো নয়; এক গুদাম বাছলে বদলিও গোনে।
     * ⓘ কোন বারে একদিনও মাল ঢোকেনি (বাকি দিনে ঢুকেছে) — নমুনার "শুক্রবার মাল ঢোকে না"।
     *
     * @return array{days: list<array{date: string, in: string, out: string}>, in: string, out: string, quiet: ?int}
     */
    public function dailyFlow(): array
    {
        $from = Carbon::today()->subDays(29);
        $snap = $this->snapshot()->keyBy('id');
        $rows = StockMovement::query()
            ->where('trx_date', '>=', $from->toDateString())
            ->tap(fn ($q) => $this->narrow($q))
            ->when($this->picked()['warehouse'] === null, fn ($q) => $q->where(fn ($x) => $x->whereNull('source_type')->orWhere('source_type', '<>', StockTransfer::STOCK_SOURCE)))
            ->groupBy('product_id', 'trx_date')
            ->selectRaw('product_id, trx_date, SUM(CASE WHEN floor_change + unplaced_change > 0 THEN floor_change + unplaced_change ELSE 0 END) as qin, SUM(CASE WHEN floor_change + unplaced_change < 0 THEN -(floor_change + unplaced_change) ELSE 0 END) as qout')
            ->get();

        $days = [];

        for ($d = $from->copy(); $d->lte(Carbon::today()); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'in' => '0', 'out' => '0'];
        }

        foreach ($rows as $r) {
            $p = $snap[(int) $r->product_id] ?? null;
            $key = substr((string) $r->trx_date, 0, 10);

            if (! $p instanceof Product || ! isset($days[$key])) {
                continue;
            }

            $days[$key]['in'] = bcadd($days[$key]['in'], self::valueOf($p, (string) $r->qin), 4);
            $days[$key]['out'] = bcadd($days[$key]['out'], self::valueOf($p, (string) $r->qout), 4);
        }

        $byWeekday = [];

        foreach ($days as $d) {
            $wd = Carbon::parse($d['date'])->dayOfWeek;
            $byWeekday[$wd] = ($byWeekday[$wd] ?? false) || bccomp($d['in'], '0', 4) > 0;
        }

        $quiet = array_keys(array_filter($byWeekday, fn (bool $any) => ! $any));
        $sum = fn (string $k) => array_reduce($days, fn (string $c, array $d) => bcadd($c, $d[$k], 4), '0');

        return [
            'days' => array_values($days),
            'in' => $sum('in'),
            'out' => $sum('out'),
            'quiet' => count($quiet) === 1 && in_array(true, $byWeekday, true) ? $quiet[0] : null,
        ];
    }

    /**
     * ⭐ গুদামের জায়গা কতটা ভরা — আজকের মাল (মূল এককে) ÷ গুদামের ধারণক্ষমতা ([[Warehouse]]-এর capacity_qty)।
     * ⓘ ধারণক্ষমতা বসানো না থাকলে শতাংশ নয় — null, আর ঘরে "বসানো নেই"; আন্দাজ নয়। 85%-এর ওপরে মাল রাখা ও খোঁজা কঠিন (প্রচলিত সীমা)।
     *
     * @return list<array{name: string, pct: ?int, edit: string}>
     */
    public function space(): array
    {
        $out = [];

        foreach ($this->warehouses() as $w) {
            $held = $this->warehouseSnapshot((int) $w->id)->reduce(fn (string $c, Product $p) => bccomp((string) $p->getAttribute('on_hand_qty'), '0', 4) > 0 ? bcadd($c, (string) $p->getAttribute('on_hand_qty'), 4) : $c, '0');
            $cap = $w->capacity_qty === null ? null : (string) $w->capacity_qty;

            $out[] = [
                'name' => (string) $w->drillLabel(),
                'pct' => $cap === null || bccomp($cap, '0', 4) <= 0 ? null : (int) round((float) bcdiv(bcmul($held, '100', 4), $cap, 4)),
                'edit' => self::link('inventory.warehouse.edit', [$w]),
            ];
        }

        return $out;
    }

    /**
     * ⭐ মাল ঢোকার ধাপ · তিন-দিক মিলান — গত 30 দিনের ক্রয় আদেশ → মাল এসেছে → মান যাচাই পাস → বিল মিলেছে ([[StockSupply::receivingFunnel()]]),
     * আর এখন বিল না আসা চালান ([[StockSupply::unbilledReceipts()]])।
     *
     * @return array{steps: array{orders: int, received: int, passed: int, matched: int}, unbilled: array{receipts: int, value: string, supplier: string}}
     */
    public function receiving(): array
    {
        return [
            'steps' => $this->supply->receivingFunnel(Carbon::today()->subDays(29)->toDateString(), Carbon::today()->toDateString()),
            'unbilled' => $this->supply->unbilledReceipts(),
        ];
    }

    /**
     * ⭐ গুদাম-বদলি · পথে — রওনা হয়েছে, পৌঁছায়নি; পুরনোটা আগে। দিন 2-এর বেশি হলে লাল (সমস্যা কেন্দ্রের একই সীমা)।
     *
     * @return list<array{from: string, to: string, days: int, qty: string, value: string}>
     */
    public function inTransit(int $limit = 3): array
    {
        $snap = $this->snapshot()->keyBy('id');
        $w = $this->picked()['warehouse'];

        return StockTransfer::query()->onTheWay()->with(['lines', 'fromWarehouse', 'toWarehouse'])
            ->when($w !== null, fn ($q) => $q->where(fn ($x) => $x->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w)))
            ->orderBy('dispatched_at')->limit($limit)->get()
            ->map(function (StockTransfer $t) use ($snap): array {
                $qty = '0';
                $value = '0';

                foreach ($t->lines as $line) {
                    $qty = bcadd($qty, (string) $line->qty, 4);
                    $p = $snap[(int) $line->product_id] ?? null;
                    $value = bcadd($value, $p instanceof Product ? self::valueOf($p, (string) $line->qty) : '0', 4);
                }

                return [
                    'from' => (string) ($t->fromWarehouse?->drillLabel() ?? ''),
                    'to' => (string) ($t->toWarehouse?->drillLabel() ?? ''),
                    'days' => $t->dispatched_at === null ? 0 : (int) Carbon::parse($t->dispatched_at)->startOfDay()->diffInDays(Carbon::today()),
                    'qty' => $qty,
                    'value' => $value,
                ];
            })->values()->all();
    }

    /**
     * ⭐ নষ্ট, ফেরত ও আটকে রাখা মাল · এ মাস — কেনা দামে।
     * ⓘ মেয়াদোত্তীর্ণ = মজুদ-সমন্বয়ে বাদ দেওয়া লট, যার মেয়াদ বাদ দেওয়ার দিনে বা আগে শেষ; বাকি সমন্বয়ে কমা = ভাঙা/নষ্ট ([[writeOffs()]]-এর একই কারণগুলো)।
     * ⓘ গ্রাহক ফেরত আর সরবরাহকারীকে ফেরত — বিক্রয়/ক্রয়ের ফেরত-চলাচল (উৎস-নাম চুক্তি থেকে); মান যাচাইয়ে আটকে = আজ আটকে রাখা মাল।
     *
     * @return array{broken: string, expired: string, returned_in: ?string, held: string, returned_out: ?string, last_month: string, reason: string}
     */
    public function damage(): array
    {
        $snap = $this->snapshot()->keyBy('id');
        $month = Carbon::today()->startOfMonth()->toDateString();
        $today = Carbon::today()->toDateString();
        $worth = fn ($m, string $qty): string => ($p = $snap[(int) $m->product_id] ?? null) instanceof Product ? self::valueOf($p, $qty) : '0';

        $reasons = ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->get()->keyBy('id');
        $written = StockMovement::query()->with('batch')->whereIn('reason_code_id', $reasons->keys()->all() ?: [0])
            ->tap(fn ($q) => $this->narrow($q))
            ->where('floor_change', '<', 0)->whereBetween('trx_date', [$month, $today])->get();

        $broken = '0';
        $expired = '0';
        $byReason = [];

        foreach ($written as $m) {
            $v = $worth($m, ltrim((string) $m->floor_change, '-'));
            $lapsed = $m->batch?->expiry_date !== null && substr((string) $m->batch->expiry_date, 0, 10) <= substr((string) $m->trx_date, 0, 10);

            if ($lapsed) {
                $expired = bcadd($expired, $v, 4);
            } else {
                $broken = bcadd($broken, $v, 4);
            }

            $byReason[(int) $m->reason_code_id] = bcadd($byReason[(int) $m->reason_code_id] ?? '0', $v, 4);
        }

        uasort($byReason, fn (string $a, string $b) => bccomp($b, $a, 4));
        $top = $byReason === [] ? null : $reasons->get(array_key_first($byReason));

        $moved = function (?string $source, int $sign) use ($month, $today, $worth): ?string {
            if ($source === null) {
                return null;
            }

            return StockMovement::query()->where('source_type', $source)
                ->tap(fn ($q) => $this->narrow($q))
                ->whereBetween('trx_date', [$month, $today])
                ->get(['product_id', 'floor_change', 'unplaced_change'])
                ->reduce(function (string $c, StockMovement $m) use ($sign, $worth): string {
                    $q = bcmul(bcadd((string) $m->floor_change, (string) $m->unplaced_change, 4), (string) $sign, 4);

                    return bccomp($q, '0', 4) > 0 ? bcadd($c, $worth($m, $q), 4) : $c;
                }, '0');
        };

        $held = StockMovement::query()->tap(fn ($q) => $this->narrow($q))
            ->groupBy('product_id')->havingRaw('SUM(hold_change) > 0')
            ->selectRaw('product_id, SUM(hold_change) as qty')->get()
            ->reduce(fn (string $c, $r) => bcadd($c, $worth($r, (string) $r->qty), 4), '0');

        return [
            'broken' => $broken,
            'expired' => $expired,
            'returned_in' => $moved($this->demand->returnSource(), 1),
            'held' => $held,
            'returned_out' => $moved($this->supply->returnSource(), -1),
            'last_month' => $this->writeOffs()[1],
            'reason' => $top === null ? '' : (string) $top->drillLabel(),
        ];
    }

    /**
     * ⓘ টাকার ভাগগুলো পূর্ণসংখ্যা শতাংশে, যোগফল ঠিক 100 (বড় ভগ্নাংশ-বাকি আগে) — সব শূন্য হলে সব শূন্য।
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    private static function percents(array $values): array
    {
        $total = array_reduce($values, fn (string $c, string $v) => bcadd($c, $v, 4), '0');

        if (bccomp($total, '0', 4) <= 0) {
            return array_fill(0, count($values), 0);
        }

        $raw = array_map(fn (string $v) => (float) $v * 100 / (float) $total, $values);
        $floor = array_map(fn (float $r) => (int) floor($r), $raw);
        $order = array_keys($raw);
        usort($order, fn (int $a, int $b) => ($raw[$b] - $floor[$b]) <=> ($raw[$a] - $floor[$a]));

        for ($i = 0, $left = 100 - array_sum($floor); $left > 0; $i++, $left--) {
            $floor[$order[$i % count($order)]]++;
        }

        return $floor;
    }

    /** ⓘ লট আর চলাচলের প্রশ্নে ছাঁকনি — বাছা গুদাম, আর ব্র্যান্ড/শ্রেণি/ABC বাছা থাকলে কেবল সেই পণ্যগুলো */
    private function narrow($query): void
    {
        $p = $this->picked();

        if ($p['warehouse'] !== null) {
            $query->where('warehouse_id', $p['warehouse']);
        }

        if ($p['brand'] !== null || $p['category'] !== null || $p['abc'] !== null) {
            $query->whereIn('product_id', $this->snapshot()->pluck('id')->all() ?: [0]);
        }
    }

    /** @param  array<string, mixed>  $params */
    private static function link(string $route, array $params = []): string
    {
        return Route::has($route) ? route($route, $params) : '#';
    }
}
