<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ⭐ সম্পদের শ্রেণি — যানবাহন, আসবাব, কম্পিউটার… (IAS 16.37; মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ শ্রেণিটা নিবন্ধনের ছাঁচ: পাঁচটা খাত আর ডিফল্ট পদ্ধতি-আয়ু-শেষ দাম এখানে একবার লেখা, প্রতিটা সম্পদে আবার নয়।
 * ⚠️ সম্পদে খাতগুলো নকল হয়ে বসে ([[FixedAsset]]) — শ্রেণি পরে বদলালে পুরনো সম্পদের দাখিলার খাত নড়ে না; খাতায় যা
 * বসেছে তা যেখানে বসেছে সেখানেই থাকে।
 */
class AssetCategory extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'acc_asset_categories';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'asset_account_id', 'accumulated_account_id', 'expense_account_id',
        'gain_account_id', 'loss_account_id', 'impairment_account_id',
        'method', 'life_months', 'rate', 'residual_percent', 'capitalisation_threshold',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'life_months' => 'integer',
            'rate' => 'decimal:4',
            'residual_percent' => 'decimal:4',
            'capitalisation_threshold' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'bn' && filled($this->name_bn) ? (string) $this->name_bn : (string) $this->name_en;
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
