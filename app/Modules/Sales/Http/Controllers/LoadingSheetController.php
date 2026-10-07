<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\TripPacking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * লোডিং শিট — ট্রিপ ধরে, কোন গাড়িতে কোন চালানের কোন মাল উঠছে (মালিকের "Delivery Processing" নকশা,
 * ২৮ সেপ্টেম্বর ২০২৬, রাত)।
 *
 * ⓘ ট্রিপই শিট: নতুন কাগজ নয়, ট্রিপের খাতার ([[Shipment]]) গুদাম-মুখী রূপ — মাল তোলার লোক
 * দেখেন কী তুলবেন, ছাপা হাতে নেন, আর তোলা শেষে "লোডিং নিশ্চিত" চাপেন।
 * ⭐ "লোডিং নিশ্চিত" ট্রিপের চালানগুলোকে "প্যাক হয়েছে" ধাপে নেয় ([[DeliveryStage::PACKED]]) — কেবল
 * যেগুলো এখনো বরাদ্দ বা তোলার ধাপে; যা আগেই প্যাক, তা যেমন আছে। ⛔ রওনা হয়ে যাওয়া ট্রিপে নয়।
 */
class LoadingSheetController extends Controller implements HasMiddleware
{
    /** যে ধাপ থেকে "লোডিং নিশ্চিত" প্যাকে নেয়। */
    public const LOADABLE = TripPacking::LOADABLE;

    public function __construct(
        private readonly DeliveryStageService $stages,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.shipment.view', only: ['index', 'show']),
            // ⓘ ধাপ বদলানো ডেলিভারির চাবির কাজ — ঠিক যেমন চালানের পাতায়
            new Middleware('can:sales.delivery.update', only: ['confirm']),
        ];
    }

    public function index(Request $request): View
    {
        // ⓘ গুদামের কাজ খোলা ট্রিপে — যেগুলো এখনো বেরোয়নি; বেরোনোগুলো ডিসপ্যাচ রেজিস্টারে
        $trips = Shipment::query()
            ->where('status', DocumentStatus::DRAFT)
            ->withCount('lines')
            ->orderByDesc('trx_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('sales::loading.index', [
            'menu' => $this->menu->forUser($request->user()),
            'trips' => $trips,
        ]);
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $shipment->load(['lines.challan.customer', 'lines.challan.lines.product.unit', 'lines.challan.lines.batch']);

        $stages = DeliveryState::query()
            ->whereIn('delivery_challan_id', $shipment->lines->pluck('delivery_challan_id'))
            ->pluck('stage', 'delivery_challan_id');

        return view('sales::loading.show', [
            'menu' => $this->menu->forUser($request->user()),
            'trip' => $shipment,
            'stages' => $stages,
            'totals' => self::productTotals($shipment),
        ]);
    }

    /**
     * ⭐ লোডিং নিশ্চিত — ট্রিপের চালানগুলো "প্যাক হয়েছে"-তে, একসাথে (একটা আটকালে কোনোটাই নয়)।
     */
    public function confirm(Request $request, Shipment $shipment): RedirectResponse
    {
        // ⓘ ফোনের লোডিং শিটের একই সেবা ([[TripPacking]])
        $moved = app(TripPacking::class)->pack($shipment);

        return redirect()
            ->route('sales.loading_sheet.show', $shipment)
            ->with('saved', __('sales::loading.confirmed', ['count' => $moved, 'no' => $shipment->document_no]));
    }

    /**
     * পণ্য ধরে যোগফল — গাড়িতে কোন পণ্য মোট কত (পরিমাণ + ফ্রি), সব চালান মিলিয়ে।
     *
     * ⭐ লট ধরে ভাগ — ধাপ ৪ (মালিক, ৬ অক্টোবর ২০২৬: "পণ্য ধরে কত তুলতে হবে"): তোলার লোককে কোন লট থেকে কত, সেটাও
     * জানতে হয়; `lots` = লট → পরিমাণ (+ফ্রি), লট-ছাড়া মাল লটের ঘরে নেই। পর্দা, ছাপা আর ফোন — তিনটাই এটা পড়ে।
     *
     * ⓘ `for` = কার জন্য — "দোকান (চালান)" → পরিমাণ (+ফ্রি); ছাপায় নামের নিচে।
     *
     * @return list<array{product: string, unit: string, qty: string, free: string, lots: array<string, string>, for: array<string, string>}>
     */
    public static function productTotals(Shipment $shipment): array
    {
        $totals = [];

        foreach ($shipment->lines as $tripLine) {
            foreach ($tripLine->challan?->lines ?? [] as $line) {
                $key = (int) $line->product_id;
                $totals[$key] ??= [
                    'product' => (string) $line->product?->name(),
                    'unit' => (string) ($line->product?->unit?->name() ?? ''),
                    'qty' => '0',
                    'free' => '0',
                    'lots' => [],
                    'for' => [],
                ];
                $totals[$key]['qty'] = bcadd($totals[$key]['qty'], (string) $line->delivered_qty, 4);
                $totals[$key]['free'] = bcadd($totals[$key]['free'], (string) ($line->free_qty ?? '0'), 4);

                $who = trim(($tripLine->challan?->customer?->name() ?? '').' ('.($tripLine->challan?->document_no ?? '').')');
                $totals[$key]['for'][$who] = bcadd($totals[$key]['for'][$who] ?? '0',
                    bcadd((string) $line->delivered_qty, (string) ($line->free_qty ?? '0'), 4), 4);

                $lot = (string) ($line->batch?->batch_no ?? '');
                if ($lot !== '') {
                    $totals[$key]['lots'][$lot] = bcadd($totals[$key]['lots'][$lot] ?? '0',
                        bcadd((string) $line->delivered_qty, (string) ($line->free_qty ?? '0'), 4), 4);
                }
            }
        }

        return array_values($totals);
    }
}
