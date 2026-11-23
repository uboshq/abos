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
 * ⭐ অর্জিত ছুটি — ধারা ১১৭ হুবহু: এক বছর একটানা চাকরির পরে, আগের ১২ মাসের প্রতি ১৮ দিন কাজে ১ দিন; বছর শেষে বড়জোর ৪০ দিন
 * পরের বছরে; চাকরি ছাড়লে না-নেওয়া দিনের টাকা চূড়ান্ত পাওনায় (মালিকের অনুমোদন ও fe-র উত্তর, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন
 * ২০০৬ ধারা ১১, ১১৭; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * অর্জিত ছুটি ছিল বাঁধা বছরের কোটা (১৫ দিন), যোগ দেওয়ার দিনেই পুরোটা; বছর শেষে না-নেওয়া দিন নিঃশব্দে হারাত, আর চলে যাওয়া
 * কর্মীর না-নেওয়া ছুটির টাকার হিসাবই ছিল না।
 *
 * ── ⭐ "কাজ করা দিন" — ঠিক কী গোনা হয় ─────────────────────────────────
 * হাজিরার খাতায় **"উপস্থিত"** লেখা দিন (দেরিতে এলেও উপস্থিত)। ⛔ গোনা হয় না: ছুটি (বেতনসহ বা বিনা বেতনে), সাপ্তাহিক বা তালিকার
 * ছুটির দিন, অনুপস্থিত, আর যে দিন লেখাই হয়নি — খাতাই একমাত্র সাক্ষী ([[AttendanceService::unpaidDays()]]-এর একই নিয়ম)।
 *
 * ── ⭐ কবে, কত (ধারা ১১৭(১)) ──────────────────────────────────────────
 *  · **যোগ্যতা:** যোগদানের তারিখ + ১ বছর (মাসের শেষ পেরোয় না: ২৯ ফেব্রুয়ারি + ১ = ২৮ ফেব্রুয়ারি), সেদিন থেকে। একটানা চাকরি =
 *    যোগদানের তারিখ থেকে (ধারা ১৪ — খাতায় আলাদা ছেদ নেই, তাই যোগদানের তারিখই ভিত্তি)। তার আগে অর্জিত ছুটি শূন্য।
 *  · **"আগের ১২ মাস":** ছুটির বছর ক্যালেন্ডারের বছর; Y বছরের পাওনা = ⌊Y−1 বছরের (১ জানুয়ারি–৩১ ডিসেম্বর) কাজ করা দিন ÷ ১৮⌋,
 *    যোগ্য হওয়ার দিন থেকে। প্রতিটা কাজের দিন ঠিক একবার গোনা হয় — চলতি বছরের কাজ পরের বছরের পাওনা।
 *  · **জের (ধারা ১১৭(৩)):** Y-এর সমাপনী = Y-এ ঢোকা জের + Y-এর পাওনা − Y-এ নেওয়া, শূন্যের নিচে নয়, সীমার (সেটিং
 *    `hr.earned_leave_carry_cap`, ডিফল্ট ৪০) উপরে নয়; বছর শেষের ছবি ([[LeaveCarryForward]], [[carryForward()]])।
 *  · **চাকরি ছাড়লে (ধারা ১১):** না-নেওয়া দিন = (পাওনা + জের − নেওয়া, শূন্যের নিচে নয়) + ⌊চলতি বছরে ছাড়ার দিন পর্যন্ত কাজ ÷ ১৮⌋ —
 *    দ্বিতীয় ভাগটা অর্জিত, কিন্তু পরের বছরে পাওয়ার কথা ছিল; ছেড়ে গেলে সেটাও পাওনা। যোগ্যতার আগে ছাড়লে শূন্য ([[encashableDays()]])।
 *    টাকা = দিন × দৈনিক মজুরি; দৈনিক মজুরি = সেদিনের মাসিক মজুরি ÷ ৩০ ([[encashment()]])।
 *
 * ── ⭐ "মজুরি" কী — ধারা ২(৪৫) (fe-র উত্তর ১, ২১ অক্টোবর ২০২৬) ─────────────────────────
 * কাঠামোর আয়ের খাতগুলোর যোগ — মূল বেতন আর ভাতা (বাড়িভাড়া, চিকিৎসা ইত্যাদি) সহ; ⛔ বাদ যা ধারা ২(৪৫) বাদ দেয়: বোনাস বা উৎসব ভাতা,
 * মালিকের দেওয়া ভবিষ্য তহবিলের চাঁদা, যাতায়াত ভাতা বা বিশেষ খরচের ভাতা। কোন খাত বাদ তা খাতের নিজের ঘরে
 * (`outside_wages`, বেতনের খাতের পর্দায় টিক) — নাম দেখে আন্দাজ নয়। কর্তনের খাত মজুরি কমায় না।
 */
final class EarnedLeave
{
    /** ধারা ১১৭ — এতগুলো কাজ করা দিনে এক দিন অর্জিত ছুটি */
    public const WORKED_DAYS_PER_DAY = 18;

    public const DEFAULT_CARRY_CAP = 40;

    /** ধারা ১১৭(১) — অর্জিত ছুটির আগে একটানা চাকরি, বছরে */
    public const SERVICE_YEARS = 1;

    /** দৈনিক মজুরি = মাসিক মজুরি ÷ ৩০ */
    public const DAILY_RATE_DIVISOR = 30;

    public function __construct(private readonly SettingsService $settings) {}

    /** দুই তারিখের মাঝে (দুই মাথাসহ) "উপস্থিত" দিন */
    public function workedBetween(Employee $employee, Carbon $from, Carbon $to): int
    {
        return AttendanceService::rowsBetween($from->toDateString(), $to->toDateString())
            ->where('employee_id', $employee->id)
            ->where('status', Attendance::PRESENT)
            ->count();
    }

    /** যেদিন থেকে অর্জিত ছুটির অধিকার — যোগদান + ১ বছর; যোগদানের তারিখ না থাকলে কখনো নয় (null) */
    public function eligibleFrom(Employee $employee): ?Carbon
    {
        return $employee->joining_date?->copy()->startOfDay()->addYearsNoOverflow(self::SERVICE_YEARS);
    }

    public function isEligibleOn(Employee $employee, Carbon $on): bool
    {
        $from = $this->eligibleFrom($employee);

        return $from !== null && $on->copy()->startOfDay()->gte($from);
    }

    /** `$on`-এর বছরের পাওনা — আগের ক্যালেন্ডার বছরের কাজ থেকে, যোগ্য হলে; পুরো দিনে */
    public function accrued(Employee $employee, Carbon $on): string
    {
        if (! $this->isEligibleOn($employee, $on)) {
            return '0.0';
        }

        $previous = $on->copy()->subYearNoOverflow();

        return intdiv($this->workedBetween($employee, $previous->copy()->startOfYear(), $previous->copy()->endOfYear()), self::WORKED_DAYS_PER_DAY).'.0';
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

    /** একটা বছরে নেওয়া অর্জিত ছুটি — মঞ্জুর, শুরুর দিন সেই বছরে ([[LeaveService::takenInYear()]]) */
    public function takenIn(Employee $employee, LeaveType $type, int $year): string
    {
        return LeaveService::takenInYear(Carbon::create($year, 6, 30))
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->get()
            ->reduce(fn (string $carry, LeaveApplication $a) => bcadd($carry, (string) $a->days, 1), '0.0');
    }

    /** একটা শেষ হওয়া বছরের সমাপনী — জের + সেই বছরের পাওনা − নেওয়া, শূন্য থেকে সীমার মধ্যে */
    public function closingOf(Employee $employee, LeaveType $type, int $year): string
    {
        $end = Carbon::create($year, 12, 31)->startOfDay();

        $open = bcsub(bcadd($this->carriedInto($employee, $type, $year), $this->accrued($employee, $end), 1), $this->takenIn($employee, $type, $year), 1);

        if (bccomp($open, '0', 1) < 0) {
            return '0.0';
        }

        return bccomp($open, $this->cap(), 1) > 0 ? bcadd($this->cap(), '0', 1) : $open;
    }

    /**
     * ⭐ চাকরি ছাড়ার দিনে না-নেওয়া অর্জিত ছুটি — চূড়ান্ত পাওনার কাজ এটা ডাকে (ধারা ১১; উপরের নিয়ম)। ⓘ কেবল পড়ে, কিছু লেখে না।
     */
    public function encashableDays(Employee $employee, Carbon $on): string
    {
        $type = LeaveType::query()->withoutGlobalScopes()->where('company_id', $employee->company_id)->where('code', LeaveType::EARNED)->first();

        if (! $type instanceof LeaveType || ! $this->isEligibleOn($employee, $on)) {
            return '0.0';
        }

        $left = bcsub(bcadd($this->accrued($employee, $on), $this->carriedInto($employee, $type, $on->year), 1), $this->takenIn($employee, $type, $on->year), 1);
        $left = bccomp($left, '0', 1) < 0 ? '0.0' : $left;

        $thisYear = intdiv($this->workedBetween($employee, $on->copy()->startOfYear(), $on), self::WORKED_DAYS_PER_DAY);

        return bcadd($left, (string) $thisYear, 1);
    }

    /**
     * ⭐ না-নেওয়া অর্জিত ছুটির টাকা — দিন × (সেদিনের মাসিক মজুরি ÷ ৩০), পয়সায় গোল।
     *
     * @return array{days: string, monthly_wage: string, daily_rate: string, amount: string}
     */
    public function encashment(Employee $employee, Carbon $on): array
    {
        $days = $this->encashableDays($employee, $on);
        $monthly = $this->monthlyWages($employee, $on);
        $daily = bcdiv($monthly, (string) self::DAILY_RATE_DIVISOR, 10);

        return [
            'days' => $days,
            'monthly_wage' => bcadd($monthly, '0', 2),
            'daily_rate' => \App\Core\Support\Money::round($daily, 2),
            'amount' => \App\Core\Support\Money::round(bcmul($daily, $days, 10), 2),
        ];
    }

    /**
     * ⭐ ধারা ২(৪৫)-এর মাসিক মজুরি — কাঠামোর আয়ের খাত, `outside_wages` বাদ (উপরের নিয়ম)।
     */
    public function monthlyWages(Employee $employee, Carbon $on): string
    {
        $wages = '0';

        foreach (app(SalaryStructureService::class)->componentsOn($employee, $on) as $component) {
            $head = $component['head'];

            if ($head->isEarning() && ! $head->outside_wages) {
                $wages = bcadd($wages, $component['amount'], 4);
            }
        }

        return $wages;
    }

    /**
     * ⭐ বছর শেষের কাজ — চলতি কোম্পানির প্রতিজন কর্মীর `$year`-এর সমাপনী `$year + 1`-এ জের হয়ে বসে।
     *
     * ⓘ বারবার চালানো যায়: একই কর্মী × ধরন × বছরে একটাই সারি (`hr_carry_once`), আবার চালালে নতুন করে গোনা অঙ্ক বসে — দেরিতে লেখা
     * হাজিরা বা প্রত্যাহার করা ছুটি তাতে ধরা পড়ে, আর বদল অডিটে। ⓘ কেবল যাঁরা সেই বছরে যোগ দিয়েছিলেন আর পরের বছরের ১ জানুয়ারি
     * কর্মরত; চলে যাওয়া কর্মীর জের বসে না (তাঁর পাওনা টাকায় — [[encashment()]])। ⚠️ বছরগুলো ক্রমে চালাতে হয়।
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
