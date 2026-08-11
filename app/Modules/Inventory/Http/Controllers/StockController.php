<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * মজুদের পর্দা — চারটা অবস্থা এক জায়গায়।
 *
 * এই একটা টেবিলই ব্যবহারকারীর আসল প্রশ্নের উত্তর: "কী কত আছে, আর তার
 * কতটা বেচা যাবে"। চারটা আলাদা পাতায় ভাগ করলে তিনটা খুলে মনে মনে বিয়োগ
 * করতে হত, আর সেটাই ভুলের জায়গা।
 */
class StockController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly StockService $stock,
        private readonly StockAdjustmentService $adjustments,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.stock.view', only: ['index']),
            new Middleware('can:inventory.stock.adjust', only: ['adjust', 'storeAdjustment']),
            new Middleware('can:inventory.stock.hold', only: ['storeHold', 'storeRelease']),
        ];
    }

    public function index(Request $request): View
    {
        $warehouse = $this->chosenWarehouse($request);

        /*
         * চারটা অবস্থা কোয়েরির ভেতরেই, সারি প্রতি একটা করে নয়।
         *
         * প্রতিটা সারির জন্য statesFor() ডাকলে পঞ্চাশ পণ্যে পঞ্চাশটা কোয়েরি
         * হত। সরবরাহকারীর প্রদেয়েও একই ভুল একবার করা হয়েছিল।
         *
         * প্রথমে এখানে একটা আলাদা GROUP BY কোয়েরি ছিল, কিন্তু তাতে দুইটা
         * সমস্যা ছিল। এক, পাতায় পঞ্চাশটা পণ্য দেখালেও যোগফল আসত সব পণ্যের —
         * দশ হাজার পণ্যের গুদামে ওটা প্রতিবার দশ হাজার সারি টানত। দুই,
         * সংখ্যাগুলো কোয়েরির বাইরে থাকায় ওগুলো দিয়ে সাজানো যেত না, অথচ
         * "কোনটা ফুরিয়ে আসছে" প্রশ্নটাই এই পর্দার সবচেয়ে কাজের প্রশ্ন।
         *
         * সাব-সিলেক্ট করায় দুইটাই মেটে: যোগফল আসে শুধু দেখানো সারিগুলোর,
         * আর ORDER BY-তে ওগুলো ব্যবহার করা যায়। ইনডেক্সটা ঠিক এই কাজের
         * জন্যই — (company_id, product_id, warehouse_id)।
         */
        $query = Product::query()
            ->search($request->query('q'))
            ->active()
            ->with('unit')
            ->select('inv_products.*')
            ->selectSub($this->sumOf('floor_change', $warehouse), 'floor_total')
            ->selectSub($this->sumOf('reserved_change', $warehouse), 'reserved_total')
            ->selectSub($this->sumOf('hold_change', $warehouse), 'hold_total');

        $sort = $this->applySort($query, $request, $this->sorts());

        return view('inventory::stock.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $query->paginate(50)->withQueryString(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * এক পণ্যের এক ধরনের চলাচলের যোগফল — সাব-সিলেক্ট হিসেবে।
     */
    private function sumOf(string $column, ?Warehouse $warehouse): Builder
    {
        return DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn (Builder $q, Warehouse $w) => $q->where('warehouse_id', $w->id));
    }

    /**
     * সাজানোর উপায়গুলো।
     *
     * ডিফল্ট "বিক্রয়যোগ্য কম আগে" — তালিকা খুলেই যেগুলো ফুরিয়ে আসছে
     * সেগুলো উপরে থাকে। নামে সাজালে প্রথম পাতায় কী থাকবে তা নির্ভর করত
     * বর্ণমালার উপর, আর যে পণ্যটা আজ শেষ হয়ে যাবে সেটা তিন নম্বর পাতায়
     * পড়ে থাকত।
     *
     * @return array<string, callable>
     */
    private function sorts(): array
    {
        // সাব-সিলেক্টের নাম দিয়েই সাজানো — ব্যবহারকারীর পাঠানো কোনো লেখা
        // এখানে পৌঁছায় না, শুধু এই ঘোষিত ছয়টা চাবির একটা
        $available = 'floor_total - reserved_total - hold_total';

        return [
            'available' => fn ($q) => $q->orderByRaw("{$available} asc")->orderBy('inv_products.name_en'),
            'available_desc' => fn ($q) => $q->orderByRaw("{$available} desc"),
            'floor_desc' => fn ($q) => $q->orderByRaw('floor_total desc'),
            'hold_desc' => fn ($q) => $q->orderByRaw('hold_total desc'),
            'name' => fn ($q) => $q->orderBy('inv_products.name_en'),
            'code' => fn ($q) => $q->orderBy('inv_products.code'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'available' => __('inventory::sort.available_low'),
            'available_desc' => __('inventory::sort.available_high'),
            'floor_desc' => __('inventory::sort.floor_high'),
            'hold_desc' => __('inventory::sort.hold_high'),
            'name' => __('inventory::sort.name'),
            'code' => __('inventory::sort.code'),
        ];
    }

    /** গণনা ও সমন্বয়ের পর্দা। */
    public function adjust(Request $request): View
    {
        return view('inventory::stock.adjust', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'reasons' => ReasonCode::query()
                ->inContext(ReasonCode::STOCK_ADJUSTMENT)
                ->active()->orderBy('code')->get(),
            'holdReasons' => ReasonCode::query()
                ->inContext(ReasonCode::HOLD)
                ->active()->orderBy('code')->get(),
            'stock' => $this->stock,
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::STOCK_ADJUSTMENT, 'counted');

        $movement = $this->adjustments->adjust(
            product: $data['product'],
            warehouse: $data['warehouse'],
            countedQty: (string) $request->input('counted'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
            unitCost: $request->input('unit_cost'),
        );

        // মিলে গেলে কোনো সারি বসে না — আর সেটা ব্যবহারকারীকে বলা হয়,
        // নাহলে তিনি ভাবতেন সেভ হয়নি
        return back()->with('saved', $movement === null
            ? __('inventory::message.adjust_matched')
            : __('inventory::message.adjusted', [
                'difference' => number_format((float) $movement->floor_change, 2),
            ]));
    }

    /**
     * বিক্রি ছাড়া মাল বের করে দেওয়ার পর্দা।
     *
     * সমন্বয়ের পর্দা থেকে আলাদা, কারণ প্রশ্নটাই আলাদা: ওখানে "গুনে কত
     * পেলাম", এখানে "কতটা দিয়ে দিলাম"। কারণটা বেছে নিলেই টাকাটা ঠিক
     * খাতে যায় — আপ্যায়ন খরচে, উপহার উপহারে, মালিকের ব্যবহার উত্তোলনে।
     */
    public function issue(Request $request): View
    {
        return view('inventory::stock.issue', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'reasons' => ReasonCode::query()
                ->inContext(ReasonCode::STOCK_ISSUE)
                ->active()->with('account')->orderBy('code')->get(),
            'stock' => $this->stock,
        ]);
    }

    public function storeIssue(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::STOCK_ISSUE, 'qty');

        $movement = $this->adjustments->issue(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
        );

        return back()->with('saved', __('inventory::message.issued', [
            'qty' => rtrim(rtrim((string) $request->input('qty'), '0'), '.'),
            'reason' => $data['reason']->name(),
            'account' => $data['reason']->account?->label()
                ?? __('inventory::message.issue_no_account'),
        ]));
    }

    public function storeHold(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::HOLD, 'qty');

        $this->stock->hold(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
        );

        return back()->with('saved', __('inventory::message.held'));
    }

    public function storeRelease(Request $request): RedirectResponse
    {
        $data = $this->validatedMovement($request, ReasonCode::HOLD, 'qty');

        $this->stock->release(
            product: $data['product'],
            warehouse: $data['warehouse'],
            qty: (string) $request->input('qty'),
            reason: $data['reason'],
            date: $request->input('trx_date'),
        );

        return back()->with('saved', __('inventory::message.released'));
    }

    /**
     * @return array{product: Product, warehouse: Warehouse, reason: ReasonCode}
     */
    private function validatedMovement(Request $request, string $context, string $qtyField): array
    {
        /*
         * exists নিয়মে company_id — গ্লোবাল স্কোপ ভ্যালিডেটরের কাঁচা
         * কোয়েরিতে কাজ করে না, তাই ওটা ছাড়া অন্য কোম্পানির পণ্যের id
         * পাঠিয়ে দেওয়া যেত।
         */
        $companyId = CompanyContext::id();

        $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'reason_code_id' => ['required', 'integer',
                Rule::exists('mdm_reason_codes', 'id')->where('company_id', $companyId)],
            $qtyField => ['required', 'numeric'],
            'trx_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * দর — কেবল গণনায় বেশি পাওয়া গেলে, আর তখন বাধ্যতামূলক।
             *
             * এখানে required করা যায় না, কারণ পাওয়া গেছে বেশি না কম
             * সেটা জানা যায় গোনার পর — তাক আর খাতার পার্থক্য দেখে।
             * পাহারাটা তাই সার্ভিসে, যেখানে পার্থক্যটা জানা।
             */
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reason = ReasonCode::query()->findOrFail($request->integer('reason_code_id'));

        if ($reason->context !== $context) {
            abort(422, __('inventory::validation.wrong_reason_context'));
        }

        return [
            'product' => Product::query()->findOrFail($request->integer('product_id')),
            'warehouse' => Warehouse::query()->findOrFail($request->integer('warehouse_id')),
            'reason' => $reason,
        ];
    }

    private function chosenWarehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        // ০ বা অচেনা id মানে "সব গুদাম" — ভাঙা নয়
        return $id > 0 ? Warehouse::query()->find($id) : null;
    }
}
