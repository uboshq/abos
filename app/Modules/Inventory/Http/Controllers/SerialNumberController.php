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
            new Middleware('can:inventory.serial.manage', only: ['create', 'store', 'issue', 'storeIssue']),
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

    /**
     * ⭐ পিস বেরোনোর পর্দা — ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ ইঞ্জিনটার কোনো দরজাই ছিল না ──────────────────────────────
     * [[SerialNumberService::issue()]] লেখা হয়েছিল ২৪ সেপ্টেম্বরে, আর
     * সেদিন থেকে **একটাও পথ ওটাতে পৌঁছাত না** — না পর্দা, না রুট, না
     * API। ⚠️ ফলে পিস ঢুকত, কোনোদিন বেরোত না, আর প্রতিটা নম্বর চিরকাল
     * `IN_STOCK` হয়ে বসে থাকত।
     *
     * ⓘ ওয়ারেন্টির গোটা প্রশ্নটাই এর উপর দাঁড়ানো: *"এই পিসটা কবে
     * গেল, আর মেয়াদ আছে কি"* — আর বেরোনোর তারিখ ছাড়া ওয়ারেন্টি শুরুই
     * হয় না ([[SerialNumber::underWarranty()]])।
     *
     * ── ⚠️ কেন বিক্রয়ের কাগজ থেকে আপনা-আপনি নয় ─────────────────────
     * ⓘ ওটাই শেষ গন্তব্য, কিন্তু তাতে বিক্রয়ের সারিতে নম্বর লেখার ঘর
     * লাগে — আর ঐ কোড অন্য মডিউলে। ⛔ সেই জোড়াটা না বসা পর্যন্ত
     * ইঞ্জিনটা অচল রাখার কোনো কারণ নেই: এই পর্দা আজ থেকেই প্রশ্নটার
     * উত্তর দিতে পারে, আর কাল বিক্রয় জুড়লে ওটা **এই একই সেবাই** ডাকবে।
     */
    public function issue(Request $request): View
    {
        return view('inventory::serial.issue', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * ⓘ কেবল যে পিসগুলো এখনো গুদামে — ⛔ বেরিয়ে যাওয়া নম্বর
             * তালিকায় রাখলে কেউ ওটা বেছে নিতেন, আর সেবা ব্যতিক্রম
             * ছুড়ত ("এই পিস আগেই বেরিয়ে গেছে")। ⚠️ যে ভুলটা আগেই
             * ঠেকানো যায়, সেটা ব্যতিক্রম দিয়ে ঠেকানো অপচয়।
             */
            'inStock' => SerialNumber::query()
                ->where('status', SerialNumber::IN_STOCK)
                ->with('product')
                ->orderBy('serial_no')
                ->limit(500)
                ->get(),
        ]);
    }

    public function storeIssue(Request $request): RedirectResponse
    {
        $data = $request->validate([
            /* ⓘ গ্রহণের ঘরের মতোই — প্রতি লাইনে একটা নম্বর, স্ক্যানারের ভাষা */
            'serials' => ['required', 'string', 'max:20000'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'sold_to' => ['nullable', 'string', 'max:160'],

            /*
             * ⛔ শূন্য মানে *"ওয়ারেন্টি নেই"*, খালি নয় — ⓘ তাই ঘরটা
             * `nullable` **আর** `integer`, আর সেবা শূন্যে দুইটা তারিখই
             * খালি রাখে।
             */
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        $gone = $this->serials->issue(
            serials: preg_split('/\r\n|\r|\n/', $data['serials']) ?: [],
            data: [
                'issued_on' => $data['issued_on'],
                'sold_to' => $data['sold_to'] ?? null,
                'warranty_months' => $data['warranty_months'] ?? 0,
            ],
        );

        return redirect()
            ->route('inventory.serial.index')
            ->with('saved', trans_choice('inventory::message.serials_issued', count($gone), [
                'count' => count($gone),
            ]));
    }
}
