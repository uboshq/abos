<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজনের এক দিনের হাজিরা।
 */
class Attendance extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

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

    public function scopeForMonth(Builder $query, string $monthStart, string $monthEnd): Builder
    {
        return $query->whereBetween('work_date', [$monthStart, $monthEnd]);
    }
}
