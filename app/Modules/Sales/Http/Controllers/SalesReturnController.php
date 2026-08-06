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
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Http\Requests\SalesReturnRequest;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয় ফেরত — পর্দা।
 */
class SalesReturnController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly SalesReturnService $returns,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesReturn::class, 'return'),
            new Middleware('can:sales.return.create', only: ['confirm']),
            new Middleware('can:sales.return.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = SalesReturn::query()
            ->search($request->query('q'))
            ->with(['customer', 'warehouse'])
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::return.index', [
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
        return view('sales::return.form', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => new SalesReturn(['trx_date' => now()->toDateString()]),
            'invoice' => $this->chosenInvoice($request),
            ...$this->formData(),
        ]);
    }

    public function store(SalesReturnRequest $request): RedirectResponse
    {
        $document = $this->returns->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.return.show', $document)
            ->with('saved', __('sales::message.return_created'));
    }

    public function show(Request $request, SalesReturn $return): View
    {
        $return->load(['lines.product.unit', 'lines.invoiceLine.invoice', 'customer', 'warehouse', 'reasonCode', 'creator']);

        return view('sales::return.show', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => $return,
        ]);
    }

    public function edit(Request $request, SalesReturn $return): View
    {
        $return->load(['lines.product', 'lines.invoiceLine']);

        return view('sales::return.form', [
            'menu' => $this->menu->forUser($request->user()),
            'return' => $return,
            'invoice' => null,
            ...$this->formData(),
        ]);
    }

    public function update(SalesReturnRequest $request, SalesReturn $return): RedirectResponse
    {
        $this->returns->update($return, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.return.show', $return)
            ->with('saved', __('sales::message.return_updated'));
    }

    public function confirm(SalesReturn $return): RedirectResponse
    {
        $this->returns->confirm($return);

        return redirect()
            ->route('sales.return.show', $return)
            ->with('saved', __('sales::message.return_confirmed'));
    }

    public function cancel(Request $request, SalesReturn $return): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->returns->cancel($return, $reason);

        return redirect()
            ->route('sales.return.show', $return)
            ->with('saved', __('sales::message.return_cancelled'));
    }

    /** বিল ধরে খোলা হলে ওই বিলের লাইনগুলো আগে থেকে বসে। */
    private function chosenInvoice(Request $request): ?SalesInvoice
    {
        $id = $request->integer('sales_invoice_id');

        if ($id <= 0) {
            return null;
        }

        return SalesInvoice::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['customer', 'lines.product'])
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

            /*
             * ফেরতের কারণগুলো — মাস্টার ডেটা থেকে, হার্ডকোড নয়।
             *
             * এক ডিপোতে "নষ্ট / মেয়াদ", আরেকটায় "ভুল মাল / বেশি এসেছে"।
             * তালিকাটা কোম্পানি নিজেই বাড়াতে পারে।
             */
            'reasons' => ReasonCode::query()
                ->active()
                ->inContext(ReasonCode::SALES_RETURN)
                ->orderBy('code')
                ->get(),

            'invoices' => SalesInvoice::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('customer')
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
            'recent' => __('sales::sort.recent'),
            'oldest' => __('sales::sort.oldest'),
            'largest' => __('sales::sort.largest'),
            'customer' => __('sales::sort.customer'),
        ];
    }
}
