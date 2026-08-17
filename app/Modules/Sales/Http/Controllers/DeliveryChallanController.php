<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Http\Requests\DeliveryChallanRequest;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডেলিভারি চালান — পর্দা।
 */
class DeliveryChallanController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly DeliveryChallanService $service,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(DeliveryChallan::class, 'challan'),
            new Middleware('can:sales.challan.create', only: ['confirm']),
            new Middleware('can:sales.challan.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = DeliveryChallan::query()
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

        return view('sales::challan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'challans' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::challan.form', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => new DeliveryChallan(['trx_date' => now()->toDateString()]),
            'order' => $this->chosenOrder($request),
            ...$this->formData(),
        ]);
    }

    public function store(DeliveryChallanRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.challan.show', $document)
            ->with('saved', __('sales::message.challan_created'));
    }

    public function show(Request $request, DeliveryChallan $challan): View
    {
        $challan->load(['lines.product.unit', 'customer', 'warehouse', 'order', 'creator']);

        return view('sales::challan.show', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => $challan,
        ]);
    }

    public function edit(Request $request, DeliveryChallan $challan): View
    {
        $challan->load(['lines.product', 'order.lines']);

        return view('sales::challan.form', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => $challan,
            'order' => $challan->order,
            ...$this->formData(),
        ]);
    }

    public function update(DeliveryChallanRequest $request, DeliveryChallan $challan): RedirectResponse
    {
        $this->service->update($challan, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.challan.show', $challan)
            ->with('saved', __('sales::message.challan_updated'));
    }

    public function confirm(DeliveryChallan $challan): RedirectResponse
    {
        $this->service->confirm($challan);

        return redirect()
            ->route('sales.challan.show', $challan)
            ->with('saved', __('sales::message.challan_confirmed'));
    }

    public function cancel(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($challan, $reason);

        return redirect()
            ->route('sales.challan.show', $challan)
            ->with('saved', __('sales::message.challan_cancelled'));
    }

    /**
     * অর্ডার ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা থাকে — যা বাকি ঠিক ততটুকু।
     *
     * গুদামের লোক গাড়ির পাশে দাঁড়িয়ে এটা লেখেন; প্রতিটা লাইন হাতে খুঁজতে
     * বললে তাড়াহুড়োয় ভুল পণ্য বাছা হত।
     */
    private function chosenOrder(Request $request): ?SalesOrder
    {
        $id = $request->integer('sales_order_id');

        if ($id <= 0) {
            return null;
        }

        return SalesOrder::query()->open()->with(['lines.product.unit', 'customer'])->find($id);
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
             * গোটা ক্যাটালগের মজুদ — চার্ট/বাল্ক শীটের জন্য, এক কোয়েরিতে।
             *
             * পণ্য ধরে ধরে গুনলে চারশো পণ্যে চারশো কোয়েরি হত। তার চেয়েও
             * বড় কথা, আলাদা মুহূর্তে গোনা সংখ্যাগুলো একে অন্যের সাথে
             * না-ও মিলতে পারে — শীটটা একই মুহূর্তের ছবি হওয়া দরকার।
             *
             * গুদাম বাছার আগে ডিফল্ট গুদামের সংখ্যা, কারণ শীটটা ফর্ম
             * খোলার সময়েই বসে; ভুল গুদামের সংখ্যা দেখানোর চেয়ে
             * প্রতিষ্ঠানের প্রধান গুদামেরটা দেখানো কম বিভ্রান্তিকর।
             */
            'stock' => app(StockService::class)->statesForAll(
                Warehouse::query()->where('is_default', true)->first()
            ),
            'orders' => SalesOrder::query()->open()->with('customer')
                ->orderByDesc('trx_date')->limit(200)->get(),

            /*
             * বহরের গাড়িগুলো — সুইচ বন্ধ থাকলে খালি।
             *
             * খালি হলে ফর্মে শুধু লেখা নম্বরের ঘরটাই থাকে, ঠিক আগের
             * মতো। যে প্রতিষ্ঠান বহরের তালিকা রাখে না, তার পর্দায়
             * চিরকাল খালি একটা ড্রপডাউন ঝুলে থাকা উচিত নয়।
             */
            'vehicles' => $this->settings->get('master_data.vehicle_enabled')
                ? Vehicle::query()->active()->orderBy('code')->get()
                : collect(),
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
