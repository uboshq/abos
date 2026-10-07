<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Reports\RentalReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ⭐ ভাড়ার চুক্তি ও জামানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ (৬ অক্টোবর ২০২৬, [[RentalReports]])। পাতা গ্রাহকের
 * খাতার একই (`accounts::report.show`): তারিখ, সারি, মোট, ছাপা/PDF/Excel।
 *
 * ⓘ স্লাগ থেকে রিপোর্ট ([[SLUGS]]) — দরজার চাবি আর রিপোর্টের চাবি এক কি না পাহারা নিজেই মেলায়
 * ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]])।
 */
class RentalReportController extends Controller
{
    public const SLUGS = [
        'schedule' => RentalReports::SCHEDULE,
        'advance' => RentalReports::ADVANCE,
        'deposit-book' => RentalReports::DEPOSIT_BOOK,
        'contracts' => RentalReports::CONTRACTS,
    ];

    /** রিপোর্টের সারি — প্রতিটা রিপোর্টের মাথায় আর ভাড়ার পাতায় একই ক্রমে */
    public const TABS = [
        'schedule' => 'finance::rental_report.schedule_short',
        'advance' => 'finance::rental_report.advance_short',
        'deposit-book' => 'finance::rental_report.book_short',
        'contracts' => 'finance::rental_report.contracts_short',
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

        $view = [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'extraFilters' => 'finance::rental.partials.report-tabs',
            // ⓘ ফলটা এক লাইনে — রিপোর্ট নিজের যোগফল থেকে কষে
            'summary' => $definition->summary === null ? null : ($definition->summary)($result->totals),
        ];

        // ⓘ জামানতের খাতা — চুক্তি বাছার ঘর: দেখার শাখার চালু আর শেষ চুক্তি
        if ($key === RentalReports::DEPOSIT_BOOK) {
            $view['partyFilter'] = 'rental_contract_id';
            $view['parties'] = RentalContract::query()->inViewedBranch()
                ->whereIn('status', [RentalContract::ACTIVE, RentalContract::CLOSED])
                ->orderBy('counterparty')->get()
                ->map(fn (RentalContract $c) => (object) ['id' => (int) $c->id, 'name' => trim($c->counterparty.' · '.$c->document_no, ' ·')]);
        }

        return view('accounts::report.show', $view);
    }
}
