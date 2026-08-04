<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Http\Requests\PurchaseReceiptRequest;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মাল বুঝে নেওয়ার পর্দা।
 */
class PurchaseReceiptController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly PurchaseReceiptService $receipts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseReceipt::class, 'receipt'),
            new Middleware('can:purchase.receipt.create', only: ['confirm']),
            new Middleware('can:purchase.receipt.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseReceipt::query()
            ->search($request->query('q'))
            ->with(['supplier', 'warehouse'])
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
        ]);

        return view('purchase::receipt.index', [
            'menu' => $this->menu->forUser($request->user()),
            'receipts' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        /*
         * আদেশ ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা থাকে — যা বাকি ঠিক
         * ততটুকু নিয়ে।
         *
         * গুদামের লোক ট্রাকের পাশে দাঁড়িয়ে এটা লেখেন। প্রতিটা লাইন হাতে
         * খুঁজে বের করতে বললে সময় লাগত, আর তাড়াহুড়োয় ভুল পণ্য বাছা হত।
         */
        $order = $this->chosenOrder($request);

        return view('purchase::receipt.form', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => new PurchaseReceipt(['trx_date' => now()->toDateString()]),
            'order' => $order,
            'orders' => PurchaseOrder::query()->open()->with('supplier')
                ->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseReceiptRequest $request): RedirectResponse
    {
        $receipt = $this->receipts->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_created'));
    }

    public function show(Request $request, PurchaseReceipt $receipt): View
    {
        $receipt->load(['lines.product.unit', 'supplier', 'warehouse', 'order', 'creator']);

        return view('purchase::receipt.show', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => $receipt,
        ]);
    }

    public function edit(Request $request, PurchaseReceipt $receipt): View
    {
        $receipt->load(['lines.product', 'order.lines']);

        return view('purchase::receipt.form', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => $receipt,
            'order' => $receipt->order,
            'orders' => PurchaseOrder::query()->open()->with('supplier')
                ->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseReceiptRequest $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->receipts->update($receipt, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_updated'));
    }

    public function confirm(PurchaseReceipt $receipt): RedirectResponse
    {
        $this->receipts->confirm($receipt);

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_confirmed'));
    }

    public function cancel(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->receipts->cancel($receipt, $reason);

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_cancelled'));
    }

    private function chosenOrder(Request $request): ?PurchaseOrder
    {
        $id = $request->integer('purchase_order_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseOrder::query()->open()->with(['lines.product.unit', 'supplier'])->find($id);
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
