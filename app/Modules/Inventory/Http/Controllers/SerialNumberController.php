<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\SerialNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ সিরিয়াল নম্বর — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ এই পর্দার আসল প্রশ্ন একটাই ────────────────────────────────────
 * *"এই নম্বরের পিসটা কোথায়, আর তার ওয়ারেন্টি আছে কি না"* — আর সেটা
 * জিজ্ঞেস করেন কাউন্টারে দাঁড়ানো একজন ক্রেতা, হাতে একটা নষ্ট টিভি
 * নিয়ে। ⚠️ তাই খোঁজার ঘরটাই পর্দার সবচেয়ে উপরে।
 *
 * ── ⛔ এখানে মজুদ নড়ে না ────────────────────────────────────────────
 * ⓘ পরিমাণের হিসাব [[StockService]]-এর। এখানে কেবল **পরিচয়**: কোন
 * পিসটা কোথায়। ⚠️ দুইটা মিশিয়ে ফেললে একই মাল দুইবার গোনা হত।
 */
class SerialNumberController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly SerialNumberService $serials,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.serial.view', only: ['index']),
            new Middleware('can:inventory.serial.manage', only: ['create', 'store']),
        ];
    }

    public function index(Request $request): View
    {
        $query = SerialNumber::query()->with(['product', 'warehouse', 'batch']);

        /*
         * ⭐ নম্বর ধরে খোঁজা — ⚠️ বড়-ছোট হরফ ও ফাঁকা জায়গা বাদ দিয়ে
         * ([[SerialNumber::scopeNumbered()]])। ⓘ কাগজে নম্বর বড় হরফে
         * ছাপা থাকে, আর মানুষ যেভাবে পারেন টাইপ করেন।
         */
        $serial = trim((string) $request->query('serial'));

        if ($serial !== '') {
            $query->numbered($serial);
        }

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('id'),
        ]);

        return view('inventory::serial.index', [
            'menu' => $this->menu->forUser($request->user()),
            'serials' => $query->paginate(50)->withQueryString(),
            'serial' => $serial,
            'sort' => $sort,
            'sortOptions' => [
                'recent' => __('inventory::sort.recent'),
                'oldest' => __('inventory::sort.oldest'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::serial.form', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),

            /*
             * ⓘ কেবল যে পণ্যে সিরিয়াল ধরা হয়। ⛔ সব পণ্য দেখালে
             * মালিকের সিদ্ধান্তটাই (*"পণ্যে একটা টিক"*) অর্থহীন হত,
             * আর চাল-ডালের বস্তার নম্বর চাওয়া হত।
             */
            'products' => Product::query()->active()->where('track_serial', true)
                ->with('unit')->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],

            /*
             * ⭐ নম্বরগুলো এক ঘরে, প্রতি লাইনে একটা।
             *
             * ⛔ একশো পিসের চালানে একশোটা আলাদা ঘর আঁকলে পাতাটাই
             * খুলত না। ⚠️ আর স্ক্যানার সাধারণত প্রতিটা স্ক্যানের পরে
             * একটা নতুন লাইন পাঠায় — অর্থাৎ ঘরটা স্ক্যানারের ভাষাতেই
             * কথা বলে।
             */
            'serials' => ['required', 'string', 'max:20000'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        $made = $this->serials->receive(
            product: $product,
            serials: preg_split('/\r\n|\r|\n/', $data['serials']) ?: [],
            data: [
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'received_on' => $data['received_on'],
            ],
        );

        return redirect()
            ->route('inventory.serial.index')
            ->with('saved', trans_choice('inventory::message.serials_added', count($made), [
                'count' => count($made),
            ]));
    }
}
