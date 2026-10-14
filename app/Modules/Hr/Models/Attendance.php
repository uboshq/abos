<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Models\User;
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
    public static function shareOf(LeaveApplication $application, Carbon $day): string
    {
        $before = (string) $application->from_date->copy()->startOfDay()->diffInDays($day->copy()->startOfDay());
        $left = bcsub((string) $application->days, $before, 1);

        return bccomp($left, '1', 1) >= 0 ? '1' : (bccomp($left, '0', 1) > 0 ? $left : '0');
    }

    /** এই সারিটা ছুটির কত ভাগ — ছুটির সারি না হলে বা আবেদন না থাকলে পুরো দিন */
    public function leaveShare(): string
    {
        return $this->status === self::LEAVE && $this->leaveApplication !== null
            ? self::shareOf($this->leaveApplication, $this->work_date)
            : '1';
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
