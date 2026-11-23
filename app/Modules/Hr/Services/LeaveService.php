<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\PermissionSyncer;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Support\LeaveSigner;
use App\Modules\Hr\Support\PaidMonth;
use App\Modules\Hr\Support\WorkCalendar;
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
    /** এক আবেদনের তারিখ সবচেয়ে বেশি কত দিন জুড়ে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৫) */
    public const MAX_SPAN_DAYS = 366;

    /** ধারা ৪৬(৩) — এতজন বা তার বেশি জীবিত সন্তানে মাতৃত্বকালীন সুবিধা নয় */
    public const MATERNITY_CHILDREN_LIMIT = 2;

    /** ধারা ৪৭ — প্রসবের আগে ও পরে, প্রতিপাশে এত সপ্তাহ */
    public const MATERNITY_WEEKS_EACH_SIDE = 8;

    /**
     * আবেদন জমা।
     *
     * @param  string  $days  আধা দিনের জন্য দশমিক — "১.৫"
     * @param  string|null  $expectedDelivery  ⭐ মাতৃত্বকালীন ছুটিতে প্রসবের সম্ভাব্য দিন (ধারা ৪৭; বাকি ধরনে উপেক্ষিত)
     */
    public function apply(
        Employee $employee,
        LeaveType $type,
        string $fromDate,
        string $toDate,
        string $days,
        ?string $reason = null,
        ?string $expectedDelivery = null,
    ): LeaveApplication {
        $from = Carbon::parse($fromDate);
        $to = Carbon::parse($toDate);
        $expected = $type->isMaternity() && filled($expectedDelivery) ? Carbon::parse($expectedDelivery)->startOfDay() : null;

        $this->assertDatesMakeSense($from, $to, $days);
        $this->assertWithinWorkingDays($employee, $type, $from, $to, $days);
        $this->assertEmployedThroughout($employee, $from, $to);
        $this->assertEligible($employee, $type, $from, $to, $expected);

        return DB::transaction(function () use ($employee, $type, $from, $to, $days, $reason, $expected) {
            /*
             * ⛔ কর্মীর সারিতে তালা, তারপর মেলা আর কোটা — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৪)।
             * ⓘ লেনদেন বা তালা ছিল না: দুইবার চাপলে বা ফোন আবার পাঠালে দুইটা অনুরোধই "মেলে না" দেখে একই দিনের দুইটা আবেদন বসাত।
             * তালাটা আগে, যাতে লেনদেনের প্রথম পড়াটাই তালার পরে হয় আর আগের অনুরোধের বসানো আবেদন চোখে পড়ে।
             */
            Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->first();

            $this->assertNoOverlap($employee, $from, $to);
            $this->assertWithinYearlyLimit($employee, $type, $from, $days);

            return LeaveApplication::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'expected_delivery_date' => $expected?->toDateString(),
                'days' => $days,
                'reason' => $reason,
                'status' => LeaveApplication::PENDING,
                'created_by' => auth()->id(),
            ])->fresh();
        });
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
        // ⛔ অন্যের নামে যিনি বসিয়েছেন তিনি সই দেন না — মালিক ছাড়া, আর তখন অডিটে চিহ্ন (SoD; fe, ২১ অক্টোবর ২০২৬; [[LeaveSigner]])
        app(LeaveSigner::class)->assertNotEnteredBy($decider, $application);
        // ⛔ কর্মীর নিজের ব্যবস্থাপক বা HR — চাবি থাকলেই যে কেউ নয় (ঘাটতির তালিকা #১৫; [[LeaveSigner]])
        app(LeaveSigner::class)->assertMayDecide($decider, $application);

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

            // ⛔ বেতন হয়ে যাওয়া মাসে মঞ্জুরি নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️২; [[PaidMonth]], [[LeaveIsCountedWhenItIsApprovedTest]])
            PaidMonth::assertOpen($application->from_date, $application->to_date, 'status', 'hr::validation.leave_month_paid_approve');

            $employee = Employee::query()->withoutGlobalScopes()->findOrFail($application->employee_id);
            $type = LeaveType::query()->withoutGlobalScopes()->findOrFail($application->leave_type_id);
            // ⛔ আবেদনের পরে তালিকায় ছুটির দিন বসলে কাজের দিন কমে — আবার মাপা, নইলে কোটা বেশি কাটত ([[assertWithinWorkingDays()]])
            $this->assertWithinWorkingDays($employee, $type, $application->from_date->copy(), $application->to_date->copy(), (string) $application->days);
            // ⛔ মাতৃত্বকালীন ছুটির যোগ্যতা মঞ্জুরের মুহূর্তেও — আবেদনের পরে লিঙ্গ বদলালে বা ফোনের পুরনো আবেদনে ([[assertEligible()]])
            $this->assertEligible($employee, $type, $application->from_date->copy(), $application->to_date->copy(),
                $application->expected_delivery_date?->copy());
            $this->assertWithinYearlyLimit($employee, $type, $application->from_date->copy(), (string) $application->days);

            /*
             * ⛔ মঞ্জুরের মুহূর্তে আবার মেলানো — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৪; [[LeaveIsCountedWhenItIsApprovedTest]])।
             * ⓘ একই দিনের দুইটা আবেদন (আগের দুইবার চাপা) দুটোই মঞ্জুর হত: দিনগুলো কোটায় দুইবার কাটত, হাজিরার সারি দ্বিতীয়টার নামে
             * সরে যেত, আর সেটা প্রত্যাহার করলে প্রথমটা "মঞ্জুর" থেকেও হাজিরায় কিছু থাকত না। কর্মীর তালার নিচে, এই আবেদন বাদে।
             */
            // ⓘ কেবল মঞ্জুর হওয়াগুলোর সাথে — আগে থেকে থাকা দুইটা একই-দিনের অপেক্ষার আবেদনের একটা সরাসরি মঞ্জুর করা যায়, অন্যটা তখন থামে
            $this->assertNoOverlap($employee, $application->from_date->copy(), $application->to_date->copy(), $application->id, [LeaveApplication::APPROVED]);

            $application->forceFill([
                'status' => LeaveApplication::APPROVED,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();

            $this->stampAttendance($application->fresh());
            app(LeaveSigner::class)->markOwnerSelfSigned($decider, $application);

            return $application->fresh();
        });
    }

    public function reject(LeaveApplication $application, User $decider, string $remarks): LeaveApplication
    {
        $this->assertPending($application);
        /*
         * ⛔ ফেরানোও সই — নিজের আবেদনে নয়, আর কেবল কর্মীর নিজের ব্যবস্থাপক বা HR (ঘাটতির তালিকা #১৫; a6, ২১ অক্টোবর ২০২৬)।
         * ⓘ আগে ফেরানোয় কোনো প্রশ্নই ছিল না: চাবি হাতে যে কেউ অন্য দলের ছুটি ফেরাতেন। নিজের অপেক্ষমাণ আবেদন তুলে নেওয়ার পথ
         * আলাদা আর আগের মতোই খোলা ([[cancel()]])।
         */
        $this->assertNotOwn($application, $decider);
        app(LeaveSigner::class)->assertNotEnteredBy($decider, $application);
        app(LeaveSigner::class)->assertMayDecide($decider, $application);

        return DB::transaction(function () use ($application, $decider, $remarks) {
            /*
             * ⛔ তালা দিয়ে তাজা অবস্থা — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৩; [[LeaveIsCountedWhenItIsApprovedTest]])।
             * ⓘ অবস্থা দেখা হত তালার আগের কপি থেকে: একই সেকেন্ডে একজন মঞ্জুর আর আরেকজন নামঞ্জুর চাপলে মঞ্জুরিটা বসে হাজিরায় ছুটির
             * সারি লিখত, তারপর নামঞ্জুর পুরনো কপি দেখে "অপেক্ষমাণ" ভেবে অবস্থা "নামঞ্জুর" করে দিত — কোটায় গোনা নেই, অথচ সারিগুলো রয়ে
             * গেল। [[approve()]]-এর একই নিয়ম: সারিতে তালা, তারপর আবার দেখা।
             */
            $this->lockFresh($application);
            $this->assertPending($application);

            $application->forceFill([
                'status' => LeaveApplication::REJECTED,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();
            app(LeaveSigner::class)->markOwnerSelfSigned($decider, $application);

            return $application->fresh();
        });
    }

    /**
     * প্রত্যাহার — মঞ্জুর হয়ে থাকলে হাজিরার সারিগুলোও ফিরিয়ে নেওয়া।
     *
     * সারিগুলো রেখে দিলে লোকটা কাজে ফিরেও খাতায় ছুটিতে থাকতেন।
     */
    public function cancel(LeaveApplication $application, ?User $by = null): LeaveApplication
    {
        $by ??= auth()->user();

        return DB::transaction(function () use ($application, $by) {
            /*
             * ⛔ তালা দিয়ে তাজা অবস্থা — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৩)। ⓘ হাতের পুরনো কপি দেখে প্রত্যাহার হত: একই
             * সময়ে মঞ্জুরি চললে প্রত্যাহারটা তার আগের অবস্থা দেখত, আর বাতিল আবেদনের নামে ছুটির সারি থেকে যেতে পারত। কর্মীর সারিতে
             * আগে তালা — [[approve()]]-এর একই ক্রম, তাই দুইটা একে অন্যের জন্য আটকে থাকে না।
             */
            Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->lockForUpdate()->first();
            $this->lockFresh($application);

            if ($application->status === LeaveApplication::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('hr::validation.leave_already_cancelled'),
                ]);
            }

            /*
             * ⛔ নিজের মঞ্জুর হওয়া ছুটি নিজে প্রত্যাহার নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৭; [[LeaveIsCountedWhenItIsApprovedTest]])।
             * ⓘ নিজের ছুটি নিজে মঞ্জুর আটকানো ছিল, কিন্তু কেবল `hr.leave.manage`-ওয়ালা কর্মী বিনা বেতনের ছুটি কাটিয়ে বেতনের আগে নিজেই
             * সেটা প্রত্যাহার করতে পারতেন — ছুটির সারি মুছে যেত, খালি দিন কাটে না, আর কোটাও ফিরত। মঞ্জুরির একই নিয়ম ([[assertNotOwn()]]):
             * কেবল মালিক (সুপার অ্যাডমিন) নিজেরটা পারেন। অপেক্ষমাণ আবেদন নিজে তুলে নেওয়া আগের মতোই চলে।
             */
            if ($by instanceof User && $application->isApproved()) {
                $this->assertNotOwn($application, $by, 'hr::validation.leave_own_withdrawal');
            }

            /*
             * ⛔ বেতন হয়ে যাওয়া মাসের হাজিরা মোছা নয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ১০; [[ALeaveInAPaidMonthStaysTest]])।
             * ⓘ প্রত্যাহারে ছুটির দিনগুলোর হাজিরা মুছে যেত, অথচ সেই মাসের বেতন ওই হাজিরা ধরেই হিসাব হয়ে খাতায় বসে গেছে — বেতনশিট আর
             * হাজিরার পাতা আলাদা কথা বলত, আর বিনা বেতনের ছুটি হলে কাটা টাকার কোনো কারণ আর খুঁজে পাওয়া যেত না। আগে সে মাসের রান বাতিল।
             */
            PaidMonth::assertOpen($application->from_date, $application->to_date, 'status', 'hr::validation.leave_month_paid');

            /*
             * ⛔ কেবল যে সারি এখনো "ছুটি" — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৮; [[LeaveIsCountedWhenItIsApprovedTest]])।
             * ⓘ ছুটির মাঝে কর্মী কাজে এলে অফিস সেদিনের সারি "উপস্থিত" করে, কিন্তু আবেদনের দাগটা সারিতে থেকে যায়; প্রত্যাহারে দাগ ধরে
             * মোছায় অফিসের সেই সংশোধনও মুছে যেত, আর দিনটা খালি হত — কাজে আসার খবরই হারাত।
             */
            Attendance::query()
                ->where('leave_application_id', $application->id)
                ->where('status', Attendance::LEAVE)
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
     * ⭐ অর্জিত ছুটিতে পাওনা = এ বছরের পাওনা (এক বছর চাকরির পরে, আগের বছরের কাজ থেকে) + আগের বছরের জের — বাঁধা কোটা নয় (শ্রম আইন ধারা ১১৭;
     * [[EarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)। বাকি ধরনে `accrued`/`carried` খালি।
     *
     * @return array{allowed: string, taken: string, left: string|null, accrued: string|null, carried: string|null}
     */
    public function balance(Employee $employee, LeaveType $type, ?Carbon $on = null): array
    {
        $on ??= now();

        $taken = self::takenInYear($on)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->get()
            ->reduce(fn (string $carry, LeaveApplication $a) => bcadd($carry, (string) $a->days, 1), '0');

        if ($type->isEarned()) {
            $earned = app(EarnedLeave::class);
            $accrued = $earned->accrued($employee, $on);
            $carried = $earned->carriedInto($employee, $type, $on->year);
            $allowed = bcadd($accrued, $carried, 1);

            return ['allowed' => $allowed, 'taken' => $taken, 'left' => bcsub($allowed, $taken, 1),
                'accrued' => $accrued, 'carried' => $carried];
        }

        return [
            'allowed' => (string) $type->days_per_year,
            'taken' => $taken,
            // সীমাহীন ছুটিতে "কত বাকি" প্রশ্নটারই মানে নেই
            'left' => $type->hasNoYearlyLimit() ? null : bcsub((string) $type->days_per_year, $taken, 1),
            'accrued' => null,
            'carried' => null,
        ];
    }

    /**
     * ⭐ চাকরি ছাড়ার দিনে না-নেওয়া অর্জিত ছুটির দিন — চূড়ান্ত পাওনার কাজ এটা ডাকে (শ্রম আইন ধারা ১১ ও ১১৭; fe, ২১ অক্টোবর ২০২৬;
     * [[EarnedLeave::encashableDays()]])। ⓘ কেবল পড়ে; যোগ্যতার (১ বছর) আগে শূন্য।
     */
    public function encashableEarnedDays(Employee $employee, Carbon $on): string
    {
        return app(EarnedLeave::class)->encashableDays($employee, $on);
    }

    /**
     * ⭐ সেই দিনগুলোর টাকা — দিন × (মাসিক মজুরি ÷ ৩০), পয়সায় গোল ([[EarnedLeave::encashment()]])।
     *
     * @return array{days: string, monthly_wage: string, daily_rate: string, amount: string}
     */
    public function earnedLeaveEncashment(Employee $employee, Carbon $on): array
    {
        return app(EarnedLeave::class)->encashment($employee, $on);
    }

    /**
     * ⭐ একটা বছরে নেওয়া ছুটি — মঞ্জুর হওয়া আবেদন, শুরুর দিন সেই বছরে (a6, ২১ অক্টোবর ২০২৬; ঘাটতির তালিকা #১৬)।
     *
     * ⓘ [[balance()]] এখান থেকে একজনের এক ধরন নেয়, আর ছুটির জের রিপোর্ট ([[HrReports::leaveBalance()]]) একই আবেদনগুলো কর্মী × ধরন
     * ধরে যোগ করে। ⛔ দুই জায়গায় দুই প্রশ্ন হলে ফোনের "কত বাকি" আর রিপোর্টের "বাকি" একদিন আলাদা হত।
     *
     * @return \Illuminate\Database\Eloquent\Builder<LeaveApplication>
     */
    public static function takenInYear(Carbon $on): \Illuminate\Database\Eloquent\Builder
    {
        return LeaveApplication::query()
            ->approved()
            ->whereBetween('from_date', [$on->copy()->startOfYear()->toDateString(), $on->copy()->endOfYear()->toDateString()]);
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
        $calendar = app(WorkCalendar::class);
        $employee = Employee::query()->withoutGlobalScopes()->find($application->employee_id);
        $application->setRelation('employee', $employee);
        $branchId = $employee?->branch_id === null ? null : (int) $employee->branch_id;
        // ⓘ মাতৃত্বকালীন ছুটি ক্যালেন্ডারের দিনে — ছুটির দিনেও "ছুটি"-র সারি ([[LeaveType::countsCalendarDays()]])
        $everyDay = (bool) LeaveType::query()->withoutGlobalScopes()->find($application->leave_type_id)?->countsCalendarDays();

        while ($day->lte($application->to_date)) {
            /*
             * ⭐ ছুটির দিনে "ছুটি"-র সারি বসে না — মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬ (শ্রম আইন ধারা ১১৮; [[WorkCalendar]]; a6)।
             * ⓘ শুক্রবার বা তালিকার ছুটির দিন আবেদনের দিন নয়; সেদিনের সারি (থাকলে) যেমন আছে তেমন থাকে, না থাকলে খালি — "লেখা হয়নি"।
             */
            if (! $everyDay && $calendar->isOffDay($day, (int) $application->company_id, $branchId)) {
                $day->addDay();

                continue;
            }

            /*
             * ⛔ আধা দিন লেখা থাকে — একই পুনঃঅডিট (HR ৮)। ⓘ "১.৫ দিন" দুই তারিখে বসত দুইটা পুরো দিনের সারি হয়ে, আর বিনা বেতনের
             * ছুটিতে বেতন থেকে দুই দিন কাটত। দিনের ভাগটা [[Attendance::leaveShare()]] গোনে; এখানে কেবল পর্দার জন্য মন্তব্য।
             */
            $share = Attendance::shareOf($application, $day, $calendar);

            // ⓘ কর্মীর সারিতে তালা [[approve()]]-এ আগেই; একসাথে অফিসের পাতা বা ফোন একই দিন লিখলে ৫০০ নয় (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬, ⓘ১২)
            Attendance::writeDay((int) $application->employee_id, $day->toDateString(), [
                'status' => Attendance::LEAVE,
                'leave_application_id' => $application->id,
                'remarks' => bccomp($share, '1', 1) < 0 ? __('hr::message.leave_part_day', ['share' => $share]) : null,
                'created_by' => auth()->id(),
            ]);

            $day->addDay();
        }
    }

    /**
     * ⛔ নিজের ছুটি নিজে মঞ্জুর নয় — একই পুনঃঅডিট (HR ৮)। ⓘ ছুটি মঞ্জুরের চাবি থাকলে ব্যবস্থাপক নিজের আবেদনও মঞ্জুর করতে
     * পারতেন। সইয়ের ইঞ্জিনের নিয়মেই ([[ApprovalEngine::canDecide()]]) কেবল মালিক (সুপার অ্যাডমিন) নিজের কাগজে সই দিতে পারেন।
     */
    private function assertNotOwn(LeaveApplication $application, User $decider, string $messageKey = 'hr::validation.leave_own_approval'): void
    {
        $own = Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->value('user_id');

        if ($own !== null && (int) $own === (int) $decider->id && ! $decider->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            throw ValidationException::withMessages([
                'status' => __($messageKey),
            ]);
        }
    }

    /** আবেদনের সারিতে তালা, আর হাতের কপিতে তালার নিচের তাজা অবস্থা — লেনদেনের ভিতরেই ডাকতে হয় */
    private function lockFresh(LeaveApplication $application): void
    {
        $fresh = LeaveApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
        $application->setRawAttributes($fresh->getAttributes(), true);
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
        $spanDays = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $span = (string) $spanDays;

        /*
         * ⛔ এক আবেদনে এক বছরের বেশি নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৫; [[LeaveIsCountedWhenItIsApprovedTest]])।
         * ⓘ সাল ভুল টাইপ করলে (২০২৬-এর বদলে ২০৬২) "২ দিন" চাওয়াও পার হত, আর মঞ্জুরিতে হাজার হাজার "ছুটি"-র সারি বসত।
         */
        if ($spanDays > self::MAX_SPAN_DAYS) {
            throw ValidationException::withMessages([
                'to_date' => __('hr::validation.leave_range_too_long', ['max' => self::MAX_SPAN_DAYS]),
            ]);
        }

        if (bccomp($days, $span, 1) > 0) {
            throw ValidationException::withMessages([
                'days' => __('hr::validation.days_exceed_range', ['span' => $span]),
            ]);
        }

        /*
         * ⓘ তারিখের চেয়ে কম দিন চলে — আধা দিন বা ছুটির শেষে আগে ফেরা। শুক্রবার আর ছুটির দিনের প্রশ্নের উত্তর মালিক দিয়েছেন
         * (২১ অক্টোবর ২০২৬): গোনা হয় না — [[assertWithinWorkingDays()]]। হাজিরায় দিনগুলো প্রথম কাজের দিন থেকে ভরে
         * ([[Attendance::shareOf()]]), ছুটির দিন বাদ দিয়ে।
         */
    }

    /**
     * ⭐ ছুটির দিন কেবল কাজের দিন — সাপ্তাহিক ছুটি আর তালিকার ছুটি বাদ (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬
     * ধারা ১১৫–১১৮; [[WorkCalendar]]; a6, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ আগে সীমা ছিল ক্যালেন্ডারের দিন: শনি–বৃহস্পতি "৭ দিন" চাওয়া যেত, আর মাঝের শুক্রবারও কোটা থেকে কাটত; বিজয় দিবস পড়লে সেটাও।
     * এখন চাওয়া দিন পরিসরের কাজের দিনের বেশি নয় — কর্মীর শাখার ছুটি ধরে। ⛔ পুরো পরিসর ছুটির দিন হলে (কেবল একটা শুক্রবার) কাজের
     * দিন শূন্য, তাই কোনো দিনই চাওয়া যায় না — ছুটির দিনে ছুটির আবেদন অর্থহীন।
     */
    private function assertWithinWorkingDays(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, string $days): void
    {
        // ⓘ মাতৃত্বকালীন ছুটি সপ্তাহে, ক্যালেন্ডারের দিনে — পরিসরের সীমা উপরে [[assertDatesMakeSense()]]-এ ([[LeaveType::countsCalendarDays()]])
        if ($type->countsCalendarDays()) {
            return;
        }

        $working = (string) app(WorkCalendar::class)->workingDays(
            $from, $to, (int) $employee->company_id, $employee->branch_id === null ? null : (int) $employee->branch_id);

        if (bccomp($days, $working, 1) > 0) {
            throw ValidationException::withMessages([
                'days' => __('hr::validation.days_exceed_working', ['working' => $working]),
            ]);
        }
    }

    /**
     * ⭐ মাতৃত্বকালীন ছুটি কার — কেবল নারী কর্মী, আর ছুটির প্রথম দিনে চাকরি অন্তত ৬ মাস (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ
     * শ্রম আইন ২০০৬ অধ্যায় ৪, ধারা ৪৬; a6, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ লিঙ্গ কর্মীর পাতার ঘর (`gender`, পরিচয়ের চাবির পেছনে); খালি থাকলে "নারী" নয় — তাই আগে পাতায় লিখতে হয়। ৬ মাস = যোগদানের
     * তারিখ + ৬ ক্যালেন্ডার মাস (মাসের শেষ পেরোয় না: ৩১ আগস্ট + ৬ = ২৮ ফেব্রুয়ারি), সেদিন বা তার পরে শুরু হলে চলে। যোগদানের তারিখ
     * না থাকলে চাকরির বয়স মাপা যায় না, তাই ফেরে। ⓘ বেতনসহ — ধরনের `is_paid`; টাকা আগের তিন মাসের গড় দৈনিক মজুরিতে
     * ([[MaternityBenefit]], ধারা ৪৮)।
     *
     * ⭐ fe-র উত্তর ২ (২১ অক্টোবর ২০২৬) — আরও দুই শর্ত:
     *  · **ধারা ৪৬(৩):** জীবিত সন্তান দুই বা তার বেশি হলে মাতৃত্বকালীন সুবিধা নয় (অন্য পাওনা ছুটি চলে)। ঘর খালি = "তথ্য নেই" —
     *    আটকায় না, সইকারীকে সতর্ক করে ([[LeaveApplication::signerWarning()]])।
     *  · **ধারা ৪৭ — ৮ + ৮ সপ্তাহ:** প্রসবের সম্ভাব্য দিন লাগে; ছুটি শুরু সেদিনের বড়জোর ৫৬ দিন আগে, শেষ সেদিনসহ বড়জোর ৫৬ দিনে
     *    (সম্ভাব্য দিন + ৫৫)। মোট ১১২ দিনের সীমা ধরনের কোটায়।
     */
    private function assertEligible(Employee $employee, LeaveType $type, Carbon $from, ?Carbon $to = null, ?Carbon $expected = null): void
    {
        if (! $type->isMaternity()) {
            return;
        }

        if ($employee->gender !== Employee::FEMALE) {
            throw ValidationException::withMessages([
                'leave_type_id' => __('hr::validation.maternity_women_only'),
            ]);
        }

        $eligibleFrom = $employee->joining_date?->copy()->startOfDay()->addMonthsNoOverflow(LeaveType::MATERNITY_SERVICE_MONTHS);

        if ($eligibleFrom === null || $from->copy()->startOfDay()->lt($eligibleFrom)) {
            throw ValidationException::withMessages([
                'from_date' => __('hr::validation.maternity_six_months', ['date' => $eligibleFrom?->toDateString() ?? '—']),
            ]);
        }

        if ($employee->surviving_children !== null && (int) $employee->surviving_children >= self::MATERNITY_CHILDREN_LIMIT) {
            throw ValidationException::withMessages([
                'leave_type_id' => __('hr::validation.maternity_two_children', ['count' => (int) $employee->surviving_children]),
            ]);
        }

        if ($expected === null) {
            throw ValidationException::withMessages([
                'expected_delivery_date' => __('hr::validation.maternity_expected_required'),
            ]);
        }

        $earliest = $expected->copy()->subDays(self::MATERNITY_WEEKS_EACH_SIDE * 7);
        $latest = $expected->copy()->addDays(self::MATERNITY_WEEKS_EACH_SIDE * 7 - 1);

        if ($from->copy()->startOfDay()->lt($earliest)) {
            throw ValidationException::withMessages([
                'from_date' => __('hr::validation.maternity_too_early', ['date' => $earliest->toDateString()]),
            ]);
        }

        if ($to !== null && $to->copy()->startOfDay()->gt($latest)) {
            throw ValidationException::withMessages([
                'to_date' => __('hr::validation.maternity_too_late', ['date' => $latest->toDateString()]),
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
    /** @param  list<string>  $statuses */
    private function assertNoOverlap(Employee $employee, Carbon $from, Carbon $to, ?int $except = null,
        array $statuses = [LeaveApplication::PENDING, LeaveApplication::APPROVED]): void
    {
        $clash = LeaveApplication::query()
            ->where('employee_id', $employee->id)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->whereIn('status', $statuses)
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
