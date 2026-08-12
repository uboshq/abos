<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * কাউন্টারের পর্দা।
 *
 * ── কেন পণ্যগুলো আগেই পাঠানো হয় ──────────────────────────────────────
 * পুরো পণ্যতালিকা পাতার সাথেই যায়, আর খোঁজা হয় ব্রাউজারেই। প্রতিটা
 * অক্ষরে সার্ভারে গেলে কাউন্টারে দেরি হত — আর ডিপোর ইন্টারনেট গেলে
 * বিক্রিই বন্ধ হয়ে যেত (docs/Decision — নেটওয়ার্ক গেলে কী)।
 *
 * তালিকা বড় হলে (কয়েক হাজার পণ্য) এটা আর চলবে না; তখন সার্ভারে খোঁজা
 * ফিরবে। সেই দিনের জন্য অনুমানটা এখানে লেখা রইল, যাতে সীমাটা কেউ
 * আবিষ্কার না করে বরং জানতে পারে।
 */
class PosController extends Controller implements HasMiddleware
{
    /** এর বেশি পণ্য হলে পাতার সাথে পাঠানো বন্ধ, সার্ভারে খোঁজা শুরু। */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly PosService $pos,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.pos')];
    }

    public function index(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('sales::pos.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),
            'customers' => Customer::query()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'walkinId' => (int) $this->settings->get('sales.walkin_customer_id', 0),
            'todaysTotal' => $this->pos->todaysTotal(),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'paid' => ['required', 'numeric', 'min:0'],

            /*
             * টিলের চাবি — এক কার্টে একটা, ব্রাউজারে তৈরি।
             *
             * ঐচ্ছিক, কারণ পুরনো টিল বা পরীক্ষার কোড এটা পাঠায় না, আর
             * না পাঠালে আচরণ আগের মতোই। যারা পাঠায়, তাদের দ্বিতীয়বার
             * Enter চাপা দ্বিতীয় বিল বানায় না।
             */
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->pos->checkout($data, $data['lines']);

        /*
         * সোজা রসিদে — কাউন্টারে বিক্রির পরের কাজটা ছাপা, আর কিছু নয়।
         *
         * ৮০mm ডিফল্ট: থার্মাল রোলই কাউন্টারের স্বাভাবিক কাগজ। যিনি A4
         * চান তিনি বিলের পাতা থেকে নিতে পারেন।
         */
        return redirect()
            ->route('sales.print.invoice', ['invoice' => $result['invoice']->id, 'paper' => '80mm'])
            ->with('saved', __('sales::message.pos_done', [
                'no' => $result['invoice']->document_no,
                'change' => number_format((float) $result['change'], 2),
            ]));
    }

    /**
     * বারকোড দিয়ে একটা পণ্য — স্ক্যানার এখানেই আসে।
     *
     * পাতার সাথে পাঠানো তালিকায় না পেলে তখনই কেবল সার্ভারে আসা হয়, তাই
     * স্বাভাবিক অবস্থায় এই রুটটা কখনো ডাকা হয় না।
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));

        if ($code === '') {
            return response()->json(null, 404);
        }

        $product = Product::query()
            ->active()
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('code', $code))
            ->with('unit')
            ->first();

        if ($product === null) {
            return response()->json(null, 404);
        }

        return response()->json([
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name(),
            'unit' => $product->unit?->name(),
            'rate' => (string) $product->sale_price,
            'barcode' => $product->barcode,
        ]);
    }

    /**
     * পর্দার সাথে যাওয়া পণ্যতালিকা — বিক্রয়যোগ্য সংখ্যা সহ।
     *
     * সংখ্যাটা সাব-সিলেক্টে, সারি প্রতি কোয়েরিতে নয় — মজুদের পর্দায় এই
     * ভুলটা একবার করে শোধরানো হয়েছে।
     *
     * @return Collection<int, object>
     */
    private function catalogue(?Warehouse $warehouse)
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id));

        return Product::query()
            ->active()
            ->with('unit')
            ->select('inv_products.*')
            ->selectSub($sum('floor_change'), 'floor_total')
            ->selectSub($sum('reserved_change'), 'reserved_total')
            ->selectSub($sum('hold_change'), 'hold_total')
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(fn (Product $p) => (object) [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name(),
                'unit' => $p->unit?->name() ?? '',
                'rate' => (string) $p->sale_price,
                'barcode' => (string) $p->barcode,
                'available' => bcsub(
                    bcsub((string) $p->floor_total, (string) $p->reserved_total, 4),
                    (string) $p->hold_total,
                    4,
                ),
            ]);
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->active()->first();
    }
}
