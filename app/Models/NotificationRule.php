<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ বিজ্ঞপ্তির নিয়ম — কোড না লিখে "কোন ঘটনায়, কোন শর্তে, কার কাছে, কোন মাধ্যমে" (মালিকের স্পেক §৮; ধাপ ৩)।
 *
 * ⓘ নিয়ম মডিউলের নিজের প্রাপকদের সরায় না — কেবল যোগ করে, গুরুত্ব বা মাধ্যম ঠিক করে, লেখা টেমপ্লেট থেকে বসায়।
 * ⛔ নিয়ম কেবল জানায়; কোনো কাগজ বদলায় না। প্রতিটা বদলে সংস্করণ বাড়ে, আর পুরো ছবি [[NotificationRuleVersion]]-এ।
 */
class NotificationRule extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** @var list<string> শর্তের তুলনা — কেবল এগুলো, আর কিছু নয় */
    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in'];

    protected $fillable = [
        'company_id', 'name', 'module', 'event', 'branch_id', 'conditions', 'recipients', 'channels', 'priority',
        'template_id', 'delay_minutes', 'expires_minutes', 'cooldown_minutes', 'is_active', 'effective_from',
        'effective_to', 'version', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'recipients' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NotificationRuleVersion::class, 'rule_id')->orderByDesc('version');
    }

    /** @return list<int> এক ধরনের প্রাপকের আইডি */
    public function recipientIds(string $kind): array
    {
        return array_values(array_map('intval', (array) (($this->recipients ?? [])[$kind] ?? [])));
    }

    /** সংস্করণের ছবি — যা বদলালে নিয়মের অর্থ বদলায় */
    public function snapshot(): array
    {
        return $this->only([
            'name', 'module', 'event', 'branch_id', 'conditions', 'recipients', 'channels', 'priority', 'template_id',
            'delay_minutes', 'expires_minutes', 'cooldown_minutes', 'is_active',
        ]) + [
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
