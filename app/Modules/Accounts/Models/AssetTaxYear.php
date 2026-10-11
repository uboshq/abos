<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক আয়বর্ষে একটা সম্পদের করের অবচয় — খাতায় বসে না, কেবল করের হিসাবে (স্থায়ী সম্পদ ধাপ ৫; [[AssetTaxService]])।
 */
class AssetTaxYear extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_asset_tax_years';

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'branch_id', 'year_start', 'year_end', 'method', 'rate',
        'opening_wdv', 'amount', 'closing_wdv', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year_start' => 'date', 'year_end' => 'date', 'rate' => 'decimal:4',
            'opening_wdv' => 'decimal:4', 'amount' => 'decimal:4', 'closing_wdv' => 'decimal:4',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
