<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\SalesPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /sales/price-list/quote?customer=` — এই গ্রাহকের প্রতিটা পণ্যের দর আর উৎস ([[SalesPrice]], ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ কাউন্টার গ্রাহক বদলালে এটা ডাকে আর কার্টের আপনা-আপনি দরগুলো বদলায় (`customer-prices.js`); আদেশ ও উদ্ধৃতির
 * সারিও একই উত্তর থেকে দর ভরে। ⚠️ উত্তরটা কেবল দেখানোর — বিলের পাহারা ([[SalesInvoiceService]]) নিজে আবার মাপে।
 */
final class PriceQuoteController extends Controller
{
    /** যে চাবির যেকোনো একটায় বিক্রির কোনো পর্দা খোলে — সেই পর্দার দরই এখানে। */
    private const KEYS = ['sales.challan.create', 'sales.invoice.create', 'sales.order.create', 'sales.quotation.create', 'sales.pos', 'sales.do.create'];

    public function __construct(private readonly SalesPrice $prices) {}

    public function quote(Request $request): JsonResponse
    {
        $this->authorizeQuote($request);
        $request->validate(['customer' => ['nullable', 'integer'], 'date' => ['nullable', 'date']]);

        $customer = $request->filled('customer')
            ? Customer::query()->inViewedBranch()->find((int) $request->query('customer'))
            : null;

        $products = Product::query()->active()->get(['id', 'unit_id', 'sale_price']);

        $prices = [];

        foreach ($this->prices->forMany($customer, $products, $request->query('date')) as $id => $price) {
            $prices[$id] = $price->toArray();
        }

        return response()->json(['prices' => (object) $prices]);
    }

    /** ⛔ বিক্রির কোনো পর্দার চাবি নেই তো ৪০৩ — দর তালিকা ভেতরের কথা। */
    private function authorizeQuote(Request $request): void
    {
        abort_unless((bool) $request->user()?->canAny(self::KEYS), 403);
    }
}
