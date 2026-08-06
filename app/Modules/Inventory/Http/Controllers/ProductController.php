<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\CustomFieldService;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * পণ্যের স্ক্রিন।
 *
 * পণ্যের পাতায় মজুদের চারটা অবস্থা দেখানো হয়, আর নিচে সেই চলাচলগুলো
 * যেগুলো যোগ হয়ে সংখ্যাগুলো হয়েছে — নিয়ম ১। "৪৭ কেন" প্রশ্নের উত্তর
 * এক ক্লিক দূরে থাকা উচিত, দুই পাতা দূরে নয়।
 */
class ProductController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly ProductService $products,
        private readonly StockService $stock,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Product::class, 'product'),
            new Middleware('can:delete,product', only: ['activate']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Product::query()
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active())
            ->with('unit');

        $sort = $this->applySort($query, $request, $this->sorts());

        $products = $query->paginate(50)->withQueryString();

        return view('inventory::product.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $products,
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
            'stock' => $this->stock,
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::product.form', [
            'menu' => $this->menu->forUser($request->user()),
            'product' => new Product(['purchase_price' => 0, 'sale_price' => 0, 'reorder_level' => 0]),
            ...$this->options(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->validated());

        // কোম্পানির নিজের যোগ করা ঘরগুলো — সেবার বাইরে, কারণ সেবা
        // ওগুলোর অস্তিত্বই জানে না
        app(CustomFieldService::class)->save($product, $request->input('custom', []));

        return redirect()
            ->route('inventory.product.show', $product)
            ->with('saved', __('inventory::message.created'));
    }

    public function show(Request $request, Product $product): View
    {
        $movements = StockMovement::query()
            ->forProduct($product->id)
            ->with(['warehouse', 'reasonCode'])
            ->orderByDesc('trx_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory::product.show', [
            'menu' => $this->menu->forUser($request->user()),
            'product' => $product->load(['unit', 'tax']),
            // চারটা অবস্থা একসাথে — একটা কোয়েরিতে
            'states' => $this->stock->statesFor($product),
            'movements' => $movements,
        ]);
    }

    public function edit(Request $request, Product $product): View
    {
        return view('inventory::product.form', [
            'menu' => $this->menu->forUser($request->user()),
            'product' => $product,
            ...$this->options(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->validated());

        app(CustomFieldService::class)->save($product, $request->input('custom', []));

        return redirect()
            ->route('inventory.product.show', $product)
            ->with('saved', __('inventory::message.updated'));
    }

    /** মোছা নয়, নিষ্ক্রিয় করা — নিয়ম ৫। */
    public function destroy(Product $product): RedirectResponse
    {
        $this->products->deactivate($product);

        return redirect()
            ->route('inventory.product.index')
            ->with('saved', __('inventory::message.deactivated'));
    }

    public function activate(Product $product): RedirectResponse
    {
        $this->products->activate($product);

        return redirect()
            ->route('inventory.product.show', $product)
            ->with('saved', __('inventory::message.activated'));
    }

    /**
     * @return array<string, callable(Builder): mixed>
     */
    private function sorts(): array
    {
        return [
            'name' => fn ($q) => $q->orderBy('name_en'),
            'code' => fn ($q) => $q->orderBy('code'),
            'recent' => fn ($q) => $q->orderByDesc('created_at'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'name' => __('inventory::sort.name'),
            'code' => __('inventory::sort.code'),
            'recent' => __('inventory::sort.recent'),
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'units' => Unit::query()->active()->orderBy('code')->get(),
            'taxes' => Tax::query()->active()->orderBy('code')->get(),
            // ব্র্যান্ডের ঘরটা প্রতি-কোম্পানি সুইচে (নিয়ম ৭)
            'brandOn' => $this->settings->enabled('inventory.brand_enabled'),
        ];
    }
}
