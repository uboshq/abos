<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফোনে নিজের ছুটি — আবেদন, নিজের তালিকা আর অবস্থা (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬: বাকি মডিউল অ্যাপে)।
 *
 * `GET /api/v1/hr/leave` · `POST /api/v1/hr/leave`
 *
 * ⓘ আবেদন বসে ওয়েবের একই সেবায় ([[LeaveService::apply()]]) — তারিখের যাচাই, কর্মরত থাকা, আগের আবেদনের সাথে না মেলা আর বছরের
 * সীমা সব সেখানেই; মঞ্জুরি আগের মতোই ওয়েবে, `hr.leave.approve`-এ। বাকি ছুটি একই সেবার [[LeaveService::balance()]]।
 *
 * ⛔ কেবল নিজের: কর্মী আসে লগইন থেকে ([[ExpenseClaimService::employeeOf()]], দাবির একই প্রশ্ন), ফোনের পাঠানো কিছু থেকে নয়;
 * তালিকাও কেবল সেই কর্মীর। অন্যের নামে আবেদন ওয়েবের `hr.leave.manage`-এর কাজ — এখানে নেই।
 */
final class LeaveApiController extends Controller implements HasMiddleware
{
    /** নিজের আবেদন কয়টা — সাম্প্রতিক; পুরনো সব ওয়েবের ছুটির পাতায় */
    private const RECENT = 50;

    public function __construct(
        private readonly LeaveService $leave,
        private readonly ExpenseClaimService $claims,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:hr.leave.self')];
    }

    /** নিজের আবেদনগুলো, আর প্রতিটা ধরনে এ বছর কত বাকি */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->claims->employeeOf($request->user());

        return response()->json([
            'employee' => $employee !== null,
            'types' => $employee === null ? [] : LeaveType::query()->active()->orderBy('code')->get()
                ->map(function (LeaveType $type) use ($employee): array {
                    $balance = $this->leave->balance($employee, $type);

                    return [
                        'id' => (string) $type->public_id,
                        'name' => $type->name('bn'),
                        'paid' => (bool) $type->is_paid,
                        'allowed' => (string) $balance['allowed'],
                        'taken' => (string) $balance['taken'],
                        // ⓘ null = বছরে সীমা নেই
                        'left' => $balance['left'],
                    ];
                })->values(),
            'applications' => $employee === null ? [] : LeaveApplication::query()
                ->with('leaveType')
                ->where('employee_id', $employee->id)
                ->orderByDesc('from_date')->orderByDesc('id')
                ->limit(self::RECENT)->get()
                ->map(fn (LeaveApplication $a) => $this->facts($a))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->claims->employeeOf($request->user());

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages(['employee' => __('hr::message.leave_no_employee')]);
        }

        $data = $request->validate([
            'leave_type' => ['required', 'uuid',
                Rule::exists('hr_leave_types', 'public_id')->where('company_id', CompanyContext::id())->where('is_active', true)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            // ⛔ ওয়েবের একই নিয়ম — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ৯); ⓘ ফোনের JSON সংখ্যা 1e-7 লেখায় "1.0E-7" হয়ে ৫০০ দিত
            'days' => ['required', 'decimal:0,1', 'regex:/^\d+(\.\d)?$/', 'gt:0', 'max:'.LeaveService::MAX_SPAN_DAYS],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'days.decimal' => __('hr::validation.days_format'),
            'days.regex' => __('hr::validation.days_format'),
        ]);

        $application = $this->leave->apply(
            $employee,
            LeaveType::query()->where('public_id', $data['leave_type'])->firstOrFail(),
            (string) $data['from_date'],
            (string) $data['to_date'],
            (string) $data['days'],
            $data['reason'] ?? null,
        );

        return response()->json($this->facts($application->load('leaveType')), 201);
    }

    /** @return array<string, mixed> */
    private function facts(LeaveApplication $a): array
    {
        return [
            'id' => (string) $a->public_id,
            'type' => (string) ($a->leaveType?->name('bn') ?? ''),
            'from_date' => $a->from_date?->toDateString(),
            'to_date' => $a->to_date?->toDateString(),
            'days' => (string) $a->days,
            'reason' => (string) ($a->reason ?? ''),
            // ⓘ pending · approved · rejected · cancelled — বাংলা নাম ফোনের নিজের
            'status' => (string) $a->status,
            'remarks' => (string) ($a->decision_remarks ?? ''),
            'decided_at' => $a->decided_at?->toIso8601String(),
        ];
    }
}
