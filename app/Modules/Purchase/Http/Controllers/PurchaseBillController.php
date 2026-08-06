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
use App\Modules\Purchase\Http\Requests\PurchaseBillRequest;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ক্রয় বিলের পর্দা।
 */
class PurchaseBillController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseBillService $bills,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseBill::class, 'bill'),
            new Middleware('can:purchase.bill.create', only: ['confirm']),
            new Middleware('can:purchase.bill.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseBill::query()
            ->search($request->query('q'))
            ->with('supplier')
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
        ]);

        return view('purchase::bill.index', [
            'menu' => $this->menu->forUser($request->user()),
            'bills' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        // চালান ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা — যতটুকুর বিল এখনো
        // হয়নি ঠিক ততটুকু নিয়ে
        $receipt = $this->chosenReceipt($request);

        return view('purchase::bill.form', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => new PurchaseBill(['trx_date' => now()->toDateString()]),
            'receipt' => $receipt,
            'receipts' => PurchaseReceipt::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseBillRequest $request): RedirectResponse
    {
        $bill = $this->bills->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_created'));
    }

    public function show(Request $request, PurchaseBill $bill): View
    {
        $bill->load(['lines.product.unit', 'lines.receiptLine.receipt', 'supplier', 'creator']);

        return view('purchase::bill.show', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => $bill,
        ]);
    }

    public function edit(Request $request, PurchaseBill $bill): View
    {
        $bill->load(['lines.product', 'lines.receiptLine']);

        return view('purchase::bill.form', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => $bill,
            'receipt' => null,
            'receipts' => PurchaseReceipt::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseBillRequest $request, PurchaseBill $bill): RedirectResponse
    {
        $this->bills->update($bill, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_updated'));
    }

    public function confirm(PurchaseBill $bill): RedirectResponse
    {
        $this->bills->confirm($bill);

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_confirmed'));
    }

    public function cancel(Request $request, PurchaseBill $bill): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->bills->cancel($bill, $reason);

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_cancelled'));
    }

    private function chosenReceipt(Request $request): ?PurchaseReceipt
    {
        $id = $request->integer('purchase_receipt_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseReceipt::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['lines.product.unit', 'supplier'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
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
