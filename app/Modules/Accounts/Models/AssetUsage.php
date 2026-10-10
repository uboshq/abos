<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক মাসে সম্পদটা কত একক চলল — ব্যবহারের এককে ক্ষয়ের ভিত্তি (IAS 16.62; স্থায়ী সম্পদ ধাপ ২)।
 */
class AssetUsage extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_asset_usages';

    protected $fillable = ['company_id', 'fixed_asset_id', 'period_end', 'units', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['period_end' => 'date', 'units' => 'decimal:4'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
