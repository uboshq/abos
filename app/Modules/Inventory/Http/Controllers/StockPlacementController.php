<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * মাল বুঝে নেওয়া — গাড়ি থেকে নামা আর গুদামে ঢোকা এক ঘটনা নয়।
 *
 * ── মালিকের নিয়ম, ৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * *"direct purchase হওয়ার পর বা received হওয়ার পর inventory-তে Stock
 * Placement নামে একটা মেনু হবে"* · *"স্টক প্লেসমেন্ট করার আগ পর্যন্ত
 * কোনো বিল করা যাবে না, মানে সেল করা যাবে না।"*
 *
 * ── তালিকাটা কাগজ ধরে, পণ্য ধরে নয় ───────────────────────────────────
 * গুদামের লোক একটা কাগজ হাতে নিয়ে দাঁড়ান — একটা চালান, একটা বিল। তিনি
 * "কোন পণ্যের কত বাকি" জানতে চান না; তিনি জানতে চান **এই কাগজটার মাল
 * বুঝে নেওয়া হয়েছে কি না**। পণ্য ধরে সাজালে একই চালানের ছয়টা লাইন
 * ছয় জায়গায় ছড়িয়ে যেত, আর কেউ বলতে পারত না কাগজটা শেষ হলো কি না।
 *
 * ── কেন এখানে কোনো "সব বসিয়ে দিন" বোতাম নেই ──────────────────────────
 * ⚠️ দশ কার্টনের আটটা ঠিক, দুইটা ভাঙা — এক চাপে সবটা বসানোর সুযোগ
 * থাকলে ভাঙা মালও বসে যেত, কারণ কাজটা এগোতে হবে। প্রতিটা লাইনে
 * পরিমাণ লেখা যায়, আর ডিফল্টে পুরোটা বসানো থাকে; **কমাতে হলে
 * ইচ্ছে করে কমাতে হয়।**
 */
