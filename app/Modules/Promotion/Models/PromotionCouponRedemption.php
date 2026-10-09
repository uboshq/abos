<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কুপনটা একবার খাটল — কোন বিলে, কোন ক্রেতার জন্য।
 *
 * ⚠️ সারিটা কখনো মোছা হয় না। ⓘ বিল বাতিল হলে `reversed_at` বসে, সারিটা
 * থেকে যায় — ⛔ মুছলে *"এই কুপন কোথায় কোথায় খেটেছিল"* প্রশ্নের উত্তর
 * হারাত, আর §১৯-এর নিরীক্ষার খাতা ভাঙত।
 */
class PromotionCouponRedemption extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'coupon_id', 'promotion_application_id', 'customer_id',
        'source_type', 'source_id', 'carried_amount', 'redeemed_at', 'reversed_at', 'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PromotionCoupon::class, 'coupon_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'promotion_application_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
