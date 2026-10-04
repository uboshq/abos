<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * এক মাস্টার তালিকার স্বাস্থ্য — মালিকের ড্যাশবোর্ড নকশা §১২ (৩ অক্টোবর ২০২৬): "Data Governance & Data Quality"।
 *
 * ⓘ গোনার নিয়ম এক জায়গায়, যাতে গ্রাহক, সরবরাহকারী, পণ্য, কর্মী আর গুদাম পাঁচ রকম করে না গোনে:
 *   সম্পূর্ণ   = চালু সারির মধ্যে যাদের দরকারি ঘর ভরা (দরকারি ঘর কোনটা, সেটা মডিউল বলে);
 *   দ্বিতীয়বার = চালু সারির মধ্যে যাদের ফোন/নাম আরেকটা চালু সারির সাথে হুবহু এক (খালি গোনা হয় না);
 *   বন্ধ      = `is_active` মিথ্যা।
 * ⓘ শতাংশ চালু সারি ধরে — বন্ধ সারির ফাঁকা ঘর কারও কাজ আটকায় না। চালু সারি না থাকলে ১০০%: খালি তালিকায় ভুল নেই।
 * ⓘ মডেলের কোয়েরি দিয়ে, তাই কোম্পানির দেয়াল নিজেই বসে।
 */
final class MasterHealth
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $rows  তালিকার সব সারি
     * @param  Closure(Builder): mixed  $complete  দরকারি ঘর ভরা — কোয়েরিতে শর্ত বসায়
     */
    public static function widget(
        string $label,
        string $href,
        string $permission,
        Builder $rows,
        Closure $complete,
        ?string $sameColumn,
        int $sort,
    ): Widget {
        $table = $rows->getModel()->getTable();
        $active = (clone $rows)->where($table.'.is_active', true);

        $total = (clone $rows)->count();
        $live = (clone $active)->count();
        $whole = $live === 0 ? $live : (int) $complete(clone $active)->count();
        $missing = $live - $whole;

        $same = 0;
        if ($sameColumn !== null && $live > 0) {
            $repeated = (clone $active)
                ->reorder()
                ->whereNotNull($table.'.'.$sameColumn)
                ->where($table.'.'.$sameColumn, '<>', '')
                ->groupBy($table.'.'.$sameColumn)
                ->havingRaw('COUNT(*) > 1')
                ->selectRaw('COUNT(*) as n')
                ->toBase()
                ->pluck('n');
            $same = (int) $repeated->sum();
        }

        // ⓘ নিচের দিকে কাটা — একটাও ফাঁকা থাকলে ১০০% দেখায় না
        $score = $live === 0 ? '100' : (string) intdiv(100 * $whole, $live);

        return new Widget(
            group: 'health',
            label: $label,
            value: $score.'%',
            href: $href,
            permission: $permission,
            tone: $missing === 0 && $same === 0 ? 'good' : 'warn',
            hint: __('core.dashboard.health_hint', ['missing' => $missing, 'same' => $same, 'off' => $total - $live]),
            sort: $sort,
            parts: [
                'total' => (string) $total,
                'active' => (string) $live,
                'complete' => (string) $whole,
                'missing' => (string) $missing,
                'same' => (string) $same,
                'inactive' => (string) ($total - $live),
            ],
        );
    }
}
