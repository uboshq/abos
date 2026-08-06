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
use App\Modules\Sales\Http\Requests\SalesInvoiceRequest;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয় বিল — পর্দা।
 */
class SalesInvoiceController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly SalesInvoiceService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesInvoice::class, 'invoice'),
            new Middleware('can:sales.invoice.create', only: ['confirm']),
            new Middleware('can:sales.invoice.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = SalesInvoice::query()
            ->search($request->query('q'))
            ->with(['customer'])
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        // তারিখের পরিসর — হোম পর্দার "আজকের বিক্রয়" ঠিক এখানেই নামে
        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::invoice.index', [
            'menu' => $this->menu->forUser($request->user()),
            'invoices' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::invoice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => new SalesInvoice(['trx_date' => now()->toDateString()]),
            'challan' => $this->chosenChallan($request),
            ...$this->formData(),
        ]);
    }

    public function store(SalesInvoiceRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.invoice.show', $document)
            ->with('saved', __('sales::message.invoice_created'));
    }

    public function show(Request $request, SalesInvoice $invoice): View
    {
        $invoice->load(['lines.product.unit', 'lines.challanLine.challan', 'customer', 'creator']);

        return view('sales::invoice.show', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => $invoice,
        ]);
    }

    public function edit(Request $request, SalesInvoice $invoice): View
    {
        $invoice->load(['lines.product', 'lines.challanLine']);

        return view('sales::invoice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => $invoice,
            'challan' => null,
            ...$this->formData(),
        ]);
    }

    public function update(SalesInvoiceRequest $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->service->update($invoice, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_updated'));
    }

    public function confirm(SalesInvoice $invoice): RedirectResponse
    {
        $this->service->confirm($invoice);

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_confirmed'));
    }

    public function cancel(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($invoice, $reason);

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_cancelled'));
    }

    /** চালান ধরে খোলা হলে যতটুকুর বিল হয়নি ঠিক ততটুকু নিয়ে লাইন ভরে। */
    private function chosenChallan(Request $request): ?DeliveryChallan
    {
        $id = $request->integer('delivery_challan_id');

        if ($id <= 0) {
            return null;
        }

        return DeliveryChallan::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['lines.product.unit', 'customer'])
            ->find($id);
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
            'challans' => DeliveryChallan::query()->where('status', DocumentStatus::CONFIRMED)
                ->with('customer')->orderByDesc('trx_date')->limit(200)->get(),
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
