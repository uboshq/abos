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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা ছুটির আবেদন।
 *
 * মঞ্জুর হলে দিনগুলো হাজিরার খাতায় "ছুটি" হয়ে বসে যায় — নাহলে ছুটিতে
 * থাকা লোকটাকে অনুপস্থিত দেখাত, আর বেতন থেকে কাটা যেত।
 */
class LeaveApplication extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'hr_leave_applications';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id',
        'from_date', 'to_date', 'days', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'days' => 'decimal:1',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
