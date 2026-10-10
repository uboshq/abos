<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ⭐ একটা সারসংক্ষেপ চিঠি — কার, কোন দিনের বা সপ্তাহের, কয়টা খবর, গেল কি না (ধাপ ৩); যন্ত্রের লেখা */
class NotificationDigest extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = ['company_id', 'user_id', 'period', 'period_key', 'items', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
