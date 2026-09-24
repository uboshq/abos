<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** অনুমোদনের এক ধাপ — কোন রোল বা কোন ব্যক্তি। */
class ApprovalFlowStep extends Model
{
    use HasFactory;
    use HasPublicId;

    public const BY_ROLE = 'role';

    public const BY_USER = 'user';

    protected $fillable = [
        'approval_flow_id', 'level', 'step_name', 'approver_type', 'approver_id', 'requires_all',
        'sla_hours', 'warn_hours', 'escalate_hours', 'escalate_to_type', 'escalate_to_id',
        'min_approvals',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'requires_all' => 'boolean',
            'sla_hours' => 'integer',
            'warn_hours' => 'integer',
            'escalate_hours' => 'integer',
            'min_approvals' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function allows(User $user): bool
    {
        if ($this->approver_type === self::BY_USER) {
            return $user->id === (int) $this->approver_id;
        }

        return $user->roles()->whereKey($this->approver_id)->exists();
    }
}
