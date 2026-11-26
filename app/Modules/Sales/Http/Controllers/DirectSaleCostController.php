<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBillLine;
use Illuminate\Http\JsonResponse;

/**
 * ⭐ "এই লাইন"-এর "ক্রয়মূল্য" — পণ্যটার খরচ কোথা থেকে এল (মালিক, ২৬ নভেম্বর ২০২৬): শেষ পাকা ক্রয়ের দর, ছাড়, আনলোডিং খরচ
 * (সারির নিজের ভাগ, না থাকলে বিলের আনলোডিং টাকার অনুপাতে), প্রতি এককে মোট, ফ্রি ধরে প্রতি একক, খাতার এখনকার খরচ, আর নোট।
 *
 * ⛔ কেবল `sales.cost.view`-ওয়ালার জন্য — খরচের সংখ্যা অন্য কারো পর্দায় যায় না (ক্যাটালগের একই নিয়ম, [[DirectSaleOptions]])।
 * ⓘ কিছুই লেখে না; পণ্যটা নিজের কোম্পানির না হলে খুঁজেই পায় না (মডেলের কোম্পানি-সীমা)।
 */
class DirectSaleCostController extends Controller
{
    public function __invoke(int $product): JsonResponse
    {
        abort_unless((bool) auth()->user()?->can('sales.cost.view'), 403);

        $item = Product::query()->findOrFail($product);

        $line = PurchaseBillLine::query()
            ->where('product_id', $item->id)
            ->whereHas('bill', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED))
            ->with(['bill.supplier'])
            ->latest('id')
            ->first();

        $book = (string) ($item->purchase_price ?? '0');

        if ($line === null) {
            return response()->json(['found' => false, 'book' => $book, 'unit' => (string) ($item->unit?->name() ?? '')]);
        }

        $bill = $line->bill;
        $qty = (string) $line->qty;
        $free = (string) ($line->free_qty ?? '0');
        $per = fn (string $v): string => bccomp($qty, '0', 4) > 0 ? bcdiv($v, $qty, 4) : '0';

        // ⓘ আনলোডিং — সারির নিজের ভাগ; না থাকলে বিলের আনলোডিং টাকা সারির টাকার অনুপাতে
        $unload = (string) ($line->unload_cost ?? '0');
        if (bccomp($unload, '0', 4) === 0 && bccomp((string) ($bill->unload_amount ?? '0'), '0', 4) > 0
            && bccomp((string) $bill->subtotal, '0', 4) > 0 && $bill->unload_mode !== 'supplier') {
            $unload = bcdiv(bcmul((string) $bill->unload_amount, (string) $line->amount, 8), (string) $bill->subtotal, 4);
        }

        $rate = (string) $line->rate;
        $discount = $per((string) ($line->discount ?? '0'));
        $unloadEach = $per($unload);
        $total = bcadd(bcsub($rate, $discount, 4), $unloadEach, 4);
        $withFree = bccomp($free, '0', 4) > 0 && bccomp(bcadd($qty, $free, 4), '0', 4) > 0
            ? bcdiv(bcmul($total, $qty, 8), bcadd($qty, $free, 4), 4) : null;

        return response()->json([
            'found' => true,
            'unit' => (string) ($item->unit?->name() ?? ''),
            'rate' => $rate,
            'discount' => $discount,
            'unload' => $unloadEach,
            'total' => $total,
            'qty' => $qty,
            'free' => $free,
            'withFree' => $withFree,
            'book' => $book,
            'billNo' => (string) $bill->document_no,
            'supplierBillNo' => (string) ($bill->supplier_bill_no ?? ''),
            'date' => DateFormat::format($bill->trx_date),
            'supplier' => (string) ($bill->supplier?->name() ?? ''),
            'notes' => array_values(array_filter([(string) ($line->narration ?? ''), (string) ($bill->narration ?? '')])),
        ]);
    }
}
