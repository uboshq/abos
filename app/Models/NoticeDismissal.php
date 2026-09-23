<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে কোন নোটিশ নিজের চোখের সামনে থেকে সরিয়ে দিয়েছেন।
 *
 * ── ⚠️ এটা [[NoticeRead]] নয়, আর তফাতটা দামি ─────────────────────────
 * ⓘ ক্রসে চাপা মানে *"এটা আমার সামনে থেকে সরাও"*। পড়া মানে *"আমি এটা
 * পড়েছি"*। ⛔ এক ঘরে রাখলে *"কতজন পড়েছেন"* সংখ্যাটা **বেশির দিকে**
 * মিথ্যা হত — যাঁরা কেবল বিরক্ত হয়ে সরিয়েছেন তাঁরাও পাঠক হিসেবে
 * গোনা হতেন।
 *
 * ⚠️ আর ঐ সংখ্যাটা দেখেই সিদ্ধান্ত হয় নোটিশটা আবার পাঠাতে হবে কি না।
 */
final class NoticeDismissal extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = ['company_id', 'notice_id', 'user_id', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
