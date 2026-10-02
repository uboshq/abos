<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SaleTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ফোনের "ডেলিভারি ট্র্যাকিং" — `/api/v1/sales/tracking` (মালিক, ২ অক্টোবর ২০২৬)।
 *
 * ⛔ চাবি: ডেলিভারি দেখা বা আদেশ দেখা — যেকোনো একটা (`sales.delivery.view` | `sales.order.view`)।
 * অন্য কোম্পানি বা শাখার বিক্রি মডেলের নিজের দেয়ালে আটকায় (৪০৪)। ⓘ কোন ধাপ, কে কখন — [[SaleTracking]]।
 */
class SaleTrackingController extends Controller
{
    public function __construct(private readonly SaleTracking $tracking) {}

    /** `GET /api/v1/sales/tracking?q=&step=&customer=` */
    public function index(Request $request): JsonResponse
    {
        $this->mayTrack($request);

        $customer = trim((string) $request->query('customer', ''));
        $customerId = $customer === '' ? null : (int) Customer::query()
            ->when(Str::isUuid($customer), fn ($q) => $q->where('public_id', $customer), fn ($q) => $q->whereKey((int) $customer))
            ->firstOrFail()->id;

        return response()->json($this->tracking->list(
            trim((string) $request->query('q', '')) ?: null,
            $request->query('step') !== null ? (string) $request->query('step') : null,
            $customerId,
        ));
    }

    /** `GET /api/v1/sales/tracking/{kind}/{id}` — kind: challan | order */
    public function show(Request $request, string $kind, string $id): JsonResponse
    {
        $this->mayTrack($request);

        $sale = match ($kind) {
            'challan' => DeliveryChallan::query()->where('public_id', $id)->firstOrFail(),
            'order' => SalesOrder::query()->where('public_id', $id)->firstOrFail(),
            default => abort(404),
        };

        return response()->json($this->tracking->story($sale));
    }

    /** `GET /sales/tracking` — ওয়েবের একই তালিকা (মালিক: "web eo eta dite bolo") */
    public function page(Request $request, MenuBuilder $menu): View
    {
        $this->mayTrack($request);

        $step = in_array($request->query('step'), SaleTracking::STEPS, true) ? (string) $request->query('step') : null;
        $term = trim((string) $request->query('q', '')) ?: null;

        return view('sales::tracking.index', [
            'menu' => $menu->forUser($request->user()),
            'list' => $this->tracking->list($term, $step, null),
            'step' => $step,
            'q' => $term,
        ]);
    }

    /** `GET /sales/tracking/{kind}/{id}` — একটা বিক্রির সময়রেখা; তালিকা থেকে পপ-আপে খোলে */
    public function story(Request $request, MenuBuilder $menu, string $kind, string $id): View
    {
        $this->mayTrack($request);

        $sale = match ($kind) {
            'challan' => DeliveryChallan::query()->where('public_id', $id)->firstOrFail(),
            'order' => SalesOrder::query()->where('public_id', $id)->firstOrFail(),
            default => abort(404),
        };

        return view('sales::tracking.show', [
            'menu' => $menu->forUser($request->user()),
            'sale' => $this->tracking->story($sale),
        ]);
    }

    private function mayTrack(Request $request): void
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('sales.delivery.view') || $user->can('sales.order.view')), 403);
    }
}
