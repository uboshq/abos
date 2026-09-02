<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * মজুদের সংখ্যাগুলোর সংজ্ঞা — একটাই জায়গা।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * [[SalesMetrics]]-এর গল্পটাই এখানেও: "আজকের বিক্রয়" চার জায়গায় গোনা
 * হত আর একবার দুইটা আলাদা উত্তর দিয়েছিল। মজুদে ঠিক সেই ঝুঁকিটা তৈরি
 * হচ্ছিল — [[InventoryWidgets]]-এ "ফুরিয়ে আসছে"-র হিসাবটা লেখা ছিল, আর
 * ড্যাশবোর্ড লিখতে গিয়ে দ্বিতীয়বার লিখতে যাচ্ছিলাম।
 *
 * দুইটা লেখা মানে একদিন দুইটা উত্তর, আর তখন কেউ বলতে পারত না কোনটা
 * ঠিক। তাই হিসাবটা এখানে এলো, আর widget এখান থেকেই নেয়।
 *
 * ── কেন `Metric` নয় ─────────────────────────────────────────────────
 * [[Metric]] ডকুমেন্টের **অবস্থা** ছাড়া তৈরিই হয় না (`statuses === []`
 * হলে ব্যতিক্রম) — আর সেটা ঠিক, কারণ টাকার প্রতিটা সংখ্যার পেছনে
 * প্রশ্নটা থাকে "খসড়া গোনা হয়েছে কি না"।
 *
 * মজুদের নড়াচড়ার কোনো অবস্থা নেই: [[StockService]] দিয়ে একটা সারি বসা
 * মানেই মালটা নড়েছে। জোর করে একটা অবস্থা বসালে সেটা মিথ্যা হত।
 */
final class StockFacts
{
    /**
     * চারটা অবস্থা — তাকে, অর্ডারে ধরা, আটকানো, আর বিক্রয়যোগ্য।
     *
     * ── কেন চারটাই একসাথে ───────────────────────────────────────────
     * "গুদামে কত মাল" প্রশ্নটার একটা উত্তর নেই। তাকে ১০০ থাকতে পারে
     * অথচ বেচার মতো ৭৫ — বাকিটা কারও অর্ডারে ধরা বা কোনো কারণে
     * আটকানো। একটা সংখ্যা দেখালে বিক্রয়কর্মী ১০০ বেচার প্রতিশ্রুতি
     * দিতেন, আর ভুলটা ধরা পড়ত মাল দিতে গিয়ে।
     *
     * @return array{floor: string, reserved: string, hold: string, available: string}
     */
    public function states(?int $warehouseId = null): array
    {
        $row = StockMovement::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('COALESCE(SUM(floor_change), 0) as floor')
            ->selectRaw('COALESCE(SUM(reserved_change), 0) as reserved')
            ->selectRaw('COALESCE(SUM(hold_change), 0) as hold')
            ->first();

        $floor = (string) ($row->floor ?? '0');
        $reserved = (string) ($row->reserved ?? '0');
        $hold = (string) ($row->hold ?? '0');

        return [
            'floor' => $floor,
            'reserved' => $reserved,
            'hold' => $hold,
            /*
             * বিক্রয়যোগ্য = তাকে − অর্ডারে ধরা − আটকানো।
             *
             * ঠিক এই হিসাবটাই স্টক পর্দার "Available" কলামে দেখায়, তাই
             * ড্যাশবোর্ড থেকে ক্লিক করে নামলে সংখ্যাটা মেলে। দুই
             * জায়গায় দুই হিসাব থাকলে মিলত না, আর মানুষ কোনটা বিশ্বাস
             * করবেন বুঝতেন না।
             */
            'available' => bcsub(bcsub($floor, $reserved, 4), $hold, 4),
        ];
    }

    /**
     * পুনঃক্রয় সীমার নিচে কতগুলো পণ্য।
     *
     * সীমা বসানো হয়নি (০) এমন পণ্য বাদ — নাহলে প্রতিটা নতুন পণ্য
     * "ফুরিয়ে গেছে" হিসেবে গোনা হত, আর সংখ্যাটা এত বড় হত যে কেউ আর
     * তাকাত না।
     */
    public function belowReorder(): int
    {
        return $this->belowReorderQuery()->count();
    }

    /**
     * ফুরিয়ে আসা পণ্যগুলো, সবচেয়ে জরুরিটা আগে।
     *
     * @return Collection<int, Product>
     */
    public function lowStock(int $limit = 8)
    {
        return $this->belowReorderQuery()
            ->with('unit')
            ->select('inv_products.*')
            ->selectRaw($this->availableSql().' as available_qty')
            ->orderByRaw($this->availableSql().' asc')
            ->limit($limit)
            ->get();
    }

