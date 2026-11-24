<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\EmptiesTheReadWindow;
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
    use EmptiesTheReadWindow;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /**
     * ⭐ শাখার ধরন (মালিক, ২৪ নভেম্বর ২০২৬) — নাম `core.branch_type.*`-এ; খালি মানে দেওয়া নেই। ⓘ এখনো কোনো নিয়ম এর উপর দাঁড়ায় না,
     *   কেবল খোঁজা আর একসাথে বাছার জন্য।
     */
    public const TYPES = ['shop', 'depot', 'warehouse', 'office'];

    /** ছাঁকনিতে "ধরন দেওয়া নেই" */
    public const NO_TYPE = 'none';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'type', 'sort_order',
        'address_en', 'address_bn', 'phone', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** ধরনের নাম — দেওয়া না থাকলে খালি */
    public function typeLabel(): string
    {
        return in_array($this->type, self::TYPES, true) ? (string) __('core.branch_type.'.$this->type) : '';
    }

    /** @return array<string, string> ফর্ম আর ছাঁকনির তালিকা */
    public static function typeOptions(bool $withNone = false): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = (string) __('core.branch_type.'.$type);
        }

        return $withNone ? [...$out, self::NO_TYPE => (string) __('core.branch_type.none')] : $out;
    }

    /** ঠিকানার `type` → চেনা ধরনগুলো (একটা বা অনেক); অচেনা মান বাদ @return list<string> */
    public static function typesFrom(mixed $raw): array
    {
        return array_values(array_intersect([...self::TYPES, self::NO_TYPE], array_filter((array) $raw, 'is_string')));
    }

    /** @param  list<string>  $types */
    public function scopeOfTypes(\Illuminate\Database\Eloquent\Builder $query, array $types): void
    {
        if ($types === []) {
            return;
        }

        // ⓘ নিজের বন্ধনীতে — চারপাশের কোম্পানির দেয়াল যেন না ভাঙে
        $query->where(fn ($w) => $w->whereIn('type', array_values(array_diff($types, [self::NO_TYPE])))
            ->when(in_array(self::NO_TYPE, $types, true), fn ($n) => $n->orWhereNull('type')));
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