class StockPlacementController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly StockService $stock,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * মেনু যা চায়, রুটও তা-ই চায় — [[StockController::middleware()]]-এর
         * পাঠ। ⚠️ না বসালে ঠিকানা টাইপ করেই যে কেউ মাল বসিয়ে দিতে
         * পারতেন, আর সেটা সোজা বিক্রয়যোগ্য মজুদে যেত।
         */
        return [
            new Middleware('can:inventory.stock.place', only: ['index', 'store']),
        ];
    }

    /**
     * ⛔ পাতা ভাগ নেই, ইচ্ছাকৃত — এটা তালিকা নয়, একটা বোর্ড।
     *
     * সারিগুলো কাগজ ধরে দলে বাঁধা ([[waiting]]), আর প্রতিটা দলের নিচে
     * তার লাইনগুলো। পঞ্চাশে কাটলে কাটটা পড়ত একটা কাগজের **মাঝখানে** —
     * একটা চালানের তিনটা লাইন এই পাতায়, বাকি দুইটা পরের পাতায়, আর
     * বসানোর ফর্মটা তখন অর্ধেক কাগজ জমা দিত।
     *
     * তালিকাটা নিজে থেকেই ছোট থাকে: বসানো শেষ হলে সারিটা চলে যায়
     * (`SUM(unplaced_change) > 0`)। লম্বা হলে সেটা জমে থাকা নয়, একটা
     * সংকেত — মাল এসেছে অথচ কেউ তাকে তুলছেন না।
     *
     * কারণটা `EveryListScreenPaginatesTest`-এর ছাড়ের তালিকাতেও আছে।
     */
    public function index(Request $request): View
    {
        $papers = $this->waiting();

        return view('inventory::stock.placement', [
            'menu' => $this->menu->forUser($request->user()),
            'papers' => $papers,
            'places' => $this->placesIn($papers),

            /*
             * ⭐ দুই ভাগ — মালিকের ছবি, ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ ক্রয়ের কাগজ আর ফেরতের কাগজ এক তালিকায় মিশলে গুদামের
             * লোককে প্রতিটা সারি পড়ে বুঝতে হত মালটা গাড়ি থেকে নামল না
             * গ্রাহকের কাছ থেকে ফিরল — আর দুইটার পরীক্ষা এক নয়।
             *
             * ⚠️ ফেরতের ভাগটা আজ খালি থাকতে পারে: বিক্রয় ফেরতের মাল
             * এখনও সোজা তাকে ওঠে ([[SalesReturnService]])। ⓘ তবু ভাগটা
             * আছে, কারণ নিয়মটা বদলালে সারিগুলো ইতিমধ্যেই ঠিক জায়গায় বসবে।
             */
            'groups' => [
                'purchase' => array_filter($papers, fn (array $paper) => ! $this->isReturn($paper)),
                'return' => array_filter($papers, fn (array $paper) => $this->isReturn($paper)),
            ],
        ]);
    }

    /**
     * এই কাগজটা কি ফেরতের।
     *
     * ⓘ উৎসের নামেই লেখা থাকে (`sales_return`, `purchase_return`) —
     * তাই অন্য মডিউলের ক্লাসের নাম ধরে ডাকতে হয় না।
     *
     * @param  array<string, mixed>  $paper
     */
    private function isReturn(array $paper): bool
    {
        return str_contains((string) $paper['source_type'], 'return');
    }

    /**
     * তাকের গাছ — কেবল যে গুদামগুলো আজ পর্দায় আছে, তাদেরটা।
     *
     * ── কেন সব গুদামের সব তাক নয় ────────────────────────────────────
     * ⚠️ একটা ডিপোতে পাঁচশো শেলফ থাকতে পারে। সবগুলো পাঠালে পর্দাটা
     * ভারী হত, অথচ ব্যবহারকারী আজ দুইটা গুদামের বাইরে কিছুই বাছবেন
     * না — বাকি সব সারি নিছক ওজন।
     *
     * ── আকৃতি ───────────────────────────────────────────────────────
     * `[warehouseId => [locationId => ['id','code','name','depth','parent']]]`
     *
     * ⓘ সমতল, গাছ নয়। পর্দায় Alpine `parent` ধরে ছেঁকে নেয়, আর
     * সমতল তালিকা JSON-এ ছোট এবং পড়াও সহজ। ⭐ গভীরতা বাড়লে এই
     * আকৃতিটার এক অক্ষরও বদলায় না — কেবল আরেকটা কলাম দেখাতে হয়।
     *
     * @param  array<string, array{lines: list<array<string, mixed>>}>  $papers
     * @return array<int, list<array<string, mixed>>>
     */
    private function placesIn(array $papers): array
    {
        $warehouseIds = [];

        foreach ($papers as $paper) {
            foreach ($paper['lines'] as $line) {
                $warehouseIds[(int) $line['warehouse_id']] = true;
            }
        }

        if ($warehouseIds === []) {
            return [];
        }

        return StorageLocation::query()
            ->active()
            ->with('warehouse:id,code,name_en,name_bn')
            ->whereIn('warehouse_id', array_keys($warehouseIds))
            ->inWalkingOrder()
            ->get()
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => $rows->map(fn (StorageLocation $place) => [
                'id' => $place->id,
                'code' => $place->code,
                'name' => $place->name(),
                'depth' => $place->depth,
                'parent' => $place->parent_id,

                /*
                 * ⓘ গুদামের নামটা প্রতিটা সারিতে — উপরের "সবার জন্য"
                 * বারটা গুদামের তালিকা এখান থেকেই বানায়। ⚠️ আলাদা করে
                 * পাঠালে দুইটা তালিকা একদিন আলাদা হত: একটায় গুদাম আছে
                 * অথচ তাক নেই, আর ড্রপডাউনটা খালি খুলত।
                 */
                'warehouse_name' => $place->warehouse?->name(),
            ])->values()->all())
            ->all();
    }

    /**
     * যে কাগজগুলোর মাল এখনো বসেনি — কাগজ ধরে, তার ভেতরে সারি ধরে।
     *
     * ── কেন কাঁচা কোয়েরি, `statesFor()` নয় ───────────────────────────
     * `statesFor()` একটা পণ্যের যোগফল দেয়; এখানে দরকার **কোন কাগজের
     * কোন লাইনে** কত বাকি। উৎস ধরে ভাগ না করলে দুইটা চালানের একই
     * পণ্য এক সারিতে মিশে যেত, আর তখন কোনটা বসানো হচ্ছে তা বলা যেত না।
     *
     * ⓘ ব্যাচও ভাগের অংশ: একই পণ্যের দুই লট আলাদা করে বসাতে হয়,
     * নাহলে বসানোর সময় লট হারিয়ে যেত আর রিকলে ধরা পড়ত না।
     *
     * @return array<string, array{document_no: ?string, source_type: string, source_id: int, lines: list<array<string, mixed>>}>
     */
    private function waiting(): array
    {
        $rows = DB::table('inv_stock_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('inv_batches as b', 'b.id', '=', 'm.batch_id')
            ->where('m.company_id', CompanyContext::id())
            /*
             * ⚠️ কাগজের নম্বর ও তারিখ **দলের অংশ নয়** — MAX/MIN দিয়ে আনা হয়।
             *
             * ── কী ভাঙা ছিল, ৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
             * প্রথমে `m.document_no` আর `m.trx_date`-ও GROUP BY-তে ছিল।
             * ফলে আসার সারি (নম্বর "PB-…") আর বসানোর সারি (নম্বর খালি)
             * **দুইটা আলাদা দলে** পড়ত: একটায় `+১২`, অন্যটায় `−৮`।
             * কাটাকাটি হত না, আর পর্দায় বারো-ই দেখাত — যদিও আট বসে গেছে।
             *
             * ⓘ ধরা পড়েছে ব্রাউজারে বসিয়ে, তারপর পাতাটা আবার খুলে।
             * পণ্যের যোগফল ঠিক ছিল, কেবল পর্দার দলটা ভুল ছিল — **কোড
             * পড়ে ধরার কোনো উপায় ছিল না।**
             *
             * নম্বরটা কাগজের, প্রতিটা সারির নয় — তাই দলটা উৎস ধরেই হয়,
             * আর নম্বরটা দল থেকে তুলে আনা হয়।
             */
            ->groupBy(
                'm.source_type', 'm.source_id',
                'm.product_id', 'p.code', 'p.name_en', 'p.name_bn',
                'm.warehouse_id', 'w.code', 'w.name_en',
                'm.batch_id', 'b.batch_no',
            )
            /*
             * শূন্য বা ঋণাত্মক বাদ — বসানো শেষ হয়ে গেলে সারিটা নিজে
             * থেকেই তালিকা থেকে চলে যায়। ⓘ কোনো "বসানো হয়েছে" পতাকা
             * রাখা হয়নি: পতাকা আর যোগফল একদিন আলাদা কথা বলত।
             */
            ->havingRaw('SUM(m.unplaced_change) > 0 OR SUM(m.unplaced_free_change) > 0')
            ->orderByRaw('MIN(m.trx_date)')
            ->select([
                'm.source_type', 'm.source_id',
                DB::raw('MAX(m.document_no) as document_no'),
                DB::raw('MIN(m.trx_date) as trx_date'),

                /* ⭐ কে বুঝিয়ে দিলেন — মালিকের ছবির "Process By"।
                   ⓘ এক কাগজের সব সারি একজনেরই লেখা, তাই MAX ধরলেই চলে। */
                DB::raw('MAX(m.created_by) as created_by'),
                'm.product_id', 'p.code as product_code', 'p.name_en as product_name',
                'm.warehouse_id', 'w.name_en as warehouse_name',
                'm.batch_id', 'b.batch_no',
                DB::raw('SUM(m.unplaced_change) as waiting'),
                DB::raw('SUM(m.unplaced_free_change) as waiting_free'),
            ])
            ->get();

        $papers = [];

        foreach ($rows as $row) {
            /*
             * ⭐ দলটা **কাগজ ধরে**, উৎসের নাম ধরে নয় — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ── ⛔ মালিকের প্রশ্ন ───────────────────────────────────
             * *"একটা বিল ক্রয় হলো, কিন্তু দুটো ভাগ কেন?"* — একই বিল
             * (PBL-0004) পর্দায় দুইটা কার্ড হয়ে বসত: একটার আইডি
             * `purchase_bill-free:8`, অন্যটার `purchase_bill-8`।
             *
             * ── ⓘ কেন ─────────────────────────────────────────────
             * ফ্রি মাল নিজের উৎস-নামে চলে (`…:free`), আর কারণটা ঠিকই
             * আছে — ফ্রি কার্টনের ক্রয়মূল্য নেই, তাই সে আলাদা ভাণ্ডারে
             * ঢোকে আর বাতিলের সময় আলাদা করে চেনা যায়
             * ([[PurchaseBillService::bringInFree()]])।
             *
             * ⚠️ কিন্তু ওটা **হিসাবের ভাগ**, আর এটা **কাজের পর্দা**।
             * গুদামের লোকের কাছে একটাই লরি, একটাই কাগজ, একটাই কাজ —
             * তাঁকে একই বিল দুইবার খুঁজে বের করতে হত।
             *
             * ⛔ আর দেখতেও ভুল লাগত: ফ্রি কার্ডে টাকার ঘরগুলোয় বসানো
             * থাকত `0.0000`, আর টাকার কার্ডে ফ্রির ঘরে `—` — দুইটা
             * কার্ডেই অর্ধেক ঘর মৃত।
             */
            $key = $this->paperOf($row->source_type).':'.$row->source_id;

            $papers[$key] ??= [
                'document_no' => $row->document_no,

                /* ⓘ মাথার নামটা মূল কাগজের — ড্রিলও ওটাই ধরে খোঁজে */
                'source_type' => $this->paperOf($row->source_type),

                'source_id' => (int) $row->source_id,
                'trx_date' => $row->trx_date,
                'created_by' => $row->created_by === null ? null : (int) $row->created_by,
                'lines' => [],
            ];

            $papers[$key]['lines'][] = [
                /*
                 * ⚠️ উৎসের নামটা **সারির নিজের**, কার্ডের নয়।
                 *
                 * ⛔ বসানোর সারিটা যে উৎসে এসেছিল ঠিক সেই উৎসেই লিখতে
                 * হয়, নাহলে আসা আর বসানো দুইটা আলাদা দলে পড়ে, যোগফল
                 * কাটাকাটি হয় না, আর কাগজটা তালিকা থেকে **কোনোদিন
                 * সরে না** ([[StockService::place()]]-এর ৪ সেপ্টেম্বরের
                 * টীকা)।
                 *
                 * ⓘ তাই কার্ড এক হলেও ফ্রি সারি নিজের `…:free` নামটা
                 * সাথে নিয়েই চলে।
                 */
                'source_type' => $row->source_type,
                'source_id' => (int) $row->source_id,

                'product_id' => (int) $row->product_id,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'batch_id' => $row->batch_id === null ? null : (int) $row->batch_id,
                'batch_no' => $row->batch_no,
                'waiting' => (string) $row->waiting,
                'waiting_free' => (string) $row->waiting_free,
            ];
        }

        return $this->withTheirFacts($papers);
    }

    /**
     * এই উৎস-নামটা কোন কাগজের — ফ্রি-র লেজ কেটে।
     *
     * ⓘ `purchase_bill:free` আর `purchase_bill` একই কাগজ, কেবল দুই
     * রকম মাল। ⚠️ `:free:cancel`-ও আছে (বাতিলের সারি), তাই কাটা হয়
     * **প্রথম `:free` থেকে**, শেষেরটা থেকে নয়।
     */
    private function paperOf(string $sourceType): string
    {
        $at = strpos($sourceType, ':free');

        return $at === false ? $sourceType : substr($sourceType, 0, $at);
    }

    /**
     * কাগজের মাথার চারটা কথা — কার, কোন চালান, কবে, কে করলেন।
     *
     * ⭐ মালিকের ছবি, ২০ সেপ্টেম্বর ২০২৬ — কাগজের মাথায় সরবরাহকারী,
     * চালান নম্বর, তারিখ আর কে প্রক্রিয়া করলেন। ⓘ গুদামের লোক হাতে
     * একটা কাগজ নিয়ে দাঁড়ান; ওই কাগজটাই খুঁজে নিতে পারা চাই।
     *
     * ⛔ ক্রয় মডিউলের নাম ধরে ডাকা হয় না: মজুদ ক্রয়ের উপর নির্ভর
     * করে না ([[BoundariesTest]])। ⓘ তাই সরবরাহকারীর নাম আর কাগজের নম্বর
     * আসে কোরের drill রেজিস্ট্রি থেকে — যে মডিউল কাগজটা বানায়,
     * নামটাও সে-ই দেয়। ⭐ আর কাগজে যাওয়ার লিংকটাও সাথেই আসে।
     *
     * @param  array<string, array<string, mixed>>  $papers
     * @return array<string, array<string, mixed>>
     */
    private function withTheirFacts(array $papers): array
    {
        $drill = app(DrillResolver::class);

        $users = User::query()
            ->whereIn('id', array_filter(array_column($papers, 'created_by')))
            ->pluck('name', 'id')
            ->all();

        foreach ($papers as $key => $paper) {
            $seen = $drill->describe($paper['source_type'], $paper['source_id']);

            $papers[$key]['party'] = $seen['resolved'] ? $seen['label'] : null;
            $papers[$key]['document_no'] = $paper['document_no'] ?: $seen['document_no'];
            $papers[$key]['route'] = $seen['resolved'] ? $seen['route'] : null;
            $papers[$key]['by'] = $users[$paper['created_by']] ?? null;
        }

        return $papers;
    }

    /**
     * বসিয়ে দেওয়া — এক বা একাধিক সারি, একসাথে।
     *
     * ⚠️ পুরোটা একটা ট্রানজেকশনে: একটা কাগজের ছয়টা লাইনের চতুর্থটা
     * ব্যর্থ হলে আগের তিনটাও ফিরে যায়। **অর্ধেক বসানো কাগজ সবচেয়ে
     * খারাপ অবস্থা** — গুদামের লোক ভাবতেন কাজ শেষ, অথচ বাকিটা রয়ে
     * যেত, আর সেটা কেউ খুঁজে পেত না কারণ কাগজটা তালিকা থেকে সরে যেত না।
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.warehouse_id' => ['required', 'integer'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.source_type' => ['required', 'string', 'max:60'],
            'lines.*.source_id' => ['required', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],

            /*
             * তাকটা ঐচ্ছিক — আর চিরকাল ঐচ্ছিক।
             *
             * ⓘ যে গুদামে তাক বসানো নেই, সেখানে ঘরটা পর্দাতেই আসে না।
             * ⛔ `required` করলে ছোট দোকানের প্রতিটা বসানোই আটকে যেত।
             *
             * ⚠️ তাকটা এই গুদামেরই কি না, সেটা এখানে নয় — নিয়মটা
             * [[StockService::place()]]-এ, কারণ সিডার আর ইমপোর্টও
             * ওখান দিয়েই যায়।
             */
            'lines.*.storage_location_id' => ['nullable', 'integer'],

            /*
             * ⭐ কেবল একটা সারি — মালিকের ছবির তিরচিহ্ন, ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ বোতামের নিজের নাম-মান ফরমের সাথে যায়, তাই কোনো JS লাগে
             * না। ⚠️ মানটা সারির চাবি হতে হয় — অচেনা চাবি দিলে কিছুই
             * বসে না, আর সেটাই নিরাপদ: ভুল সারি বসানোর চেয়ে কিছু না
             * বসাই ভালো।
             */
            'only' => ['nullable', 'string', 'max:40'],
        ]);

        /*
         * ⓘ একটা সারির বোতাম চাপলে বাকি সারিগুলো ফেলে দেওয়া হয়।
         * ⭐ দশ কার্টনের আটটা আজ, বাকি দুইটা কাল — পুরো কাগজটা
         * আটকে থাকার দরকার নেই।
         */
        if (($data['only'] ?? null) !== null) {
            $data['lines'] = array_intersect_key($data['lines'], [$data['only'] => true]);
        }

        $placed = 0;

        DB::transaction(function () use ($data, &$placed) {
            foreach ($data['lines'] as $line) {
                $qty = (string) ($line['qty'] ?? '0');
                $freeQty = (string) ($line['free_qty'] ?? '0');

                /*
                 * শূন্য লাইন চুপচাপ বাদ — ভুল নয়।
                 *
                 * ⓘ ব্যবহারকারী ছয়টা লাইনের দুইটা বসাতে চাইলে বাকি
                 * চারটা শূন্য রেখে দেন। ওগুলো ত্রুটি বললে তাঁকে প্রতিটা
                 * ঘর মুছতে হত।
                 */
                if (bccomp($qty, '0', 4) <= 0 && bccomp($freeQty, '0', 4) <= 0) {
                    continue;
                }

                $this->stock->place(
                    product: Product::findOrFail($line['product_id']),
                    warehouse: Warehouse::findOrFail($line['warehouse_id']),
                    qty: $qty,
                    sourceType: (string) $line['source_type'],
                    sourceId: (int) $line['source_id'],
                    batch: isset($line['batch_id']) ? Batch::find($line['batch_id']) : null,
                    freeQty: $freeQty,
                    location: filled($line['storage_location_id'] ?? null)
                        ? StorageLocation::findOrFail($line['storage_location_id'])
                        : null,
                );

                $placed++;
            }
        });

        return back()->with('saved', __('inventory::message.placed', ['count' => $placed]));
    }
}
