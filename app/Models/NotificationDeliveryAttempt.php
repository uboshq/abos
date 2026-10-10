<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ পৌঁছানোর একটা চেষ্টা — কখন, কোন প্রোভাইডার, ফল, রেফারেন্স, ভুল (মালিকের স্পেক §৪ "Delivery Logs", §১৪; ধাপ ২)।
 *
 * ⛔ ভুলের লেখা ছাঁকা থাকে — টোকেন, চাবি বা ঠিকানার প্রশ্নাংশ নয় ([[DeliveryResult::clean()]])।
 * IsAudited নেই: নিজেই একটা লগ, কেউ বদলায় না।
 */
class NotificationDeliveryAttempt extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'job_id', 'attempt', 'channel', 'provider', 'outcome', 'provider_ref', 'error', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(NotificationJob::class, 'job_id');
    }
}
