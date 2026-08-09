<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * সরাসরি ক্রয় চালান — এক পর্দায় পুরো ঘটনা।
 *
 * বিক্রয়ের DirectSaleController-এর আয়না, শুধু দিকটা উল্টো: ওখানে মাল
 * বেরোয় আর টাকা আসে, এখানে মাল ঢোকে আর টাকা যায়। গড়নটা এক রাখা
 * হয়েছে ইচ্ছাকৃতভাবে — যিনি বিক্রয়ের পর্দা চালাতে জানেন তিনি এটাও
 * জানেন, নতুন করে শিখতে হয় না।
 */
class DirectPurchaseController extends Controller implements HasMiddleware
{
    /**
     * পুরো তালিকা পর্দায় পাঠানোর সীমা।
     *
     * এর বেশি পণ্য হলে ব্রাউজারে ধরানোর চেষ্টাই ভুল — তখন খোঁজাটা
     * সার্ভারে যাওয়াই ঠিক। সীমাটা বিক্রয়ের পর্দার মতোই।
     */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly DirectPurchaseService $purchases,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:purchase.bill.create')];
    }

    public function create(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('purchase::direct.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'moneyAccounts' => $this->moneyAccounts(),

            /*
             * ঘরগুলোর সুইচ — বিক্রয়ের পর্দার মতোই (নিয়ম ৭)।
             *
             * ভ্যাটের চাবিটা master_data-র, purchase-এর নয়: ভ্যাট দেওয়া
             * বা না-দেওয়া পুরো প্রতিষ্ঠানের ব্যাপার, এক মডিউলের নয়।
             */
            'show' => [
                'free_qty' => $this->settings->get('purchase.field_free_qty', true),
                'line_discount' => $this->settings->get('purchase.field_line_discount', true),
                'vat' => $this->settings->get('master_data.tax_enabled', true),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date'],
            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'due_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * হাতে হাতে দেওয়া টাকা — ঐচ্ছিক।
             *
             * টাকা দিলে কোন খাত থেকে গেল সেটা বলতেই হবে; নইলে পরিশোধটা
             * কোথা থেকে এল তা খাতায় লেখা থাকত না।
             */
            'paid_now' => ['nullable', 'numeric', 'min:0'],
            'paid_from_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
                Rule::requiredIf(fn () => (float) $request->input('paid_now', 0) > 0)],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sales_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->purchases->complete($data, $data['lines']);

        return redirect()
            ->route('purchase.bill.show', $result['bill']->id)
            ->with('saved', __('purchase::message.direct_done', [
                'no' => $result['bill']->document_no,
            ]));
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $chosen = $request->integer('warehouse_id');

        return $chosen > 0
            ? Warehouse::query()->find($chosen)
            : Warehouse::query()->where('is_default', true)->first()
                ?? Warehouse::query()->active()->orderBy('code')->first();
    }

    /**
     * পর্দার পণ্য তালিকা — মজুদ ও শেষ দর সহ।
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(?Warehouse $warehouse): array
    {
        return Product::query()
            ->active()
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(fn (Product $p) => $this->purchases->stockPanel($p, $warehouse))
            ->all();
    }

    /** নগদ ও ব্যাংক — টাকাটা কোথা থেকে গেল। */
    private function moneyAccounts()
    {
        $heads = Account::query()
            ->whereIn('code', [StandardChart::CASH_IN_HAND, StandardChart::BANK_AND_MFS])
            ->pluck('id');

        return Account::query()
            ->where(fn ($q) => $q->whereIn('parent_id', $heads)->orWhereIn('id', $heads))
            ->where('is_group', false)
            ->orderBy('code')
            ->get();
    }
}
