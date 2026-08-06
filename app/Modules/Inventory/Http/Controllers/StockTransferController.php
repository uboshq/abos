<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StockTransferRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * স্টক স্থানান্তর — পর্দা।
 */
class StockTransferController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(StockTransfer::class, 'transfer'),
            new Middleware('can:inventory.transfer.create', only: ['dispatch']),

            /*
             * বুঝে নেওয়ার চাবি আলাদা।
             *
             * যিনি পাঠান আর যিনি বুঝে নেন — দুই গুদামের দুইজন। একজনেই
             * দুইটা করলে "পাঠিয়েছি, পৌঁছেছে" লিখে দিয়ে মাল পথেই সরিয়ে
             * ফেলা যেত, আর কাগজে সব মিলত।
             */
            new Middleware('can:inventory.transfer.receive', only: ['receive']),
            new Middleware('can:inventory.transfer.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = StockTransfer::query()
            ->search($request->query('q'))
            ->with(['fromWarehouse', 'toWarehouse'])
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            /*
             * রাস্তায় থাকাগুলো আগে — ডিফল্ট।
             *
             * এই তালিকার আসল প্রশ্ন "কোন মালটা এখনো পৌঁছায়নি", আর
             * সাম্প্রতিক দিয়ে সাজালে ওগুলো নিচে চাপা পড়ত।
             */
            'on_the_way' => fn ($q) => $q
                ->orderByRaw("CASE WHEN status = '".DocumentStatus::CONFIRMED."' THEN 0 ELSE 1 END")
                ->orderByDesc('trx_date'),
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
        ]);

        return view('inventory::transfer.index', [
            'menu' => $this->menu->forUser($request->user()),
            'transfers' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::transfer.form', [
            'menu' => $this->menu->forUser($request->user()),
            'transfer' => new StockTransfer(['trx_date' => now()->toDateString()]),
            ...$this->formData(),
        ]);
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $transfer = $this->transfers->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('inventory.transfer.show', $transfer)
            ->with('saved', __('inventory::message.transfer_created'));
    }

    public function show(Request $request, StockTransfer $transfer): View
    {
        $transfer->load(['lines.product.unit', 'fromWarehouse', 'toWarehouse', 'creator']);

        return view('inventory::transfer.show', [
            'menu' => $this->menu->forUser($request->user()),
            'transfer' => $transfer,
        ]);
    }

    public function edit(Request $request, StockTransfer $transfer): View
    {
        $transfer->load('lines.product');

        return view('inventory::transfer.form', [
            'menu' => $this->menu->forUser($request->user()),
            'transfer' => $transfer,
            ...$this->formData(),
        ]);
    }

    public function update(StockTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        $this->transfers->update($transfer, $request->documentData(), $request->lineData());

        return redirect()
            ->route('inventory.transfer.show', $transfer)
            ->with('saved', __('inventory::message.transfer_updated'));
    }

    public function dispatch(StockTransfer $transfer): RedirectResponse
    {
        $this->transfers->dispatch($transfer);

        return redirect()
            ->route('inventory.transfer.show', $transfer)
            ->with('saved', __('inventory::message.transfer_dispatched'));
    }

    public function receive(StockTransfer $transfer): RedirectResponse
    {
        $this->transfers->receive($transfer);

        return redirect()
            ->route('inventory.transfer.show', $transfer)
            ->with('saved', __('inventory::message.transfer_received'));
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->transfers->cancel($transfer, $reason);

        return redirect()
            ->route('inventory.transfer.show', $transfer)
            ->with('saved', __('inventory::message.transfer_cancelled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
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
            'on_the_way' => __('inventory::sort.on_the_way'),
            'recent' => __('inventory::sort.recent'),
            'oldest' => __('inventory::sort.oldest'),
        ];
    }
}
