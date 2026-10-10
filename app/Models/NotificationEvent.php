<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ একটা ঘটনার খবর — বিষয়বস্তু একবারই, যতজনই পান (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১; স্পেক §১১-এর `notifications`)।
 *
 * ⓘ প্রত্যেক প্রাপকের পড়া-দেখা-আর্কাইভ তাঁর নিজের সারিতে ([[Notification]])। idempotency চাবি কোম্পানিতে অনন্য — একই
 * ঘটনা দুইবার এলে এই সারিটাই ফেরে, নতুন খবর যায় না ([[NotificationService::send()]])।
 *
 * IsAudited নেই, [[Notification]]-এর একই কারণে: খবর নিজেই একটা ঘটনার প্রতিধ্বনি; আসল ঘটনা নিজের জায়গায় নিরীক্ষিত, আর
 * খবরের ওপর মানুষের কাজ (কেন্দ্র থেকে আর্কাইভ) [[NotificationAuditLog]]-এ।
 */
class NotificationEvent extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'branch_id', 'module', 'type', 'category', 'priority', 'title', 'body', 'url',
        'subject_type', 'subject_id', 'idempotency_key', 'actor_id', 'recipients', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'recipients' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Notification::class, 'event_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
