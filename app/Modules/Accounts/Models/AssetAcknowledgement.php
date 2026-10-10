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
 * ⭐ দায়িত্বে থাকা কর্মী সম্পদটা বুঝে নিয়েছেন — কবে, কোন অবস্থায় (স্থায়ী সম্পদ ধাপ ৪)।
 *
 * ⓘ ইতিহাস: কর্মী বদলালে নতুন জনকে আবার স্বীকার করতে হয়; পুরনো সারি থাকে।
 */
class AssetAcknowledgement extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const GOOD = 'good';

    public const DAMAGED = 'damaged';

    /** @var list<string> */
    public const CONDITIONS = [self::GOOD, self::DAMAGED];

    protected $table = 'acc_asset_acknowledgements';

    protected $fillable = ['company_id', 'fixed_asset_id', 'employee_id', 'user_id', 'acknowledged_at', 'condition', 'note'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
