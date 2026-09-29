<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Reports\SalesReturnReasonReports;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * কারণ ধরে ফেরত — পর্দা। NEXUS §২৪।
 *
 * ── ⓘ কেন [[SalesReportController]]-এর একটা slug নয় ─────────────────
 * ঐ কন্ট্রোলারের পাঁচটা রিপোর্ট একটাই চাবিতে (`sales.report`)। এটা
 * আলাদা চাবিতে (`sales.return.report`): ফেরতের অঙ্ক কারণ ধরে দেখলে কে
 * কত ভুয়া ফেরত দেখাচ্ছে সেটা বোঝা যায় — ⚠️ বিক্রয়কর্মীর রিপোর্টের
 * চাবিতে রাখলে যাঁর ফেরত মাপা হচ্ছে তিনিই মাপকাঠিটা দেখতেন।
 *
 * ⭐ পর্দাটা তবু সেই একই ([[accounts::report.show]]) — ছাঁকনি, রপ্তানি,
 * ছাপা, তুলনা সব একবারই লেখা। কেবল গ্রাহক আর পণ্যের দুইটা ড্রপডাউন
 * এই রিপোর্টের নিজের, আর সেগুলো `extraFilters` দিয়ে ঢোকে।
 */
class SalesReturnReasonReportController extends Controller implements HasMiddleware
{
    /**
     * স্লাগ → রিপোর্ট।
     *
     * ⚠️ একটাই রিপোর্ট, তবু তালিকা আর `{slug}` — রিপোর্টের দুই পাহারা
     * ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]],
     * [[EveryReportScreenOpensInEveryModuleTest]]) দরজা খোঁজে `*.report.show`
     * রুট আর এই `SLUGS` ধ্রুবক দেখে। ⛔ অন্য আকারে দরজাটা ওদের চোখে "নেই",
     * আর চাবির মিল কেউ মাপত না।
     *
     * @var array<string, string>
     */
    public const SLUGS = [
        'by-reason' => SalesReturnReasonReports::KEY,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.SalesReturnReasonReports::PERMISSION)];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            // ঘরগুলো ঘোষণা থেকেই — হাতে লেখা তালিকা নয় (২১ সেপ্টেম্বর ২০২৬-এর ভুল)
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
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'extraFilters' => 'sales::return.partials.reason-report-filters',
            'customers' => Customer::query()->active()->orderBy('name_en')->get(),
            'products' => Product::query()->active()->orderBy('name_en')->get(),
        ]);
    }
}
