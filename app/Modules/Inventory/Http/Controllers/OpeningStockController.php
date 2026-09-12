<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
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
        );

        return back()->with('saved', __('inventory::message.opening_saved', [
            'product' => $product->name(),
            'qty' => Money::format($validated['qty']),
            'value' => Money::format(bcmul((string) $validated['qty'], (string) $validated['unit_cost'], 4)),
        ]));
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
        return Product::query()->active()->orderBy('name_en')->get();
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
            ->where('inv_stock_movements.source_type', OpeningStockService::SOURCE_TYPE);
    }
}
