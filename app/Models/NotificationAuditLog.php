<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ বিজ্ঞপ্তির ওপর কে কী করলেন, আর ফল — স্পেক §১৩ (Actor, Action, Time, Target, Outcome), ধাপ ১।
 *
 * ⓘ লেখা হয় কেবল [[NotificationAudit]] দিয়ে, আর কখনো বদলায় না — তাই `updated_at` নেই। ⛔ `detail`-এ কেবল সংখ্যা আর
 * আইডি; বার্তার লেখা, ঠিকানা, পাসওয়ার্ড বা টোকেন কখনো নয়।
 *
 * IsAudited নেই: এটা নিজেই নিরীক্ষার খাতা — এর নিরীক্ষা রাখলে প্রতিটা সারি দুইবার লেখা হত।
 */
class NotificationAuditLog extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'actor_id', 'action', 'target_type', 'target_id', 'outcome', 'detail', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'detail' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
