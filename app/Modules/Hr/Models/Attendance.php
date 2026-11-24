<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\User;
use App\Modules\Hr\Support\WorkCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * একজনের এক দিনের হাজিরা।
 */
class Attendance extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_attendance';

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const LEAVE = 'leave';

    public const HOLIDAY = 'holiday';

    /** @var list<string> */
    public const STATUSES = [self::PRESENT, self::ABSENT, self::LEAVE, self::HOLIDAY];

    protected $fillable = [
        'company_id', 'employee_id', 'work_date', 'status',
        'is_late', 'in_time', 'out_time', 'remarks',
        'leave_application_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_late' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveApplication, $this> */
    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ⛔ ছুটির এই দিনটা কত ভাগ — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৮; [[LeaveIsCountedWhenItIsApprovedTest]])।
     *
     * ⓘ আবেদনের দিনগুলো তারিখের ক্রমে ভরে: "১.৫ দিন" দুই তারিখে মানে প্রথমটা পুরো, দ্বিতীয়টা আধা; "০.৫ দিন" এক তারিখে আধা।
     * আবেদনে যত দিন লেখা, বেতনে ঠিক ততই — তারিখের সংখ্যা নয়।
     */
    public static function shareOf(LeaveApplication $application, Carbon $day, ?WorkCalendar $calendar = null): string
    {
        /*
         * ⭐ কেবল কাজের দিন ভরে — সাপ্তাহিক ছুটি আর তালিকার ছুটির দিন শূন্য (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১১৮;
         * [[WorkCalendar]]; a6, ২১ অক্টোবর ২০২৬)। ⓘ আগে প্রথম তারিখ থেকে ক্যালেন্ডারের দিন ধরে ভরত: "বৃহস্পতি–শনি, ২ দিন"-এ
         * বৃহস্পতি আর শুক্র পুরো, শনি শূন্য — বিনা বেতনের ছুটিতে শনিবার কাজে এলেও শুক্রবারের দিনটা কাটত।
         */
        // ⓘ ক্যালেন্ডারের দিনে গোনা ধরন (মাতৃত্বকালীন) — আগের নিয়মে, প্রতিটা দিন ([[LeaveType::countsCalendarDays()]])
        if ($application->leaveType?->countsCalendarDays()) {
            $before = (string) $application->from_date->copy()->startOfDay()->diffInDays($day->copy()->startOfDay());
            $left = bcsub((string) $application->days, $before, 1);

            return bccomp($left, '1', 1) >= 0 ? '1' : (bccomp($left, '0', 1) > 0 ? $left : '0');
        }

        $calendar ??= app(WorkCalendar::class);
        $companyId = $application->company_id === null ? null : (int) $application->company_id;
        $branchId = self::branchOf($application);

        if ($calendar->isOffDay($day, $companyId, $branchId)) {
            return '0';
        }

        $before = $day->copy()->startOfDay()->lte($application->from_date->copy()->startOfDay())
            ? '0'
            : (string) $calendar->workingDays($application->from_date, $day->copy()->subDay(), $companyId, $branchId);
        $left = bcsub((string) $application->days, $before, 1);

        return bccomp($left, '1', 1) >= 0 ? '1' : (bccomp($left, '0', 1) > 0 ? $left : '0');
    }

    /** এই সারিটা ছুটির কত ভাগ — ছুটির সারি না হলে বা আবেদন না থাকলে পুরো দিন */
    public function leaveShare(?WorkCalendar $calendar = null): string
    {
        return $this->status === self::LEAVE && $this->leaveApplication !== null
            ? self::shareOf($this->leaveApplication, $this->work_date, $calendar)
            : '1';
    }

    /** ⓘ আবেদনের কর্মীর শাখা — ছুটির তালিকা শাখা ধরে ([[WorkCalendar]]); কর্মী মুছে গেলেও খুঁজে পায় */
    private static function branchOf(LeaveApplication $application): ?int
    {
        $branch = $application->relationLoaded('employee')
            ? $application->employee?->branch_id
            : Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->value('branch_id');

        return $branch === null ? null : (int) $branch;
    }

    /**
     * ⛔ একজনের এক দিনের সারি — থাকলে তালা দিয়ে বদলায়, না থাকলে বসায় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
     * (ⓘ১২; [[AttendanceAndLeaveStayInsideTheBranchTest]])।
     *
     * ⓘ `updateOrCreate` আগে দেখত, তারপর বসাত: দুইজন একই দিন একসাথে সংরক্ষণ করলে (বা ফোনের সিঙ্ক আর অফিস, বা মঞ্জুরি) দুজনেই
     * "নেই" দেখে বসাতে যেত, আর দ্বিতীয়জন `hr_attendance_unique`-এ ঠেকে ৫০০ পেত — লেনদেনের পুরনো ছবিতে আগেরটা দেখাই যেত না।
     * এখন বসাতে গিয়ে ঠেকলে তালা দিয়ে তাজা সারিটা পড়ে বদলায়; না-থাকা সারিতে তালা আগে দেওয়া হয় না (ফাঁকের তালায় দুইজন
     * একে অন্যকে আটকায়)। ⚠️ ডাকার আগে কর্মীর সারিতে তালা চাই — তাতে একই কর্মীর দুইটা লেখা একটার পর একটা হয়।
     *
     * @param  array<string, mixed>  $values
     */
    /**
     * একটা সারির তিনটা ঘরের ছাপ — পাতা খোলার সময় কী ছিল, সংরক্ষণের সময় তাই আছে কি না মেলাতে (দিনের খাতা; ২৫ নভেম্বর ২০২৬)।
     * ⓘ খালি আর null একই; সারি না থাকলে খালি ঘরের ছাপ।
     */
    public static function stampOf(?string $status, bool $late, ?string $remarks): string
    {
        return substr(hash('sha256', json_encode([(string) $status, $late ? 1 : 0, trim((string) $remarks)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 24);
    }

    public static function stampOfRow(?self $row): string
    {
        return self::stampOf($row?->status, (bool) $row?->is_late, $row?->remarks);
    }

    public static function writeDay(int $employeeId, string $date, array $values): self
    {
        $key = ['employee_id' => $employeeId, 'work_date' => $date];
        $row = null;

        if (static::query()->where($key)->exists()) {
            $row = static::query()->where($key)->lockForUpdate()->first();
        }

        if ($row === null) {
            try {
                return static::query()->withSavepointIfNeeded(fn () => static::query()->create($key + $values));
            } catch (UniqueConstraintViolationException) {
                // ⓘ এর মধ্যে আরেকজন বসিয়ে ফেলেছেন — তাঁর সারিটাই তালা দিয়ে পড়ে বদলানো
                $row = static::query()->where($key)->lockForUpdate()->firstOrFail();
            }
        }

        $row->fill($values)->save();

        return $row;
    }

    public function scopeForMonth(Builder $query, string $monthStart, string $monthEnd): Builder
    {
        return $query->whereBetween('work_date', [$monthStart, $monthEnd]);
    }
}
