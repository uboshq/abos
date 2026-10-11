<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\MasterHealth;
use App\Core\Dashboard\Widget;
use App\Core\Services\DataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * মানুষের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন আজকের হাজিরা এখানে ──────────────────────────────────────────
 * বেতন হাজিরা থেকেই হিসাব হয়, আর হাজিরা না বসালে মাস শেষে কেউ টের পায়
 * না — শুধু বেতনের অঙ্কটা ভুল হয়। সংখ্যাটা রোজ চোখের সামনে থাকলে
 * ফাঁকটা ওই দিনেই ধরা পড়ে, ত্রিশ দিন পরে নয়।
 */
final class HrWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $today = Carbon::today();

        return [
            new Widget(
                group: 'today',
                label: __('hr::dashboard.present_today'),
                value: self::presentToday($today).' / '.self::onPayroll($today),
                href: route('hr.attendance.index', ['date' => $today->toDateString()]),
                permission: 'hr.attendance.view',
                tone: 'neutral',
                sort: 60,
            ),

            new Widget(
                group: 'todo',
                label: __('hr::dashboard.leave_awaiting'),
                // ⭐ দেখার শাখার কর্মীর ছুটি — ছুটির সারিতে শাখা নেই, কর্মীর আছে (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬)
                value: (string) LeaveApplication::query()->pending()
                    ->whereIn('employee_id', self::inView(Employee::query(), 'hr_employees.branch_id')->select('id'))
                    ->count(),
                href: route('hr.leave.index'),
                permission: 'hr.leave.approve',
                tone: 'warn',
                sort: 95,
                icon: 'calendar',
            ),
        ];
    }

    /** আজ যাদের হাজিরা "উপস্থিত" হিসেবে বসেছে। */
    private static function presentToday(Carbon $today): int
    {
        // ⭐ দেখার শাখার কর্মী — হাজিরার সারিতে শাখা নেই, কর্মীর আছে (২৯ সেপ্টেম্বর ২০২৬)
        return Attendance::query()
            ->whereIn('employee_id', self::inView(Employee::query(), 'hr_employees.branch_id')->select('id'))
            ->whereDate('work_date', $today->toDateString())
            ->where('status', Attendance::PRESENT)
            ->count();
    }

    /** আজ যাদের বেতনের খাতায় থাকার কথা। */
    private static function onPayroll(Carbon $today): int
    {
        return self::inView(Employee::query(), 'hr_employees.branch_id')->onPayrollFor($today)->count();
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া; "সব শাখা"-তে নাগাল,
     * শাখাহীনসহ ([[DataScope::viewBranchIds()]])। ⛔ কেবল দেখানোর সংখ্যায়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function inView(Builder $query, string $column): Builder
    {
        return app(DataScope::class)->inView($query, $column);
    }

    /**
     * ⭐ কর্মী তালিকার স্বাস্থ্য — মোবাইল, বিভাগ আর পদ ভরা; একই মোবাইল দুইজনের হলে দ্বিতীয়বার ([[MasterHealth]])।
     *
     * @return list<Widget>
     */
    public static function health(): array
    {
        return [MasterHealth::widget(
            label: __('hr::menu.employees'),
            href: route('hr.employee.index'),
            permission: 'hr.employee.view',
            rows: Employee::query(),
            complete: fn ($q) => $q->whereNotNull('hr_employees.mobile')->where('hr_employees.mobile', '<>', '')
                ->whereNotNull('hr_employees.department_id')->whereNotNull('hr_employees.designation_id'),
            sameColumn: 'mobile',
            sort: 40,
        )];
    }
}
