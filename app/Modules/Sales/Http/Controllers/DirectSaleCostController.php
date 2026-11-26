<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Contracts\LastPurchaseCost;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ "এই লাইন"-এর "ক্রয়মূল্য" — পণ্যটার খরচ কোথা থেকে এল (মালিক, ২৬ নভেম্বর ২০২৬): শেষ পোস্ট হওয়া ক্রয়ের দর, ছাড়, আনলোডিং খরচ
 * (সারির নিজের ভাগ, না থাকলে বিলের আনলোডিং টাকার অনুপাতে), প্রতি এককে মোট, ফ্রি ধরে প্রতি একক, খাতার এখনকার খরচ, আর নোট।
 *
 * ⛔ কেবল `sales.cost.view`-ওয়ালার জন্য — খরচের সংখ্যা অন্য কারো পর্দায় যায় না (ক্যাটালগের একই নিয়ম, [[DirectSaleOptions]])।
 * ⓘ কিছুই লেখে না; পণ্যটা নিজের কোম্পানির না হলে খুঁজেই পায় না (মডেলের কোম্পানি-সীমা)। ক্রয়ের ভেতরে হাত দেয় না —
 *   ক্রয় নিজের বিল থেকে বলে ([[LastPurchaseCost]]; বিক্রয়ের `depends_on`-এ ক্রয় নেই, ক্রয়ই বিক্রয়কে চেনে)।
 */
final class DirectSaleCostController extends Controller implements HasMiddleware
{
    public function __construct(private readonly LastPurchaseCost $costs) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.cost.view')];
    }

    public function __invoke(int $product): JsonResponse
    {
        $item = Product::query()->findOrFail($product);
        $book = (string) ($item->purchase_price ?? '0');
        $unit = (string) ($item->unit?->name() ?? '');
        $cost = $this->costs->of((int) $item->id);

        if ($cost === null) {
            return response()->json(['found' => false, 'book' => $book, 'unit' => $unit]);
        }

        return response()->json(['found' => true, 'unit' => $unit] + $cost + ['book' => $book]);
    }
}
