<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\CreditControl\Reports\CreditControlLists;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/** বাকি নিয়ন্ত্রণের তালিকা — হিসাবের রিপোর্ট-পর্দার ভিউ দিয়েই ([[SalesReportController]]-এর ছাঁচ) */
class CreditReportController extends Controller implements HasMiddleware
{
    public const SLUGS = [
        'positions' => CreditPositionReports::POSITIONS,
        'over-limit' => CreditPositionReports::OVER_LIMIT,
        'overdue-stopped' => CreditPositionReports::OVERDUE_STOPPED,
        'overdue-bills' => CreditPositionReports::OVERDUE_BILLS,
        'pending-requests' => CreditPositionReports::PENDING_REQUESTS,
        // ⭐ ধাপ ৩.২ — মালিকের রিপোর্টের তালিকা ([[CreditControlLists]])
        'blocked' => CreditPositionReports::BLOCKED,
        'ageing' => CreditControlLists::AGEING,
        'high-risk' => CreditControlLists::HIGH_RISK,
        'payment-behaviour' => CreditControlLists::BEHAVIOUR,
        'limit-breaches' => CreditControlLists::BREACHES,
        'wall-evidence' => CreditControlLists::EVIDENCE,
        'limit-changes' => CreditControlLists::CHANGES,
        'limit-requests' => CreditControlLists::REQUESTS,
        'temporary-limits' => CreditControlLists::TEMPORARY,
        'exposure-by-branch' => CreditControlLists::BY_BRANCH,
        'exposure-by-salesperson' => CreditControlLists::BY_SALESPERSON,
        'risk-trend' => CreditControlLists::RISK_TREND,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:credit_control.view')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $definition = $this->reports->get(self::SLUGS[$slug]);

        $result = $this->reports->run(
            self::SLUGS[$slug],
            $request->only($definition->requestKeys()),
            page: max(1, (int) $request->query('page', 1)),
            byBranch: true,
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'summary' => $definition->summary === null ? null : ($definition->summary)($result->totals),
        ]);
    }
}
