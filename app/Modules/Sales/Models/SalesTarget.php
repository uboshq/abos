<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * একজনের এক মাসের লক্ষ্যমাত্রা।
 *
 * ── কেন এটা অডিটেড ──────────────────────────────────────────────────
 * মাস শেষে টার্গেট নামিয়ে দিলে অর্জন হঠাৎ ১২০% দেখায়, আর কাগজে কোনো
 * চিহ্ন থাকে না। সংখ্যাটা ছোট, কিন্তু ওটার উপর মানুষের কমিশন ও
 * মূল্যায়ন ভর করে — তাই কে কবে কী বদলাল, সেটা লেখা থাকা দরকার।
 */
class SalesTarget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_targets';

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'month', 'amount', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * মাসটা সবসময় ১ তারিখ ধরে।
     *
     * পর্দা থেকে "2026-09" আসে, ফর্ম থেকে "2026-09-17"-ও আসতে পারে।
     * এক জায়গায় গুটিয়ে না নিলে একই মাসের দুইটা সারি বসত, আর ইউনিক
     * নিয়মটা ওদের আলাদা ভেবে দুইটাই মেনে নিত।
     */
    public static function monthOf(Carbon|string $month): string
    {
        return Carbon::parse($month)->startOfMonth()->toDateString();
    }

    public function scopeForMonth(Builder $query, Carbon|string $month): Builder
    {
        return $query->whereDate('month', self::monthOf($month));
    }
}