    /**
     * একেবারে শূন্য হয়ে যাওয়া পণ্য।
     *
     * "ফুরিয়ে আসছে"-র চেয়ে আলাদা প্রশ্ন: ওটা সতর্কতা, এটা ঘটে যাওয়া
     * ঘটনা — আজ কেউ চাইলে দেওয়া যাবে না।
     */
    public function outOfStock(): int
    {
        return Product::query()
            ->active()
            ->whereRaw($this->availableSql().' <= 0')
            ->count();
    }

    /**
     * মজুদের মূল্য — অবশিষ্ট স্তরগুলোর যোগফল।
     *
     * ── কেন cost layer থেকে, নড়াচড়া থেকে নয় ────────────────────────
     * প্রতিটা স্তরে (`inv_cost_layers`) লেখা আছে কত ঢুকেছিল, কত এখনো
     * বাকি, আর কত দরে। অর্থাৎ **আজকের মজুদ কোন দরে কেনা** সেটা সেখানেই
     * আছে। নড়াচড়া থেকে গুনলে আজকের দর দিয়ে পুরনো মাল মূল্যায়ন করা
     * হত, আর দাম বাড়লে-কমলে সংখ্যাটা লাফাত।
     *
     * ⚠️ **`null` ফেরে যদি দেখার অনুমতি না থাকে।** এটা কোনো কারিগরি
     * ব্যর্থতা নয় — মজুদের মূল্য একটা **খরচের সংখ্যা**, আর [[FieldSecurity]]
     * ঠিক ওটাই `inventory.cost.view`-এর পেছনে রাখে। এই পর্দাটা যদি
     * সংখ্যাটা এমনিই দেখাত, তবে ঘরের পাহারা টপকানোর সবচেয়ে সহজ দরজা
     * হত এটাই: পণ্যের পাতায় ক্রয়মূল্য ঢাকা, অথচ ড্যাশবোর্ডে গোটা
     * গুদামের দাম খোলা।
     */
    public function value(): ?string
    {
        if (! FieldSecurity::visible(StockMovement::class, 'unit_cost')) {
            return null;
        }

        $total = DB::table('inv_cost_layers')
            ->where('company_id', CompanyContext::id())
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as total')
            ->value('total');

        return bcadd((string) $total, '0', 2);
    }

    /** আজ কতগুলো নড়াচড়া লেখা হয়েছে। */
    public function movementsToday(): int
    {
        return StockMovement::query()
            ->whereDate('trx_date', Carbon::today()->toDateString())
            ->count();
    }

