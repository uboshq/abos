<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\OpeningStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * খোলা মজুদের পর্দা — পুরনো হিসাব থেকে আসার দিনের একবারের কাজ।
 *
 * ── কেন এটার নিজের পর্দা, গণনা ও সমন্বয় নয় ─────────────────────────
 * দুইটা দেখতে এক, কিন্তু হিসাবের দিক থেকে সম্পূর্ণ আলাদা:
 *
 *   গণনা ও সমন্বয় — এই বছরের ঘটনা। তাকে যা নেই তা ঘাটতি, আর ঘাটতি
 *                     এই বছরের **ক্ষতি** (৫১৬০ খাতে)।
 *   খোলা মজুদ     — আগের ব্যবসার ফল, নতুন খাতায় তোলা। কোনো ক্ষতি বা
 *                     আয় নয়, সরাসরি **অবশিষ্ট মুনাফা**।
 *
 * একই পর্দায় দুইটা করলে একদিন কেউ শুরুর দিনের আট লাখ টাকার মালকে
 * "উদ্বৃত্ত" লিখে ফেলত, আর প্রথম মাসেই আট লাখ টাকার ভুল মুনাফা দেখাত —
 * যার উপর কর বসত।
 */
class OpeningStockController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly OpeningStockService $opening,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.stock.opening')];
    }

    public function index(Request $request): View
    {
        return view('inventory::stock.opening', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),

            /*
             * ⓘ বাছার ঘরের পণ্যগুলো — পাতা ভাগ হয় না, আর হওয়ার কথাও নয়।
             * এটা একটা `<select>`-এর বিকল্প তালিকা, পর্দার তালিকা নয়;
             * ড্রপডাউনের অর্ধেক পণ্য লুকিয়ে দিলে বাকিগুলো বসানোই যেত না।
             */
            'products' => $this->openProducts(),

            // ⭐ মালটা কার — সরবরাহকারী/প্রিন্সিপাল, ঐচ্ছিক (৬ অক্টোবর ২০২৬)
            'suppliers' => self::suppliers(),

            'entered' => $this->entered(),

            /*
             * মোট মূল্য নিজের কোয়েরিতে, তালিকা থেকে নয়।
             *
             * আগে এটা `$entered->reduce(...)` ছিল — তালিকাটা পুরোটা আসত
             * বলে যোগফলও পুরোটার হত। পাতা ভাগ বসার পর ওই লেখাটা চুপচাপ
             * **এই পাতার** যোগফল হয়ে যেত, অথচ শিরোনামে লেখা থাকত "মোট
             * খোলা মজুদের মূল্য"। আর এই সংখ্যাটা শুরুর দিনের অবশিষ্ট
             * মুনাফায় বসে — ভুল হলে সেটা খাতার ভুল, পর্দার নয়।
             */
            'total' => $this->enteredTotal(),
        ]);
    }

    /**
     * এই কোম্পানির সরবরাহকারী — নাম, সংক্ষিপ্ত নাম আগে।
     *
     * ⓘ সরাসরি টেবিল থেকে, মডেল ছাড়া: মজুদ মডিউল সরবরাহকারী মডিউলের উপর নির্ভর করে না, তাই তার ক্লাস ডাকে না।
     *
     * @return array<int, string>
     */
    public static function suppliers(): array
    {
        $bn = app()->getLocale() === 'bn';

        /*
         * ⭐ কেবল এই শাখার প্রিন্সিপাল — মালিক, ৭ অক্টোবর ২০২৬ (ছবি: লায়নের সারিতে "Star Line (Super)" আর
         * পরিবহনের "Hirra Miya"): *"প্রিন্সিপাল এক ব্রাঞ্চের টা অন্য ব্রাঞ্চে দেখায়, ট্রান্সপোর্টার প্রিন্সিপালে দেখায়"*।
         * ⓘ খোলা মজুদের প্রিন্সিপাল মানে মালের কারখানা — তাই সেবাদাতা (পরিবহন, হাম্মালি…) নয়: ধরন VENDOR, বা
         * ধরন বসানো নেই ([[Supplier::scopeOnlySuppliers()]]-এর একই নিয়ম; মজুদ সরবরাহকারী মডিউলের ক্লাস ডাকে না, তাই এখানে
         * কাঁচা)। ⛔ চালু কেবল; আর হেডারে বাছা শাখার, নয়তো শাখাহীন ([[ViewedBranch::narrow()]]); "সব শাখা"-য় নাগালের সব।
         */
        $query = DB::table('suppliers')
            ->leftJoin('mdm_party_types as pt', 'pt.id', '=', 'suppliers.party_type_id')
            ->where('suppliers.company_id', CompanyContext::id())
            ->whereNull('suppliers.deleted_at')
            ->where('suppliers.is_active', true)
            ->where(fn ($q) => $q->whereNull('suppliers.party_type_id')->orWhere('pt.code', 'VENDOR'));

        return \App\Core\Support\ViewedBranch::narrow($query, 'suppliers.branch_id')
            ->orderBy('suppliers.name_en')
            ->get(['suppliers.id', 'suppliers.short_name', 'suppliers.name_en', 'suppliers.name_bn'])
            ->mapWithKeys(fn ($s) => [(int) $s->id => filled($s->short_name) ? (string) $s->short_name
                : ($bn && filled($s->name_bn) ? (string) $s->name_bn : (string) $s->name_en)])
            ->all();
    }

    /**
     * ⭐ খোলা মজুদের কার্ট — এক চাপে অনেক সারি (মালিক, ৬ অক্টোবর ২০২৬: *"পাশাপাশি করে দিলে হতো না"*)।
     *
     * ⓘ উপরে একবার গুদাম, তারিখ, বিবরণ; নিচে প্রতি সারি এক পণ্য। পণ্যের ঘরে লেখা থাকে "কোড — নাম" (ব্রাউজারের তালিকা
     * থেকে বাছা), সার্ভার কোড ধরে চেনে। খালি সারি বাদ। ⛔ সব সারি এক লেনদেনে ([[OpeningStockService::bringInMany()]]) —
     * একটা ভুল হলে কিছুই বসে না, আর ভুলটা তার সারির নামে ফেরে।
     */
    public function storeMany(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $head = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'max:500'],
        ]);

        $rows = [];
        $unknown = [];

        foreach ((array) $request->input('rows', []) as $i => $row) {
            $typed = trim((string) ($row['product'] ?? ''));
            $qty = trim((string) ($row['qty'] ?? ''));

            // ⓘ পুরো খালি সারি — বাদ
            if ($typed === '' && $qty === '') {
                continue;
            }

            // ⭐ সার্চ বাক্স থেকে বাছা হলে id আসে; হাতে লেখা হলে কোড বা বারকোড ধরে চেনা
            $product = filled($row['product_id'] ?? null) && ctype_digit((string) $row['product_id'])
                ? Product::query()->soldInViewedBranch()->whereKey((int) $row['product_id'])->first()
                : $this->productFromTyped($typed);

            if ($product === null) {
                $unknown["rows.{$i}.product"] = [__('inventory::message.opening_unknown_product')];

                continue;
            }

            $rows[(int) $i] = [
                'product_id' => $product->id,
                'qty' => $qty === '' ? '0' : $qty,
                'unit_cost' => trim((string) ($row['unit_cost'] ?? '')) ?: '0',
                'batch_no' => $row['batch_no'] ?? null,
                'expiry_date' => $row['expiry_date'] ?? null,
                'supplier_id' => $row['supplier_id'] ?? null,
                // ⭐ ফ্রি আর দাম — মালিক, ৬ অক্টোবর ২০২৬ ("Quantity * Free", "Rate, Markup, Margin, Sales price")
                'free_qty' => $row['free_qty'] ?? null,
                'sales_price' => $row['sales_price'] ?? null,
                'pricing_anchor' => $row['pricing_anchor'] ?? null,
                'pricing_pct' => $row['pricing_pct'] ?? null,
            ];
        }

        if ($unknown !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($unknown);
        }

        $warehouse = Warehouse::query()->findOrFail($head['warehouse_id']);

        try {
            $movements = $this->opening->bringInMany($warehouse, array_values($rows), $head['trx_date'] ?? null, $head['narration'] ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // ⓘ সেবা সারি গোনে ০ থেকে, খালি সারি বাদ দিয়ে — পর্দার সারির নম্বরে ফেরানো, যাতে ঠিক সেই সারিটা লাল হয়
            $keys = array_keys($rows);
            $mapped = [];

            foreach ($e->errors() as $field => $messages) {
                $mapped[preg_replace_callback('/^rows\.(\d+)\./', fn ($m) => 'rows.'.($keys[(int) $m[1]] ?? $m[1]).'.', $field)] = $messages;
            }

            throw \Illuminate\Validation\ValidationException::withMessages($mapped);
        }

        $value = collect($rows)->reduce(fn (string $sum, array $r) => bcadd($sum, bcmul((string) $r['qty'], (string) $r['unit_cost'], 4), 4), '0');

        return redirect()->route('inventory.stock.opening')->with('saved', __('inventory::message.opening_cart_saved', [
            'count' => count($movements),
            'value' => Money::format($value),
        ]));
    }

    /** "কোড — নাম — বারকোড" বা কেবল কোড/বারকোড — এই কোম্পানির, এই শাখায় বিক্রি হওয়া পণ্য */
    private function productFromTyped(string $typed): ?Product
    {
        if ($typed === '') {
            return null;
        }

        $code = trim(explode(' — ', $typed)[0]);

        return Product::query()->soldInViewedBranch()
            ->where(fn ($q) => $q->where('code', $code)->orWhere('barcode', $code))
            ->first();
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $validated = $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'qty' => ['required', 'numeric', 'gt:0'],

            /*
             * দর এখানে required — সমন্বয়ের পর্দায় যেটা ঐচ্ছিক।
             *
             * ওখানে ঐচ্ছিক, কারণ ঘাটতির দাম মালের নিজের চালান থেকে আসে।
             * এখানে চালান বলে কিছু নেই — শুরুর দিনের মালের আগে কোনো
             * কাগজ নেই, তাই দরটা মানুষকেই বলতে হয়।
             */
            'unit_cost' => ['required', 'numeric', 'gt:0'],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * ⓘ লট — ঐচ্ছিক এখানে, বাধ্যতামূলক সেবায়।
             *
             * ⚠️ পর্দায় `required` বসালে নিয়মটা **পর্দার** হয়ে যেত, আর
             * শুরুর মজুদ আসে দুই পথে: এই ফর্ম আর এক্সেল আমদানি
             * ([[OpeningStockImporter]])। ⛔ দেয়াল পর্দায় থাকলে দ্বিতীয়
             * পথটা একটা ফাঁক — আর ঠিক ঐ পথেই হাজার সারি একসাথে ঢোকে।
             */
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],

            // ⭐ মালটা কার — ঐচ্ছিক; এই কোম্পানির সরবরাহকারী ([[OpeningPrincipalController]]-এ পরেও বসানো যায়)
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);

        $this->opening->bringIn(
            product: $product,
            warehouse: $warehouse,
            qty: (string) $validated['qty'],
            unitCost: (string) $validated['unit_cost'],
            date: $validated['trx_date'] ?? null,
            narration: $validated['narration'] ?? null,
            batch: $this->lotFor($product, $validated),
            supplierId: isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
        );

        return back()->with('saved', __('inventory::message.opening_saved', [
            'product' => $product->name(),
            // ⛔ পরিমাণ, টাকা নয় — দুই ঘরে গোল হত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⓘ১৪); মূল্যটা টাকা, তাই format-ই
            'qty' => Money::quantity($validated['qty']),
            'value' => Money::format(bcmul((string) $validated['qty'], (string) $validated['unit_cost'], 4)),
        ]));
    }

    /**
     * এই লাইনের লট — লট ধরা পণ্য না হলে কিছুই না।
     *
     * ── ⚠️ কেন লট ধরা না হলে নম্বরটা ফেলে দেওয়া হয় ──────────────────
     * ⓘ চাল-ডাল-সাবানে লট নেই। ⛔ কেউ ভুল করে ঘরটা ভরে ফেললে ওখানে
     * একটা লট জন্মাত যার কোনো অর্থ নেই, আর রিকলের খাতায় একটা মিথ্যা
     * সারি বসত। ⚠️ পণ্যটা কী বলছে সেটাই শেষ কথা, ঘরটা কী পেয়েছে তা নয়।
     *
     * @param  array<string, mixed>  $validated
     */
    private function lotFor(Product $product, array $validated): ?Batch
    {
        if (! $product->track_batch) {
            return null;
        }

        return app(BatchService::class)->receive(
            product: $product,
            batchNo: (string) ($validated['batch_no'] ?? ''),
            expiry: $validated['expiry_date'] ?? null,
        );
    }

    /**
     * যেসব পণ্য-গুদাম জোড়ায় খোলা মজুদ এখনো বসানো যায়।
     *
     * তালিকাটা সার্ভার থেকেই আসে, ব্রাউজারে ছাঁকা হয় না — নইলে যে জোড়া
     * বসানো যায় না সেটাও পছন্দ করা যেত, আর ভুলটা ধরা পড়ত সেভ করার পর।
     *
     * @return Collection<int, Product>
     */
    private function openProducts()
    {
        return Product::query()->soldInViewedBranch()->active()->orderBy('name_en')->get();
    }

    /**
     * যা ইতিমধ্যে বসানো হয়েছে — পণ্য, গুদাম, পরিমাণ ও মূল্য।
     *
     * মূল্যটা স্তর থেকে গোনা হয়, আলাদা করে কোথাও জমা রাখা নয় — দুই
     * জায়গায় একই সংখ্যা রাখলে একদিন আলাদা হবেই।
     *
     * ── কেন পাতা ভাগ ────────────────────────────────────────────────
     * "একবারের কাজ" বলে সারির সংখ্যা ছোট মনে হয়, কিন্তু সারি বসে
     * **পণ্য × গুদাম** ধরে। পাঁচ হাজার পণ্যের একটা দোকান তিনটা গুদামে
     * খোলা মজুদ তুললে এই তালিকাটাই পনেরো হাজার সারি — আর তখন ঠিক
     * সেদিনই পর্দাটা মরত, যেদিন ব্যবহারকারী সিস্টেমটা প্রথম চালু করছেন।
     */
    private function entered()
    {
        return $this->enteredQuery()
            ->select([
                'inv_stock_movements.id',
                'inv_stock_movements.trx_date',
                'inv_products.name_en',
                'inv_products.name_bn',
                'inv_products.code as product_code',
                'inv_warehouses.name_en as warehouse_en',
                'inv_warehouses.name_bn as warehouse_bn',
                'inv_stock_movements.floor_change as qty',
                DB::raw('COALESCE(inv_cost_layers.qty_in * inv_cost_layers.unit_cost, 0) as value'),
                'inv_cost_layers.unit_cost',
            ])
            ->orderByDesc('inv_stock_movements.id')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * বসানো সবটার মোট মূল্য — সারি না এনে, ডাটাবেজেই যোগ করা।
     *
     * ⚠️ যোগটা SQL-এ, PHP-তে নয়, আর সেটা ইচ্ছাকৃত: PHP-তে করতে হলে
     * পনেরো হাজার সারি মেমরিতে তুলতে হত — ঠিক যেটা এড়াতে পাতা ভাগ
     * বসানো হয়েছে। মোট দেখানোর জন্য পুরো তালিকা তোলা মানে পাতা ভাগটা
     * কেবল চোখের, মেশিনের নয়।
     *
     * ⓘ ঘরগুলো DECIMAL, তাই SUM নির্ভুল — float-এর গোলমাল ঢোকে না,
     * আর `bcadd`-এর সাথে ফলটা চার দশমিকেই মেলে।
     */
    private function enteredTotal(): string
    {
        $sum = $this->enteredQuery()
            ->selectRaw('COALESCE(SUM(COALESCE(inv_cost_layers.qty_in * inv_cost_layers.unit_cost, 0)), 0) as total')
            ->value('total');

        return bcadd((string) ($sum ?? '0'), '0', 4);
    }

    /**
     * তালিকা আর যোগফলের সাধারণ ভিত্তি — একই join, একই ছাঁকনি।
     *
     * দুই জায়গায় আলাদা করে লিখলে একদিন একটায় শর্ত যোগ হত অন্যটায় নয়,
     * আর তখন উপরের "মোট" নিচের সারিগুলোর সাথে মিলত না — যে অমিলটা
     * ধরার একমাত্র উপায় হত হাতে যোগ করা।
     *
     * ⚠️ কলামের তালিকাটা এখানে **নেই**, ইচ্ছাকৃতভাবে। ডাকা দুই পক্ষ
     * দুই রকম কলাম চায় — একজন সারি, অন্যজন একটা যোগফল — আর এখানে
     * `select()` বসালে যোগফলের কোয়েরিতে ওই কলামগুলোও থেকে যেত: GROUP BY
     * ছাড়া সংগ্রহের পাশে সাধারণ কলাম, যেটা কড়া MySQL সোজা খারিজ করে।
     */
    private function enteredQuery()
    {
        return StockMovement::query()
            ->join('inv_products', 'inv_products.id', '=', 'inv_stock_movements.product_id')
            ->join('inv_warehouses', 'inv_warehouses.id', '=', 'inv_stock_movements.warehouse_id')
            ->leftJoin('inv_cost_layers', function ($join) {
                $join->on('inv_cost_layers.source_id', '=', 'inv_stock_movements.id')
                    ->where('inv_cost_layers.source_type', '=', OpeningStockService::SOURCE_TYPE);
            })
            ->where('inv_stock_movements.source_type', OpeningStockService::SOURCE_TYPE)
            // ⭐ সংশোধিত বা মুছে ফেলা সারি তালিকায় নয় — তার জায়গায় নতুন সারি (৬ অক্টোবর ২০২৬)
            ->whereNotExists(fn ($q) => OpeningStockService::correctionOf($q, 'inv_stock_movements.id'));
    }
}
