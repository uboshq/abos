<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা অফারের একটা ছাদ — স্পেক §১৫।
 *
 * ⚠️ নিরীক্ষিত, কারণ ছাদ বাড়ানো মানে আরও টাকা দেওয়ার সিদ্ধান্ত। ⓘ কে,
 * কবে, কত থেকে কত বাড়ালেন — না জানলে মাস শেষে *"এত ছাড় গেল কেন"*
 * প্রশ্নের উত্তর থাকত না।
 */
class PromotionBudget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const TOTAL = 'total';

    public const DISCOUNT = 'discount';

    public const GIFT = 'gift';

    public const QUANTITY = 'quantity';

    /*
     * ⭐ কোন জানালায় গোনা — স্পেক §১৫ *"Limit Controls"*।
     * ⓘ ধরন বলে *কী* গোনা হয়; জানালা বলে *কোথায়*। দুইটা আলাদা অক্ষ।
     */
    public const PER_OFFER = 'offer';

    public const PER_BILL = 'bill';

    public const PER_CUSTOMER = 'customer';

    public const PER_DAY = 'day';

    public const PER_MONTH = 'month';

    /** @var list<string> */
    public const WINDOWS = [self::PER_OFFER, self::PER_BILL, self::PER_CUSTOMER, self::PER_DAY, self::PER_MONTH];

    protected $fillable = ['promotion_id', 'kind', 'per', 'ceiling', 'warn_at_percent'];

    protected function casts(): array
    {
        return [
            'ceiling' => 'decimal:4',
            'warn_at_percent' => 'integer',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
