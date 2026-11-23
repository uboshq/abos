<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Support\PaidMonth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ অধিকাল (ওভারটাইম) — BLA ২০০৬ ধারা ১০৮: দিনের কাজের ঘণ্টার বাইরে প্রতিটা ঘণ্টা মূল মজুরির ঘণ্টা-হারের দ্বিগুণে (মালিকের অনুমোদিত
 * আইনি বেতনের পরিকল্পনা, a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ সূত্র ──────────────────────────────────────────────────────────────
 *   ঘণ্টা-হার = মূল ÷ ২০৮ (মাসে ২৬ কাজের দিন × ৮ ঘণ্টা — বাংলাদেশের প্রচলিত ভাগক)
 *   অধিকালের টাকা = ঘণ্টা-হার × ২ × বাড়তি ঘণ্টা, পয়সায় একবার গোল ([[amountFor()]])
 *   বাড়তি ঘণ্টা = প্রতিটা উপস্থিত দিনের (বেরোনো − ঢোকা) − দিনের কাজের ঘণ্টা (সেটিং, ডিফল্ট ৮), শূন্যের নিচে নয়; মাস জুড়ে যোগ
 *   ([[hoursFromAttendance()]])। ⓘ মূল = দেওয়া মূল — স্লিপের মূল-সারি, মাসের কাজের দিনের ভাগে, পয়সায় গোল (fe, দফা ৪)।
 *
 * ── ⭐ কে পান ─────────────────────────────────────────────────────────────
 * ধারা ১০৮ শ্রমিকের — কর্মকর্তার নয়। কর্মীর নিয়োগের ধরনের কোড সেটিংয়ের তালিকায় থাকলে তবে ([[isEligible()]]); ডিফল্ট কেবল `WORKER`
 * (শ্রমিক)। ⛔ তালিকার বাইরের কেউ অধিকাল পান না, শিট থাকলেও।
 *
 * ── ⛔ সই ছাড়া এক পয়সাও নয় ─────────────────────────────────────────────
 * হাজিরা থেকে শিট বানানো হয় "সইয়ের অপেক্ষায়"; কর্মীর নিজের ব্যবস্থাপক (যাঁর অধীনে, শাখার নাগালে) "সই দিন" চাপলে তবে "অনুমোদিত"
 * ([[sign()]]), আর বেতনের রান কেবল অনুমোদিত ঘণ্টাই নেয় ([[signedHours()]])। ⛔ নিজের শিটে নিজে নয়, আর যিনি শিট বানালেন তিনিও নন
 * — কেবল মালিক পারেন, অডিটে আলাদা চিহ্ন `owner_self_signed` (ছুটির সইয়ের হুবহু নিয়ম, [[LeaveSigner]])। ব্যবস্থাপক না থাকলে
 * মালিক সই দেন। ⓘ সই হলে ঘণ্টা আর বদলায় না; বেতন দেওয়া হয়ে যাওয়া মাসে শিট বানানো বা সই দেওয়া যায় না ([[PaidMonth]])।
 */
final class OvertimeService
{
    public const ENABLED = 'hr.ot.enabled';

    public const DAILY_HOURS = 'hr.ot.daily_hours';

    public const ELIGIBLE_TYPES = 'hr.ot.eligible_types';

    /** ⓘ ধারা ১০৮-এর ঘণ্টা-হারের ভাগক — ২৬ দিন × ৮ ঘণ্টা */
    public const MONTHLY_HOURS = '208';

    public const OWNER_SELF_SIGNED = 'owner_self_signed';

    /** ⓘ বেতনের স্লিপের সারির কোড — খাতের কোড নয় */
    public const LINE_CODE = 'OT';

    /** @var array<int, list<int>> ব্যবহারকারী → তাঁর চালু কর্মী-সারি */
    private array $employeesOf = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BranchReach $reach,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    /** দিনের কাজের ঘণ্টা — ১ থেকে ২৪-এর সাধারণ সংখ্যা; ভুল লেখায় ৮ */
    public function dailyHours(): string
    {
        $value = trim((string) $this->settings->get(self::DAILY_HOURS));

        return preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) && bccomp($value, '0', 2) > 0 && bccomp($value, '24', 2) <= 0
            ? bcadd($value, '0', 2) : '8.00';
    }

    /** @return list<string> যে নিয়োগের ধরনের কোড অধিকাল পায় — বড় হাতের, কমা দিয়ে আলাদা */
    public function eligibleCodes(): array
    {
        return array_values(array_filter(array_map(
            fn (string $code) => strtoupper(trim($code)),
            explode(',', (string) $this->settings->get(self::ELIGIBLE_TYPES)),
        ), fn (string $code) => $code !== ''));
    }

    public function isEligible(Employee $employee): bool
    {
        $code = $employee->employmentType()->withTrashed()->value('code');

        return $code !== null && in_array(strtoupper((string) $code), $this->eligibleCodes(), true);
    }

    /** ঘণ্টা-হার — মূল ÷ ২০৮, দশ ঘরে (গোল কেবল শেষে) */
    public function hourlyRate(string $basic): string
    {
        return bcdiv($basic, self::MONTHLY_HOURS, 10);
    }

    /** অধিকালের টাকা — মূল × ২ × ঘণ্টা ÷ ২০৮, পয়সায় একবার গোল */
    public function amountFor(string $basic, string $hours): string
    {
        return Money::round(bcdiv(bcmul(bcmul($basic, '2', 10), $hours, 10), self::MONTHLY_HOURS, 10), 2);
    }

    /**
     * মাসের বাড়তি ঘণ্টা — হাজিরার উপস্থিত দিনগুলোর ঢোকা-বেরোনো থেকে।
     *
     * ⓘ ঢোকা বা বেরোনো না থাকলে দিনটা গোনা হয় না (ফোনের হাজিরায় বেরোনো প্রায়ই থাকে না) — অনুমানে ঘণ্টা নয়। বেরোনো ঢোকার আগে
     * মানে রাত পেরোনো পালা: চব্বিশ ঘণ্টা যোগ।
     *
     * @return array{hours: string, days: int}
     */
    public function hoursFromAttendance(Employee $employee, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $limit = (int) bcmul($this->dailyHours(), '60', 0);
        $minutes = 0;
        $days = 0;

        $rows = Attendance::query()
            ->where('employee_id', $employee->id)
            ->forMonth($from->toDateString(), $from->copy()->endOfMonth()->toDateString())
            ->where('status', Attendance::PRESENT)
            ->whereNotNull('in_time')
            ->whereNotNull('out_time')
            ->get();

        foreach ($rows as $row) {
            $in = self::minuteOfDay((string) $row->in_time);
            $out = self::minuteOfDay((string) $row->out_time);

            if ($in === null || $out === null) {
                continue;
            }

            $worked = $out >= $in ? $out - $in : $out + 1440 - $in;
            $extra = $worked - $limit;

            if ($extra > 0) {
                $minutes += $extra;
                $days++;
            }
        }

        return ['hours' => bcdiv((string) $minutes, '60', 4), 'days' => $days];
    }

    /**
     * ⭐ মাসের শিট বানানো — দেখার শাখার প্রতিজন যোগ্য কর্মীর, হাজিরা থেকে ("সইয়ের অপেক্ষায়")।
     *
     * ⓘ আগের শিট সইয়ের অপেক্ষায় থাকলে নতুন ঘণ্টায় বদলায়; ⛔ অনুমোদিত শিট ছোঁয়া হয় না। বাড়তি ঘণ্টা শূন্য আর শিট নেই — কিছু বসে না।
     * বেতন দেওয়া হয়ে যাওয়া মাসে কারও শিট নয়।
     *
     * @return int কয়টা শিট বসল বা বদলাল
     */
    public function prepare(Carbon $month, User $by): int
    {
        $month = $month->copy()->startOfMonth();
        PaidMonth::assertOpen($month, $month, 'month', 'hr::validation.ot_month_paid');

        $staff = $this->reach->employeesInView(Employee::query()->onPayrollFor($month->copy()->endOfMonth()), $by)
            ->with('employmentType')->orderBy('code')->get();
        $made = 0;

        foreach ($staff as $employee) {
            if ($this->isEligible($employee) && $this->prepareFor($employee, $month, (int) $by->id) !== null) {
                $made++;
            }
        }

        return $made;
    }

    /** একজনের শিট — বসল বা বদলাল তো শিটটা, নইলে `null` */
    public function prepareFor(Employee $employee, Carbon $month, ?int $by = null): ?OvertimeSheet
    {
        $month = $month->copy()->startOfMonth();
        $counted = $this->hoursFromAttendance($employee, $month);

        return DB::transaction(function () use ($employee, $month, $counted, $by) {
            $sheet = OvertimeSheet::query()
                ->where('employee_id', $employee->id)
                ->whereDate('month', $month->toDateString())
                ->lockForUpdate()
                ->first();

            if ($sheet?->isSigned()) {
                return null;
            }

            if ($sheet === null && bccomp($counted['hours'], '0', 4) <= 0) {
                return null;
            }

            $sheet ??= new OvertimeSheet([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'month' => $month->toDateString(),
                'status' => OvertimeSheet::WAITING,
            ]);

            $sheet->fill(['hours' => $counted['hours'], 'days' => $counted['days'], 'created_by' => $by ?? auth()->id()])->save();

            return $sheet->fresh();
        });
    }

    /**
     * ⭐ বোতাম আর দরজার একই প্রশ্ন — এই মানুষ এই শিটে এখন সই দিতে পারেন কি না।
     *
     * ⓘ অপেক্ষমাণ শিট; কর্মীর নিজের ব্যবস্থাপক আর কর্মী তাঁর নাগালে, বা মালিক। ⛔ নিজের শিট বা নিজে বানানো শিট — কেবল মালিক।
     */
    public function maySign(?User $user, OvertimeSheet $sheet): bool
    {
        if ($user === null || $sheet->isSigned()) {
            return false;
        }

        $employee = $this->employeeOf($sheet);

        if ($employee === null) {
            return false;
        }

        $owner = $this->isOwner($user, $sheet);

        if (($this->isOwn($user, $employee) || $this->madeBy($user, $sheet)) && ! $owner) {
            return false;
        }

        if ($owner) {
            return true;
        }

        $manager = $employee->getAttributes()['reports_to_employee_id'] ?? null;

        return $manager !== null && in_array((int) $manager, $this->employeesOf($user), true)
            && $this->reach->reaches($user, $employee);
    }

    /**
     * ⭐ "সই দিন" — শিট "অনুমোদিত", ঘণ্টা আটকে যায়, আর বেতনের রান এটাই নেয়।
     *
     * @throws ValidationException সই দেওয়ার অধিকার নেই, শিট আগেই অনুমোদিত, বা মাসের বেতন দেওয়া হয়ে গেছে
     */
    public function sign(OvertimeSheet $sheet, User $by): OvertimeSheet
    {
        return DB::transaction(function () use ($sheet, $by) {
            /** @var OvertimeSheet $fresh */
            $fresh = OvertimeSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isSigned()) {
                throw ValidationException::withMessages(['status' => __('hr::validation.ot_already_signed')]);
            }

            if (! $this->maySign($by, $fresh)) {
                throw ValidationException::withMessages(['status' => __('hr::validation.ot_not_your_line')]);
            }

            PaidMonth::assertOpen($fresh->month, $fresh->month, 'month', 'hr::validation.ot_month_paid', (int) $fresh->employee_id);

            $fresh->forceFill([
                'status' => OvertimeSheet::SIGNED,
                'signed_by' => $by->id,
                'signed_at' => now(),
            ])->save();

            $employee = $this->employeeOf($fresh);

            if ($employee !== null && ($this->isOwn($by, $employee) || $this->madeBy($by, $fresh))) {
                $fresh->auditAction(self::OWNER_SELF_SIGNED, __('hr::message.ot_owner_self_signed'));
            }

            return $fresh->fresh();
        });
    }

    /** ⭐ বেতনের রানের প্রশ্ন — এই মাসের অনুমোদিত ঘণ্টা; শিট নেই বা অনুমোদিত নয় মানে শূন্য */
    public function signedHours(Employee $employee, Carbon $month): string
    {
        $hours = OvertimeSheet::query()
            ->where('employee_id', $employee->id)
            ->whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->where('status', OvertimeSheet::SIGNED)
            ->value('hours');

        return $hours === null ? '0' : bcadd((string) $hours, '0', 4);
    }

    /** "HH:MM[:SS]" → দিনের মিনিট */
    private static function minuteOfDay(string $clock): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $clock, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    private function isOwn(User $user, Employee $employee): bool
    {
        $own = $employee->getAttributes()['user_id'] ?? null;

        return $own !== null && (int) $own === (int) $user->id;
    }

    private function madeBy(User $user, OvertimeSheet $sheet): bool
    {
        return $sheet->created_by !== null && (int) $sheet->created_by === (int) $user->id;
    }

    /** মালিক — সুপার অ্যাডমিন বা কোম্পানির মালিক ([[LeaveSigner]]-এর একই মাপ) */
    private function isOwner(User $user, OvertimeSheet $sheet): bool
    {
        return $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)
            || app(Ownership::class)->isOwnerIn($user, (int) $sheet->company_id);
    }

    private function employeeOf(OvertimeSheet $sheet): ?Employee
    {
        $loaded = $sheet->relationLoaded('employee') ? $sheet->getRelation('employee') : null;

        return $loaded instanceof Employee ? $loaded : Employee::query()->withoutGlobalScopes()->find($sheet->employee_id);
    }

    /** @return list<int> */
    private function employeesOf(User $user): array
    {
        return $this->employeesOf[(int) $user->id] ??= Employee::query()
            ->where('user_id', $user->id)
            ->active()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
