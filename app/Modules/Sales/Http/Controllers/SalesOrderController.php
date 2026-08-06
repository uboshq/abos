<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Requests\SalesOrderRequest;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয় আদেশ — পর্দা।
 */
class SalesOrderController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly SalesOrderService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesOrder::class, 'order'),
            new Middleware('can:sales.order.update', only: ['confirm']),
            new Middleware('can:sales.order.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = SalesOrder::query()
            ->search($request->query('q'))
            ->with(['customer', 'warehouse'])
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::order.index', [
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
        return view('sales::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => new SalesOrder(['trx_date' => now()->toDateString()]),
            ...$this->formData(),
        ]);
    }

    public function store(SalesOrderRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.order.show', $document)
            ->with('saved', __('sales::message.order_created'));
    }

    public function show(Request $request, SalesOrder $order): View
    {
        $order->load(['lines.product.unit', 'customer', 'warehouse', 'challans', 'creator']);

        return view('sales::order.show', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
        ]);
    }

    public function edit(Request $request, SalesOrder $order): View
    {
        $order->load(['lines.product']);

        return view('sales::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
            ...$this->formData(),
        ]);
    }

    public function update(SalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->service->update($order, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_updated'));
    }

    public function confirm(SalesOrder $order): RedirectResponse
    {
        $this->service->confirm($order);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_confirmed'));
    }

    public function cancel(Request $request, SalesOrder $order): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($order, $reason);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_cancelled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name_en')->get(),
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
            'recent' => __('sales::sort.recent'),
            'oldest' => __('sales::sort.oldest'),
            'largest' => __('sales::sort.largest'),
            'customer' => __('sales::sort.customer'),
        ];
    }
}