    /**
     * শেষ কয়েক মাসের ঢোকা ও বেরোনো।
     *
     * ── কেন ঢোকা-বেরোনো, মজুদের যোগফল নয় ───────────────────────────
     * মজুদের যোগফলের রেখা প্রায় সমান থাকে, তাই চোখে কিছুই বলে না।
     * ঢোকা আর বেরোনো পাশাপাশি রাখলে **ব্যবসাটা দেখা যায়**: কোন মাসে
     * বেশি কিনেছি, কোন মাসে বেশি বেরিয়েছে, আর দুইটার ফারাক বাড়ছে
     * কি না।
     *
     * @return list<array{month: string, in: string, out: string}>
     */
    public function monthlyFlow(int $months = 7): array
    {
        $from = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $rows = StockMovement::query()
            ->where('trx_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(trx_date, '%Y-%m') as ym")
            ->selectRaw('COALESCE(SUM(GREATEST(floor_change, 0)), 0) as moved_in')
            ->selectRaw('COALESCE(SUM(GREATEST(-floor_change, 0)), 0) as moved_out')
            ->groupBy('ym')
            ->pluck('moved_out', 'ym')
            ->all();

        $ins = StockMovement::query()
            ->where('trx_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(trx_date, '%Y-%m') as ym")
            ->selectRaw('COALESCE(SUM(GREATEST(floor_change, 0)), 0) as moved_in')
            ->groupBy('ym')
            ->pluck('moved_in', 'ym')
            ->all();

        $out = [];
        $cursor = $from->copy();

        /*
         * প্রতিটা মাস তালিকায় থাকে, নড়াচড়া না থাকলেও।
         *
         * কেবল যেসব মাসে সারি আছে সেগুলো দেখালে ফাঁকা মাসগুলো চার্ট
         * থেকে **উধাও** হত, আর সাতটা বারের বদলে পাঁচটা দেখে কেউ
         * ভাবতেন ব্যবসা সাত মাস চলেনি।
         */
        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');

            $out[] = [
                'month' => $cursor->translatedFormat('M'),
                'in' => bcadd((string) ($ins[$key] ?? '0'), '0', 2),
                'out' => bcadd((string) ($rows[$key] ?? '0'), '0', 2),
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * সদ্য যা নড়েছে।
     *
     * @return Collection<int, StockMovement>
     */
    public function recentMovements(int $limit = 8)
    {
        return StockMovement::query()
            ->with(['product', 'warehouse'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * কত দিনের জানালায় দেখা হবে — বৈধ পছন্দগুলো।
     *
     * ── কেন হাতে লেখা যেকোনো সংখ্যা নয় ──────────────────────────────
     * মানটা ঠিকানা থেকে আসে। খোলা রাখলে কেউ `?days=99999` দিয়ে পুরো
     * ইতিহাস স্ক্যান করাতে পারতেন, আর পর্দাটা মিনিটের পর মিনিট ঝুলত।
     * তিনটা পছন্দই বাস্তবে লাগে: সপ্তাহ, মাস, ত্রৈমাসিক।
     *
     * @var list<int>
     */
    public const WINDOWS = [7, 30, 90];

    /**
     * ধীরগতির মাল — আছে, নড়েছে, কিন্তু বেরোয়নি।
     *
     * ── কেন "নড়েছে" শর্তটা লাগে ─────────────────────────────────────
     * ওটা না দিলে ধীরগতি আর নিশ্চল এক হয়ে যেত, আর দুইটা সংখ্যা একই
     * পণ্য দুইবার গুনত। দুইটা আলাদা ঘটনা, আর আলাদা কাজ দাবি করে:
     * ধীরগতির মালে **দাম বা প্রচার** লাগে, নিশ্চল মালে **প্রশ্ন** —
     * ওটা কি আদৌ বিক্রির জিনিস, নাকি ভুলে পড়ে আছে।
     */
    public function slowMoving(int $days): int
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'any').' > 0')
            ->whereRaw($this->movementSql($days, 'out').' = 0')
            ->count();
    }

    /** নিশ্চল মাল — আছে, অথচ কেউ ছোঁয়নি। */
    public function nonMoving(int $days): int
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'any').' = 0')
            ->count();
    }

    /**
     * ধীরগতির ও নিশ্চল মালের তালিকা, সবচেয়ে বেশি টাকা আটকে থাকা আগে।
     *
     * ── কেন পরিমাণ নয়, আটকে থাকা টাকা ───────────────────────────────
     * পাঁচশো পিস সস্তা কলম পড়ে থাকার চেয়ে দশটা দামি যন্ত্র পড়ে থাকা
     * অনেক বেশি ক্ষতি। পরিমাণ ধরে সাজালে তালিকার মাথায় সবসময় সস্তা
     * জিনিসগুলোই উঠত, আর যেটা নিয়ে সত্যিই কিছু করার আছে সেটা নিচে
     * পড়ে থাকত।
     *
     * @return Collection<int, Product>
     */
    public function stagnant(int $days, int $limit = 8)
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'out').' = 0')
            ->with('unit')
            ->select('inv_products.*')
            ->selectRaw($this->availableSql().' as available_qty')
            ->selectRaw($this->movementSql($days, 'any').' as touches')
            ->orderByRaw($this->availableSql().' * COALESCE(inv_products.purchase_price, 0) desc')
            ->limit($limit)
            ->get();
    }

    /**
     * মাল আছে এমন সচল পণ্যগুলো।
     *
     * দুইটা সংখ্যারই ভিত্তি একই: **যার কিছুই নেই তাকে "পড়ে আছে" বলা
     * অর্থহীন**। শূন্য মজুদ আলাদা একটা সমস্যা, আর তার নিজের সংখ্যা আছে।
     */
    private function onHandQuery()
    {
        return Product::query()
            ->active()
            ->whereRaw($this->availableSql().' > 0');
    }

    /**
     * এই পণ্যে গত কয়েক দিনে কতগুলো নড়াচড়া।
     *
     * `any` — যেকোনো সারি; `out` — কেবল যেগুলোয় মাল কমেছে।
     */
    private function movementSql(int $days, string $kind): string
    {
        $from = Carbon::today()->subDays($days)->toDateString();
        $extra = $kind === 'out' ? ' and m.floor_change < 0' : '';

        return "(select COUNT(*)
                 from inv_stock_movements m
                 where m.product_id = inv_products.id
                   and m.company_id = inv_products.company_id
                   and m.trx_date >= '{$from}'{$extra})";
    }

    /**
     * ফুরিয়ে আসা পণ্যের কোয়েরি — একটাই সংজ্ঞা, দুই জায়গায় ব্যবহার।
     */
    private function belowReorderQuery()
    {
        return Product::query()
            ->active()
            ->where('reorder_level', '>', 0)
            ->whereRaw($this->availableSql().' <= inv_products.reorder_level');
    }

    /**
     * বিক্রয়যোগ্য পরিমাণের SQL।
     *
     * ⚠️ এটা আর [[StockFacts::states()]]-এর `available` **একই নিয়ম**।
     * একটা বদলালে অন্যটাও বদলাতে হবে, নাহলে ড্যাশবোর্ডের ডোনাট আর
     * "ফুরিয়ে আসছে"-র সংখ্যা দুইটা আলাদা বাস্তবতা দেখাবে।
     */
    private function availableSql(): string
    {
        return '(select COALESCE(SUM(m.floor_change - m.reserved_change - m.hold_change), 0)
                 from inv_stock_movements m
                 where m.product_id = inv_products.id
                   and m.company_id = inv_products.company_id)';
    }
}
