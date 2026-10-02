<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\DeliveryOrderTabs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডেলিভারি অর্ডার — প্রতিটা বিক্রির চালান, ধাপের ট্যাবে ([[DeliveryOrderTabs]])।
 *
 * ⓘ কেবল দেখা — চালান দেখার চাবিই যথেষ্ট; ডেলিভারি নিশ্চিত, বাতিল ইত্যাদি চালানের নিজের
 * পাতায়, নিজের চাবিতে। ⚠️ মডেলের স্কোপ কোম্পানি ও শাখা বসায়, তাই অন্যের DO আসে না।
 */
class DeliveryOrderController extends Controller implements HasMiddleware
{
    use FiltersByDate;
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DeliveryOrderTabs $tabs,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.view')];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), DeliveryOrderTabs::LISTED, true)
            ? (string) $request->query('tab')
            : 'awaiting';

        $query = $this->tabs->query($tab)
            ->search($request->query('q'))
            ->with(['customer.location', 'warehouse']);

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::do.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals($query, ['total' => 't.total']),
            'orders' => $query->paginate(50)->withQueryString(),
            'tab' => $tab,
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
        ]);
    }

    /** @return array<string, string> */
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
