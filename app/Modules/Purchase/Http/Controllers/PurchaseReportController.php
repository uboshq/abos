<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ক্রয়ের তিনটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * ভিউটা ReportDefinition ছাড়া আর কিছু জানে না, তাই নতুন ভিউ লেখার মানে
 * হত একই টেবিল আবার লেখা (সেকশন ১৯.৮)।
 */
class PurchaseReportController extends Controller implements HasMiddleware
{
    /**
     * @var array<string, string>
     */
    private const SLUGS = [
        'pending-orders' => 'purchase.pending_orders',
        'uninvoiced' => 'purchase.uninvoiced',
        'by-supplier' => 'purchase.by_supplier',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:purchase.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only(['from', 'to', 'branch_id', 'top', 'compare']),
            page: max(1, (int) $request->query('page', 1)),
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
        ]);
    }
}
