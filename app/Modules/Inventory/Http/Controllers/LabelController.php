<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * পণ্যের লেবেল — নিজের ছাপা।
 *
 * ── কেন নিজের লেবেল লাগে ────────────────────────────────────────────
 * ডিপোর অনেক পণ্যে গায়ে কোনো বারকোড থাকেই না — খোলা চাল, নিজের প্যাক
 * করা মশলা, বা এমন ব্র্যান্ড যারা ছাপায় না। কাউন্টারে ওগুলো প্রতিবার
 * নাম লিখে খুঁজতে হয়, আর নামের বানানে একটু এদিক-ওদিক হলেই ভুল পণ্য
 * বেরোয়। নিজের ছাপা লেবেল ওই খোঁজাটাই তুলে দেয়।
 *
 * ── কোন সংখ্যাটা দাগে বসে ───────────────────────────────────────────
 * পণ্যের নিজের বারকোড থাকলে সেটাই — গায়ে ছাপা নম্বরের সাথে আমাদের
 * ছাপা লেবেল আলাদা হলে দুইটা স্ক্যানে দুই উত্তর আসত। না থাকলে পণ্যের
 * কোড (`PRD-0004`), আর খোঁজার ঘর ওই দুইটাই মেলায়, তাই স্ক্যান করলে
 * পণ্যটা ঠিকই বেরোয়।
 */
class LabelController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * পণ্য দেখার চাবিই যথেষ্ট।
         *
         * লেবেলে নতুন কোনো তথ্য নেই — নাম, কোড, আর (চাইলে) দাম, তিনটাই
         * পণ্যের পাতায় দেখা যায়। আলাদা চাবি রাখলে গুদামের যে লোক
         * লেবেল সাঁটেন তিনি ছাপাতে পারতেন না, অথচ ওটাই তাঁর কাজ।
         */
        return [new Middleware('can:inventory.product.view')];
    }

    public function index(Request $request): View
    {
        return view('inventory::label.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
        ]);
    }

    public function print(Request $request): Response
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1'],
            'products.*' => ['integer'],

            /*
             * কপির সংখ্যা — একটা করে নয়।
             *
             * এক পণ্যের ত্রিশ কার্টন এলে ত্রিশটা লেবেল লাগে। সীমাটা
             * ২০০, কারণ তার বেশি চাইলে সেটা প্রায় সবসময় টাইপো, আর
             * একটা ভুল সংখ্যায় গোটা রোল ছাপা হয়ে যেত।
             */
            'copies' => ['nullable', 'integer', 'min:1', 'max:200'],
            'price' => ['nullable', 'boolean'],
            'paper' => ['nullable', 'string'],
        ]);

        $copies = (int) ($data['copies'] ?? 1);
        $withPrice = (bool) ($data['price'] ?? false);

        $paper = in_array($data['paper'] ?? '', PaperSize::all(), true)
            ? $data['paper']
            : PaperSize::A4;

        $labels = $this->labelsFor($data['products'], $copies, $withPrice);

        abort_if($labels->isEmpty(), 404);

        $pdf = $this->print->render(
            template: 'print.labels',
            data: ['labels' => $labels],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="labels.pdf"',
        ]);
    }

    /**
     * প্রতিটা পণ্যের জন্য কয়টা করে ঘর।
     *
     * ── কেন বাংলা নাম দাগে যায় না ───────────────────────────────────
     * Code 128 কেবল ASCII বইতে পারে। নাম পাঠালে বাংলা অক্ষরে সেবা
     * থেমে যেত — আর সেটাই ঠিক, কারণ নীরবে অক্ষর বাদ দিলে স্ক্যান করে
     * অন্য পণ্য বেরোত। দাগে যায় কোড, চোখে পড়ে নাম।
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{name: string, payload: string, price: ?string}>
     */
    private function labelsFor(array $ids, int $copies, bool $withPrice): Collection
    {
        $products = Product::query()->whereIn('id', $ids)->orderBy('name_en')->get();

        $labels = collect();

        foreach ($products as $product) {
            $payload = trim((string) ($product->barcode ?: $product->code));

            if ($payload === '') {
                continue;
            }

            for ($copy = 0; $copy < $copies; $copy++) {
                $labels->push([
                    'name' => $product->name(),
                    'payload' => $payload,
                    'price' => $withPrice ? (string) $product->sale_price : null,
                ]);
            }
        }

        return $labels;
    }
}
