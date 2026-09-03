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
     * বেরোচ্ছে না — আছে, কিন্তু জানালায় কিছুই বেরোয়নি (ধীর + নিশ্চল একসাথে)।
     *
     * stagnant() তালিকার হুবহু সংখ্যা-জোড়া: একই `out = 0` শর্ত, তাই
     * "বেরোচ্ছে না ১২" ক্লিক করলে ঠিক ১২টাই দেখা যায়। ব্যবসায়িকভাবে এটাই
     * সবচেয়ে কাজের ভাগ — ডিপোর টাকা ঠিক এখানে আটকে থাকে।
     */
    public function stagnantCount(int $days): int
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'out').' = 0')
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
     * ধীরগতির মালের তালিকা — সংখ্যা slowMoving()-এর হুবহু একই শর্ত।
     *
     * ড্যাশবোর্ডে "ধীরগতি ৫" ক্লিক করলে ঠিক ওই পাঁচটাই দেখা যায়: predicate
     * এক জায়গা থেকে (movementSql), তাই সংখ্যা আর তালিকা কোনোদিন আলাদা বলে না।
     *
     * @return Collection<int, Product>
     */
    public function slowMovingList(int $days, int $limit = 100)
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'any').' > 0')
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
     * নিশ্চল মালের তালিকা — সংখ্যা nonMoving()-এর হুবহু একই শর্ত।
     *
     * @return Collection<int, Product>
     */
    public function nonMovingList(int $days, int $limit = 100)
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'any').' = 0')
            ->with('unit')
            ->select('inv_products.*')
            ->selectRaw($this->availableSql().' as available_qty')
            ->orderByRaw($this->availableSql().' * COALESCE(inv_products.purchase_price, 0) desc')
            ->limit($limit)
            ->get();
    }

    /**
     * দ্রুতগতির মাল — আছে, আর জানালার ভেতরে বেরিয়েছে।
     *
     * ধীরগতি ও নিশ্চলের আয়না: ওরা বেরোয়নি, এরা বেরিয়েছে। একই onHandQuery
     * আর movementSql, শুধু `out > 0`।
     */
    public function fastMoving(int $days): int
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'out').' > 0')
            ->count();
    }

    /**
     * দ্রুতগতির মালের তালিকা — সবচেয়ে বেশি বেরিয়েছে আগে।
     *
     * @return Collection<int, Product>
     */
    public function fastMovingList(int $days, int $limit = 100)
    {
        return $this->onHandQuery()
            ->whereRaw($this->movementSql($days, 'out').' > 0')
            ->with('unit')
            ->select('inv_products.*')
            ->selectRaw($this->availableSql().' as available_qty')
            ->selectRaw($this->movementSql($days, 'out').' as sold_moves')
            ->orderByRaw($this->movementSql($days, 'out').' desc')
            ->limit($limit)
            ->get();
    }

    /**
     * স্টকের বয়স — FIFO স্তর ধরে, প্রাপ্তির তারিখ থেকে। বাকেটে আটকে থাকা টাকা।
     *
     * ── কেন স্তর, "সর্বশেষ inbound" নয় ──────────────────────────────
     * একটা পণ্য প্রতি সপ্তাহে নতুন এলেও পুরনো ইউনিট পড়ে থাকতে পারে।
     * সর্বশেষ inbound ধরলে "বয়স ৩ দিন" দেখাত, অথচ কিছু মাল ৮ মাসের — ঠিক
     * যেখানে টাকা আটকে, সেটাই লুকাত। inv_cost_layers-এর প্রতিটা স্তরের
     * নিজের trx_date ও qty_remaining আছে, তাই বয়সটা আসল।
     *
     * ⚠️ এই রিপোর্ট স্তরে কেবল **পড়ে, লেখে না** — স্তরগুলো খরচের জন্য
     * ([[CostLayerService]]); বয়সের সংজ্ঞা যেন খরচের সংজ্ঞা থেকে আলাদা না হয়।
     *
     * সংখ্যা (আটকে টাকা) আর তালিকা এক শর্ত (agingScope) থেকে — তাই বাকেটের
     * অঙ্ক আর তার তালিকার যোগফল কখনো আলাদা বলে না।
     *
     * $minDays ≤ বয়স < $maxDays (maxDays null = খোলা, সবচেয়ে পুরনো বাকেট)।
     */
    public function agingValue(int $minDays, ?int $maxDays = null): string
    {
        $row = $this->agingScope($minDays, $maxDays)
            ->selectRaw('COALESCE(SUM(l.qty_remaining * l.unit_cost), 0) as v')
            ->first();

        return bcadd((string) ($row->v ?? '0'), '0', 2);
    }

    /**
     * বয়স-বাকেটের স্তর-তালিকা — সবচেয়ে পুরনো আগে, বয়স ও আটকে থাকা টাকাসহ।
     *
     * @return Collection<int, object>
     */
    public function agingLayers(int $minDays, ?int $maxDays = null, int $limit = 100): Collection
    {
        return $this->agingScope($minDays, $maxDays)
            ->join('inv_products as p', function ($j) {
                $j->on('p.id', '=', 'l.product_id')
                    ->on('p.company_id', '=', 'l.company_id');
            })
            ->selectRaw('l.id, l.product_id, l.document_no, l.trx_date,
                         l.qty_remaining, l.unit_cost,
                         (l.qty_remaining * l.unit_cost) as value_stuck,
                         DATEDIFF(?, l.trx_date) as age_days,
                         p.code as product_code, p.name_en, p.name_bn',
                [Carbon::today()->toDateString()])
            ->orderBy('l.trx_date') // পুরনো আগে
            ->limit($limit)
            ->get();
    }

    /**
     * বয়স-বাকেটের এক শর্ত — সংখ্যা ও তালিকা যেন কখনো আলাদা না হয়।
     * শুধু যে স্তরে মাল এখনো পড়ে আছে (qty_remaining > 0)।
     */
    private function agingScope(int $minDays, ?int $maxDays)
    {
        /*
         * আজকের তারিখটা অ্যাপ থেকে, `CURDATE()` থেকে নয়।
         *
         * ── কেন এটা মজুদের ক্ষেত্রে বিশেষভাবে জরুরি ─────────────────
         * ডাটাবেসের ঘড়ি অ্যাপের ঘড়ি নয়। দুইটা আলাদা টাইমজোনে বা
         * আলাদা মেশিনে থাকলে **"৩০ দিনের পুরনো মাল" ভুল দিন থেকে গোনা
         * হয়** — আর তখন একটা লট বাকেট বদলে ফেলে।
         *
         * ⚠️ ভুলটা এক দিনের, তাই চোখে পড়ে না — কিন্তু সীমানার ঠিক
         * উপরে-নিচে থাকা লটগুলো একদিন এক বাকেটে, পরদিন অন্যটায় দেখা
         * যায়, আর কেউ বুঝতে পারে না কেন সংখ্যাটা নড়ছে।
         *
         * ⓘ আর টেস্টে সময় জমিয়ে রাখা (`Carbon::setTestNow`) তখনই কাজ
         * করে যখন তারিখটা অ্যাপ থেকে আসে — ডাটাবেস ওই জমাটা মানে না।
         */
        $today = Carbon::today()->toDateString();

        $q = DB::table('inv_cost_layers as l')
            ->where('l.company_id', CompanyContext::id())
            ->where('l.qty_remaining', '>', 0)
            ->whereRaw('DATEDIFF(?, l.trx_date) >= ?', [$today, $minDays]);

        if ($maxDays !== null) {
            $q->whereRaw('DATEDIFF(?, l.trx_date) < ?', [$today, $maxDays]);
        }

        return $q;
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
