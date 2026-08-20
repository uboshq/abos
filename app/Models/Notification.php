<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজনের জন্য একটা খবর।
 *
 * IsAudited নেই ইচ্ছাকৃতভাবে: বিজ্ঞপ্তি নিজেই একটা ঘটনার প্রতিধ্বনি,
 * আর প্রতিধ্বনির নিরীক্ষা রাখা মানে একই ঘটনা দুইবার লেখা। আসল ঘটনাটা
 * (অনুমোদন, বাতিল) তার নিজের জায়গায় নিরীক্ষিত।
 *
 * SoftDeletes-ও নেই: পড়া বিজ্ঞপ্তি মুছে ফেলাই স্বাভাবিক, আর মুছে ফেলা
 * বিজ্ঞপ্তি ফিরিয়ে আনার কোনো ব্যবসায়িক কারণ নেই।
 */
class Notification extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'type', 'title', 'body', 'url', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeFor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
