<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\SalePriceBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মূল্য তালিকা — পণ্যের বিক্রয়-দাম এক পাতায়, প্রতিটা সারি থেকে বদলানো যায়।
 *
 * মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬: "মূল্য নির্ধারণ"-এর এই পাতাটা এতদিন
 * "তৈরি হচ্ছে" দেখাত। ⓘ দেখা বিক্রয়ের আদেশ দেখার চাবিতে (মেনুর সারি আগে
 * থেকেই তাই), বদলানো পণ্য সম্পাদনার বিদ্যমান চাবিতে — দামটা পণ্যেরই ঘর,
 * তাই পণ্যের পর্দায় যিনি বদলাতে পারেন না, এখান থেকেও পারেন না।
 */
final class PriceListController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SalePriceBook $book,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.order.view', only: ['index', 'history']),
            new Middleware('can:inventory.product.update', only: ['update']),
        ];
    }

    public function index(Request $request): View
    {
        $q = $request->string('q')->toString();
        $products = $this->book->list($q);

        return view('sales::price_list.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $products,
            'last' => $this->book->lastChanges($products->items()),
            'q' => $q,
            // ⓘ ক্রয়মূল্য কেবল যাঁর চাবি আছে — বিক্রয়ের অন্য পর্দার নিয়মই
            'showCost' => (bool) $request->user()?->can('sales.cost.view'),
            'canEdit' => (bool) $request->user()?->can('inventory.product.update'),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $request->validate(['sale_price' => ['required', 'numeric', 'min:0']]);

        $this->book->setPrice($product, $request->input('sale_price'));

        return back()->with('saved', __('sales::price_list.saved', ['product' => $product->name()]));
    }

    public function history(Request $request, Product $product): View
    {
        return view('sales::price_list.history', [
            'menu' => $this->menu->forUser($request->user()),
            'product' => $product,
            'rows' => $this->book->history($product),
        ]);
    }
}
