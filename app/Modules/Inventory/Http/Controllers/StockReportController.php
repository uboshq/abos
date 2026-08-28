<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মজুদের তিনটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * ভিউটা ReportDefinition ছাড়া আর কিছু জানে না, তাই নতুন ভিউ লেখার মানে
 * হত একই টেবিল তৃতীয়বার লেখা (সেকশন ১৯.৮)।
 */
class StockReportController extends Controller implements HasMiddleware
{
    /**
     * @var array<string, string>
     */
    private const SLUGS = [
        'stock-ledger' => 'inventory.stock_ledger',
        'stock-summary' => 'inventory.stock_summary',
        'hold' => 'inventory.hold',

        /* খাদ্য-খরচ — রান্না করা খাবারের প্রশ্ন, মজুদের নয়,
           কিন্তু উত্তরটা মজুদের সংখ্যা থেকেই আসে। */
        'food-cost' => 'inventory.food_cost',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.report')];
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
