<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Support\NoticePriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * নোটিশের ধরন — আর তার নিজের ডিফল্ট.
 *
 * ⓘ ক্যাটাগরি বলে *"এই ধরনের নোটিশ সাধারণত কতটা জরুরি, আর সই লাগে
 * কি না"*. ⚠️ অনুমোদনের আসল প্রবাহ [[ApprovalFlow]]-এ; এখানে কেবল
 * *"সই লাগবে"* কথাটা — দুইটা আলাদা রাখা হয়েছে যাতে প্রবাহের নিয়ম
 * বদলালে ক্যাটাগরি ছুঁতে না হয়.
 */
final class NoticeCategory extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'default_priority', 'needs_approval', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'needs_approval' => 'boolean',
            'is_active' => 'boolean',
            'default_priority' => NoticePriority::class,
        ];
    }

    /** @return HasMany<Notice, $this> */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    public function name(): string
    {
        return app()->getLocale() === 'bn' && filled($this->name_bn)
            ? (string) $this->name_bn
            : (string) $this->name_en;
    }
}
