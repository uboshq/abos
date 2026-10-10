<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Services\DataScope;
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
    use HasPublicId;

    protected $fillable = [
        'company_id', 'user_id', 'type', 'title', 'body', 'url', 'read_at',
        // ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ — কোন ঘটনার, কোন শাখার, কোন কাগজের; শ্রেণি-গুরুত্ব; দেখা আর আর্কাইভ (প্রাপকের নিজের)
        'event_id', 'branch_id', 'module', 'category', 'priority', 'subject_type', 'subject_id', 'seen_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'seen_at' => 'datetime',
            'archived_at' => 'datetime',
            'subject_id' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'event_id');
    }

    public function isCritical(): bool
    {
        return $this->priority === 'critical';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<self>  $query */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * ⛔ এই মানুষটা যা দেখতে পারেন — নিজের খবর, আর কাগজের শাখা তাঁর নাগালে (ধাপ ১; স্পেক §১৩)।
     *
     * ⓘ শাখাহীন খবর (কোম্পানির ব্যাপার, পুরনো সারি) সবসময় দেখা যায় — [[DataScope::allows()]]-এর একই নিয়ম। নাগাল পরে
     * কমানো হলে আগের পাওয়া অন্য শাখার খবরও লুকায়; খোলার সময় কাগজটা আবার দেখা হয় ([[NotificationService::mayOpen()]])।
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where($query->getModel()->getTable().'.user_id', $user->id);

        $reach = app(DataScope::class)->idsFor($user, UserDataScope::BRANCH);

        if ($reach !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $reach));
        }

        return $query;
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
