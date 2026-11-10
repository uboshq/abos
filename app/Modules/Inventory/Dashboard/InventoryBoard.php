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

    /** @return Collection<int, Product> */
    public function snapshot(): Collection
    {
        return $this->snap ??= $this->facts->snapshot();
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
                'value' => $unbilled['receipts'] === 0 ? $none : self::short($unbilled['value']).($unbilled['supplier'] !== '' ? ' · '.$unbilled['supplier'] : ''),
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
        $costShown = $snap->first()?->getAttributes() !== null && array_key_exists('avg_cost', $snap->first()->getAttributes());

        $today = Carbon::today();
        $fill = $this->demand->fillRate($today->copy()->subDays(30)->toDateString(), $today->toDateString());
        $ira = $this->recordAccuracy();
        // ⓘ GMROI = গত 365 দিনের গ্রস লাভ ÷ আজকের মজুদ (কেনা দামে) — গড় মজুদের সবচেয়ে কাছের সহজ মাপ
        $gp = $this->demand->grossProfit($today->copy()->subDays(365)->toDateString(), $today->toDateString());
        $gmroi = bccomp($cost, '0', 4) > 0 ? bcdiv($gp, $cost, 2) : null;

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

        return Batch::query()
            ->with(['product', 'warehouse'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays($days)->toDateString()])
            ->withSum('movements as qty_sum', 'floor_change')
            ->get()
            ->filter(fn (Batch $b) => bccomp((string) ($b->getAttribute('qty_sum') ?? '0'), '0', 4) > 0)
            ->map(function (Batch $b) use ($today, $snap): array {
                $qty = (string) $b->getAttribute('qty_sum');
                $p = $snap[(int) $b->product_id] ?? $b->product;

                return [
                    'batch' => (string) $b->batch_no,
                    'product' => (string) ($b->product?->name() ?? ''),
                    'warehouse' => (string) ($b->warehouse?->name() ?? ''),
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
                $worst = ['warehouse' => (string) ($count->warehouse?->name() ?? ''), 'value' => $value,
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
        $stuck = StockTransfer::query()->onTheWay()->with(['lines', 'fromWarehouse', 'toWarehouse'])
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
            'routes' => $stuck->take(2)->map(fn (StockTransfer $t) => ($t->fromWarehouse?->name() ?? '').' → '.($t->toWarehouse?->name() ?? ''))->implode(', '),
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

    /** @param  array<string, mixed>  $params */
    private static function link(string $route, array $params = []): string
    {
        return Route::has($route) ? route($route, $params) : '#';
    }
}
