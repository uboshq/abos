<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\TripPacking;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে লোডিং শিট — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: *"পণ্য ধরে কত তুলতে হবে, চালান ধরে
 * কার জন্য"*, আর তোলা শেষে "প্যাক হয়েছে"।
 *
 * ⓘ ওয়েবের লোডিং শিটের ([[LoadingSheetController]]) একই খোলা ট্রিপ, একই পণ্য-যোগ ([[LoadingSheetController::productTotals()]])
 * আর একই "লোডিং নিশ্চিত" সেবা ([[TripPacking]]) — দ্বিতীয় কোনো হিসাব নয়।
 * ⛔ দেখা ট্রিপের চাবিতে (`sales.shipment.view`), "প্যাক হয়েছে" ডেলিভারি বদলানোর চাবিতে (`sales.delivery.update`) — ওয়েবের মতো।
 */
class LoadingApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 50;

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.shipment.view', only: ['index', 'show']),
            new Middleware('can:sales.delivery.update', only: ['packed']),
        ];
    }

    /** `GET /api/v1/sales/loading` — খোলা ট্রিপ, দেখার শাখায়, নতুন আগে */
    public function index(): JsonResponse
    {
        $trips = ViewedBranch::narrow(Shipment::query(), 'sal_shipments.branch_id')
            ->where('sal_shipments.status', DocumentStatus::DRAFT)
            ->withCount('lines')
            ->orderByDesc('sal_shipments.trx_date')->orderByDesc('sal_shipments.id')
            // ⓘ পাতা ভাগ — ৫০টা করে, পরের পাতার নম্বরসহ ([[EveryListScreenPaginatesTest]])
            ->paginate(self::PER_PAGE);

        return response()->json([
            'next_page' => $trips->hasMorePages() ? $trips->currentPage() + 1 : null,
            'rows' => collect($trips->items())->map(fn (Shipment $t): array => [
                ...$this->head($t),
                'challans' => (int) $t->lines_count,
            ])->values(),
        ]);
    }

    /** `GET /api/v1/sales/loading/{id}` — এক ট্রিপের শিট: পণ্য ধরে (লট, কার জন্য) আর চালান ধরে */
    public function show(string $id): JsonResponse
    {
        $trip = $this->trip($id);
        $trip->load(['lines.challan.customer', 'lines.challan.lines.product.unit', 'lines.challan.lines.batch']);

        $stages = DeliveryState::query()
            ->whereIn('delivery_challan_id', $trip->lines->pluck('delivery_challan_id'))
            ->pluck('stage', 'delivery_challan_id');

        return response()->json([
            ...$this->head($trip),
            'open' => $trip->status === DocumentStatus::DRAFT,
            'products' => collect(LoadingSheetController::productTotals($trip))->map(fn (array $r): array => [
                'product' => (string) $r['product'],
                'unit' => (string) ($r['unit'] ?? ''),
                'qty' => (string) $r['qty'],
                'free' => (string) $r['free'],
                'lots' => collect($r['lots'] ?? [])->map(fn ($q, $lot) => ['lot' => (string) $lot, 'qty' => (string) $q])->values()->all(),
                'for' => collect($r['for'] ?? [])->map(fn ($q, $who) => ['who' => (string) $who, 'qty' => (string) $q])->values()->all(),
            ])->values(),
            'challans' => $trip->lines->filter(fn ($l) => $l->challan !== null)->map(fn ($l): array => [
                'document_no' => (string) $l->challan->document_no,
                'customer' => (string) ($l->challan->customer?->name() ?? ''),
                'stage' => $stages[$l->challan->id] ?? null,
                'packed' => in_array($stages[$l->challan->id] ?? null, TripPacking::LOADABLE, true) === false && isset($stages[$l->challan->id]),
                'lines' => $l->challan->lines->map(fn ($x): array => [
                    'product' => $x->product?->name(),
                    'qty' => bcadd((string) $x->delivered_qty, '0', 4),
                    'free' => bcadd((string) ($x->free_qty ?? '0'), '0', 4),
                ])->values()->all(),
            ])->values(),
        ]);
    }

    /** `POST /api/v1/sales/loading/{id}/packed` — ওয়েবের "লোডিং নিশ্চিত" */
    public function packed(string $id): JsonResponse
    {
        $trip = $this->trip($id);
        $moved = app(TripPacking::class)->pack($trip);

        return response()->json([
            'packed' => $moved,
            'message' => __('sales::loading.confirmed', ['count' => $moved, 'no' => $trip->document_no]),
        ]);
    }

    private function trip(string $id): Shipment
    {
        return ViewedBranch::narrow(Shipment::query(), 'sal_shipments.branch_id')->wherePublicId($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function head(Shipment $t): array
    {
        return [
            'id' => (string) $t->public_id,
            'document_no' => (string) $t->document_no,
            'date' => $t->trx_date?->toDateString(),
            'vehicle' => $t->vehicle_no,
            'driver' => $t->driver_name,
            'driver_phone' => $t->driver_phone,
            'carrier' => $t->carrier_name,
        ];
    }
}
