<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Contracts\Drillable;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** একটা অনুমোদনের অনুরোধ। polymorphic — যেকোনো ডকুমেন্টে বসে। */
class Approval extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'approvable_type', 'approvable_id', 'module', 'action',
        'amount', 'status', 'current_level', 'payload',
        'requested_reason', 'requested_by', 'requested_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'current_level' => 'integer',
            'payload' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    // ── Drillable — নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে যায় ──────────────

    /**
     * ── কেন অনুরোধটাই গন্তব্য, নিচের কাগজটা নয় ──────────────────────
     * প্রলুব্ধ করে ভাবতে যে "১২টা অপেক্ষমাণ" থেকে ক্লিক করলে ক্রয়াদেশটাই
     * খোলা উচিত। কিন্তু পাঠকের প্রশ্নটা তখন **অনুমোদন নিয়ে** — কে আটকে
     * আছে, কোন স্তরে, কে কী মন্তব্য করেছেন। ওই উত্তরগুলো আছে অনুরোধের
     * পর্দায়, আর সেখান থেকে কাগজটাতেও যাওয়া যায়। উল্টোটা নয়।
     */
    public static function drillSourceType(): string
    {
        return 'approval';
    }

    /**
     * অনুরোধের নিজের কোনো নম্বর নেই — কাগজেরটা আছে।
     *
     * তাই মডিউল ও কাজের নাম, আর সাথে আইডি: রিপোর্টে দুইটা সারি একই
     * রকম দেখালে কোনটা কোনটা বলার আর কোনো উপায় থাকত না।
     */
    public function drillDocumentNo(): string
    {
        return $this->module.'.'.$this->action.'#'.$this->getKey();
    }

    public function drillLabel(): string
    {
        return trim((string) $this->requested_reason) !== ''
            ? (string) $this->requested_reason
            : $this->drillDocumentNo();
    }

    public function drillRoute(): array
    {
        return ['approval.inbox.show', ['approval' => $this->id]];
    }
}
