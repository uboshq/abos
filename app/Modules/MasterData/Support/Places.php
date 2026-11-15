<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Support;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ⭐ এলাকা আর পয়েন্টের অংশ — সব তালিকায় এক নিয়মে, আর হালকা (১৫ নভেম্বর ২০২৬)।
 *
 * ⓘ জায়গার গাছ একবারই পড়া হয় (এক কোয়েরি, পাতাপ্রতি একবার); প্রতিটা এলাকার নিচের ডাল সেখান থেকে হিসাব হয় — এলাকাপ্রতি
 *   আলাদা কোয়েরি নয়। ⓘ ঘরের পাশের সংখ্যাও এক কোয়েরিতে: তালিকার সারি জায়গা ধরে গুনে, তারপর গাছ বেয়ে এলাকায় যোগ।
 *   ⚠️ আগে এলাকাপ্রতি দুই কোয়েরি যেত (ডাল আর সংখ্যা) — এলাকা বাড়লে পাতা ধীর হত ([[TheCustomerNameCarriesPointAndBranchTest]] ধরেছে)।
 * ⓘ ঘর দেখায় কেবল চালু জায়গা, যে শাখা মানুষটা দেখেন (বা শাখাহীন) — [[Location::scopeInViewedBranch()]]-এর একই নিয়ম।
 */
final class Places
{
    /**
     * ঘর আর এক-কোয়েরির গোনা — একটা অংশ বানানোর সব।
     *
     * @param  string  $level  Location::TERRITORY · Location::POINT …
     * @param  Closure(list<int>): Closure(Builder): mixed  $applyIds  জায়গার আইডি (ডালসহ) দিলে কোয়েরিতে কী বসবে
     * @param  Closure(Builder): Collection<int, object{loc: int|null, n: int}>  $perLocation  ছাঁকা তালিকার সারি জায়গা ধরে গোনা
     * @return array{options: array<string, array{label: string, apply: Closure(Builder): mixed}>, count: Closure(Builder): array<string, int>}
     */
    public static function section(string $level, Closure $applyIds, Closure $perLocation): array
    {
        $tree = self::tree();
        $options = [];
        $members = [];

        foreach ($tree['nodes'] as $node) {
            if ($node->level !== $level || ! $node->is_active || ! self::visible($node)) {
                continue;
            }

            $ids = self::descendants((int) $node->id, $tree['children']);
            $members[(string) $node->id] = $ids;
            $options[(string) $node->id] = ['label' => $node->name(), 'apply' => $applyIds($ids)];
        }

        uasort($options, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        $count = function (Builder $query) use ($perLocation, $members): array {
            $byLocation = [];

            foreach ($perLocation($query) as $row) {
                $byLocation[(int) $row->loc] = (int) $row->n;
            }

            $counts = [];

            foreach ($members as $option => $ids) {
                $counts[$option] = array_sum(array_intersect_key($byLocation, array_flip($ids)));
            }

            return $counts;
        };

        return ['options' => $options, 'count' => $count];
    }

    /**
     * @return array{nodes: Collection<int, Location>, children: array<int, list<int>>}
     */
    private static function tree(): array
    {
        $key = 'list_filter.places';
        $cached = request()->attributes->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $nodes = Location::query()->get(['id', 'parent_id', 'level', 'is_active', 'branch_id', 'code', 'name_en', 'name_bn']);
        $children = [];

        foreach ($nodes as $node) {
            if ($node->parent_id !== null) {
                $children[(int) $node->parent_id][] = (int) $node->id;
            }
        }

        $tree = ['nodes' => $nodes, 'children' => $children];
        request()->attributes->set($key, $tree);

        return $tree;
    }

    /**
     * @param  array<int, list<int>>  $children
     * @return list<int>
     */
    private static function descendants(int $root, array $children): array
    {
        $out = [];
        $stack = [$root];

        while ($stack !== []) {
            $id = array_pop($stack);
            $out[] = $id;

            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    private static function visible(Location $node): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $node->branch_id === null) {
            return true;
        }

        $ids = app(DataScope::class)->viewBranchIds($user);

        return $ids === null || in_array((int) $node->branch_id, array_map('intval', $ids), true);
    }
}
