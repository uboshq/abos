<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * একটা কুপনের কোড — কোন অফারের, কতবার, কার জন্য।
 *
 * ── ⚠️ কেন `code` আর `used_count` কখনো `fillable`-এ নয় ───────────────
 * ⓘ কোডটা সিরিজ বা [[CouponDesk::issue()]] থেকে আসে, ফর্ম থেকে নয়।
 * ⛔ আর `used_count` গোনা হয় কেবল তালার নিচে — `fillable`-এ থাকলে একটা
 * সাধারণ সম্পাদনায় কেউ সংখ্যাটা শূন্য করে দিতে পারতেন, আর একবারের
 * কুপন আবার খাটত।
 */
class PromotionCoupon extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'promotion_id', 'max_uses', 'max_uses_per_customer', 'customer_id',
        'valid_from', 'valid_to', 'is_active', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionCouponRedemption::class, 'coupon_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** ⓘ আর কতবার খাটবে। */
    public function usesLeft(): int
    {
        return max(0, $this->max_uses - $this->used_count);
    }

    /**
     * ⭐ এই দিনটা কুপনের নিজের মেয়াদের ভিতরে কি না।
     *
     * ⚠️ অফারের মেয়াদ আলাদা প্রশ্ন — সেটা [[Promotion::isLiveOn()]]। ⓘ দুইটাই
     * সত্য হতে হয়: কুপন চালু অথচ অফার থামানো, তাহলে কিছু খাটে না।
     */
    public function isWithinDates(Carbon $at): bool
    {
        $day = $at->toDateString();

        if ($this->valid_from !== null && $day < $this->valid_from->toDateString()) {
            return false;
        }

        return ! ($this->valid_to !== null && $day > $this->valid_to->toDateString());
    }

    /**
     * ⓘ ক্রেতা যা টাইপ করেন তা এক রূপে — বড় হাতের, ফাঁকা ছাড়া।
     *
     * ⛔ নাহলে *"eid100"* আর *"EID100 "* দুইটা আলাদা কোড মনে হত, আর
     * কাউন্টারে ক্রেতা শুনতেন *"এমন কুপন নেই"*।
     */
    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }
}
