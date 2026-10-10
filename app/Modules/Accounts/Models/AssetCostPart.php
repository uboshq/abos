<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কেনা দামের একটা ভাগ — দাম, পরিবহন, বসানো, শুল্ক (IAS 16.16-17; মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ ভাগগুলোর যোগফলই সম্পদের দাম ([[FixedAssetService::register()]]) — "ফ্রিজ ৮০ হাজার, আনতে ৩ হাজার, বসাতে ২ হাজার"
 * পাতায় আলাদা পড়া যায়, অথচ খাতায় একটা দাম।
 */
class AssetCostPart extends Model
{
    use BelongsToCompany;

    public const PURCHASE = 'purchase';

    public const FREIGHT = 'freight';

    public const INSTALLATION = 'installation';

    public const DUTY = 'duty';

    public const OTHER = 'other';

    /** @var list<string> */
    public const KINDS = [self::PURCHASE, self::FREIGHT, self::INSTALLATION, self::DUTY, self::OTHER];

    protected $table = 'acc_asset_cost_parts';

    protected $fillable = ['company_id', 'fixed_asset_id', 'kind', 'amount', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
