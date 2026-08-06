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
use App\Modules\Purchase\Http\Requests\PurchaseOrderRequest;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ক্রয় আদেশের পর্দা।
 */
class PurchaseOrderController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseOrder::class, 'order'),
            new Middleware('can:purchase.order.update', only: ['confirm']),
            new Middleware('can:purchase.order.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseOrder::query()
            ->search($request->query('q'))
            ->with(['supplier', 'warehouse'])
            // বাতিলগুলো লুকানো থাকে, কিন্তু মোছা হয় না (নিয়ম ৫) — দেখতে
            // চাইলে টিক দিলেই আসে
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
        ]);

        return view('purchase::order.index', [
            'menu' => $this->menu->forUser($request->user()),
            'orders' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => new PurchaseOrder(['trx_date' => now()->toDateString()]),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.order.show', $order)
            ->with('saved', __('purchase::message.order_created'));
    }

    public function show(Request $request, PurchaseOrder $order): View
    {
        $order->load(['lines.product.unit', 'supplier', 'warehouse', 'receipts', 'creator']);

        return view('purchase::order.show', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): View
    {
        $order->load('lines.product');

        return view('purchase::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $this->orders->update($order, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.order.show', $order)
            ->with('saved', __('purchase::message.order_updated'));
    }

    public function confirm(PurchaseOrder $order): RedirectResponse
    {
        $this->orders->confirm($order);

        return redirect()
            ->route('purchase.order.show', $order)
            ->with('saved', __('purchase::message.order_confirmed'));
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->orders->cancel($order, $reason);

        return redirect()
            ->route('purchase.order.show', $order)
            ->with('saved', __('purchase::message.order_cancelled'));
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
