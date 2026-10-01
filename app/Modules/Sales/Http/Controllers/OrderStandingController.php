<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\OrderStanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;

/**
 * অর্ডার পাঠানোর আগের হিসাব আর লাইনের অফার — JSON, ওয়েবের অর্ডার/DO পর্দা আর ফোন একসাথে (১ অক্টোবর ২০২৬)।
 *
 * ⓘ সংখ্যাগুলো একটাই সেবার ([[OrderStanding]]), তাই ওয়েব আর ফোন কখনো দুই রকম বলে না।
 * ⓘ গ্রাহক খোঁজা হয় কোম্পানির দেয়ালের ভেতরে (`Customer::query()`); ফোন পাঠায় `public_id`, ওয়েব ক্রমিক id।
 * ⛔ অর্ডার নেওয়ার চাবি ছাড়া কিছুই নয় — বাকির অঙ্ক সংবেদনশীল।
 */
class OrderStandingController extends Controller implements HasMiddleware
{
    public function __construct(private readonly OrderStanding $standing) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.order.create')];
    }

    /** `GET …/standing/{customer}?total=` */
    public function standing(Request $request, string $customer): JsonResponse
    {
        $total = (string) $request->query('total', '0');

        return response()->json($this->standing->for(
            $this->customer($customer),
            is_numeric($total) ? $total : '0',
        ));
    }

    /** `POST …/offers` — `{customer, warehouse_id?, lines: [{product, qty, rate?}]}` */
    public function offers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.product' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn (array $line) => [
            'product' => $this->product($line['product']),
            'qty' => (string) $line['qty'],
            'rate' => isset($line['rate']) ? (string) $line['rate'] : null,
        ], $data['lines']);

        return response()->json(['lines' => $this->standing->offers(
            $this->customer($data['customer']),
            $lines,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        )]);
    }

    private function customer(string $key): Customer
    {
        return Customer::query()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }

    private function product(string $key): Product
    {
        return Product::query()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }
}
