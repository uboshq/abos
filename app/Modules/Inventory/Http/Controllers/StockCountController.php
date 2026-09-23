<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StockCountRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ মাল গোনা — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কাজটা লেখা ছিল, দরজাটা ছিল না ─────────────────────────────────
 * [[StockCountService]]-এ `record()` ও `approve()` দুইটাই সম্পূর্ণ, সই
 * ও কারণ-কোড সহ। ⚠️ কিন্তু কোনো রুট ওটাকে ডাকত না। ⓘ গোনার একমাত্র পথ
 * ছিল সমন্বয়ের পর্দার ভিতরে **এক সারি** — একবারে একটা পণ্য।
 *
 * ⛔ গুদাম গোনা মানে একশো পণ্য, আর একশো বার সমন্বয়ের পাতা খোলা কেউ
 * করে না। ⚠️ ফল: ব্যবস্থাটা ছিল, ব্যবহার হত না — আর কোথাও কিছু লাল
 * হত না বলে কেউ টেরও পায়নি।
 *
 * ── ⓘ দুই ধাপ, আর দুই হাত ───────────────────────────────────────────
 *   ১. **গোনা** (`store`) — খাতা এক চুলও নড়ে না, কেবল লেখা হয়
 *   ২. **মেনে নেওয়া** (`approve`) — পার্থক্যগুলো সমন্বয় হয়ে বসে
 *
 * ⚠️ দুইটার চাবি আলাদা ([[StockCountPolicy]])। ⛔ এক চাবিতে রাখলে যিনি
 * গোনেন তিনিই নিজের গোনাটা মেনে নিতে পারতেন, আর গোনার মানেই থাকত না।
 */
class StockCountController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly StockCountService $counts,
        private readonly StockService $stock,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(StockCount::class, 'count'),

            /*
             * ⭐ মেনে নেওয়ার চাবি আলাদা — উপরের docblock-এ কারণ।
             *
             * ⚠️ `resourcePermissions()` সাতটা পদ্ধতি চেনে, `approve`
             * তাদের একটাও নয়। ⛔ এই লাইনটা না থাকলে রুটটা **খোলা**
             * থাকত, আর যে কেউ পার্থক্য মেনে নিয়ে খাতা বদলে দিতে পারত।
             */
            new Middleware('can:approve,count', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $query = StockCount::query()
            ->with(['warehouse', 'counter'])
            ->withCount('lines');

        $dates = $this->applyDateRange($query, $request, 'count_date');

        $sort = $this->applySort($query, $request, [
            /*
             * ⭐ খসড়াগুলো আগে — ডিফল্ট।
             *
             * ⓘ এই তালিকার আসল প্রশ্ন *"কোন গোনাটা এখনো মেনে নেওয়া
             * হয়নি"*। ⚠️ সাম্প্রতিক দিয়ে সাজালে ওগুলো নিচে চাপা পড়ত,
             * আর একটা অমীমাংসিত গোনা মানে খাতা আর তাক এখনো দুই কথা
             * বলছে।
             */
            'pending' => fn ($q) => $q
                ->orderByRaw("CASE WHEN status = '".DocumentStatus::DRAFT."' THEN 0 ELSE 1 END")
                ->orderByDesc('count_date')->orderByDesc('id'),
            'recent' => fn ($q) => $q->orderByDesc('count_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('count_date')->orderBy('id'),
        ]);

        return view('inventory::count.index', [
            'menu' => $this->menu->forUser($request->user()),
            'counts' => $query->paginate(50)->withQueryString(),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::count.form', [
            'menu' => $this->menu->forUser($request->user()),
            'count' => new StockCount(['count_date' => now()->toDateString()]),
            ...$this->formData($request),
        ]);
    }

    public function store(StockCountRequest $request): RedirectResponse
    {
        $count = $this->counts->record($request->documentData(), $request->lineData());

        return redirect()
            ->route('inventory.count.show', $count)
            ->with('saved', __('inventory::message.count_recorded'));
    }

    public function show(Request $request, StockCount $count): View
    {
        $count->load(['lines.product.unit', 'lines.batch', 'warehouse', 'counter', 'approver']);

        return view('inventory::count.show', [
            'menu' => $this->menu->forUser($request->user()),
            'count' => $count,

            /*
             * ⓘ কারণগুলো কেবল তখনই লাগে যখন সত্যিই মেনে নেওয়ার বোতাম
             * দেখানো হবে — আর সেটা নীতি ঠিক করে, পর্দা নয়।
             */
            'reasons' => $request->user()?->can('approve', $count)
                ? ReasonCode::query()->active()->with('account')->orderBy('code')->get()
                : collect(),
        ]);
    }

    /**
     * ⭐ পার্থক্যটা মেনে নেওয়া — এখানেই খাতা বদলায়।
     *
     * ⛔ কারণ-কোড বাধ্যতামূলক, আর সেটা সেবার নিয়ম নয়, এখানকারও:
     * ⚠️ মাল কম পাওয়া গেছে — চুরি, ভাঙা, মেয়াদ, নাকি গোনার ভুল?
     * ⓘ উত্তরটা ছাড়া সংখ্যাটা কেবল একটা ক্ষতি; উত্তর থাকলে মাস শেষে
     * *"কোন কারণে কত গেল"* প্রশ্নের জবাব দেওয়া যায়।
     */
    public function approve(Request $request, StockCount $count): RedirectResponse
    {
        $reasonId = $request->validate([
            'reason_code_id' => ['required', 'integer', 'exists:mdm_reason_codes,id'],
        ])['reason_code_id'];

        $this->counts->approve($count, ReasonCode::query()->findOrFail($reasonId));

        return redirect()
            ->route('inventory.count.show', $count)
            ->with('saved', __('inventory::message.count_approved'));
    }

    /**
     * গোনার শিট — কোন পণ্য, আর খাতায় কত।
     *
     * ── ⚠️ খাতার সংখ্যা পর্দায় দেখানো হবে কি না ──────────────────────
     * ⓘ দেখানো হয়, কিন্তু **বাছাই করা যায়** — উপরে একটা সুইচ। ⛔ খাতার
     * সংখ্যা চোখের সামনে থাকলে গণনাকারী প্রায়ই ওটাই লিখে দেন
     * ("blind count" ঠিক এই কারণেই আছে), আর তখন গোনাটা কেবল খাতার
     * একটা প্রতিধ্বনি।
     *
     * ⚠️ তবু ডিফল্টে দেখানো থাকে: ছোট দোকানে একজনই গোনেন আর তিনিই
     * মেলান, আর তাঁর কাছে সংখ্যাটা লুকানো কেবল কাজ বাড়ায়।
     *
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        $warehouses = Warehouse::query()->active()->orderBy('code')->get();

        $warehouse = $warehouses->firstWhere('id', (int) $request->query('warehouse'))
            ?? $warehouses->first();

        $products = Product::query()->active()->with('unit')->orderBy('name_en')->get();

        return [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'products' => $products,

            /*
             * ⓘ খাতার সংখ্যাগুলো একবারে, পণ্য ধরে ধরে নয় — একশো
             * পণ্যের শিটে একশোটা আলাদা প্রশ্ন করলে পাতাটা খুলতই না।
             */
            'bookQty' => $warehouse === null
                ? []
                : $products->mapWithKeys(fn (Product $product) => [
                    $product->id => $this->stock->floorQty($product, $warehouse),
                ])->all(),

            'blind' => $request->boolean('blind'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'pending' => __('inventory::sort.count_pending'),
            'recent' => __('inventory::sort.recent'),
            'oldest' => __('inventory::sort.oldest'),
        ];
    }
}
