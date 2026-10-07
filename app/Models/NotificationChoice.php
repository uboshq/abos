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

    protected $fillable = ['user_id', 'type', 'enabled', 'by_email'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',

            /*
             * ⓘ `null` একটা আলাদা উত্তর — *"আমি কিছু বলিনি"* — আর সেটাই
             * ⭐ ডিফল্টে নামার সংকেত ([[NotificationKinds]])।
             *
             * ⚠️ তাই `boolean` কাস্টের সাথে **পড়ার জায়গায় `??` নয়,
             * `=== null` দেখতে হয়**: `(bool) null` আর `(bool) false`
             * এক জিনিস দেখায়, অথচ একটা মানে "ধরনের নিয়ম চলুক" আর
             * অন্যটা "আমি নিজে বন্ধ করেছি"।
             */
            'by_email' => 'boolean',
        ];
    }

    /**
     * এই মানুষটা কোন ধরনগুলোর ব্যাপারে চিঠির কথা **নিজে** বলেছেন।
     *
     * ⓘ ফেরত আসে `type => bool` — যা তালিকায় নেই তার মানে তিনি কিছু
     * বলেননি, আর তখন ধরনটার নিজের নিয়ম চলে।
     *
     * @return array<string, bool>
     */
    public static function mailChoicesFor(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->whereNotNull('by_email')
            ->pluck('by_email', 'type')
            ->map(fn ($on) => (bool) $on)
            ->all();
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
