<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ সূচিমতো খবর — একটা টেমপ্লেট, একটা প্রাপক-দল, কখন আর কতবার (মালিকের স্পেক §৪ "Notification Schedule"; ধাপ ৩)।
 * ⓘ `abos:notifications-schedule` প্রতি মিনিটে সময় হওয়াগুলো পাঠায়; একই সময়ের পাঠানো একবারই (idempotency চাবি)।
 */
class NotificationSchedule extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** @var list<string> */
    public const RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];

    protected $fillable = [
        'company_id', 'name', 'template_id', 'group_id', 'priority', 'timezone', 'recurrence', 'next_run_at',
        'last_run_at', 'runs', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> যন্ত্রের লেখা ঘর — নিরীক্ষায় নয় */
    public function auditIgnores(): array
    {
        return ['last_run_at', 'runs'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipientGroup::class, 'group_id');
    }
}
