<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Sales\Reports\MarginReport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মার্জিন রিপোর্টের পাতা — হিসাবের পর্দার ভিউ দিয়েই (`accounts::report.show`)।
 *
 * ── ⚠️ কেন [[SalesReportController]]-এ নয় ─────────────────────────────
 * ওখানকার দরজা `sales.report` — মাঠের ASM/RSM/DSM সবার হাতে। ⛔ এই
 * রিপোর্টের পুরো কথাই খরচ আর মার্জিন, তাই এর নিজের চাবি
 * ([[MarginReport::PERMISSION]]); আর খরচের কলামগুলো তার উপরেও
 * `sales.cost.view`-এর পেছনে।
 */
class MarginReportController extends Controller implements HasMiddleware
{
    /**
     * ঠিকানার নাম থেকে ইঞ্জিনের চাবি।
     *
     * ⚠️ `EveryReportScreenOpensInEveryModuleTest` এই ধ্রুবকটা reflection দিয়ে
     * পড়ে (রুটের নাম `.report.show`-এ শেষ হলে) — নাম বদলাবেন না।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'margin' => MarginReport::KEY,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.MarginReport::PERMISSION)];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            // ⓘ ঘরগুলো ঘোষণা থেকেই — হাতে লেখা তালিকা নয় ([[SalesReportController]]-এর একই কারণ)
            $request->only($definition->requestKeys()),
            page: max(1, (int) $request->query('page', 1)),
            // ⭐ "সব শাখা"-তে শাখা ধরে ভাগ + সর্বমোট — ভাগ হবে কি না ইঞ্জিন ঠিক করে ([[ReportEngine::branchPlan()]])
            byBranch: true,
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
            'partyTypes' => collect(),
        ]);
    }
}
