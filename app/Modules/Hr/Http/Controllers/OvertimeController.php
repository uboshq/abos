<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Support\LeaveSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ অধিকালের শিট — মাস ধরে তালিকা, হাজিরা থেকে বানানো, আর ব্যবস্থাপকের "সই দিন" (BLA ধারা ১০৮; a6, ২১ অক্টোবর ২০২৬;
 * [[OvertimeService]])।
 *
 * ⓘ তালিকায় কেবল ঘণ্টা — টাকা নয় (সেটা বেতনের রানে, বেতনের চাবির পিছনে), তাই হাজিরার চাবিতে খোলে। বানানো হাজিরা বদলানোর চাবিতে;
 * সই চাবিতে নয়, রিপোর্টিং লাইনে ([[OvertimeSheetPolicy]])।
 */
class OvertimeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly OvertimeService $overtime,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            // ⓘ তালিকার দরজা নীতিতে ([[OvertimeSheetPolicy::viewAny()]]) — হাজিরার চাবি, বা নিজের দল থাকা ব্যবস্থাপক
            new Middleware('can:hr.attendance.manage', only: ['store']),
        ];
    }

    /**
     * ⭐ মাসের শিট — হাজিরার চাবি থাকলে দেখার শাখার সবার আর নিজের দলের; চাবি ছাড়া ব্যবস্থাপক কেবল নিজের দলের (ছুটির একই নিয়ম,
     * [[LeaveSigner::listFor()]]; fe, ২১ অক্টোবর ২০২৬, দফা ৩)। ⛔ অন্য দলের শিট তাঁর তালিকায় নেই, আর তাতে সই ৪০৩।
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', OvertimeSheet::class);

        $month = $this->monthFrom((string) $request->query('month'));
        $user = $request->user();
        $team = app(LeaveSigner::class)->teamOf($user);

        $query = $user->can('hr.attendance.view')
            ? OvertimeSheet::query()->where(fn ($q) => $q
                ->whereIn('hr_overtime_sheets.id', app(BranchReach::class)->throughEmployeeInView(OvertimeSheet::query(), $user)->select('hr_overtime_sheets.id'))
                ->when($team !== [], fn ($w) => $w->orWhereIn('hr_overtime_sheets.employee_id', $team)))
            : OvertimeSheet::query()->whereIn('hr_overtime_sheets.employee_id', $team);

        $sheets = $query
            ->with(['employee', 'signer'])
            ->whereDate('hr_overtime_sheets.month', $month->toDateString())
            ->orderBy('hr_overtime_sheets.id')
            ->paginate(50)
            ->withQueryString();

        return view('hr::overtime.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'sheets' => $sheets,
            'enabled' => $this->overtime->enabled(),
            'dailyHours' => $this->overtime->dailyHours(),
            'eligible' => implode(', ', $this->overtime->eligibleCodes()),
            'overtime' => $this->overtime,
        ]);
    }

    /** ⭐ "হাজিরা থেকে বানান" — দেখার শাখার যোগ্য কর্মীদের মাসের শিট, সইয়ের অপেক্ষায় */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = $this->monthFrom($data['month']);

        $made = $this->overtime->prepare($month, $request->user());

        return redirect()->route('hr.overtime.index', ['month' => $month->format('Y-m')])
            ->with('saved', __('hr::message.ot_prepared', ['count' => $made]));
    }

    /** ⭐ "সই দিন" — ব্যবস্থাপক বা মালিক; সেবা তালা দিয়ে আবার দেখে */
    public function sign(Request $request, OvertimeSheet $sheet): RedirectResponse
    {
        $this->authorize('sign', $sheet);

        $this->overtime->sign($sheet, $request->user());

        return back()->with('saved', __('hr::message.ot_signed'));
    }

    private function monthFrom(string $raw): Carbon
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)
            ? Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
    }
}
