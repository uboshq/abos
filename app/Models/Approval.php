<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** একটা অনুমোদনের অনুরোধ। polymorphic — যেকোনো ডকুমেন্টে বসে। */
class Approval extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'approvable_type', 'approvable_id', 'module', 'action',
        'amount', 'status', 'current_level', 'payload',
        'requested_reason', 'requested_by', 'requested_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'current_level' => 'integer',
            'payload' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
