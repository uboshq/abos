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
 * ⭐ বিজ্ঞপ্তির টেমপ্লেট — বাংলা ও ইংরেজি লেখা, সংস্করণসহ (মালিকের স্পেক §৯গ "Template Studio"; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)।
 *
 * ⓘ লেখা থাকে সংস্করণে ([[NotificationTemplateVersion]]); খবরে বসে কেবল প্রকাশিতটা। আগের সংস্করণে ফেরা মানে সেটাকে আবার
 * প্রকাশ করা — ইতিহাস মোছে না।
 */
class NotificationTemplate extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['company_id', 'code', 'name', 'category', 'channels', 'published_version_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    public function published(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'published_version_id');
    }

    /** সবচেয়ে নতুন সংস্করণ — খসড়া হলেও */
    public function latest(): ?NotificationTemplateVersion
    {
        return $this->versions()->first();
    }
}
