<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ আয়ু, শেষ দাম, পদ্ধতি বা হার বদল — কবে, কে, কেন, আগে কী ছিল (IAS 16.51 আর IAS 8.36; স্থায়ী সম্পদ ধাপ ২)।
 *
 * ⓘ বদল আগামীর দিকে: আগে বসা অবচয় ছোঁয়া হয় না, পরের মাস থেকে বাকি দাম বাকি আয়ুতে ভাগ হয় ([[DepreciationEngine]])।
 */
class AssetEstimateChange extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_asset_estimate_changes';

    protected $fillable = ['company_id', 'fixed_asset_id', 'changed_on', 'before', 'after', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['changed_on' => 'date', 'before' => 'array', 'after' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
