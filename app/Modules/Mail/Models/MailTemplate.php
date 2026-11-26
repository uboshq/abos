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

/**
 * ⭐ মেইলের টেমপ্লেট (নমুনা v4 "ok"): কাজ ধরে ভাগ, ঘর {গ্রাহকের নাম} ইত্যাদি পাঠানোর সময় আসল তথ্যে বসে ([[MailFields]])।
 * ⓘ কোম্পানির টেমপ্লেট (`user_id` খালি) — `mail.manage` বানান, সবাই ব্যবহার করেন; নিজেরটা কেবল নিজে দেখেন।
 * ⛔ নিজে থেকে পাঠানো (`auto`) প্রথমে বন্ধ — প্রশাসক চালু করলে তবেই।
 */
class MailTemplate extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ নতুন টেমপ্লেটের মাথার রং */
    public const COLOUR = '#1a3fd0';

    public const CATEGORIES = ['collect', 'sales', 'delivery', 'purchase', 'hr', 'festival', 'offer', 'papers', 'reply'];

    protected $table = 'mail_templates';

    protected $fillable = [
        'user_id', 'category', 'key', 'name', 'locale', 'format', 'colour', 'subject', 'body', 'button',
        'attach_paper', 'auto', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['attach_paper' => 'boolean', 'active' => 'boolean', 'auto' => 'array', 'used' => 'integer', 'user_id' => 'integer'];
    }

    /** ⓘ কতবার ব্যবহার — যন্ত্রের গোনা, খাতায় নয় */
    public function auditIgnores(): array
    {
        return ['used'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** ⓘ এই কর্মী যা দেখেন — কোম্পানির সব, আর নিজের */
    public function scopeSeenBy(Builder $q, User $user): Builder
    {
        return $q->where(fn (Builder $w) => $w->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    public function isCompanys(): bool
    {
        return $this->user_id === null;
    }
}
