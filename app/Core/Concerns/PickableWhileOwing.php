<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ টাকার কাগজের বাছাইয়ে নিষ্ক্রিয় পক্ষও — যতক্ষণ খাতায় তার জের আছে (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; fe-র সিদ্ধান্ত;
 * [[AnInactivePartyWithABalanceCouldNotBeSettledTest]])।
 *
 * ⚠️ বকেয়া রেখে বন্ধ করা দোকানের টাকা আদায়, বা বন্ধ সরবরাহকারীর বাকি দেনা পরিশোধের কোনো পথ ছিল না — আদায়, পরিশোধ আর
 * ভাউচারের তালিকা কেবল সক্রিয় পক্ষ দেখাত। ⓘ কেবল টাকা মেটানোর পর্দায়; নতুন বিক্রি বা কেনায় নিষ্ক্রিয় পক্ষ আসে না।
 * নামের পাশে "নিষ্ক্রিয় · জের আছে", যাতে কেউ ভুলে নতুন সম্পর্ক না ভাবেন।
 *
 * ⓘ মডেলের `drillSourceType()` (খাতার `party_type`) আর `name()` লাগে — গ্রাহক আর সরবরাহকারী।
 */
trait PickableWhileOwing
{
    /** সক্রিয়, অথবা খাতায় এখনো জের আছে — গোটা কোম্পানি ধরে (জের শাখায় ভাগ হয় না) */
    public function scopeActiveOrOwing(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $w) => $w->where($table.'.is_active', true)
            ->orWhereIn($table.'.id', DB::table('ledger_entries')
                ->select('party_id')
                ->where('company_id', CompanyContext::id())
                ->where('party_type', static::drillSourceType())
                ->groupBy('party_id')
                ->havingRaw('SUM(debit) - SUM(credit) <> 0')));
    }

    /** বাছাইয়ের তালিকায় নাম — নিষ্ক্রিয় হলে চিহ্নসহ */
    public function pickerName(): string
    {
        return $this->is_active ? $this->name() : $this->name().' — '.__('core.inactive_with_balance');
    }
}
