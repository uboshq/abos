<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Support;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\Columns;
use App\Core\ListFilter\FilterSet;
use App\Models\Branch;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ এলাকার গোটা গাছ একবারে, মুখে — কোন জায়গা কোন টেরিটরি, এরিয়া, বিভাগের নিচে, আর নিচে কয়টা পয়েন্ট (মালিকের নমুনা, ২৫ নভেম্বর
 *   ২০২৬: "পথ" এক ঘরে নয়, প্রতিটা স্তর নিজের কলামে, নিজের ছাঁকনিতে)। ⓘ সারি ধরে কোয়েরি নয় — কয়েকশো জায়গা, এক কোয়েরি।
 */
final class LocationMap
{
    /** @var array<int, array{parent: ?int, level: string, name: string, branch: ?int}> */
    private array $nodes = [];

    /** @var array<int, int>|null */
    private ?array $points = null;

    public function __construct()
    {
        foreach (Location::query()->get(['id', 'parent_id', 'level', 'name_en', 'name_bn', 'company_id', 'branch_id']) as $l) {
            $this->nodes[(int) $l->id] = ['parent' => $l->parent_id === null ? null : (int) $l->parent_id, 'level' => (string) $l->level, 'name' => (string) $l->name(), 'branch' => $l->branch_id === null ? null : (int) $l->branch_id];
        }
    }

    /** এই স্তরের যে জায়গার নিচে — না থাকলে `null`; নিজে ওই স্তরের হলে নিজেই */
    public function above(int $id, string $level): ?int
    {
        $seen = [];

        while (isset($this->nodes[$id]) && ! isset($seen[$id])) {
            if ($this->nodes[$id]['level'] === $level) {
                return $id;
            }

            $seen[$id] = true;

            if ($this->nodes[$id]['parent'] === null) {
                return null;
            }

            $id = $this->nodes[$id]['parent'];
        }

        return null;
    }

    public function name(?int $id): string
    {
        return $id === null ? '' : ($this->nodes[$id]['name'] ?? '');
    }

    /** নিচে কয়টা পয়েন্ট — মাঝের স্তর ডিঙিয়েও */
    public function points(int $id): int
    {
        if ($this->points === null) {
            $this->points = [];

            foreach ($this->nodes as $point => $node) {
                if ($node['level'] !== Location::POINT) {
                    continue;
                }

                $seen = [];
                $up = $node['parent'];

                while ($up !== null && ! isset($seen[$up])) {
                    $seen[$up] = true;
                    $this->points[$up] = ($this->points[$up] ?? 0) + 1;
                    $up = $this->nodes[$up]['parent'] ?? null;
                }
            }
        }

        return $this->points[$id] ?? 0;
    }

    /**
     * এই স্তরের উপরের চালু স্তরগুলো, কাছেরটা আগে — দেশ বাদ (একটাই)।
     *
     * @param  list<string>  $ladder
     * @return list<string>
     */
    public static function upper(string $level, array $ladder): array
    {
        $at = array_search($level, $ladder, true);

        return $at === false ? [] : array_values(array_reverse(array_filter(array_slice($ladder, 0, $at), fn (string $l) => $l !== Location::COUNTRY)));
    }

    /**
     * ছাঁকনি — উপরের প্রতিটা স্তর (পাশে কয়টা সারি), আর অবস্থা।
     *
     * @param  list<string>  $ladder
     */
    public function filters(string $level, array $ladder): FilterSet
    {
        $facets = [];
        $rows = array_keys(array_filter($this->nodes, fn (array $n) => $n['level'] === $level));
        $branches = Branch::query()->orderBy('code')->get()->mapWithKeys(fn (Branch $b) => [(int) $b->id => (string) $b->name()])->all();

        // ⭐ শাখা (মালিক, ২৫ নভেম্বর ২০২৬) — কেবল যেগুলোয় সারি আছে
        $mine = [];

        foreach ($rows as $id) {
            $mine[$this->nodes[$id]['branch'] ?? 0] = true;
        }

        $options = [];

        foreach ($branches as $id => $name) {
            if (isset($mine[$id])) {
                $options[(string) $id] = ['label' => $name, 'apply' => fn (Builder $q) => $q->where($q->qualifyColumn('branch_id'), $id)];
            }
        }

        if (isset($mine[0])) {
            $options['all'] = ['label' => (string) __('master_data::message.every_branch'), 'apply' => fn (Builder $q) => $q->whereNull($q->qualifyColumn('branch_id'))];
        }

        if ($options !== []) {
            $facets[] = (new ChoiceFacet('branch', (string) __('master_data::field.branch'), $options))->look('blue', '🏬');
        }

        foreach (array_reverse(self::upper($level, $ladder)) as $upper) {
            $under = [];

            foreach ($rows as $id) {
                $under[(string) ($this->above($id, $upper) ?? 'none')][] = $id;
            }

            $options = [];

            foreach ($under as $at => $ids) {
                $options[(string) $at] = [
                    // ⓘ একই নাম কয়েক শাখায় থাকে (ময়মনসিংহ) — তাই শাখার নাম সাথে
                    'label' => $at === 'none' ? (string) __('master_data::message.level_not_set')
                        : implode(' › ', array_filter([$branches[$this->nodes[(int) $at]['branch'] ?? 0] ?? null, $this->name((int) $at)])),
                    'apply' => fn (Builder $q) => $q->whereIn($q->qualifyColumn('id'), $ids),
                ];
            }

            uasort($options, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

            if ($options !== []) {
                $facets[] = (new ChoiceFacet($upper, (string) __('master_data::level.'.$upper), $options))->look('teal', '◈');
            }
        }

        return Columns::set([...$facets, Columns::is('state', (string) __('core.table.status'), 'is_active',
            ['1' => (string) __('core.state.active'), '0' => (string) __('core.state.inactive')])]);
    }
}
