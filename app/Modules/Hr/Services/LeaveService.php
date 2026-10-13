<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ছুটির আবেদন — জমা, মঞ্জুর, নামঞ্জুর, প্রত্যাহার।
 *
 * ── কেন মঞ্জুরিটা হাজিরার খাতায় গিয়ে বসে ────────────────────────────
 * ছুটি মঞ্জুর করে চুপ করে থাকলে ওই দিনগুলো হাজিরার খাতায় খালি থাকত।
 * বেতনের হিসাব খালি দিনকে অনুপস্থিত ধরত, আর মঞ্জুর করা ছুটির জন্যই
 * লোকটার বেতন কাটা যেত — যা সবচেয়ে খারাপ ধরনের ভুল: নিয়ম মেনে চলার
 * শাস্তি।
 */
final class LeaveService
{
    /**
     * আবেদন জমা।
     *
     * @param  string  $days  আধা দিনের জন্য দশমিক — "১.৫"
     */
    public function apply(
        Employee $employee,
        LeaveType $type,
        string $fromDate,
        string $toDate,
        string $days,
        ?string $reason = null,
    ): LeaveApplication {
        $from = Carbon::parse($fromDate);
        $to = Carbon::parse($toDate);

        $this->assertDatesMakeSense($from, $to, $days);
        $this->assertEmployedThroughout($employee, $from, $to);
        $this->assertNoOverlap($employee, $from, $to);
        $this->assertWithinYearlyLimit($employee, $type, $from, $days);

        return LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $days,
            'reason' => $reason,
            'status' => LeaveApplication::PENDING,
            'created_by' => auth()->id(),
        ])->fresh();
    }

    /**
     * মঞ্জুর — আর সাথে সাথেই হাজিরার খাতায় দিনগুলো বসানো।
     *
     * দুইটা এক লেনদেনে। আলাদা করলে একদিন মঞ্জুরিটা বসত অথচ হাজিরা
     * বসত না, আর সেই মাসের বেতনে লোকটা অনুপস্থিত গোনা হত।
     */
    public function approve(LeaveApplication $application, User $decider, ?string $remarks = null): LeaveApplication
    {
        $this->assertPending($application);
        $this->assertNotOwn($application, $decider);

        return DB::transaction(function () use ($application, $decider, $remarks) {
            /*
             * ⛔ তালা দিয়ে তাজা অবস্থা, আর কোটা আবার — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৮; [[LeaveIsCountedWhenItIsApprovedTest]])।
             * ⓘ আবেদনের সময় কোটা মাপা হত কেবল মঞ্জুর হওয়াগুলো ধরে; একই কর্মীর তিনটা অপেক্ষমাণ আবেদন একে একে (বা দুইজন একসাথে)
             * মঞ্জুর করলে প্রত্যেকটা আলাদাভাবে পার হত, আর বছরের ছুটি সীমা ছাড়াত। কর্মীর সারিতে তালা, যাতে একই কর্মীর দুইটা মঞ্জুরি
             * একসাথে গুনতে না পারে; তারপর এই আবেদন বাদে মঞ্জুর হওয়াগুলোর উপর আবার মাপা।
             */
            Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->lockForUpdate()->first();
            $fresh = LeaveApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $application->setRawAttributes($fresh->getAttributes(), true);
            $this->assertPending($application);

            $employee = Employee::query()->withoutGlobalScopes()->findOrFail($application->employee_id);
            $type = LeaveType::query()->withoutGlobalScopes()->findOrFail($application->leave_type_id);
            $this->assertWithinYearlyLimit($employee, $type, $application->from_date->copy(), (string) $application->days);

            $application->forceFill([
                'status' => LeaveApplication::APPROVED,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();

            $this->stampAttendance($application->fresh());

            return $application->fresh();
        });
    }

    public function reject(LeaveApplication $application, User $decider, string $remarks): LeaveApplication
    {
        $this->assertPending($application);

        $application->forceFill([
            'status' => LeaveApplication::REJECTED,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_remarks' => $remarks,
        ])->save();

        return $application->fresh();
    }

    /**
     * প্রত্যাহার — মঞ্জুর হয়ে থাকলে হাজিরার সারিগুলোও ফিরিয়ে নেওয়া।
     *
     * সারিগুলো রেখে দিলে লোকটা কাজে ফিরেও খাতায় ছুটিতে থাকতেন।
     */
    public function cancel(LeaveApplication $application): LeaveApplication
    {
        if ($application->status === LeaveApplication::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.leave_already_cancelled'),
            ]);
        }

        /*
         * ⛔ বেতন হয়ে যাওয়া মাসের হাজিরা মোছা নয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ১০; [[ALeaveInAPaidMonthStaysTest]])।
         * ⓘ প্রত্যাহারে ছুটির দিনগুলোর হাজিরা মুছে যেত, অথচ সেই মাসের বেতন ওই হাজিরা ধরেই হিসাব হয়ে খাতায় বসে গেছে — বেতনশিট আর
         * হাজিরার পাতা আলাদা কথা বলত, আর বিনা বেতনের ছুটি হলে কাটা টাকার কোনো কারণ আর খুঁজে পাওয়া যেত না। আগে সে মাসের রান বাতিল।
         */
        $paid = PayrollRun::acrossBranches()
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereDate('month', '>=', $application->from_date->copy()->startOfMonth()->toDateString())
            ->whereDate('month', '<=', $application->to_date->copy()->startOfMonth()->toDateString())
            ->orderBy('month')
            ->first();

        if ($paid !== null) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.leave_month_paid', [
                    'month' => $paid->month->locale(app()->getLocale())->translatedFormat('F Y'),
                    'no' => $paid->document_no,
                ]),
            ]);
        }

        return DB::transaction(function () use ($application) {
            Attendance::query()
                ->where('leave_application_id', $application->id)
                ->delete();

            $application->forceFill(['status' => LeaveApplication::CANCELLED])->save();

            return $application->fresh();
        });
    }

    /**
     * এই বছরে এই ধরনের ছুটি কত নেওয়া হয়েছে আর কত বাকি।
     *
     * ── কেন গোনা হয়, জমা রাখা হয় না ────────────────────────────────
     * ব্যালেন্স কলামে রাখলে একটা আবেদন বাতিল হওয়ার দিনে সেটা কমাতে
     * ভুললে সংখ্যাটা চিরকাল ভুল থেকে যেত, আর কেউ ধরতে পারত না —
     * কারণ ভুলটা দেখতে একদম স্বাভাবিক।
     *
     * @return array{allowed: string, taken: string, left: string|null}
     */
    public function balance(Employee $employee, LeaveType $type, ?Carbon $on = null): array
    {
        $on = $on ?? now();
        $yearStart = $on->copy()->startOfYear();
        $yearEnd = $on->copy()->endOfYear();

        $taken = LeaveApplication::query()
            ->approved()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereBetween('from_date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->get()
            ->reduce(fn (string $carry, LeaveApplication $a) => bcadd($carry, (string) $a->days, 1), '0');

        return [
            'allowed' => (string) $type->days_per_year,
            'taken' => $taken,
            // সীমাহীন ছুটিতে "কত বাকি" প্রশ্নটারই মানে নেই
            'left' => $type->hasNoYearlyLimit() ? null : bcsub((string) $type->days_per_year, $taken, 1),
        ];
    }

    /**
     * মঞ্জুর হওয়া ছুটির প্রতিটা দিন হাজিরার খাতায়।
     *
     * আগে থেকে কোনো সারি থাকলে সেটাই বদলে যায় — একজনের এক দিনে দুইটা
     * হাজিরা মানে দুইটা সত্য।
     */
    private function stampAttendance(LeaveApplication $application): void
    {
        $day = $application->from_date->copy();

        while ($day->lte($application->to_date)) {
            /*
             * ⛔ আধা দিন লেখা থাকে — একই পুনঃঅডিট (HR ৮)। ⓘ "১.৫ দিন" দুই তারিখে বসত দুইটা পুরো দিনের সারি হয়ে, আর বিনা বেতনের
             * ছুটিতে বেতন থেকে দুই দিন কাটত। দিনের ভাগটা [[Attendance::leaveShare()]] গোনে; এখানে কেবল পর্দার জন্য মন্তব্য।
             */
            $share = Attendance::shareOf($application, $day);

            Attendance::query()->updateOrCreate(
                [
                    'employee_id' => $application->employee_id,
                    'work_date' => $day->toDateString(),
                ],
                [
                    'status' => Attendance::LEAVE,
                    'leave_application_id' => $application->id,
                    'remarks' => bccomp($share, '1', 1) < 0 ? __('hr::message.leave_part_day', ['share' => $share]) : null,
                    'created_by' => auth()->id(),
                ],
            );

            $day->addDay();
        }
    }

    /**
     * ⛔ নিজের ছুটি নিজে মঞ্জুর নয় — একই পুনঃঅডিট (HR ৮)। ⓘ ছুটি মঞ্জুরের চাবি থাকলে ব্যবস্থাপক নিজের আবেদনও মঞ্জুর করতে
     * পারতেন। সইয়ের ইঞ্জিনের নিয়মেই ([[ApprovalEngine::canDecide()]]) কেবল মালিক (সুপার অ্যাডমিন) নিজের কাগজে সই দিতে পারেন।
     */
    private function assertNotOwn(LeaveApplication $application, User $decider): void
    {
        $own = Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->value('user_id');

        if ($own !== null && (int) $own === (int) $decider->id && ! $decider->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.leave_own_approval'),
            ]);
        }
    }

    private function assertPending(LeaveApplication $application): void
    {
        if (! $application->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.leave_already_decided'),
            ]);
        }
    }

    private function assertDatesMakeSense(Carbon $from, Carbon $to, string $days): void
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to_date' => __('hr::validation.to_before_from'),
            ]);
        }

        if (bccomp($days, '0', 1) <= 0) {
            throw ValidationException::withMessages([
                'days' => __('hr::validation.days_must_be_positive'),
            ]);
        }

        /*
         * তারিখের পরিসরের চেয়ে বেশি দিন চাওয়া যায় না।
         *
         * এক দিনের ছুটিতে "পাঁচ দিন" লিখলে বেতন থেকে পাঁচ দিন কাটত,
         * অথচ কাগজে একটাই দিন — আর মিলটা কেউ মেলাত না।
         */
        $span = (string) ($from->diffInDays($to) + 1);

        if (bccomp($days, $span, 1) > 0) {
            throw ValidationException::withMessages([
                'days' => __('hr::validation.days_exceed_range', ['span' => $span]),
            ]);
        }
    }

    private function assertEmployedThroughout(Employee $employee, Carbon $from, Carbon $to): void
    {
        if (! $employee->wasEmployedOn($from) || ! $employee->wasEmployedOn($to)) {
            throw ValidationException::withMessages([
                'from_date' => __('hr::validation.not_employed_then'),
            ]);
        }
    }

    /**
     * একই দিনে দুইটা ছুটি নয়।
     *
     * থাকতে দিলে একই দিন দুইবার কোটা থেকে কাটত, আর হাজিরার সারিটা
     * শেষে যেটা বসত সেটাই টিকত — কোনটা, তা নির্ধারিত থাকত না।
     */
    private function assertNoOverlap(Employee $employee, Carbon $from, Carbon $to): void
    {
        $clash = LeaveApplication::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveApplication::PENDING, LeaveApplication::APPROVED])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'from_date' => __('hr::validation.leave_overlaps'),
            ]);
        }
    }

    private function assertWithinYearlyLimit(Employee $employee, LeaveType $type, Carbon $from, string $days): void
    {
        if ($type->hasNoYearlyLimit()) {
            return;
        }

        $balance = $this->balance($employee, $type, $from);

        if (bccomp($days, (string) $balance['left'], 1) > 0) {
            throw ValidationException::withMessages([
                'days' => __('hr::validation.not_enough_leave', [
                    'left' => $balance['left'],
                    'type' => $type->name(),
                ]),
            ]);
        }
    }
}
