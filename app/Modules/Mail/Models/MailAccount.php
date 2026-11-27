<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ⭐ একটা মেইলবক্স — কোম্পানির নিজের (`own`), দলের (`shared`), বা কারো ব্যক্তিগত (`personal`, অনুমতি সাপেক্ষে)।
 *
 * ⛔ `secret` আর `oauth` তালাবদ্ধ (encrypted cast) — ডাটাবেস দেখেও পাসওয়ার্ড পড়া যায় না; `$hidden`-এ, তাই JSON-এ যায় না।
 * ⓘ কে দেখতে পারে — [[MailAccess]]; ব্যক্তিগত মেইল কেবল তার মালিক, অন্য কেউ (মালিকও) নয়।
 */
class MailAccount extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const OWN = 'own';

    public const SHARED = 'shared';

    public const PERSONAL = 'personal';

    public const KINDS = [self::OWN, self::SHARED, self::PERSONAL];

    /** ⓘ অ্যাকাউন্টের রং — জোড়ার পর্দার পাঁচ বিন্দু (নমুনা v4) */
    public const COLOURS = ['#f5121d', '#1a3fd0', '#00ab3f', '#f8a500', '#6a3fd0'];

    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const OFF = 'off';

    /** ⓘ পরিচিত সেবাদাতার ঠিকানা — ব্যবহারকারীকে সার্ভার লিখতে হয় না */
    public const PROVIDERS = [
        'company' => ['imap' => ['mail.adi.com.bd', 993, 'ssl'], 'smtp' => ['mail.adi.com.bd', 465, 'ssl']],
        'gmail' => ['imap' => ['imap.gmail.com', 993, 'ssl'], 'smtp' => ['smtp.gmail.com', 465, 'ssl']],
        'outlook' => ['imap' => ['outlook.office365.com', 993, 'ssl'], 'smtp' => ['smtp.office365.com', 587, 'tls']],
        'yahoo' => ['imap' => ['imap.mail.yahoo.com', 993, 'ssl'], 'smtp' => ['smtp.mail.yahoo.com', 465, 'ssl']],
        'imap' => null,
    ];

    protected $table = 'mail_accounts';

    protected $fillable = [
        'user_id', 'kind', 'provider', 'label', 'colour', 'email', 'sender_name', 'sender_with_person',
        'imap_host', 'imap_port', 'imap_secure', 'smtp_host', 'smtp_port', 'smtp_secure',
        'username', 'secret', 'oauth', 'status', 'reason', 'expires_on',
        'decided_by', 'decided_at', 'decision_note', 'checked_at', 'last_error', 'created_by',
    ];

    protected $hidden = ['secret', 'oauth'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'oauth' => 'encrypted:array',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'expires_on' => 'date',
            'decided_at' => 'datetime',
            'checked_at' => 'datetime',
            'user_id' => 'integer',
            'sender_with_person' => 'boolean',
        ];
    }

    /** ⓘ সংযোগের সময় আর শেষ ভুল যন্ত্রের হিসাব — খাতায় নয় */
    public function auditIgnores(): array
    {
        return ['checked_at', 'last_error'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mail_account_members', 'mail_account_id', 'user_id')
            ->withPivot('can_send')->withTimestamps();
    }

    /** ⓘ ব্যবহারযোগ্য — চালু আর মেয়াদ পেরোয়নি */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', self::ACTIVE)
            ->where(fn (Builder $w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()));
    }

    public function isPersonal(): bool
    {
        return $this->kind === self::PERSONAL;
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && ($this->expires_on === null || ! $this->expires_on->isPast() || $this->expires_on->isToday());
    }

    /** ⓘ দেখানোর নাম — লেবেল, নাহলে ঠিকানা */
    public function boxTitle(): string
    {
        return (string) ($this->label ?: $this->email);
    }

    /** ⓘ খাতায় চেনার নাম ([[AuditEngine]] এটাই পড়ে) */
    public function name(): string
    {
        return (string) $this->email;
    }
}
