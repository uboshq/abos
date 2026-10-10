<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ একটা খবর একজনের কাছে একটা মাধ্যমে — ডেলিভারির কিউয়ের এক সারি (মালিকের স্পেক §১০, §১৪; ধাপ ২)।
 *
 * অবস্থা: queued (অপেক্ষায়) → processing (চলছে) → sent (পৌঁছেছে) · retrying (আবার চেষ্টা হবে) · dead (ব্যর্থ-তালিকা,
 * dead-letter) · cancelled (বাতিল)। প্রতিটা চেষ্টার নিজের সারি [[NotificationDeliveryAttempt]]-এ।
 *
 * IsAudited নেই: যন্ত্রের নিজের হিসাব — একটা খবরে কয়েকবার বদলায়; প্রতিটা চেষ্টা নিজের সারিতে, আর মানুষের কাজ (হাতে আবার
 * চেষ্টা, বাতিল) notification_audit_logs-এ।
 */
class NotificationJob extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SENT = 'sent';

    public const RETRYING = 'retrying';

    public const DEAD = 'dead';

    public const CANCELLED = 'cancelled';

    /** @var list<string> এখনো শেষ হয়নি — কিউয়ের পর্দা */
    public const OPEN = [self::QUEUED, self::PROCESSING, self::RETRYING];

    protected $fillable = [
        'company_id', 'notification_id', 'event_id', 'user_id', 'channel', 'status', 'attempts', 'max_attempts',
        'next_attempt_at', 'claimed_at', 'provider', 'provider_ref', 'error_kind', 'last_error', 'sent_at', 'dead_at',
        'resolved_by', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'dead_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attemptRows(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'job_id');
    }
}
