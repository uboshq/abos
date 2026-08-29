<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Services\DirectSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * সরাসরি বিক্রয়ের পর্দা — নমুনা অনুযায়ী চারটা অংশ।
 *
 *     ১. এন্ট্রি স্ট্রিপ  — পণ্য খোঁজা, লাইভ মজুদ, পরিমাণ ও দর, কার্টে যোগ
 *     ২. ডকুমেন্ট হেডার  — গুদাম, তারিখ, বাকির মেয়াদ, DO নম্বর, ক্রেতা
 *     ৩. কার্ট           — পণ্যের সারি, আর তার নিচে আলাদা উপহারের সারি
 *     ৪. টোটাল প্যানেল   — মোট থেকে বকেয়া পর্যন্ত, নমুনার ক্রমেই
 *
 * ── কেন পুরো তালিকা পাতার সাথে ───────────────────────────────────────
 * POS-এর মতোই: প্রতিটা অক্ষরে সার্ভারে গেলে কাউন্টারে দেরি হয়, আর ইন্টারনেট
 * গেলে বিক্রিই বন্ধ। এখানে প্রতিটা পণ্যের সাথে ছয়টা মজুদ সংখ্যাও যায়,
 * কারণ নমুনা দাবি করে সেগুলো পণ্য বাছার সাথে সাথেই দেখা যাবে।
 */
class DirectSaleController extends Controller implements HasMiddleware
{
    /** এর বেশি পণ্য হলে পাতার সাথে পাঠানো বন্ধ, সার্ভারে খোঁজা শুরু। */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly DirectSaleService $sales,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly RecipeService $recipes,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    public function create(Request $request): View
    {
        $warehouse = $this->warehouse($request);
        /*
         * withOutstanding() — নিচের customerTerms-এর জন্য, আর কারণটা গোনার।
         *
         * প্রতিটা গ্রাহকের বকেয়া ওখানে চাওয়া হয়। স্কোপটা না দিলে
         * outstanding() নিজে থেকে খাতা খুঁজত — গ্রাহকপ্রতি একটা কোয়েরি।
         * ছয়জনের ডেমোতে সেটা চোখে পড়ে না, তিন হাজার গ্রাহকের ডিপোতে
         * কাউন্টারের পাতা খোলা মানেই তিন হাজার কোয়েরি।
         */
        $customers = Customer::query()->active()->withOutstanding()->orderBy('name_en')->get();

        return view('sales::direct.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),

            /*
             * চার্ট / বাল্ক DO-র শীটের জন্য — আসল পণ্য ও তাদের মজুদ।
             *
             * ── কেন উপরের `catalogue()`-টা এখানে চলে না ──────────────
             * ওটা কাউন্টারের স্ট্রিপের জন্য বানানো সাদামাটা অবজেক্ট,
             * আর শীটটা মডেল চায় (`$product->name()`, `unit`,
             * `sale_price`)। দুইটার একটাকে অন্যটার মতো সাজানোর চেয়ে
             * দুইটাই নিজের জিনিস পাঠানো সস্তা — আর শীটটা চালানের
             * ফর্মেও ঠিক এই দুইটাই পায়, তাই একই কম্পোনেন্ট দুই পর্দায়
             * একই আচরণ করে।
             */
            'sheetProducts' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
            'sheetStock' => app(StockService::class)->statesForAll($warehouse),
            'customers' => $customers,
            'customerTerms' => $customers->mapWithKeys(fn (Customer $c) => [$c->id => [
                'limit' => (float) $c->credit_limit,
                'due' => (float) $c->outstanding(),
                'days' => (int) $c->credit_days,
            ]]),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'walkinId' => (int) $this->settings->get('sales.walkin_customer_id', 0),

            /*
             * ঘরগুলো কোম্পানি চাইলে বন্ধ করতে পারে (নিয়ম ৭)।
             *
             * DMS-এ প্রতিটা ঘরের নিজের সুইচ আছে, আর কারণটা বাস্তব: যে
             * ডিপো ভ্যাট দেয় না তার কাগজে ভ্যাটের সারি থাকলে প্রতিবার
             * শূন্য দেখে চোখ সরাতে হয়, আর একদিন ভুল ঘরে টাকা বসে।
             */
            'show' => [
                'free_qty' => $this->settings->get('sales.field_free_qty', true),
                'gift' => $this->settings->get('sales.field_gift', true),
                'line_discount' => $this->settings->get('sales.field_line_discount', true),
                'expense' => $this->settings->get('sales.field_expense', true),
                'rounding' => $this->settings->get('sales.field_rounding', true),
                'do_no' => $this->settings->get('sales.field_do_no', true),
                'deposit' => $this->settings->get('sales.field_deposit', true),
                'transport' => $this->settings->get('sales.field_transport', true),
                'shipment' => $this->settings->get('sales.field_shipment', true),
                'credit_limit' => $this->settings->get('sales.field_credit_limit', true),
                'vat' => $this->settings->get('master_data.tax_enabled', true),
                'warehouse_select' => $this->settings->get('sales.field_warehouse_select', true),
                'sub_total' => $this->settings->get('sales.field_sub_total', true),
                'total_item' => $this->settings->get('sales.field_total_item', true),
                'sales_qty' => $this->settings->get('sales.field_sales_qty', true),
                'free_qty_total' => $this->settings->get('sales.field_free_qty_total', true),
                'total_qty' => $this->settings->get('sales.field_total_qty', true),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date'],
            'do_no' => ['nullable', 'string', 'max:64'],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'credit_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'expense_amount' => ['nullable', 'numeric', 'min:0'],
            /*
             * খরচটা কীসের — টাকার অঙ্কের পাশে বাধ্যতামূলক নয়, কিন্তু
             * থাকলে কাগজে ছাপা হয়।
             *
             * ── কেন অঙ্ক থাকলে কারণটাও চাওয়া হয় ──────────────────────
             * "খরচ ২০০" এক মাস পরে কারও কোনো কাজে আসে না। ওটা ভাড়া ছিল,
             * না হাম্মালি, না নাশতা — জানার একমাত্র সময় এখনই, যখন যিনি
             * টাকাটা দিয়েছেন তিনি সামনেই দাঁড়ানো।
             */
            'expense_narration' => ['nullable', 'string', 'max:191',
                'required_with:expense_amount'],

            /*
             * পরিবহন — গাড়ি ও চালক আগে থেকেই ছিল, ভাড়াটা ছিল না।
             *
             * ভাড়াটা খরচের ঘরে ঢুকিয়ে দেওয়া যেত, কিন্তু তাতে "এই চালানে
             * পরিবহনে কত গেল" প্রশ্নের উত্তর আর আলাদা করে পাওয়া যেত না —
             * আর রুটপ্রতি খরচ বের করার একমাত্র উপায় ওটাই।
             */
            'carrier_name' => ['nullable', 'string', 'max:191'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],

            /*
             * চালানটা কোথায় যাচ্ছে।
             *
             * গ্রাহকের ঠিকানা মাস্টারে আছে, কিন্তু মাল সবসময় সেখানে যায়
             * না — দোকান এক জায়গায়, গুদাম আরেক জায়গায়, আর মাঝে মাঝে
             * সরাসরি বাজারে। কাগজে ভুল ঠিকানা ছাপা মানে গাড়ি ভুল জায়গায়।
             */
            'ship_to' => ['nullable', 'string', 'max:191'],
            'ship_date' => ['nullable', 'date'],

            /*
             * কাউন্টারে নেওয়া টাকার বিবরণ — অঙ্কটা আগে থেকেই ছিল।
             *
             * নগদ ছাড়া অন্য কিছুতে (চেক, বিকাশ) নম্বর ছাড়া টাকাটা আর
             * খুঁজে পাওয়া যায় না, আর ব্যাংকের কাগজের সাথে মেলানোও যায় না।
             */
            'deposit_method' => ['nullable', 'string', 'max:32'],
            'deposit_ref' => ['nullable', 'string', 'max:64'],
            'rounding_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'gifts' => ['nullable', 'array'],
            'gifts.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.against_product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.qty' => ['nullable', 'numeric', 'min:0'],
            'gifts.*.remarks' => ['nullable', 'string', 'max:191'],
        ]);

        $result = $this->sales->complete(
            $data,
            $data['lines'],
            array_values(array_filter(
                $data['gifts'] ?? [],
                fn (array $gift) => filled($gift['product_id'] ?? null) && (float) ($gift['qty'] ?? 0) > 0,
            )),
        );

        // সোজা রসিদে — বিক্রির পরের কাজটা কাগজ দেওয়া
        return redirect()
            ->route('sales.print.invoice', ['invoice' => $result['invoice']->id, 'paper' => '80mm'])
            ->with('saved', __('sales::message.direct_done', [
                'challan' => $result['challan']->document_no,
                'invoice' => $result['invoice']->document_no,
                'change' => Money::format($result['change']),
            ]));
    }

