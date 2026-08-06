<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Purchase\Http\Requests\PurchaseReturnRequest;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ক্রয় ফেরত — পর্দা।
 */
class PurchaseReturnController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseReturn::class, 'return'),
            new Middleware('can:purchase.return.create', only: ['confirm']),
            new Middleware('can:purchase.return.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseReturn::query()
            ->search($request->query('q'))
            ->with(['supplier', 'warehouse'])
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
        ]);

        return view('purchase::return.index', [
            'menu' => $this->menu->forUser($request->user()),
            'returns' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::return.form', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => new PurchaseReturn(['trx_date' => now()->toDateString()]),
            'bill' => $this->chosenBill($request),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseReturnRequest $request): RedirectResponse
    {
        $document = $this->returns->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.return.show', $document)
            ->with('saved', __('purchase::message.return_created'));
    }

    public function show(Request $request, PurchaseReturn $return): View
    {
        $return->load(['lines.product.unit', 'lines.billLine.bill', 'supplier', 'warehouse', 'reasonCode', 'creator']);

        return view('purchase::return.show', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => $return,
        ]);
    }

    public function edit(Request $request, PurchaseReturn $return): View
    {
        $return->load(['lines.product', 'lines.billLine']);

        return view('purchase::return.form', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => $return,
            'bill' => null,
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseReturnRequest $request, PurchaseReturn $return): RedirectResponse
    {
        $this->returns->update($return, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.return.show', $return)
            ->with('saved', __('purchase::message.return_updated'));
    }

    public function confirm(PurchaseReturn $return): RedirectResponse
    {
        $this->returns->confirm($return);

        return redirect()
            ->route('purchase.return.show', $return)
            ->with('saved', __('purchase::message.return_confirmed'));
    }

    public function cancel(Request $request, PurchaseReturn $return): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->returns->cancel($return, $reason);

        return redirect()
            ->route('purchase.return.show', $return)
            ->with('saved', __('purchase::message.return_cancelled'));
    }

    /** বিল ধরে খোলা হলে ওই বিলের লাইনগুলো আগে থেকে বসে। */
    private function chosenBill(Request $request): ?PurchaseBill
    {
        $id = $request->integer('purchase_bill_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseBill::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['supplier', 'lines.product'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),

            /*
             * ফেরতের কারণগুলো — মাস্টার ডেটা থেকে, হার্ডকোড নয়।
             *
             * এক ডিপোতে "নষ্ট / মেয়াদ", আরেকটায় "ভুল মাল / বেশি এসেছে"।
             * তালিকাটা কোম্পানি নিজেই বাড়াতে পারে।
             */
            'reasons' => ReasonCode::query()
                ->active()
                ->inContext(ReasonCode::PURCHASE_RETURN)
                ->orderBy('code')
                ->get(),

            'bills' => PurchaseBill::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')
                ->orderByDesc('trx_date')
                ->limit(200)
                ->get(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'recent' => __('purchase::sort.recent'),
            'oldest' => __('purchase::sort.oldest'),
            'largest' => __('purchase::sort.largest'),
            'supplier' => __('purchase::sort.supplier'),
        ];
    }
}
