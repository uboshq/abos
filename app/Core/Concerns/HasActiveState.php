<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * is_active কলামওয়ালা মডেলের জন্য ->active() স্কোপ।
 *
 * নিয়ম ৫ বলে কিছু মোছা হয় না, নিষ্ক্রিয় করা হয় — ফলে প্রায় প্রতিটা
 * মাস্টার টেবিলেই is_active আছে, আর প্রায় প্রতিটা ড্রপডাউন ও তালিকায়
 * "শুধু সক্রিয়গুলো" লাগে।
 *
 * এক জায়গায় লেখা, কারণ ছড়িয়ে লিখলে যা হয়েছিল তা-ই হয়: Customer-এ
 * স্কোপটা ছিল, Branch-এ ছিল না, আর গ্রাহকের ফর্ম Branch::active() ডেকে
 * ৫০০ দিত। ভুলটা ধরা পড়েছে ব্রাউজারে, কারণ ফর্মটা কোনো টেস্ট খুলত না।
 */
trait HasActiveState
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.is_active', false);
    }
}
