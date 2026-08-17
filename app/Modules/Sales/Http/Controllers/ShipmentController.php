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
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Http\Requests\ShipmentRequest;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\ShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * শিপমেন্ট — পর্দা।
 */
class ShipmentController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly ShipmentService $service,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Shipment::class, 'shipment'),
            new Middleware('can:sales.shipment.create', only: ['dispatch', 'settle', 'close']),
            new Middleware('can:sales.shipment.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Shipment::query()
            ->search($request->query('q'))
            ->with(['vehicle', 'route'])
            ->withCount('lines')
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'on_the_road' => fn ($q) => $q->orderByRaw(
                'CASE WHEN status = ? THEN 0 ELSE 1 END', [DocumentStatus::CONFIRMED]
            )->orderByDesc('trx_date'),
        ]);

        return view('sales::shipment.index', [
            'menu' => $this->menu->forUser($request->user()),
            'shipments' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::shipment.form', [
            'menu' => $this->menu->forUser($request->user()),
            'shipment' => new Shipment(['trx_date' => now()->toDateString()]),
            'loaded' => collect(),
            ...$this->formData(null),
        ]);
    }

    public function store(ShipmentRequest $request): RedirectResponse
    {
        $shipment = $this->service->create($request->documentData(), $request->challanIds());

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_created'));
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $shipment->load([
            'lines.challan.customer', 'vehicle', 'route', 'warehouse', 'creator',
        ]);

        return view('sales::shipment.show', [
            'menu' => $this->menu->forUser($request->user()),
            'shipment' => $shipment,
            'outcomes' => $this->outcomeLabels(),
        ]);
    }

    public function edit(Request $request, Shipment $shipment): View
    {
        $shipment->load('lines.challan.customer');

        return view('sales::shipment.form', [
            'menu' => $this->menu->forUser($request->user()),
            'shipment' => $shipment,
            'loaded' => $shipment->lines->pluck('challan')->filter(),
            ...$this->formData($shipment),
        ]);
    }

    public function update(ShipmentRequest $request, Shipment $shipment): RedirectResponse
    {
        $this->service->update($shipment, $request->documentData(), $request->challanIds());

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_updated'));
    }

    public function dispatch(Shipment $shipment): RedirectResponse
    {
        $this->service->dispatch($shipment);

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_dispatched'));
    }

    /** পথে কী হলো — এক সারির উত্তর। */
    public function settle(Request $request, Shipment $shipment, ShipmentLine $line): RedirectResponse
    {
        abort_unless((int) $line->shipment_id === (int) $shipment->id, 404);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'max:32'],
            'outcome_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->settle($line, $data['outcome'], $data['outcome_note'] ?? null);

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_line_settled'));
    }

    public function close(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'closing_km' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->close($shipment, $data);

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_closed'));
    }

    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($shipment, $reason);

        return redirect()
            ->route('sales.shipment.show', $shipment)
            ->with('saved', __('sales::message.shipment_cancelled'));
    }

    /**
     * ফর্মের তালিকাগুলো।
     *
     * ── কেন কেবল "মুক্ত" চালানগুলোই দেখানো হয় ───────────────────────
     * যে চালান আজ অন্য গাড়িতে আছে সেটা তালিকায় থাকলে গুদামের লোক
     * সেটা বেছে ফেলতেন, আর সেবা তখন না বলত — কাজের মাঝপথে একটা বাধা।
     * তালিকা থেকেই বাদ দিলে বাধাটা আসেই না।
     *
     * @return array<string, mixed>
     */
    private function formData(?Shipment $shipment): array
    {
        $onOtherTrips = ShipmentLine::query()
            ->when($shipment?->exists, fn ($q) => $q->where('shipment_id', '<>', $shipment->id))
            ->whereHas('shipment', fn ($q) => $q->whereIn('status',
                [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]))
            ->pluck('delivery_challan_id');

        return [
            'challans' => DeliveryChallan::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->whereNotIn('id', $onOtherTrips)
                ->with(['customer', 'warehouse'])
                ->orderByDesc('trx_date')->orderByDesc('id')
                ->limit(300)
                ->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'routes' => Location::query()->active()->atLevel('route')->orderBy('name_en')->get(),

            /*
             * বহরের গাড়িগুলো — সুইচ বন্ধ থাকলে খালি, ঠিক চালানের মতো।
             */
            'vehicles' => $this->settings->get('master_data.vehicle_enabled')
                ? Vehicle::query()->active()->orderBy('code')->get()
                : collect(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function outcomeLabels(): array
    {
        return [
            ShipmentLine::DELIVERED => __('sales::shipment.delivered'),
            ShipmentLine::RETURNED => __('sales::shipment.returned'),
            ShipmentLine::SHORT => __('sales::shipment.short'),
            ShipmentLine::PENDING => __('sales::shipment.pending'),
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
            'on_the_road' => __('sales::shipment.on_the_road_first'),
        ];
    }
}
