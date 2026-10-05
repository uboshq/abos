<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * কোম্পানির একটা শাখা।
 *
 * একটা ডিপোর তিনটা শাখা থাকলে প্রতিটার নিজের ডকুমেন্ট সিরিজ ও নিজের এন্ট্রি
 * থাকা দরকার — DMS-এ শাখা ধরা না থাকায় সব এন্ট্রি একটাতেই যেত, আর সেটা ঠিক
 * করতে পরে আলাদা কমিট লেগেছিল।
 */
class Branch extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'sort_order',
        'address_en', 'address_bn', 'phone', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    /**
     * ⭐ মালিকের ক্রমে — `sort_order` আগে (০ মানে ক্রম দেওয়া হয়নি, তাই শেষে), তারপর নামের বর্ণক্রম।
     * মালিক, ৫ অক্টোবর ২০২৬: "সব শাখা, সুপার গ্রুপ, লায়ন গ্রুপ, গোল্ড গ্রুপ, জাবেদ এগ্রো ফুড, হোলসেল"।
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeOrdered(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderByRaw('CASE WHEN sort_order = 0 THEN 1 ELSE 0 END')
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }
}
