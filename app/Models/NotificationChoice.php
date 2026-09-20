<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজন মানুষ কোন ধরনের খবর পেতে চান।
 *
 * ⓘ সারি না থাকা মানে **চালু** — কারণটা মাইগ্রেশনে লেখা: নতুন ধরনের খবর
 * যোগ হলে সেটা সবাই পান, আর যাঁর দরকার নেই তিনি বন্ধ করেন। ⛔ উল্টোটা
 * নীরবে খবর গিলে ফেলত।
 */
class NotificationChoice extends Model
{
    use HasPublicId;

    protected $fillable = ['user_id', 'type', 'enabled'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * এই মানুষটার বন্ধ করা ধরনগুলো।
     *
     * @return list<string>
     */
    public static function silencedFor(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('enabled', false)
            ->pluck('type')
            ->map(fn ($type) => (string) $type)
            ->all();
    }
}
