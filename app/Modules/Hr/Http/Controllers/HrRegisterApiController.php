<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ হাজিরা আর ছুটির রেজিস্টার, ফোনের নিজের পর্দায় — নাগালের সব কর্মীর, নিজের নয় (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e
 * bujajayna"*; fe: "দুটোই রেজিস্টার")।
 *
 * ⓘ ওয়েবের পাতার একই প্রশ্ন, একই দেয়াল ([[BranchReach]] — চূড়ান্ত অডিট ⛔১৭) আর একই চাবি: দেখা `hr.attendance.view`/`hr.leave.view`,
 * মঞ্জুর/ফেরত `hr.leave.approve`। ⛔ মঞ্জুর আর ফেরত ওয়েবের একই সেবায় ([[LeaveService]]) — নিজের ছুটি নিজে মঞ্জুর নয়, বেতন
 * হয়ে যাওয়া মাসে নয়, বছরের সীমা ছাড়িয়ে নয়; সব সেবাই আটকায়। ⓘ হাজিরা বসানো (বেতনের ভিত্তি) ওয়েবেই — ফোনে কেবল দেখা।
 * ⛔ বেতনের কোনো অঙ্ক এখানে নেই (চুক্তি §৪)।
 */
final class HrRegisterApiController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly BranchReach $reach,
        private readonly LeaveService $leave,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.attendance.view', only: ['attendance']),
            new Middleware('can:hr.leave.view', only: ['leaves']),
            new Middleware('can:hr.leave.approve', only: ['approve', 'reject']),
        ];
    }

    /** `GET /hr/attendance?date=` — সেদিনের বেতনভুক্ত কর্মী, প্রত্যেকের বসানো অবস্থা, আর অবস্থা ধরে গোনা */
    public function attendance(Request $request): JsonResponse
    {
        $raw = trim((string) $request->query('date', ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? Carbon::parse($raw) : Carbon::today();

        $employees = $this->reach->employees(Employee::query(), $request->user())
            ->onPayrollFor($date)
            ->with(['department', 'designation'])
            ->orderBy('code')
            ->get();

        $marked = $this->reach->throughEmployee(Attendance::query(), $request->user())
            ->whereDate('work_date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        $counts = array_fill_keys([...Attendance::STATUSES, 'unmarked'], 0);
        $rows = $employees->map(function (Employee $e) use ($marked, &$counts) {
            $a = $marked[$e->id] ?? null;
            $status = $a?->status;
            $counts[$status ?? 'unmarked']++;

            return [
                'id' => (string) $e->public_id,
                'code' => (string) $e->code,
                'name' => $e->name(),
                'department' => (string) ($e->department?->name() ?? ''),
                'designation' => (string) ($e->designation?->name() ?? ''),
                'status' => $status,
                'status_label' => $status === null ? __('hr::kind.unmarked') : __('hr::kind.'.$status),
                'late' => (bool) ($a?->is_late ?? false),
                'remarks' => (string) ($a?->remarks ?? ''),
            ];
        })->values();

        return response()->json([
            'date' => $date->toDateString(),
            'rows' => $rows,
            'count' => $rows->count(),
            'counts' => collect($counts)->map(fn (int $n, string $s) => [
                'status' => $s,
                'label' => $s === 'unmarked' ? __('hr::kind.unmarked') : __('hr::kind.'.$s),
                'count' => $n,
            ])->values(),
        ]);
    }

    /** `GET /hr/leave/register?pending=1&page=` — নাগালের কর্মীদের ছুটির আবেদন; `pending` দিলে কেবল অপেক্ষমাণ */
    public function leaves(Request $request): JsonResponse
    {
        $user = $request->user();
        $canDecide = $user->can('hr.leave.approve');
        // ⓘ সেবার নিজের ব্যতিক্রম — super admin নিজের ছুটিও মঞ্জুর করতে পারেন ([[LeaveService::assertNotOwn()]])
        $superAdmin = $user->hasRole(\App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE);

        $page = $this->reach->throughEmployee(LeaveApplication::query(), $user)
            ->with(['employee', 'leaveType', 'decider'])
            ->when($request->boolean('pending'), fn ($q) => $q->pending())
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'rows' => collect($page->items())->map(fn (LeaveApplication $a) => [
                'id' => (string) $a->public_id,
                'employee' => (string) ($a->employee?->name() ?? ''),
                'type' => (string) ($a->leaveType?->name() ?? ''),
                'from' => DateFormat::format($a->from_date),
                'to' => DateFormat::format($a->to_date),
                'days' => (string) $a->days,
                'status' => (string) $a->status,
                'status_label' => __('hr::kind.'.$a->status),
                'reason' => (string) ($a->reason ?? ''),
                'decider' => (string) ($a->decider?->name ?? ''),
                // ⓘ বোতাম কেবল অপেক্ষমাণে আর চাবি থাকলে, আর নিজের আবেদনে নয় — তবু শেষ কথা সেবার ([[LeaveService::approve()]])
                'can_decide' => $canDecide && $a->status === LeaveApplication::PENDING
                    && ($superAdmin || (int) ($a->employee?->user_id ?? 0) !== (int) $user->id),
            ])->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `POST /hr/leave/register/{id}/approve` — {remarks?} */
    public function approve(Request $request, string $id): JsonResponse
    {
        $this->leave->approve($this->reachable($request, $id), $request->user(),
            trim((string) $request->input('remarks', '')) ?: null);

        return response()->json(['message' => __('hr::message.leave_approved')]);
    }

    /** `POST /hr/leave/register/{id}/reject` — {remarks} বাধ্যতামূলক */
    public function reject(Request $request, string $id): JsonResponse
    {
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:500']])['remarks'];

        $this->leave->reject($this->reachable($request, $id), $request->user(), $remarks);

        return response()->json(['message' => __('hr::message.leave_rejected')]);
    }

    /** ⛔ নাগালের বাইরের কর্মীর আবেদন ৪০৩ — ওয়েবের [[LeaveController]]-এর একই নিয়ম */
    private function reachable(Request $request, string $id): LeaveApplication
    {
        $found = LeaveApplication::query()->with('employee')->where('public_id', $id)->firstOrFail();

        abort_unless($this->reach->reaches($request->user(), $found->employee), 403);

        return $found;
    }
}
