<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\NoticePriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * বারবার লেখা নোটিশের ছাঁচ.
 *
 * ⓘ ছাঁচে কেবল লেখা নয়, অগ্রাধিকার আর লক্ষ্যও — ⚠️ নাহলে প্রতিবার
 * হাতে বাছতে হত, আর কোনো একদিন কেউ ভুলতেন.
 */
final class NoticeTemplate extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'notice_category_id',
        'title', 'summary', 'body', 'priority', 'type', 'audience', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'is_active' => 'boolean',
            'priority' => NoticePriority::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    public function name(): string
    {
        return app()->getLocale() === 'bn' && filled($this->name_bn)
            ? (string) $this->name_bn
            : (string) $this->name_en;
    }
}
