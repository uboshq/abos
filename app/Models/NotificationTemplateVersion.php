<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ টেমপ্লেটের একটা সংস্করণ — একবার লেখা, আর বদলায় না (ধাপ ৩)। বদল মানে নতুন সংস্করণ; প্রকাশ মানে টেমপ্লেটের
 * `published_version_id` এটাকে দেখায়।
 */
class NotificationTemplateVersion extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'template_id', 'version', 'subject_bn', 'subject_en', 'title_bn', 'title_en', 'body_bn', 'body_en',
        'variables', 'note', 'created_by', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** এক ভাষার একটা অংশ (`subject`, `title`, `body`) — চাওয়া ভাষায় না থাকলে অন্যটায় */
    public function part(string $part, string $locale): ?string
    {
        $locale = $locale === 'en' ? 'en' : 'bn';
        $other = $locale === 'en' ? 'bn' : 'en';
        $value = $this->getAttribute($part.'_'.$locale);

        return filled($value) ? (string) $value : (filled($this->getAttribute($part.'_'.$other)) ? (string) $this->getAttribute($part.'_'.$other) : null);
    }
}
