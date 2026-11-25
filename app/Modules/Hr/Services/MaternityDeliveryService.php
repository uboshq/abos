<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\MaternityDelivery;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Support\LeaveSigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ প্রসবের আসল দিন — মাতৃত্বকালীন ছুটির পরের ৮ সপ্তাহ সেদিন থেকে (fe-র উত্তর ৩, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা
 * ৪৫ ও ৪৭; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ধাপ ────────────────────────────────────────────────────────────────
 * ১. **লেখা** ([[record()]]): HR বা কর্মীর ছুটির সইকারী ([[LeaveSigner::mayDecide()]]) মঞ্জুর হওয়া মাতৃত্বকালীন ছুটিতে প্রসবের আসল দিন
 *    লেখেন। কিছুই সরে না — অনুরোধটা "সইয়ের অপেক্ষায়"। নতুন শেষ দিন = আসল দিন + ৫৫ (সেদিনসহ ৫৬ দিন = ৮ সপ্তাহ)।
 * ২. **"সই দিন"** ([[sign()]]): ছুটির সইয়ের একই নিয়ম — কর্মীর নিজের ব্যবস্থাপক বা HR, নিজের ছুটিতে নয়, আর যিনি লিখেছেন তিনি নন
 *    (মালিক ছাড়া, তখন অডিটে `owner_self_signed`)। তবেই ছুটির শেষ দিন আর দিনের সংখ্যা বদলায়, আর অডিটে আগের → নতুন তারিখ।
 *    "প্রত্যাখ্যাত" ([[reject()]]) হলে কিছুই বদলায় না।
 *
 * ── ⛔ দেওয়া মাস কখনো বদলায় না ─────────────────────────────────────────
 * যে মাসের বেতন নিশ্চিত (এই কর্মীর বেতনশিটসহ), তার হাজিরার সারিও বদলায় না ([[PaidMonth]]-এর নিয়ম)। সেই মাসগুলোর টাকার পার্থক্য
 * পরের বেতনের রানে একটা সমন্বয়ের সারিতে ([[PayrollService::buildPayslip()]]):
 *     পার্থক্য = Σ (নতুন মাতৃত্বকালীন দিন − আগের দিন)ₘ × (গড় দৈনিক মজুরি − সেই মাসের কাঠামোর দৈনিক বেতন)ₘ
 * কাঠামোর দৈনিক বেতন = মাস-শেষের কাঠামোর হাজিরায়-কমা আয়ের খাতের যোগ ÷ মাসের দিন — দেওয়া বেতনশিট যে হারে সেই দিনগুলো দিয়েছে।
 * গড় দৈনিক মজুরি না থাকলে ([[MaternityBenefit::averageDailyWage()]] null) কাঠামোই দিয়েছিল, তাই পার্থক্য শূন্য। পয়সায় গোল, শেষে একবার।
 * খোলা মাসের সারি সাথে সাথে বদলায়, আর সেই মাসের রান নিজে থেকেই ঠিক অঙ্ক বানায়।
 */
final class MaternityDeliveryService
{
    public function record(LeaveApplication $application, string $actualDate, User $by): MaternityDelivery
    {
        $type = $application->leaveType;

        if (! $application->isApproved() || ! (bool) $type?->isMaternity()) {
            throw ValidationException::withMessages(['actual_delivery_date' => __('hr::validation.delivery_not_maternity')]);
        }

        $this->assertMaySign($application, $by);

        $actual = Carbon::parse($actualDate)->startOfDay();

        if ($actual->lt($application->from_date->copy()->startOfDay()) || $actual->gt(Carbon::today())) {
            throw ValidationException::withMessages(['actual_delivery_date' => __('hr::validation.delivery_out_of_range')]);
        }

        return DB::transaction(function () use ($application, $actual, $by) {
            Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->lockForUpdate()->first();

            if (MaternityDelivery::query()->where('leave_application_id', $application->id)->where('status', MaternityDelivery::PENDING)->exists()) {
                throw ValidationException::withMessages(['actual_delivery_date' => __('hr::validation.delivery_already_waiting')]);
            }

            return MaternityDelivery::query()->create([
                'company_id' => $application->company_id,
                'leave_application_id' => $application->id,
                'employee_id' => $application->employee_id,
                'actual_delivery_date' => $actual->toDateString(),
                'old_to_date' => $application->to_date->toDateString(),
                'new_to_date' => $actual->copy()->addDays(LeaveService::MATERNITY_WEEKS_EACH_SIDE * 7 - 1)->toDateString(),
                'status' => MaternityDelivery::PENDING,
                'requested_by' => $by->id,
            ])->fresh();
        });
    }

    public function sign(MaternityDelivery $change, User $decider): MaternityDelivery
    {
        $application = LeaveApplication::query()->withoutGlobalScopes()->with('leaveType')->findOrFail($change->leave_application_id);
        $this->assertMaySign($application, $decider);
        $this->assertNotRequestedBy($change, $decider);

        return DB::transaction(function () use ($change, $application, $decider) {
            Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->lockForUpdate()->first();
            $fresh = MaternityDelivery::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($fresh);

            $old = $application->to_date->copy()->startOfDay();
            $new = $fresh->new_to_date->copy()->startOfDay();
            $adjustment = $this->paidMonthsDifference($application, $old, $new);

            $this->moveOpenDays($application, $old, $new);

            $application->forceFill([
                'to_date' => $new->toDateString(),
                'actual_delivery_date' => $fresh->actual_delivery_date->toDateString(),
                'days' => (string) ((int) $application->from_date->copy()->startOfDay()->diffInDays($new) + 1),
            ])->save();

            $application->auditAction('maternity_delivery', __('hr::message.delivery_signed', [
                'actual' => $fresh->actual_delivery_date->toDateString(),
                'old' => $old->toDateString(),
                'new' => $new->toDateString(),
            ]));

            $fresh->forceFill([
                'status' => MaternityDelivery::APPROVED,
                'adjustment' => $adjustment,
                'decided_by' => $decider->id,
                'decided_at' => now(),
            ])->save();

            if ((int) $fresh->requested_by === (int) $decider->id) {
                $fresh->auditAction(LeaveSigner::OWNER_SELF_SIGNED, __('hr::validation.leave_owner_self_signed'));
            }

            return $fresh->fresh();
        });
    }

    public function reject(MaternityDelivery $change, User $decider, string $remarks): MaternityDelivery
    {
        $application = LeaveApplication::query()->withoutGlobalScopes()->with('leaveType')->findOrFail($change->leave_application_id);
        $this->assertMaySign($application, $decider);
        $this->assertNotRequestedBy($change, $decider);

        return DB::transaction(function () use ($change, $decider, $remarks) {
            $fresh = MaternityDelivery::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($fresh);

            $fresh->forceFill([
                'status' => MaternityDelivery::REJECTED,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();

            return $fresh->fresh();
        });
    }

    /**
     * ⭐ দেওয়া মাসগুলোর টাকার পার্থক্য — উপরের সূত্র। ⓘ খোলা মাস এখানে নয় (তাদের রান নিজে বানায়)।
     */
    public function paidMonthsDifference(LeaveApplication $application, Carbon $old, Carbon $new): string
    {
        if ($old->equalTo($new)) {
            return '0.00';
        }

        $average = app(MaternityBenefit::class)->averageDailyWage($application);

        if ($average === null) {
            return '0.00';
        }

        $employee = Employee::query()->withoutGlobalScopes()->findOrFail($application->employee_id);
        [$first, $last] = $old->lt($new) ? [$old->copy()->addDay(), $new] : [$new->copy()->addDay(), $old];
        $sign = $old->lt($new) ? '1' : '-1';
        $total = '0';

        for ($month = $first->copy()->startOfMonth(); $month->lte($last); $month->addMonthNoOverflow()) {
            if (! $this->paid($employee, $month)) {
                continue;
            }

            $from = $first->gt($month) ? $first : $month->copy();
            $to = $last->lt($month->copy()->endOfMonth()) ? $last : $month->copy()->endOfMonth()->startOfDay();
            $days = (string) ((int) $from->diffInDays($to) + 1);

            $total = bcadd($total, bcmul(bcmul($sign, $days, 0), bcsub($average, $this->structureDaily($employee, $month), 10), 10), 10);
        }

        return Money::round($total, 2);
    }

    /** মাস-শেষের কাঠামোর হাজিরায়-কমা আয়ের খাত ÷ মাসের দিন — দেওয়া বেতনশিট যে হারে দিন দিয়েছে */
    private function structureDaily(Employee $employee, Carbon $month): string
    {
        $end = $month->copy()->endOfMonth();
        $sum = '0';

        foreach (app(SalaryStructureService::class)->componentsOn($employee, $end) as $component) {
            if ($component['head']->isEarning() && $component['head']->prorated_by_attendance) {
                $sum = bcadd($sum, $component['amount'], 10);
            }
        }

        return bcdiv($sum, (string) $end->daysInMonth, 10);
    }

    /** এই কর্মীর এই মাসের বেতন নিশ্চিত কি না — [[PaidMonth]]-এর একই প্রশ্ন, ছুড়ে না দিয়ে */
    private function paid(Employee $employee, Carbon $month): bool
    {
        return PayrollRun::acrossBranches()->withoutGlobalScope('company')->where('hr_payroll_runs.company_id', $employee->company_id)
            ->where('status', DocumentStatus::CONFIRMED)
            /*
             * ⛔ কেবল মাসের বেতন — উৎসব ভাতার রান মাসটা "দেওয়া" করে না, [[PaidMonth::assertOpen()]]-এর মতোই (মেশানোর পর, a6, ২১ অক্টোবর
             * ২০২৬; [[TheFestivalBonusIsNotAMaternityMonthTest]])। ⓘ নইলে ঈদের মাসটা দেওয়া ধরে পার্থক্য এখনই বসত, আর সেই মাসের বেতনের
             * রান নতুন তারিখে সুবিধাটা আবার দিত (দুইবার); হাজিরার "ছুটি" সারিও সরত না।
             */
            ->ofType(PayrollRun::SALARY)
            ->whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->whereHas('payslips', fn ($q) => $q->withoutGlobalScope('company')->where('employee_id', $employee->id))
            ->exists();
    }

    /** খোলা মাসের হাজিরা — বাড়লে নতুন দিনে "ছুটি", কমলে বাদ পড়া দিনের "ছুটি" সারি মোছা; দেওয়া মাস অক্ষত */
    private function moveOpenDays(LeaveApplication $application, Carbon $old, Carbon $new): void
    {
        $employee = Employee::query()->withoutGlobalScopes()->findOrFail($application->employee_id);

        if ($new->gt($old)) {
            for ($day = $old->copy()->addDay(); $day->lte($new); $day->addDay()) {
                if (! $this->paid($employee, $day)) {
                    Attendance::writeDay((int) $employee->id, $day->toDateString(), [
                        'status' => Attendance::LEAVE,
                        'leave_application_id' => $application->id,
                        'remarks' => null,
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            return;
        }

        for ($day = $new->copy()->addDay(); $day->lte($old); $day->addDay()) {
            if (! $this->paid($employee, $day)) {
                Attendance::query()->where('leave_application_id', $application->id)->where('status', Attendance::LEAVE)
                    ->whereDate('work_date', $day->toDateString())->delete();
            }
        }
    }

    /** ⛔ ছুটির সইয়ের নিয়ম — নিজের ছুটিতে নয় (মালিক ছাড়া), কর্মীর নিজের ব্যবস্থাপক বা HR ([[LeaveSigner]]) */
    private function assertMaySign(LeaveApplication $application, User $user): void
    {
        $own = Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->value('user_id');

        if ($own !== null && (int) $own === (int) $user->id && ! $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            throw ValidationException::withMessages(['status' => __('hr::validation.leave_own_approval')]);
        }

        app(LeaveSigner::class)->assertMayDecide($user, $application);
    }

    /** ⛔ যিনি আসল দিন লিখেছেন তিনি সই দেন না, ফেরানও না — মালিক ছাড়া */
    private function assertNotRequestedBy(MaternityDelivery $change, User $user): void
    {
        $isOwner = $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE) || app(Ownership::class)->isOwnerIn($user, (int) $change->company_id);

        if ((int) $change->requested_by === (int) $user->id && ! $isOwner) {
            throw ValidationException::withMessages(['status' => __('hr::validation.delivery_entered_by_you')]);
        }
    }

    private function assertPending(MaternityDelivery $change): void
    {
        if (! $change->isPending()) {
            throw ValidationException::withMessages(['status' => __('hr::validation.leave_already_decided')]);
        }
    }
}
