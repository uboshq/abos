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
        $entered = $this->entered();

        return view('inventory::stock.opening', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'products' => $this->openProducts(),
            'entered' => $entered,
            'total' => $entered->reduce(
                fn (string $sum, object $row) => bcadd($sum, (string) $row->value, 4),
                '0',
            ),
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
     * @return Collection<int, object>
     */
    private function entered()
    {
        return StockMovement::query()
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
            ->join('inv_products', 'inv_products.id', '=', 'inv_stock_movements.product_id')
            ->join('inv_warehouses', 'inv_warehouses.id', '=', 'inv_stock_movements.warehouse_id')
            ->leftJoin('inv_cost_layers', function ($join) {
                $join->on('inv_cost_layers.source_id', '=', 'inv_stock_movements.id')
                    ->where('inv_cost_layers.source_type', '=', OpeningStockService::SOURCE_TYPE);
            })
            ->where('inv_stock_movements.source_type', OpeningStockService::SOURCE_TYPE)
            ->orderByDesc('inv_stock_movements.id')
            ->get();
    }
}
