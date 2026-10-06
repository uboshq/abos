<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Reports\DepositReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ⭐ আমানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৪ (৬ অক্টোবর ২০২৬, [[DepositReports]])। পাতা গ্রাহকের খাতার একই
 * (`accounts::report.show`): তারিখ, সারি, মোট, ছাপা/PDF/Excel।
 *
 * ⓘ স্লাগ থেকে রিপোর্ট ([[SLUGS]]) — দরজার চাবি আর রিপোর্টের চাবি এক কি না পাহারা নিজেই মেলায়
 * ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]])।
 */
class DepositReportController extends Controller
{
    public const SLUGS = [
        'accrued' => DepositReports::ACCRUED,
        'instalments' => DepositReports::INSTALMENTS,
    ];

    /** রিপোর্টের সারি — প্রতিটা রিপোর্টের মাথায় আর জমার পাতায় একই ক্রমে */
    public const TABS = [
        'accrued' => 'finance::deposit_report.accrued_short',
        'instalments' => 'finance::deposit_report.instalments_short',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);
        Gate::authorize($definition->permission);

        // ⓘ শাখা ধরে ভাগ চাওয়া হয়, বাকি রিপোর্ট-পর্দার মতো — ভাগ হবে কি না ইঞ্জিন ঠিক করে ([[ReportEngine::branchPlan()]])
        $result = $this->reports->run($key, $request->only($definition->requestKeys()), page: max(1, (int) $request->query('page', 1)), byBranch: true);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'extraFilters' => 'finance::deposit.partials.report-tabs',
            // ⓘ ফলটা এক লাইনে — রিপোর্ট নিজের যোগফল থেকে কষে
            'summary' => $definition->summary === null ? null : ($definition->summary)($result->totals),
        ]);
    }
}
