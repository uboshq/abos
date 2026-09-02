<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা হ্যান্ডসেট, যাকে সার্ভার চেনে।
 *
 * ── কেন এই সারিটা দরকার ─────────────────────────────────────────────
 * সিঙ্কের প্রতিটা হিসাব ডিভাইস ধরে: কে কতদূর নামিয়েছে, কার কোন বদল
 * এসেছে। ব্যবহারকারী ধরে করলে একজনের দুইটা ফোন একে অন্যের ওয়াটারমার্ক
 * এগিয়ে দিত — দ্বিতীয় ফোনটা তখন এমন রেকর্ড কোনোদিন পেত না যা প্রথমটা
 * ইতিমধ্যে নামিয়ে ফেলেছে।
 *
 * ── ফোনটা কোম্পানির, ব্যক্তির নয় ───────────────────────────────────
 * তাই `user_id` প্রতিটা লগইনে নতুন করে বসে আর `device_id` থেকে যায়।
 * সেলসম্যান বদলালে ফোনটা পরের জনের হাতে যায়, আর ক্যাটালগ আবার নামানোর
 * কোনো কারণ নেই — ওটা তো একই কোম্পানির।
 *
 * কোম্পানি বদলালে আলাদা কথা: [[SyncService::register()]] তখন ওয়াটারমার্ক
 * মুছে দেয়, নাহলে আগের কোম্পানির ক্যাটালগ ফোনে থেকে যেত।
 */
class SyncDevice extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'sync_devices';

    protected $fillable = [
        'company_id', 'user_id', 'device_id', 'app_version', 'platform', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
