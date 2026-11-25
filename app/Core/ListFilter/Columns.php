<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ তালিকার সবচেয়ে সাধারণ দুই ছাঁকনি — এক কলামের তারিখের পরিসর, আর এক কলামের বাছাই (মালিকের অডিট, ২৫ নভেম্বর ২০২৬:
 *   "ছাঁকনিগুলো সব জায়গায় বসছে কিনা")। ⓘ এগুলো কোয়েরিতে নিজেই বসে ([[FilterSet::apply()]]), তাই কন্ট্রোলারে আলাদা `when` লাগে না।
 *
 * ⛔ কলামের নাম কেবল কোড থেকে — ঠিকানার কোনো লেখা কলামের নাম হয় না; মান বাঁধা প্যারামিটারে যায়।
 */
final class Columns
{
    public static function between(string $label, string $column, bool $bar = true, string $from = 'from', string $to = 'to'): DateRangeFacet
    {
        $facet = (new DateRangeFacet('date', $label, function (Builder $q, ?string $start, ?string $end) use ($column): void {
            $q->when($start !== null, fn (Builder $w) => $w->whereDate($column, '>=', $start))
                ->when($end !== null, fn (Builder $w) => $w->whereDate($column, '<=', $end));
        }, fromKey: $from, toKey: $to))->look('sky', '📅');

        return $bar ? $facet->onTheBar() : $facet;
    }

    /** @param  array<int|string, string>  $options  মান => নাম */
    public static function is(string $key, string $label, string $column, array $options): ?ChoiceFacet
    {
        if ($options === []) {
            return null;
        }

        $choices = [];

        foreach ($options as $value => $name) {
            $choices[(string) $value] = ['label' => $name, 'apply' => fn (Builder $q) => $q->where($column, $value)];
        }

        return (new ChoiceFacet($key, $label, $choices))->look('violet', '●');
    }

    /** @param  list<Facet|null>  $facets  ⓘ null বাদ */
    public static function set(array $facets): FilterSet
    {
        return new FilterSet(array_values(array_filter($facets)));
    }
}
