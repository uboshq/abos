<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * মজুদের পর্দা — চারটা অবস্থা এক জায়গায়।
 *
 * এই একটা টেবিলই ব্যবহারকারীর আসল প্রশ্নের উত্তর: "কী কত আছে, আর তার
 * কতটা বেচা যাবে"। চারটা আলাদা পাতায় ভাগ করলে তিনটা খুলে মনে মনে বিয়োগ
 * করতে হত, আর সেটাই ভুলের জায়গা।
 */
class StockController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly StockService $stock,
        private readonly StockAdjustmentService $adjustments,
        private readonly MenuBuilder $menu,

        /*
         * ⓘ সমন্বয় এখন গণনার কাগজ দিয়ে যায় — তাতে সই চাওয়ার
         * একটা জায়গা তৈরি হয়, আর কে কেন করল তার একটা কাগজও।
         */
        private readonly StockCountService $counts,

        // পরিমাণ প্যাকে ভেঙে দেখানোর সিঁড়িটা এখান থেকে আসে
        private readonly PackConversion $packs,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.stock.view', only: ['index']),
            /*
             * ইস্যুর পর্দাটাও এখানে — আগে কোনো তালিকাতেই ছিল না।
             *
             * ফল: রুটে `can:` বসত না, তাই **যে কেউ লগইন করলেই** গুদাম
             * থেকে মাল বের করে দিতে পারতেন, আর সেটা সোজা খতিয়ানে বসত
             * (উপহার, মালিকের উত্তোলন, আপ্যায়ন)। মেনুর সারিটা ঠিকই
             * `inventory.stock.adjust` চাইত, তাই চাবি না থাকলে সারিটা
             * দেখাত না — কিন্তু ঠিকানাটা টাইপ করলেই পর্দা খুলে যেত।
             *
             * মেনু যা চায়, রুটও এখন তা-ই চায়। EveryRouteIsGuardedTest
             * এখন প্রতিটা রুট ধরে দেখে, তাই পরের পর্দাটা যোগ করার সময়
             * ভুলে গেলে টেস্ট ভাঙবে।
             */
            new Middleware('can:inventory.stock.adjust', only: [
                'adjust', 'storeAdjustment', 'issue', 'storeIssue',
            ]),
            new Middleware('can:inventory.stock.hold', only: ['storeHold', 'storeRelease']),
        ];
    }

    public function index(Request $request): View
    {
        $warehouse = $this->chosenWarehouse($request);

        /*
         * চারটা অবস্থা কোয়েরির ভেতরেই, সারি প্রতি একটা করে নয়।
         *
         * প্রতিটা সারির জন্য statesFor() ডাকলে পঞ্চাশ পণ্যে পঞ্চাশটা কোয়েরি
         * হত। সরবরাহকারীর প্রদেয়েও একই ভুল একবার করা হয়েছিল।
         *
         * প্রথমে এখানে একটা আলাদা GROUP BY কোয়েরি ছিল, কিন্তু তাতে দুইটা
         * সমস্যা ছিল। এক, পাতায় পঞ্চাশটা পণ্য দেখালেও যোগফল আসত সব পণ্যের —
         * দশ হাজার পণ্যের গুদামে ওটা প্রতিবার দশ হাজার সারি টানত। দুই,
         * সংখ্যাগুলো কোয়েরির বাইরে থাকায় ওগুলো দিয়ে সাজানো যেত না, অথচ
         * "কোনটা ফুরিয়ে আসছে" প্রশ্নটাই এই পর্দার সবচেয়ে কাজের প্রশ্ন।
         *
         * সাব-সিলেক্ট করায় দুইটাই মেটে: যোগফল আসে শুধু দেখানো সারিগুলোর,
         * আর ORDER BY-তে ওগুলো ব্যবহার করা যায়। ইনডেক্সটা ঠিক এই কাজের
         * জন্যই — (company_id, product_id, warehouse_id)।
         */
        $query = Product::query()
            ->search($request->query('q'))
            /*
             * ⛔ নিষ্ক্রিয় পণ্যও আসে — যদি তার গায়ে মাল থাকে।
             *
             * ── ⚠️ মালিক যা দেখেছেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────
             * *"দুটো বিল, এটাও ইনভেন্টরিতে যাচ্ছে না"*। ⓘ কিন্তু গেছে:
             * PBL-0001 ৫৭৬ ইউনিট মেঝেতে বসিয়েছে, চালানে ২৪ গেছে, পড়ে
             * আছে ৫৫২। ⛔ পণ্যটা (`Cosmos 40gm`) নিষ্ক্রিয়, আর এখানে
             * শুধু `active()` থাকায় সারিটাই আসত না।
             *
             * ⭐ অর্থাৎ **গুদামে মাল, পর্দায় কিছু নেই** — আর টাকা ইতিমধ্যে
             * খরচ হয়ে গেছে। ⓘ ঠিক এই ভুলটার কথা
             * [[StockReports::stockSummary]]-তেও লেখা আছে: *"এভাবেই মাল
             * উধাও দেখায়"*। সেখানে সারাই হয়েছিল, এই পর্দায় হয়নি।
             *
             * ⚠️ নিষ্ক্রিয় করা মানে *"আর কিনব না"*, **কখনোই** *"যা আছে
             * তা ভুলে যাও"*। ⓘ তাই নিয়মটা এখন: সক্রিয়, **অথবা** এখনো
             * কিছু ধরে আছে। শূন্য হয়ে যাওয়া নিষ্ক্রিয় পণ্য আগের মতোই
             * তালিকার বাইরে — সেগুলো নিয়ে কারও কিছু করার নেই।
             */
            ->where(fn (EloquentBuilder $q) => $q
                ->active()
                ->orWhereIn('inv_products.id', $this->stillHoldingSomething($warehouse)))
            ->with('unit')
            ->select('inv_products.*')
            ->selectSub($this->sumOf('floor_change', $warehouse), 'floor_total')
            ->selectSub($this->sumOf('reserved_change', $warehouse), 'reserved_total')
            ->selectSub($this->sumOf('hold_change', $warehouse), 'hold_total')

            /*
             * ⭐ ফ্রি মাল আলাদা — ১৮ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
             *
             * ── ⛔ অভিযোগটা ন্যায্য ছিল ──────────────────────────────
             * *"ইনভেন্টরিতে স্টক আলাদা ম্যানেজ হওয়ার কথা ছিল, কিন্তু
             * হচ্ছে না।"*
             *
             * ⓘ আর সবচেয়ে শেখার মতো ব্যাপারটা হলো — **হিসাবটা আগে
             * থেকেই আলাদা ছিল**। চলাচলের টেবিলে `free_change` ও
             * `free_reserved_change` কলাম দুইটা প্রথম দিন থেকে আছে,
             * [[StockService::statesForAll()]] ওগুলো গুনেও রাখত।
             *
             * ⛔ কেবল **এই পর্দাটা কোনোদিন জিজ্ঞেসই করেনি**। তাই ফ্রি
             * মাল গুদামে ঢুকত, খাতায় বসত, আর তালিকায় অদৃশ্য থাকত —
             * কোনো ত্রুটি ছাড়াই, কারণ কিছুই ভাঙেনি।
             *
             * ⚠️ ঠিক এই কারণেই ফ্রি-টা `floor`-এর সাথে যোগ করে দেখানো
             * হয় না: *"তাকে ১৬০"* আর *"তার ৪০টা ফ্রি"* দুইটা আলাদা
             * প্রশ্ন, আর একসাথে করলে লাভের হিসাব ভুল হত।
             */
            ->selectSub($this->sumOf('free_change', $warehouse), 'free_total')
            ->selectSub($this->sumOf('free_reserved_change', $warehouse), 'free_reserved_total')

            /*
             * ⭐ এসেছে কিন্তু বসেনি — ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ── ⛔ এটা না থাকায় তালিকাটা মিথ্যা বলত ──────────────────
             * ক্রয় থেকে আসা মাল প্রথমে `unplaced` খোপে বসে, `floor`-এ
             * নয় ([[PurchaseBillService::bringInDirectLines]])। ⓘ কারণটা
             * সৎ: লরি থেকে নামা মাল আর তাকে তোলা মাল এক জিনিস নয়।
             *
             * ⚠️ কিন্তু এই পর্দা কেবল `floor` গুনত। ⛔ ফল: আজ পঞ্চাশ
             * কার্টন এল, আর মজুদের তালিকা **শূন্য** দেখাল — যতক্ষণ না
             * কেউ Stock Placement পর্দায় গিয়ে বসিয়ে আসেন। ⓘ মালিকের
             * *"স্টক দেখাচ্ছে না"* অভিযোগের একটা বড় অংশ এটাই।
             *
             * ⭐ এখন সংখ্যাটা দেখা যায়, আর দেখেই বোঝা যায় কাজটা বাকি।
             */
            ->selectSub($this->sumOf('unplaced_change', $warehouse), 'unplaced_total')
            ->selectSub($this->sumOf('unplaced_free_change', $warehouse), 'unplaced_free_total');

        $sort = $this->applySort($query, $request, $this->sorts());

        $products = $query->paginate(50)->withQueryString();

        return view('inventory::stock.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $products,

            /*
             * ⭐ প্রতিটা পণ্যের প্যাকের সিঁড়ি — পরিমাণ ভেঙে দেখানোর জন্য
             * (২০ সেপ্টেম্বর ২০২৬, মালিকের কথায়)।
             *
             * ⚠️ একবারেই সব পণ্যের, সারি ধরে নয় — পঞ্চাশ সারির পাতায়
             * পণ্যপ্রতি একটা কোয়েরি মানে পঞ্চাশটা কোয়েরি, আর ঠিক ওই
             * ভুলটাই একবার আদায়ের পর্দাকে ধীর করে দিয়েছিল।
             */
            'ladders' => $this->packs->laddersFor($products->getCollection(), packsOnly: true),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * এক পণ্যের এক ধরনের চলাচলের যোগফল — সাব-সিলেক্ট হিসেবে।
     */
    /**
     * যে পণ্যগুলো এখনো কিছু ধরে আছে — নিষ্ক্রিয় হলেও।
     *
     * ⓘ চারটা ঘরই গোনা হয়: মেঝে, আটকানো, বসার অপেক্ষায়, আর ফ্রি।
     * ⚠️ কেবল `floor` দেখলে যে মাল সদ্য এসেছে অথচ কেউ গুদামে বসায়নি
     * (`unplaced`) সে আবার অদৃশ্য হত — একই ভুলের আরেক দরজা।
     *
     * ⓘ গুদামের ছাঁকনি এখানেও লাগে: নেত্রকোনার মাল দেখতে চাইলে
     * ময়মনসিংহের মজুদ এই পণ্যটাকে তালিকায় টেনে আনার কথা নয়।
     */
    private function stillHoldingSomething(?Warehouse $warehouse): Builder
    {
        return DB::table('inv_stock_movements')
            ->select('product_id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn (Builder $q, Warehouse $w) => $q->where('warehouse_id', $w->id))
            ->groupBy('product_id')
            ->havingRaw('COALESCE(SUM(floor_change), 0) <> 0
                      OR COALESCE(SUM(hold_change), 0) <> 0
                      OR COALESCE(SUM(unplaced_change), 0) <> 0
                      OR COALESCE(SUM(free_change), 0) <> 0');
    }

    private function sumOf(string $column, ?Warehouse $warehouse): Builder
    {
        return DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn (Builder $q, Warehouse $w) => $q->where('warehouse_id', $w->id));
    }

    /**
     * সাজানোর উপায়গুলো।
     *
     * ডিফল্ট "বিক্রয়যোগ্য কম আগে" — তালিকা খুলেই যেগুলো ফুরিয়ে আসছে
     * সেগুলো উপরে থাকে। নামে সাজালে প্রথম পাতায় কী থাকবে তা নির্ভর করত
     * বর্ণমালার উপর, আর যে পণ্যটা আজ শেষ হয়ে যাবে সেটা তিন নম্বর পাতায়
     * পড়ে থাকত।
     *
     * @return array<string, callable>
     */
    private function sorts(): array
    {
        // সাব-সিলেক্টের নাম দিয়েই সাজানো — ব্যবহারকারীর পাঠানো কোনো লেখা
        // এখানে পৌঁছায় না, শুধু এই ঘোষিত ছয়টা চাবির একটা
        $available = 'floor_total - reserved_total - hold_total';

        return [
            'available' => fn ($q) => $q->orderByRaw("{$available} asc")->orderBy('inv_products.name_en'),
            'available_desc' => fn ($q) => $q->orderByRaw("{$available} desc"),
            'floor_desc' => fn ($q) => $q->orderByRaw('floor_total desc'),
            'hold_desc' => fn ($q) => $q->orderByRaw('hold_total desc'),

            /*
             * ⭐ *"কোন পণ্যে সবচেয়ে বেশি ফ্রি পড়ে আছে"* — ১৮ সেপ্টেম্বর।
             *
             * ⓘ কলামটা দেখানোই যথেষ্ট নয়: ত্রিশ পাতার তালিকায় চোখে
             * খুঁজে বের করা যায় না। ⚠️ আর সাজানোর নামগুলো সাব-সিলেক্টের
             * নাম — ব্যবহারকারীর পাঠানো কোনো লেখা এখানে পৌঁছায় না।
             */
            'free_desc' => fn ($q) => $q->orderByRaw('free_total desc'),
            'name' => fn ($q) => $q->orderBy('inv_products.name_en'),
            'code' => fn ($q) => $q->orderBy('inv_products.code'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'available' => __('inventory::sort.available_low'),
            'available_desc' => __('inventory::sort.available_high'),
            'floor_desc' => __('inventory::sort.floor_high'),
            'hold_desc' => __('inventory::sort.hold_high'),
            'free_desc' => __('inventory::sort.free_high'),
            'name' => __('inventory::sort.name'),
            'code' => __('inventory::sort.code'),
        ];
    }

    /** গণনা ও সমন্বয়ের পর্দা। */
    public function adjust(Request $request): View
    {
        return view('inventory::stock.adjust', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'reasons' => ReasonCode::query()
                ->inContext(ReasonCode::STOCK_ADJUSTMENT)
                ->active()->orderBy('code')->get(),
            'holdReasons' => ReasonCode::query()
                ->inContext(ReasonCode::HOLD)
                ->active()->orderBy('code')->get(),
            'stock' => $this->stock,
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::STOCK_ADJUSTMENT, 'counted');

        /*
         * ⭐ সমন্বয় এখন একটা গণনার কাগজ হয়ে যায় — ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কেন লাগল ────────────────────────────────
         * মালিক বললেন সব জায়গায় অনুমোদন বসাতে। ⚠️ কিন্তু সমন্বয়
         * কোনো **কাগজ বানাত না** — সারিটা তৈরি হয় কাজটা হয়ে যাওয়ার
         * **পরে**, আর সই বসে এমন কাগজে যেটা আগে থেকে আছে।
         *
         * ── ⭐ সমাধান ─────────────────────────────────
         * প্রতিটা সমন্বয় এখন একটা **এক-সারির গণনা** হয়ে যায়:
         * কাগজটা তৈরি হয়, তারপর মেনে নেওয়া হয় — আর মেনে নেওয়ার
         * মুহূর্তেই সই চাওয়া হয়।
         *
         * ⓘ দুইটা লাভ একসাথে: অনুমোদনের জায়গা তৈরি হলো, আর
         * রাইট-অফের একটা নম্বরওয়ালা কাগজ রয়ে গেল — আগে কেবল
         * একটা স্টক সারি ছিল, যা দেখে কে কেন করল বলা যেত না।
         *
         * ⚠️ ছক না বসানো থাকলে আগের মতোই সাথে সাথে হয় — কেবল
         * একটা কাগজ বেশি তৈরি হয়।
         */
        $count = $this->counts->record([
            'warehouse_id' => $data['warehouse']->id,
            'count_date' => $request->input('trx_date'),
            'narration' => $request->input('narration'),
        ], [[
            'product_id' => $data['product']->id,
            'counted_qty' => (string) $request->input('counted'),
        ]]);

        $this->counts->approve($count, $data['reason']);

        $difference = (string) ($count->lines->first()?->difference ?? '0');

        // মিলে গেলে কোনো সারি বসে না — আর সেটা ব্যবহারকারীকে বলা হয়,
        // নাহলে তিনি ভাবতেন সেভ হয়নি
        return back()->with('saved', bccomp($difference, '0', 4) === 0
            ? __('inventory::message.adjust_matched')
            : __('inventory::message.adjusted', [
                'difference' => Money::format($difference),
            ]));
    }

    /**
     * বিক্রি ছাড়া মাল বের করে দেওয়ার পর্দা।
     *
     * সমন্বয়ের পর্দা থেকে আলাদা, কারণ প্রশ্নটাই আলাদা: ওখানে "গুনে কত
     * পেলাম", এখানে "কতটা দিয়ে দিলাম"। কারণটা বেছে নিলেই টাকাটা ঠিক
     * খাতে যায় — আপ্যায়ন খরচে, উপহার উপহারে, মালিকের ব্যবহার উত্তোলনে।
     */
    public function issue(Request $request): View
    {
        return view('inventory::stock.issue', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'reasons' => ReasonCode::query()
                ->inContext(ReasonCode::STOCK_ISSUE)
                ->active()->with('account')->orderBy('code')->get(),
            'stock' => $this->stock,
        ]);
    }

    public function storeIssue(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::STOCK_ISSUE, 'qty');

        $movement = $this->adjustments->issue(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
        );

        return back()->with('saved', __('inventory::message.issued', [
            'qty' => rtrim(rtrim((string) $request->input('qty'), '0'), '.'),
            'reason' => $data['reason']->name(),
            'account' => $data['reason']->account?->label()
                ?? __('inventory::message.issue_no_account'),
        ]));
    }

    public function storeHold(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::HOLD, 'qty');

        $this->stock->hold(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
        );

        return back()->with('saved', __('inventory::message.held'));
    }

    public function storeRelease(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::HOLD, 'qty');

        $this->stock->release(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
        );

        return back()->with('saved', __('inventory::message.released'));
    }

    /**
     * @return array{product: Product, warehouse: Warehouse, reason: ReasonCode}
     */
    private function validatedMovement(Request $request, string $context, string $qtyField): array
    {
        /*
         * exists নিয়মে company_id — গ্লোবাল স্কোপ ভ্যালিডেটরের কাঁচা
         * কোয়েরিতে কাজ করে না, তাই ওটা ছাড়া অন্য কোম্পানির পণ্যের id
         * পাঠিয়ে দেওয়া যেত।
         */
        $companyId = CompanyContext::id();

        $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'reason_code_id' => ['required', 'integer',
                Rule::exists('mdm_reason_codes', 'id')->where('company_id', $companyId)],
            $qtyField => ['required', 'numeric'],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * দর — কেবল গণনায় বেশি পাওয়া গেলে, আর তখন বাধ্যতামূলক।
             *
             * এখানে required করা যায় না, কারণ পাওয়া গেছে বেশি না কম
             * সেটা জানা যায় গোনার পর — তাক আর খাতার পার্থক্য দেখে।
             * পাহারাটা তাই সার্ভিসে, যেখানে পার্থক্যটা জানা।
             */
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reason = ReasonCode::query()->findOrFail($request->integer('reason_code_id'));

        if ($reason->context !== $context) {
            abort(422, __('inventory::validation.wrong_reason_context'));
        }

        return [
            'product' => Product::query()->findOrFail($request->integer('product_id')),
            'warehouse' => Warehouse::query()->findOrFail($request->integer('warehouse_id')),
            'reason' => $reason,
        ];
    }

    private function chosenWarehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        // ০ বা অচেনা id মানে "সব গুদাম" — ভাঙা নয়
        return $id > 0 ? Warehouse::query()->find($id) : null;
    }
}
