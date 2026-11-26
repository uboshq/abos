<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ নিজের ডোমেইনের একটা মেইল ঠিকানা (প্রশাসন, নমুনা v4 পর্দা ৭–১২): তৈরি, বদল, বন্ধ, মোছা — সার্ভারে [[MailHost]] দিয়ে, খাতা এখানে।
 *
 * ⛔ `system` ঠিকানা (no-reply@) কখনো মোছা যায় না; চালু ঠিকানা সরাসরি মোছে না — আগে বন্ধ।
 * ⛔ মোছা মানে `deleting` — 30 দিন রাখা, এর মধ্যে ফেরানো যায়; তারপর ব্যাকআপ কপি রেখে সার্ভার থেকে মোছে ([[TidyMailAddresses]])।
 * ⓘ প্রতিটা বদল অডিটে (মালিক, ২৬ নভেম্বর ২০২৬): কে, কখন, আগে কী ছিল, পরে কী।
 */
class MailAddress extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PERSON = 'person';

    public const SHARED = 'shared';

    public const SYSTEM = 'system';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DELETING = 'deleting';

    public const DELETED = 'deleted';

    /** ⓘ মোছার পরে কতদিন ফেরানো যায় */
    public const KEEP_DAYS = 30;

    protected $table = 'mail_addresses';

    protected $fillable = [
        'email', 'domain', 'kind', 'user_id', 'display_name', 'quota_mb', 'status', 'pause_mode', 'forward_to', 'pause_reason',
        'resume_on', 'delete_on', 'handed_to', 'auto_reply', 'mail_account_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['resume_on' => 'date', 'delete_on' => 'date', 'quota_mb' => 'integer', 'user_id' => 'integer', 'auto_reply' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function localPart(): string
    {
        return (string) strstr($this->email, '@', true);
    }

    public function isSystem(): bool
    {
        return $this->kind === self::SYSTEM;
    }

    /** ⓘ খাতায় চেনার নাম ([[AuditEngine]] এটাই পড়ে) */
    public function name(): string
    {
        return (string) $this->email;
    }
}
