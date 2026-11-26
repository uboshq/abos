<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কর্মীর মেইলের সই (নমুনা v4): নিজের ছবি (না থাকলে নামের আদ্যক্ষর), নতুন মেইল আর উত্তরের নকশা আলাদা, লিংক।
 * ⓘ যাদের মেইলের ছবি সবসময় দেখাবেন (`trusted`) — কর্মীর নিজের পছন্দ, খাতায় নয়।
 */
class MailProfile extends Model
{
    use BelongsToCompany;
    use IsAudited;

    public const LINKS = ['facebook', 'linkedin', 'youtube', 'whatsapp', 'website'];

    protected $table = 'mail_profiles';

    protected $fillable = ['user_id', 'photo_path', 'style_new', 'style_reply', 'festival', 'title', 'phone', 'links', 'trusted'];

    protected function casts(): array
    {
        return ['links' => 'array', 'trusted' => 'array', 'festival' => 'boolean', 'style_new' => 'integer', 'style_reply' => 'integer', 'user_id' => 'integer'];
    }

    public function auditIgnores(): array
    {
        return ['trusted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ⓘ এই প্রেরকের ছবি সবসময় দেখানো হবে কিনা */
    public function trusts(string $email): bool
    {
        return in_array(mb_strtolower(trim($email)), array_map('mb_strtolower', (array) ($this->trusted ?? [])), true);
    }

    /** ⓘ খাতায় চেনার নাম ([[AuditEngine]] এটাই পড়ে) */
    public function name(): string
    {
        return (string) ($this->user?->name ?? '');
    }
}