    /**
     * পর্দার সাথে যাওয়া পণ্যতালিকা — ছয়টা মজুদ সংখ্যা সহ।
     *
     * সাব-সিলেক্টে, সারি প্রতি কোয়েরিতে নয়।
     *
     * @return Collection<int, object>
     */
    private function catalogue(?Warehouse $warehouse): Collection
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id));

        return Product::query()
            ->active()
            ->with(['unit', 'tax'])
            ->select('inv_products.*')
            ->selectSub($sum('floor_change'), 'floor_total')
            ->selectSub($sum('reserved_change'), 'reserved_total')
            ->selectSub($sum('hold_change'), 'hold_total')
            ->selectSub($sum('free_change'), 'free_total')
            ->selectSub($sum('free_reserved_change'), 'free_reserved_total')
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(function (Product $p) use ($warehouse) {
                /*
                 * খাবারের উত্তরটা আলাদা — কারণটা
                 * [[RecipeService::sellableQty()]]-এ। POS-ও ঠিক এই
                 * ডাকটাই করে, তাই দুই কাউন্টারে একই খাবারের পাশে
                 * দুইটা সংখ্যা বসে না।
                 */
                $available = $this->recipes->sellableQty($p, bcsub(
                    bcsub((string) $p->floor_total, (string) $p->reserved_total, 4),
                    (string) $p->hold_total,
                    4,
                ), $warehouse);

                return (object) [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name(),
                    'unit' => $p->unit?->name() ?? '',
                    'rate' => (string) $p->sale_price,
                    'barcode' => (string) $p->barcode,

                    /*
                     * ভ্যাটের হার পণ্যের নিজের কর থেকে।
                     *
                     * পর্দায় হার বসিয়ে দিলে পণ্যভেদে আলাদা হার আর মানা হত
                     * না — ওষুধে শূন্য, বিস্কুটে সাড়ে সাত।
                     */
                    'vatRate' => (float) ($p->tax?->rate ?? 0),

                    // ক্রয়মূল্য — ভেতরের কথা, তাই পর্দায় বোতামের পেছনে
                    'cost' => (float) $p->purchase_price,

                    // নমুনার লাইভ স্টক প্যানেল — ছয়টাই
                    'main' => (string) $p->floor_total,
                    'reserved' => (string) $p->reserved_total,
                    'hold' => (string) $p->hold_total,
                    'available' => $available,
                    'free' => (string) $p->free_total,
                    'free_available' => bcsub((string) $p->free_total, (string) $p->free_reserved_total, 4),
                ];
            });
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->active()->first();
    }
}
