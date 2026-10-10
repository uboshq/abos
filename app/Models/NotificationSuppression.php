<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কোন খবর কার কাছে কেন আটকানো বা পিছানো হলো — একই খবর বারবার, নীরব সময়, পছন্দ, চুপ করা শ্রেণি, মেয়াদ, ডাইজেস্ট
 * (মালিকের স্পেক §১৭ "Preference & Suppression"; ধাপ ৩)। যন্ত্রের একবার-লেখা সারি।
 */
class NotificationSuppression extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const UPDATED_AT = null;

    /** @var list<string> */
    public const REASONS = ['cooldown', 'quiet_hours', 'preference', 'muted', 'expired', 'digest'];

    protected $fillable = ['company_id', 'user_id', 'event_id', 'rule_id', 'type', 'channel', 'reason'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
