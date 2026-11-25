<?php

declare(strict_types=1);

namespace App\Modules\Hr\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveCarryForward;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EarnedLeave;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\ProvidentFund;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ HR-এর চারটা রিপোর্ট — হাজিরার সারাংশ, বেতনশিট, কর্মী সংখ্যা, ছুটির জের (ঘাটতির তালিকা #১৬; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল না ──────────────────────────────────────────────────────
 * HR-এর কোনো রিপোর্টই ছিল না: রিপোর্ট সেন্টারে হাজিরা আর বেতনশিট "বাকি" তালিকায় ([[ReportCenterPlan]])। মাসের শেষে কে কতদিন
 * এলেন, কার কত বেতন গেল, কোন বিভাগে কতজন, কার কত ছুটি বাকি — প্রতিটা প্রশ্নে একজন একজন করে পাতা খুলতে হত (Oracle/SAP-এর
 * প্রমিত HR বিশ্লেষণে চারটাই প্রথম সারির)।
 *
 * ── ⭐ এক উৎস ─────────────────────────────────────────────────────────
 * ⓘ হাজিরার গোনা [[AttendanceService::rowsBetween()]]-এর সেই সারিগুলো, যা [[AttendanceService::monthlySummary()]] গোনে; ছুটির
 * নেওয়া [[LeaveService::takenInYear()]]-এর সেই আবেদনগুলো, যা [[LeaveService::balance()]] যোগ করে। ⛔ দুই জায়গায় দুই প্রশ্ন
 * লিখলে একদিন কর্মীর পাতা আর রিপোর্ট দুই সংখ্যা বলত — আর কোনটা ঠিক, কেউ বলতে পারত না।
 *
 * ── ⭐ শাখার দেয়াল ──────────────────────────────────────────────────
 * চারটাই কর্মীর শাখা ধরে (`hr_employees.branch_id`) — HR-এর পর্দাগুলোর একই দেয়াল ([[BranchReach::throughEmployee()]]): নাগালের
 * শাখা আর শাখাহীন (প্রধান অফিসের) কর্মী। ⓘ বেতনের রানের নিজের শাখা নেই — রান পুরো কোম্পানির, তাই বেতনশিটও কর্মী ধরে।
 */
final class HrReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::attendanceSummary());
        $engine->register(self::payrollRegister());
        $engine->register(self::headcount());
        $engine->register(self::leaveBalance());
        $engine->register(self::providentFund());
    }

    /**
     * ⭐ ভবিষ্য তহবিলের খাতা — কর্মী আর মাস ধরে: কর্মীর ভাগ, প্রতিষ্ঠানের ভাগ, আর সেই মাস পর্যন্ত চলতি জের (a6, ২১ অক্টোবর ২০২৬;
     * [[ProvidentFund::contributions()]])।
     *
     * ⓘ কেবল নিশ্চিত রান — বেতনশিটের রিপোর্টের একই কারণে। জের গোনা হয় সব মাস ধরে, তারপর পরিসর ছাঁকা হয় — তাই পরিসরের প্রথম সারির
     * জেরেও আগের সব জমা আছে (খাতার প্রারম্ভিক জেরের মতো)। ⓘ কর্মীর পাতার তহবিলের অংশ ([[ProvidentFund::ledgerOf()]]) একই প্রশ্ন পড়ে।
     * ⛔ পুরো রিপোর্টটাই টাকা — দরজার চাবি `hr.salary.view`, কলামেও।
     */
    public static function providentFund(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hr.provident_fund',
            permission: 'hr.salary.view',
            title: 'hr::menu.report_provident_fund',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                // ⓘ ভেতরের প্রশ্নটা বেতনের স্লিপের সারির মডেল থেকে — কোম্পানির দেয়াল সেখানেই ([[ProvidentFund::contributions()]])
                $inner = app(ProvidentFund::class)->contributions();
                $running = $inner->newQuery()->fromSub($inner, 'c')
                    ->select(['c.employee_id', 'c.month', 'c.run_id', 'c.employee_share', 'c.employer_share'])
                    ->selectRaw('SUM(c.employee_share + c.employer_share) OVER (PARTITION BY c.employee_id ORDER BY c.month) as balance');

                return self::staff($f)
                    ->joinSub($running, 'pf', 'pf.employee_id', '=', 'hr_employees.id')
                    ->whereBetween('pf.month', [Carbon::parse($f['from'])->startOfMonth()->toDateString(), $f['to']])
                    ->orderBy('hr_employees.code')
                    ->orderBy('pf.month')
                    ->select([
                        DB::raw("DATE_FORMAT(pf.month, '%Y-%m') as pay_month"),
                        DB::raw(self::employeeLabel().' as employee'),
                        DB::raw(self::nameOf('d').' as department'),
                        'pf.run_id',
                        DB::raw("'".PayrollRun::drillSourceType()."' as run_source"),
                        'pf.employee_id as staff_id',
                        DB::raw("'".Employee::drillSourceType()."' as staff_source"),
                        'pf.employee_share',
                        'pf.employer_share',
                        DB::raw('pf.employee_share + pf.employer_share as month_total'),
                        'pf.balance',
                    ]);
            },
            columns: [
                ['key' => 'pay_month', 'label' => 'hr::field.month', 'width' => '6rem'],
                ['key' => 'employee', 'label' => 'hr::field.employee', 'width' => '14rem'],
                ['key' => 'department', 'label' => 'hr::field.department', 'width' => '9rem'],
                ['key' => 'employee_share', 'label' => 'hr::report.pf_employee_share', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                ['key' => 'employer_share', 'label' => 'hr::report.pf_employer_share', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                ['key' => 'month_total', 'label' => 'hr::report.pf_month_total', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                // ⓘ জের যোগ হয় না — চলতি জেরের যোগফল অর্থহীন
                ['key' => 'balance', 'label' => 'hr::report.pf_balance', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view', 'total' => false,
                    'drill' => ['source' => ['type' => 'staff_source', 'id' => 'staff_id']]],
            ],
        );
    }

    /**
     * ⭐ হাজিরার সারাংশ — পরিসরের ভিতরে প্রতিজন কর্মী: উপস্থিত, অনুপস্থিত, ছুটি, সাপ্তাহিক/সরকারি ছুটি, দেরি, মোট বসানো দিন।
     *
     * ⓘ গোনার নিয়ম [[AttendanceService::monthlySummary()]]-এর হুবহু: যে দিনের সারি আছে কেবল সেটাই গোনা হয় (খালি দিন অনুপস্থিত
     * নয়), অবস্থা ধরে একটা করে, আর দেরি `is_late` ধরে। ⚠️ আধা দিনের ছুটিও এক সারি — পর্দার গোনার মতোই; বেতন থেকে কাটা দিন
     * ([[AttendanceService::unpaidDays()]]) আলাদা প্রশ্ন, এখানে নয়।
     * ⓘ পরিসরে কর্মরত থাকা প্রত্যেকে আসেন — কোনো দিন বসানো না থাকলেও (সব শূন্য): তালিকায় না থাকাটা "কেউ নেই" পড়াত।
     */
    public static function attendanceSummary(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hr.attendance_summary',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — হাজিরার পাতার চাবি ([[AttendanceController]])
            permission: 'hr.attendance.view',
            title: 'hr::menu.report_attendance',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $days = AttendanceService::rowsBetween($f['from'], $f['to'])->toBase()
                    ->groupBy('hr_attendance.employee_id')
                    ->select('hr_attendance.employee_id')
                    ->selectRaw('SUM(CASE WHEN hr_attendance.status = ? THEN 1 ELSE 0 END) as present', [Attendance::PRESENT])
                    ->selectRaw('SUM(CASE WHEN hr_attendance.status = ? THEN 1 ELSE 0 END) as absent', [Attendance::ABSENT])
                    ->selectRaw('SUM(CASE WHEN hr_attendance.status = ? THEN 1 ELSE 0 END) as on_leave', [Attendance::LEAVE])
                    ->selectRaw('SUM(CASE WHEN hr_attendance.status = ? THEN 1 ELSE 0 END) as holiday', [Attendance::HOLIDAY])
                    ->selectRaw('SUM(CASE WHEN hr_attendance.is_late = 1 THEN 1 ELSE 0 END) as late')
                    ->selectRaw('COUNT(*) as marked');

                return self::staff($f)
                    ->tap(self::employedBetween($f['from'], $f['to']))
                    ->leftJoinSub($days, 'att', 'att.employee_id', '=', 'hr_employees.id')
                    ->orderBy('hr_employees.code')
                    ->select([
                        DB::raw(self::employeeLabel().' as employee'),
                        DB::raw(self::nameOf('d').' as department'),
                        DB::raw('COALESCE(att.present, 0) as present'),
                        DB::raw('COALESCE(att.absent, 0) as absent'),
                        DB::raw('COALESCE(att.on_leave, 0) as on_leave'),
                        DB::raw('COALESCE(att.holiday, 0) as holiday'),
                        DB::raw('COALESCE(att.late, 0) as late'),
                        DB::raw('COALESCE(att.marked, 0) as marked'),
                    ]);
            },
            columns: [
                ['key' => 'employee', 'label' => 'hr::field.employee', 'width' => '14rem'],
                ['key' => 'department', 'label' => 'hr::field.department', 'width' => '10rem'],
                ['key' => 'present', 'label' => 'hr::kind.present', 'type' => ReportColumn::QUANTITY],
                ['key' => 'absent', 'label' => 'hr::kind.absent', 'type' => ReportColumn::QUANTITY],
                ['key' => 'on_leave', 'label' => 'hr::kind.leave', 'type' => ReportColumn::QUANTITY],
                ['key' => 'holiday', 'label' => 'hr::kind.holiday', 'type' => ReportColumn::QUANTITY],
                ['key' => 'late', 'label' => 'hr::field.is_late', 'type' => ReportColumn::QUANTITY],
                ['key' => 'marked', 'label' => 'hr::field.marked', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * ⭐ বেতনশিট — একটা মাসের (বা কয়েক মাসের) প্রতিটা বেতনের স্লিপ: কর্মী, বিভাগ, পদবি, মোট আয়, কর্তন, নিট, পাঠানোর পথ।
     *
     * ── ⛔ কেবল নিশ্চিত রান ───────────────────────────────────────────
     * খসড়া রান "আবার বানান"-এ বদলায় — তার অঙ্ক রিপোর্টে বসালে কাল একই মাসের বেতনশিট আরেক কথা বলত। বাতিল রানের টাকা খাতা থেকে
     * উল্টে গেছে — দেখালে মাসের বেতন দুইবার গোনা হত (নতুন রানের সাথে)। ⓘ তাই কেবল [[DocumentStatus::CONFIRMED]]: যা খাতায় বসে
     * আছে আর ব্যাংকে গেছে, ঠিক সেটুকু। খসড়া দেখতে বেতনের রানের পাতা আছে।
     *
     * ── ⛔ টাকার ঘর বেতনের চাবির পিছনে ───────────────────────────────
     * রিপোর্টের চাবি `hr.payroll.view` (রানের পাতার চাবি)। মোট আয়, কর্তন আর নিট `hr.salary.view` ছাড়া দেখা যায় না — পর্দা, ছাপা,
     * ফাইল আর ফোন চারটাতেই ([[ReportResult::columnsFor()]]); মডিউলের ঘোষণাও তাই বলে (`section_guarded_fields`)। ⓘ চাবি ছাড়া
     * মানুষ জানেন কে কোন মাসে কোন পথে বেতন পেলেন, অঙ্ক নয়।
     *
     * ⓘ মাস ধরে, পরিশোধের দিন ধরে নয়: "অক্টোবরের বেতনশিট" মানে অক্টোবরের কাজের বেতন, নভেম্বরের ৩ তারিখে দেওয়া হলেও।
     */
    public static function payrollRegister(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hr.payroll_register',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — বেতনের রানের পাতার চাবি ([[PayrollController]])
            permission: 'hr.payroll.view',
            title: 'hr::menu.report_payroll',
            filters: ['date_range', 'branch'],
            query: fn (array $f): Builder => Payslip::query()->toBase()
                ->join('hr_payroll_runs as r', 'r.id', '=', 'hr_payslips.payroll_run_id')
                ->join('hr_employees', 'hr_employees.id', '=', 'hr_payslips.employee_id')
                ->leftJoin('mdm_departments as d', 'd.id', '=', 'hr_employees.department_id')
                ->leftJoin('mdm_designations as g', 'g.id', '=', 'hr_employees.designation_id')
                // ⛔ বেতনের রেজিস্টার এক কোম্পানির খাতা — কর্মী-খাতা এক হলেও
                ->where('hr_payslips.company_id', (int) $f['company_id'])
                ->where('r.status', DocumentStatus::CONFIRMED)
                ->whereNull('r.deleted_at')
                ->whereBetween('r.month', [Carbon::parse($f['from'])->startOfMonth()->toDateString(), $f['to']])
                ->tap(ReportEngine::branchWall($f, 'hr_employees.branch_id'))
                ->orderBy('r.month')
                ->orderBy('hr_employees.code')
                ->select([
                    DB::raw("DATE_FORMAT(r.month, '%Y-%m') as pay_month"),
                    'r.id as run_id',
                    'r.document_no',
                    DB::raw("'".PayrollRun::drillSourceType()."' as run_source"),
                    DB::raw(self::employeeLabel().' as employee'),
                    DB::raw(self::nameOf('d').' as department'),
                    DB::raw(self::nameOf('g').' as designation'),
                    'hr_payslips.gross',
                    'hr_payslips.deductions',
                    'hr_payslips.net',
                ])
                ->selectRaw(
                    'CASE hr_payslips.payment_method WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? ELSE hr_payslips.payment_method END as paid_by',
                    ['cash', __('hr::kind.cash'), 'bank', __('hr::kind.bank'), 'mfs', __('hr::kind.mfs')],
                ),
            columns: [
                ['key' => 'pay_month', 'label' => 'hr::field.month', 'width' => '6rem'],
                ['key' => 'document_no', 'label' => 'hr::field.document_no', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'run_source', 'source_id' => 'run_id', 'width' => '10rem'],
                ['key' => 'employee', 'label' => 'hr::field.employee', 'width' => '14rem'],
                ['key' => 'department', 'label' => 'hr::field.department', 'width' => '9rem'],
                ['key' => 'designation', 'label' => 'hr::field.designation', 'width' => '9rem'],
                // ⛔ টাকার তিনটা ঘর — বেতনের চাবি ছাড়া নেই, মোটও নয়
                ['key' => 'gross', 'label' => 'hr::field.gross', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                ['key' => 'deductions', 'label' => 'hr::field.deductions', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                ['key' => 'net', 'label' => 'hr::field.net', 'type' => ReportColumn::MONEY, 'permission' => 'hr.salary.view',
                    'drill' => ['source' => ['type' => 'run_source', 'id' => 'run_id']]],
                ['key' => 'paid_by', 'label' => 'hr::field.payment_method', 'width' => '8rem'],
            ],
        );
    }

    /**
     * ⭐ কর্মী সংখ্যা — বিভাগ আর শাখা ধরে: "শেষ" তারিখে কতজন, পরিসরে কতজন যোগ দিলেন, কতজন ছাড়লেন।
     *
     * ⓘ "কর্মরত" মানে [[Employee::wasEmployedOn()]]-এর নিয়ম: যোগদানের দিন থেকে ছাড়ার দিন পর্যন্ত (ছাড়ার দিনটাও কাজের দিন)।
     * যোগদান = `joining_date` পরিসরে, ছাড়া = `leaving_date` পরিসরে — HR ড্যাশবোর্ডের "যোগ দিলেন/ছাড়লেন"-এর একই প্রশ্ন।
     * ⓘ পরিসরে কোনো এক দিন কর্মরত ছিলেন এমন সবাই গোনায় আসেন; তাই কেউ পরিসরের মাঝে ছাড়লে তাঁর বিভাগের সারি থাকে (ছাড়া ১,
     * এখন ০) — সারিটা মুছে গেলে বিদায়টাই হারাত।
     */
    public static function headcount(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hr.headcount',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — কর্মীর তালিকার চাবি ([[EmployeeController]])
            permission: 'hr.employee.view',
            title: 'hr::menu.report_headcount',
            filters: ['date_range', 'branch'],
            query: fn (array $f): Builder => self::staff($f)
                ->tap(self::employedBetween($f['from'], $f['to']))
                ->leftJoin('branches as b', 'b.id', '=', 'hr_employees.branch_id')
                ->groupBy('hr_employees.department_id', 'd.name_en', 'd.name_bn', 'hr_employees.branch_id', 'b.name_en', 'b.name_bn')
                ->orderBy('d.name_en')
                ->orderBy('b.name_en')
                ->select([
                    DB::raw(self::nameOf('d').' as department'),
                    DB::raw(self::nameOf('b').' as branch'),
                ])
                ->selectRaw(
                    'SUM(CASE WHEN (hr_employees.joining_date IS NULL OR hr_employees.joining_date <= ?)'
                    .' AND (hr_employees.leaving_date IS NULL OR hr_employees.leaving_date >= ?) THEN 1 ELSE 0 END) as headcount',
                    [$f['to'], $f['to']],
                )
                ->selectRaw('SUM(CASE WHEN hr_employees.joining_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as joiners', [$f['from'], $f['to']])
                ->selectRaw('SUM(CASE WHEN hr_employees.leaving_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as leavers', [$f['from'], $f['to']]),
            columns: [
                ['key' => 'department', 'label' => 'hr::field.department', 'width' => '12rem'],
                ['key' => 'branch', 'label' => 'hr::field.branch', 'width' => '10rem'],
                ['key' => 'headcount', 'label' => 'hr::report.headcount', 'type' => ReportColumn::QUANTITY],
                ['key' => 'joiners', 'label' => 'hr::report.joiners', 'type' => ReportColumn::QUANTITY],
                ['key' => 'leavers', 'label' => 'hr::report.leavers', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * ⭐ ছুটির জের — "শেষ" তারিখের বছরে প্রতিজন কর্মী × প্রতিটা চালু ধরন, যার বছরে সীমা আছে: পাওনা, নেওয়া, বাকি।
     *
     * ⓘ নেওয়া = [[LeaveService::takenInYear()]] — মঞ্জুর হওয়া আবেদন, শুরুর দিন সেই বছরে; [[LeaveService::balance()]]-এর হুবহু
     * সারি। পাওনা = ধরনের `days_per_year` — ⭐ অর্জিত ছুটিতে (কোড EARNED) এক বছর চাকরির পরে আগের বছরের কাজে অর্জিত + জের ([[EarnedLeave]], শ্রম আইন ধারা ১১৭; a6,
     * ২১ অক্টোবর ২০২৬); বাকি = পাওনা − নেওয়া (ফোনের "এ বছর কত বাকি"-ও এই সেবাই)। ⛔ সীমাহীন ধরন (০ দিন,
     * যেমন বিনা বেতনে) বাদ — "কত বাকি" প্রশ্নটারই মানে নেই ([[LeaveType::hasNoYearlyLimit()]])।
     * ⓘ কেবল সেদিন কর্মরতরা ([[Employee::wasEmployedOn()]]); যোগ নেই — আলাদা ধরনের দিন যোগ করলে অর্থহীন সংখ্যা।
     */
    public static function leaveBalance(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'hr.leave_balance',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — ছুটির পাতার চাবি ([[LeaveController]])
            permission: 'hr.leave.view',
            title: 'hr::menu.report_leave_balance',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            query: function (array $f): Builder {
                $asOf = Carbon::parse($f['to']);

                $types = LeaveType::query()->active()
                    // ⓘ সীমা আছে = দিন ০-এর বেশি, বা অর্জিত ছুটি; [[LeaveType::hasNoYearlyLimit()]]-এর উল্টো
                    ->where(fn ($q) => $q->where('hr_leave_types.days_per_year', '>', 0)->orWhere('hr_leave_types.code', LeaveType::EARNED))
                    ->select(['hr_leave_types.id', 'hr_leave_types.code', 'hr_leave_types.name_en', 'hr_leave_types.name_bn', 'hr_leave_types.days_per_year'])
                    ->toBase();

                $taken = LeaveService::takenInYear($asOf)->toBase()
                    ->groupBy('hr_leave_applications.employee_id', 'hr_leave_applications.leave_type_id')
                    ->select(['hr_leave_applications.employee_id', 'hr_leave_applications.leave_type_id'])
                    ->selectRaw('SUM(hr_leave_applications.days) as taken');

                /*
                 * ⭐ অর্জিত ছুটি — [[EarnedLeave]]-এর হুবহু (ধারা ১১৭): যোগদান + ১ বছর পেরোলে আগের ক্যালেন্ডার বছরের "উপস্থিত" দিন ÷ ১৮
                 * (পুরো দিনে), আর এই বছরে ঢোকা জের (বছর শেষের ছবি)। ⛔ এখানে আলাদা নিয়ম লিখলে কর্মীর পাতা, ফোন আর রিপোর্ট আলাদা হত।
                 * ⓘ `DATE_ADD(…, INTERVAL 1 YEAR)` ২৯ ফেব্রুয়ারিকে ২৮-এ নামায় — Carbon-এর `addYearsNoOverflow()`-এর একই উত্তর।
                 */
                $previous = $asOf->copy()->subYearNoOverflow();
                $worked = AttendanceService::rowsBetween($previous->copy()->startOfYear()->toDateString(), $previous->copy()->endOfYear()->toDateString())->toBase()
                    ->where('hr_attendance.status', Attendance::PRESENT)
                    ->groupBy('hr_attendance.employee_id')
                    ->select('hr_attendance.employee_id')
                    ->selectRaw('COUNT(*) as worked');

                $carried = LeaveCarryForward::query()->toBase()
                    ->where('hr_leave_carry_forwards.year', $asOf->year)
                    ->select(['hr_leave_carry_forwards.employee_id', 'hr_leave_carry_forwards.leave_type_id', 'hr_leave_carry_forwards.days']);

                $allowed = 'CASE WHEN lt.code = ? THEN'
                    .' (CASE WHEN hr_employees.joining_date IS NOT NULL AND DATE_ADD(hr_employees.joining_date, INTERVAL '.EarnedLeave::SERVICE_YEARS.' YEAR) <= ?'
                    .' THEN FLOOR(COALESCE(wk.worked, 0) / '.EarnedLeave::WORKED_DAYS_PER_DAY.') ELSE 0 END)'
                    .' + COALESCE(cf.days, 0) ELSE lt.days_per_year END';
                $allowedBindings = [LeaveType::EARNED, $asOf->toDateString()];

                return self::staff($f)
                    ->tap(self::employedBetween($f['to'], $f['to']))
                    ->crossJoinSub($types, 'lt')
                    ->leftJoinSub($taken, 'tk', fn ($join) => $join->on('tk.employee_id', '=', 'hr_employees.id')
                        ->on('tk.leave_type_id', '=', 'lt.id'))
                    ->leftJoinSub($worked, 'wk', 'wk.employee_id', '=', 'hr_employees.id')
                    ->leftJoinSub($carried, 'cf', fn ($join) => $join->on('cf.employee_id', '=', 'hr_employees.id')
                        ->on('cf.leave_type_id', '=', 'lt.id'))
                    ->orderBy('hr_employees.code')
                    ->orderBy('lt.code')
                    ->select([
                        DB::raw(self::employeeLabel().' as employee'),
                        DB::raw(self::nameOf('d').' as department'),
                        DB::raw(self::nameOf('lt').' as leave_type'),
                    ])
                    ->selectRaw("{$allowed} as allowed", $allowedBindings)
                    ->selectRaw('COALESCE(tk.taken, 0) as taken')
                    ->selectRaw("({$allowed}) - COALESCE(tk.taken, 0) as remaining", $allowedBindings);
            },
            columns: [
                ['key' => 'employee', 'label' => 'hr::field.employee', 'width' => '14rem'],
                ['key' => 'department', 'label' => 'hr::field.department', 'width' => '10rem'],
                ['key' => 'leave_type', 'label' => 'hr::field.leave_type', 'width' => '10rem'],
                ['key' => 'allowed', 'label' => 'hr::report.allowed', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'taken', 'label' => 'hr::report.taken', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'remaining', 'label' => 'hr::report.remaining', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /**
     * কর্মীর তালিকা — কোম্পানির দেয়াল আর মুছে ফেলা বাদ মডেলের নিজের স্কোপ থেকে, শাখার দেয়াল ইঞ্জিনের, বিভাগের নামসহ।
     *
     * ⓘ মডেল থেকে, কাঁচা `DB::table` নয়: "গোটা গ্রুপের এক কর্মী-খাতা" চালু থাকলে ([[SharedAcrossCompaniesWhenAsked]]) HR-এর
     * পর্দাগুলো যা দেখে, রিপোর্টও তা-ই দেখে।
     *
     * @param  array<string, mixed>  $f
     */
    private static function staff(array $f): Builder
    {
        return Employee::query()->toBase()
            ->leftJoin('mdm_departments as d', 'd.id', '=', 'hr_employees.department_id')
            ->tap(ReportEngine::branchWall($f, 'hr_employees.branch_id'));
    }

    /**
     * ⓘ পরিসরের কোনো এক দিন কর্মরত — [[Employee::wasEmployedOn()]]-এর নিয়ম, SQL-এ: যোগদান শেষ দিনের পরে নয়, ছাড়া প্রথম দিনের আগে নয়।
     *
     * @return \Closure(Builder): void
     */
    private static function employedBetween(string $from, string $to): \Closure
    {
        return function (Builder $query) use ($from, $to): void {
            $query->where(fn ($q) => $q->whereNull('hr_employees.joining_date')->orWhere('hr_employees.joining_date', '<=', $to))
                ->where(fn ($q) => $q->whereNull('hr_employees.leaving_date')->orWhere('hr_employees.leaving_date', '>=', $from));
        };
    }

    /** "কোড · নাম" — কর্মীর তালিকার একই রূপ; বাংলায় বাংলা নাম থাকলে সেটা ([[IsMasterRecord::name()]]-এর নিয়ম) */
    private static function employeeLabel(): string
    {
        return "CONCAT(hr_employees.code, ' · ', ".self::nameOf('hr_employees').')';
    }

    /**
     * একটা তালিকার নাম চলতি ভাষায় — [[IsMasterRecord::name()]]-এর নিয়ম: বাংলায় বাংলা নাম থাকলে সেটা, নইলে ইংরেজি।
     *
     * ⓘ ভাষা PHP-তে বাছা, তাই SQL-এ কোনো বাঁধা মান নেই; ⚠️ দলবদ্ধ রিপোর্টে দুই নামই `groupBy`-তে ([[headcount()]])।
     */
    private static function nameOf(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }
}
