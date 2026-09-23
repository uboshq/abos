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
use App\Modules\Inventory\Services\StrandedStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * লট বসানোর পর্দা — যে মাল তাকে আছে অথচ বেচা যায় না।
 *
 * ── ⓘ কেন এই পর্দাটা লাগল ───────────────────────────────────────────
 * একটা পণ্যে লট ধরা চালু করার মুহূর্তে তার আগেকার মজুদ **অদৃশ্য** হয়ে
 * যায় — মজুদের সংখ্যায় থাকে, বিক্রয়ের বাছাইয়ে থাকে না। ⚠️ পর্দায় ১২০
 * পিস দেখা যায় অথচ বিল হয় না, আর দোকানি ধরে নেন হিসাবই ভুল।
 *
 * ⛔ ত্রুটিবার্তাটা এতদিন খোলা মজুদের পর্দায় পাঠাত, যেখানে লটের ঘরই
 * নেই আর যেটা চলাচল থাকলে কিছু নেয়ই না। ⓘ এখন বার্তাটা এখানে পাঠায়।
 *
 * ── ⚠️ কেন তালিকাটা ফর্মের পাশে ─────────────────────────────────────
 * ⓘ আটকে থাকা মাল একটা-দুইটা নয় — লট চালু করার দিন **গোটা পণ্যতালিকাটা**
 * একসাথে আটকায়। ⛔ কোনটা বাকি তা না দেখালে কাজটা শেষ হয়েছে কি না বলার
 * উপায় থাকত না, আর একটা পণ্য বাদ পড়লে সেটা ধরা পড়ত মাস দুয়েক পরে,
 * কাউন্টারে।
 */
class StrandedStockController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly StrandedStock $stranded,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.stock.lot')];
    }

    public function index(Request $request): View
    {
        return view('inventory::stock.lot-assign', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'products' => Product::query()->active()->where('track_batch', true)->orderBy('name_en')->get(),
            'waiting' => $this->waiting(),
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
            'batch_no' => ['required', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],
            'manufactured_on' => ['nullable', 'date', 'before_or_equal:today'],
            'qty' => ['required', 'numeric', 'gte:0'],
            'free_qty' => ['nullable', 'numeric', 'gte:0'],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);

        /*
         * ⚠️ লটটা থাকলে সেটাই, নাহলে নতুন — `firstOrCreate`, `create` নয়।
         *
         * ⓘ একই লট নম্বর দুই গুদামের মালে বসে, আর বসাটা দুই দিনে হয়।
         * ⛔ প্রতিবার নতুন সারি বানালে দ্বিতীয়বারেই ইউনিক সূচকটা ভাঙত
         * (কোম্পানি · পণ্য · লট নম্বর), আর ব্যবহারকারী একটা ডাটাবেজ
         * ত্রুটি দেখতেন যার মানে তাঁর কাছে কিছুই নয়।
         */
        $batch = Batch::query()->firstOrCreate(
            ['product_id' => $product->id, 'batch_no' => $validated['batch_no']],
            [
                'expiry_date' => $validated['expiry_date'] ?? null,
                'manufactured_on' => $validated['manufactured_on'] ?? null,
            ],
        );

        $qty = (string) $validated['qty'];
        $free = (string) ($validated['free_qty'] ?? '0');

        $this->stranded->giveItALot(
            product: $product,
            warehouse: $warehouse,
            batch: $batch,
            qty: $qty,
            freeQty: $free,
            date: $validated['trx_date'] ?? null,
            narration: $validated['narration'] ?? null,
        );

        return back()->with('saved', __('inventory::message.lot_assigned', [
            'product' => $product->name(),
            'qty' => Money::format(bcadd($qty, $free, 4)),
            'lot' => $batch->batch_no,
        ]));
    }

    /**
     * কোন পণ্যের কোন গুদামে কতটা মাল এখনো লট ছাড়া।
     *
     * ── ⚠️ কেন কেবল লট-ধরা পণ্য ─────────────────────────────────────
     * ⓘ চাল-ডাল-সাবানের সারিও লট ছাড়া, আর চিরকাল থাকবে — ওদের লট ধরাই
     * হয় না। ⛔ তালিকায় ওগুলোও দেখালে আসল আটকে থাকা মাল ওদের ভিড়ে
     * হারাত, আর তালিকাটা কোনোদিন খালি হত না। ⓘ খালি হওয়াটাই এখানে
     * "কাজ শেষ"-এর একমাত্র চিহ্ন।
     *
     * ── ⚠️ যোগফলটা `HAVING`-এ, `WHERE`-এ নয় ─────────────────────────
     * ⓘ প্রতিটা সারি আলাদা করে দেখলে কিছুই বলা যায় না — লট ধরা শুরুর
     * আগে বেরোনো মালের সারিগুলোও লট ছাড়া, আর সেগুলো ঋণাত্মক। ⛔ কেবল
     * ধনাত্মক সারি গুনলে অনেক আগেই বিক্রি হয়ে যাওয়া মালও আটকে আছে বলে
     * দেখাত।
     */
    private function waiting()
    {
        return StockMovement::query()
            ->join('inv_products', 'inv_products.id', '=', 'inv_stock_movements.product_id')
            ->join('inv_warehouses', 'inv_warehouses.id', '=', 'inv_stock_movements.warehouse_id')
            ->whereNull('inv_stock_movements.batch_id')
            ->where('inv_products.track_batch', true)
            /*
             * ⚠️ প্রতিটা সাধারণ কলাম `GROUP BY`-তেও আছে, ইচ্ছাকৃতভাবে।
             *
             * ⓘ লাইভের MySQL `ONLY_FULL_GROUP_BY` মোডে চলে: সংগ্রহের
             * পাশে দলবিহীন একটা কলাম থাকলেই সে কোয়েরিটা খারিজ করে।
             * ⛔ এখানে ঢিল দিলে পর্দাটা স্থানীয়ভাবে খুলত আর লাইভে ৫০০
             * দিত — আর ঠিক সেই ভুলটাই এই খাতায় আগে হয়েছে।
             */
            ->groupBy(
                'inv_stock_movements.product_id',
                'inv_stock_movements.warehouse_id',
                'inv_products.code',
                'inv_products.name_en',
                'inv_products.name_bn',
                'inv_warehouses.name_en',
                'inv_warehouses.name_bn',
            )
            ->havingRaw('SUM(inv_stock_movements.floor_change) + SUM(inv_stock_movements.free_change) > 0')
            ->select([
                'inv_stock_movements.product_id',
                'inv_stock_movements.warehouse_id',
                'inv_products.code as product_code',
                'inv_products.name_en',
                'inv_products.name_bn',
                'inv_warehouses.name_en as warehouse_en',
                'inv_warehouses.name_bn as warehouse_bn',
                DB::raw('SUM(inv_stock_movements.floor_change) as qty'),
                DB::raw('SUM(inv_stock_movements.free_change) as free_qty'),
            ])
            ->orderBy('inv_products.name_en')
            ->paginate(50)
            ->withQueryString();
    }
}
