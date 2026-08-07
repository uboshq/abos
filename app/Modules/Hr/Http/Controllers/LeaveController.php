<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ছুটির আবেদন ও তার ধরন।
 */
class LeaveController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.leave.view', only: ['index', 'create', 'types']),
            new Middleware('can:hr.leave.manage', only: ['store', 'cancel', 'storeType', 'installTypes']),
            new Middleware('can:hr.leave.approve', only: ['approve', 'reject']),
        ];
    }

    public function index(Request $request): View
    {
        $applications = LeaveApplication::query()
            ->with(['employee', 'leaveType', 'decider'])
            ->when($request->boolean('pending'), fn ($q) => $q->pending())
            ->orderByDesc('from_date')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('hr::leave.index', [
            'menu' => $this->menu->forUser($request->user()),
            'applications' => $applications,
            'onlyPending' => $request->boolean('pending'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('hr::leave.create', [
            'menu' => $this->menu->forUser($request->user()),
            'employees' => Employee::query()->active()->orderBy('code')->get(),
            'types' => LeaveType::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('hr_employees', 'id')->where('company_id', $companyId)],
            'leave_type_id' => ['required', 'integer',
                Rule::exists('hr_leave_types', 'id')->where('company_id', $companyId)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'days' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->leave->apply(
            Employee::query()->findOrFail($data['employee_id']),
            LeaveType::query()->findOrFail($data['leave_type_id']),
            $data['from_date'],
            $data['to_date'],
            (string) $data['days'],
            $data['reason'] ?? null,
        );

        return redirect()->route('hr.leave.index')
            ->with('saved', __('hr::message.leave_applied'));
    }

    public function approve(Request $request, int $application): RedirectResponse
    {
        $this->leave->approve(
            LeaveApplication::query()->findOrFail($application),
            $request->user(),
            $request->string('remarks')->toString() ?: null,
        );

        return back()->with('saved', __('hr::message.leave_approved'));
    }

    public function reject(Request $request, int $application): RedirectResponse
    {
        $remarks = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],
        ])['remarks'];

        $this->leave->reject(
            LeaveApplication::query()->findOrFail($application),
            $request->user(),
            $remarks,
        );

        return back()->with('saved', __('hr::message.leave_rejected'));
    }

    public function cancel(int $application): RedirectResponse
    {
        $this->leave->cancel(LeaveApplication::query()->findOrFail($application));

        return back()->with('saved', __('hr::message.leave_cancelled'));
    }

    // ── ছুটির ধরন ─────────────────────────────────────────────────────

    public function types(Request $request): View
    {
        $types = LeaveType::query()->orderBy('code')->get();

        return view('hr::leave.types', [
            'menu' => $this->menu->forUser($request->user()),
            'types' => $types,
            'canInstallDefaults' => $types->isEmpty(),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // খালি রাখলে সিরিজ থেকে বসে — মালিকের নির্দেশ (২০২৬-০৮-০৭)
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        /*
         * কোডটা এখানেই বসে, সার্ভিসে নয় — ছুটির ধরনের নিজের কোনো
         * সার্ভিস নেই, আর কেবল কোডের জন্য একটা বানানো মানে একটা ফাইল
         * বাড়ানো যাতে আর কিছুই থাকবে না।
         */
        $data['code'] = trim((string) ($data['code'] ?? '')) !== ''
            ? trim((string) $data['code'])
            : app(NumberSeriesEngine::class)->next('LVT');

        LeaveType::create([
            ...$data,
            'is_paid' => $request->boolean('is_paid'),
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('saved', __('hr::message.leave_type_created'));
    }

    /**
     * প্রমিত ছুটির ধরনগুলো।
     *
     * বাংলাদেশের শ্রম আইনের প্রচলিত ভাগ। প্রতিষ্ঠান নিজের নীতি অনুযায়ী
     * দিন বদলে নেবে — কিন্তু খালি তালিকা থেকে শুরু করতে হবে না।
     */
    public function installTypes(): RedirectResponse
    {
        $rows = [
            ['CASUAL', 'Casual Leave', 'নৈমিত্তিক ছুটি', 10, true],
            ['SICK', 'Sick Leave', 'অসুস্থতাজনিত ছুটি', 14, true],
            ['EARNED', 'Earned Leave', 'অর্জিত ছুটি', 15, true],
            ['UNPAID', 'Leave Without Pay', 'বিনা বেতনে ছুটি', 0, false],
        ];

        foreach ($rows as [$code, $en, $bn, $days, $paid]) {
            if (LeaveType::query()->where('code', $code)->withTrashed()->exists()) {
                continue;
            }

            LeaveType::create([
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                'days_per_year' => $days,
                'is_paid' => $paid,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
        }

        return back()->with('saved', __('hr::message.leave_types_installed'));
    }
}
