<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\SettingsService;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveCarryForward;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * ⭐ অর্জিত ছুটি — প্রতি ১৮ দিন কাজে ১ দিন, আর বছর শেষে বড়জোর ৪০ দিন পরের বছরে (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম
 * আইন ২০০৬ ধারা ১১৭; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * অর্জিত ছুটি ছিল বাঁধা বছরের কোটা (১৫ দিন), যোগ দেওয়ার দিনেই পুরোটা — যিনি দুই মাস কাজ করলেন তিনিও ১৫ দিন পেতেন, আর বছর
 * শেষে না-নেওয়া দিন নিঃশব্দে হারাত। আইনে অর্জিত ছুটি কাজ থেকে অর্জিত, আর না-নেওয়া দিন জমে (৪০ দিন পর্যন্ত)।
 *
 * ── ⭐ "কাজ করা দিন" — ঠিক কী গোনা হয় ─────────────────────────────────
 * হাজিরার খাতায় **"উপস্থিত"** লেখা দিন (দেরিতে এলেও উপস্থিত), বছরের ১ জানুয়ারি থেকে যে দিনের হিসাব চাওয়া হচ্ছে সেদিন পর্যন্ত
 * (দুই মাথাসহ)। ⛔ গোনা হয় না: ছুটি (বেতনসহ বা বিনা বেতনে), সাপ্তাহিক বা তালিকার ছুটির দিন, অনুপস্থিত, আর যে দিন লেখাই হয়নি।
 * ⓘ খাতাই একমাত্র সাক্ষী — "লেখা হয়নি" কাজ করা নয়, ঠিক যেমন তা অনুপস্থিতিও নয় ([[AttendanceService::unpaidDays()]])।
 * অর্জিত = ⌊কাজ করা দিন ÷ ১৮⌋ — পুরো দিনে; ৩৫ দিনে ১, ৩৬ দিনে ২।
 *
 * ── ⭐ পাওনা = অর্জিত + আগের বছরের জের − নেওয়া ───────────────────────────
 * চলতি বছরের অর্জিত হাজিরা থেকে গোনা, জমা নয় — এক উৎস। আগের বছরের জের বছর শেষের ছবি থেকে ([[LeaveCarryForward]]), যা
 * [[carryForward()]] লেখে: সমাপনী = জের + সারা বছরের অর্জিত − সেই বছরে নেওয়া, শূন্যের নিচে নয়, সীমার (সেটিং
 * `hr.earned_leave_carry_cap`, ডিফল্ট ৪০) উপরে নয়। ⓘ ছবিটা না থাকলে জের শূন্য — কর্মীর পাতা, ফোন আর রিপোর্ট তিনটাই একই কথা বলে।
 */
final class EarnedLeave
{
    /** ধারা ১১৭ — এতগুলো কাজ করা দিনে এক দিন অর্জিত ছুটি */
    public const WORKED_DAYS_PER_DAY = 18;

    public const DEFAULT_CARRY_CAP = 40;

    public function __construct(private readonly SettingsService $settings) {}

    /** বছরের ১ জানুয়ারি থেকে `$on` পর্যন্ত (দুই মাথাসহ) "উপস্থিত" দিন */
    public function workedDays(Employee $employee, Carbon $on): int
    {
        return AttendanceService::rowsBetween($on->copy()->startOfYear()->toDateString(), $on->toDateString())
            ->where('employee_id', $employee->id)
            ->where('status', Attendance::PRESENT)
            ->count();
    }

    /** এ বছর `$on` পর্যন্ত অর্জিত — পুরো দিনে */
    public function accrued(Employee $employee, Carbon $on): string
    {
        return intdiv($this->workedDays($employee, $on), self::WORKED_DAYS_PER_DAY).'.0';
    }

    /** আগের বছর থেকে এই বছরে ঢোকা জের — বছর শেষের ছবি; না থাকলে শূন্য */
    public function carriedInto(Employee $employee, LeaveType $type, int $year): string
    {
        $days = LeaveCarryForward::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->value('days');

        return $days === null ? '0.0' : bcadd((string) $days, '0', 1);
    }

    /** পরের বছরে বড়জোর কত দিন যায় — সেটিং, ঋণাত্মক হলে শূন্য */
    public function cap(): string
    {
        $cap = (int) $this->settings->get('hr.earned_leave_carry_cap', self::DEFAULT_CARRY_CAP);

        return (string) max(0, $cap);
    }

    /** একটা শেষ হওয়া বছরের সমাপনী — জের + সারা বছরের অর্জিত − নেওয়া, শূন্য থেকে সীমার মধ্যে */
    public function closingOf(Employee $employee, LeaveType $type, int $year): string
    {
        $end = Carbon::create($year, 12, 31)->startOfDay();

        $taken = LeaveService::takenInYear($end)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->get()
            ->reduce(fn (string $carry, LeaveApplication $a) => bcadd($carry, (string) $a->days, 1), '0');

        $open = bcsub(bcadd($this->carriedInto($employee, $type, $year), $this->accrued($employee, $end), 1), $taken, 1);

        if (bccomp($open, '0', 1) < 0) {
            return '0.0';
        }

        return bccomp($open, $this->cap(), 1) > 0 ? bcadd($this->cap(), '0', 1) : $open;
    }

    /**
     * ⭐ বছর শেষের কাজ — চলতি কোম্পানির প্রতিজন কর্মীর `$year`-এর সমাপনী `$year + 1`-এ জের হয়ে বসে।
     *
     * ⓘ বারবার চালানো যায়: একই কর্মী × ধরন × বছরে একটাই সারি (`hr_carry_once`), আবার চালালে নতুন করে গোনা অঙ্ক বসে — দেরিতে লেখা
     * হাজিরা বা প্রত্যাহার করা ছুটি তাতে ধরা পড়ে, আর বদল অডিটে। ⓘ কেবল যাঁরা সেই বছরে যোগ দিয়েছিলেন আর পরের বছরের ১ জানুয়ারি
     * কর্মরত; চলে যাওয়া কর্মীর জের বসে না। ⚠️ বছরগুলো ক্রমে চালাতে হয় (আগের বছরের জের সমাপনীতে ঢোকে)।
     *
     * @return int কয়টা সারি লেখা হলো
     */
    public function carryForward(int $year): int
    {
        $type = LeaveType::query()->where('code', LeaveType::EARNED)->first();

        if (! $type instanceof LeaveType) {
            return 0;
        }

        $next = Carbon::create($year + 1, 1, 1)->startOfDay();
        $written = 0;

        Employee::query()
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', Carbon::create($year, 12, 31)->toDateString()))
            ->orderBy('id')
            ->each(function (Employee $employee) use ($type, $year, $next, &$written): void {
                if (! $employee->wasEmployedOn($next)) {
                    return;
                }

                LeaveCarryForward::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year + 1],
                    ['company_id' => $employee->company_id, 'days' => $this->closingOf($employee, $type, $year)],
                );
                $written++;
            });

        return $written;
    }
}
