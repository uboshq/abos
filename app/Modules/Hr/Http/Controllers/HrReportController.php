<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

/**
 * ⭐ HR-এর চারটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই ([[HrReports]]; ঘাটতির তালিকা #১৬, a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ `accounts::report.show` সংজ্ঞা ছাড়া আর কিছু জানে না — কলাম, ছাঁকনি, যোগফল, ছাপা, রপ্তানি সব সংজ্ঞা থেকে; ক্রয়ের
 * রিপোর্টের একই ছাঁচ ([[PurchaseReportController]])।
 */
class HrReportController extends Controller implements HasMiddleware
{
    /**
     * slug → [রিপোর্টের কী, যে চাবি লাগে]।
     *
     * ⓘ চাবি প্রতি স্লাগে আলাদা, কারণ চারটা রিপোর্ট চারটা পর্দার কথা বলে আর প্রতিটা সেই পর্দার চাবি চায়: হাজিরা, বেতনের রান,
     * কর্মী, ছুটি। ⛔ চাবি সংজ্ঞারটাই — [[EveryReportNamesTheKeyItsWebDoorAsksForTest]] দুইটা মেলায়; মেনুর সারি আর
     * [[ALinkThatLooksAliveAndIsNotTest]] এই স্লাগগুলোই পড়ে।
     *
     * @var array<string, array{key: string, permission: string}>
     */
    private const SLUGS = [
        'attendance' => ['key' => 'hr.attendance_summary', 'permission' => 'hr.attendance.view'],
        // ⭐ বেতনশিট — টাকার ঘর আলাদা করে `hr.salary.view` চায় (কলামের চাবি, [[HrReports::payrollRegister()]])
        'payroll' => ['key' => 'hr.payroll_register', 'permission' => 'hr.payroll.view'],
        'headcount' => ['key' => 'hr.headcount', 'permission' => 'hr.employee.view'],
        'leave-balance' => ['key' => 'hr.leave_balance', 'permission' => 'hr.leave.view'],
        // ⭐ ভবিষ্য তহবিলের খাতা — পুরোটাই টাকা, তাই বেতনের চাবি (a6, ২১ অক্টোবর ২০২৬; [[HrReports::providentFund()]])
        'provident-fund' => ['key' => 'hr.provident_fund', 'permission' => 'hr.salary.view'],
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        // ⓘ রুটে সবার জন্য একটা চাবি নেই — প্রতিটা স্লাগ নিজেরটা চায়, `show()`-এ; `auth` রুট-গোষ্ঠীতেই
        return [];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $this->authorize(self::SLUGS[$slug]['permission']);

        $key = self::SLUGS[$slug]['key'];
        $definition = $this->reports->get($key);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $this->reports->run(
                $key,
                // ⭐ ঘরগুলো ঘোষণা থেকেই — যে ঘোষণা থেকে ঘর আঁকা হয়, সেখান থেকেই পড়া
                $request->only($definition->requestKeys()),
                page: max(1, (int) $request->query('page', 1)),
                // ⭐ "সব শাখা"-তে শাখা ধরে ভাগ + সর্বমোট — ভাগ হবে কি না ইঞ্জিন ঠিক করে ([[ReportEngine::branchPlan()]])
                byBranch: true,
            ),
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
        ]);
    }
}
