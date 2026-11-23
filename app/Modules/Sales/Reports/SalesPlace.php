<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ গ্রাহকের জায়গার সিঁড়ি SQL-এ — পয়েন্ট › টেরিটরি › এরিয়া (মালিক, ২৩ নভেম্বর ২০২৬: এলাকা ধরে বিক্রয় আর টাকা আসা, স্তর বেছে)।
 *
 * গ্রাহকের একটাই `location_id`; উপরের ধাপ `mdm_locations.parent_id` ধরে। ⓘ [[PartyTargetReport::base()]]-এর একই উপায় (পাঁচ ধাপ),
 * এখানে আলাদা করা যাতে নতুন রিপোর্টগুলো একই সিঁড়ি পড়ে — স্তর, নাম, "উপরে কোথাও এই জায়গা" সব এক জায়গা থেকে।
 *
 * ⓘ লাইভ ADI-তে অনেক পয়েন্ট সরাসরি এরিয়ার নিচে (টেরিটরি নেই) — তখন টেরিটরির ঘর `NULL`, আর রিপোর্ট সেটাকে "টেরিটরি বসানো নেই"
 * বলে দেখায়; সারি হারায় না। ⓘ যে স্তর কোম্পানিতে নেই (ডিভিশন, অঞ্চল) তার রিপোর্টই নেই ([[LEVELS]])।
 */
final class SalesPlace
{
    /** গ্রাহকের জায়গা থেকে কত ধাপ উপরে খোঁজা — রুট › পয়েন্ট › টেরিটরি › এরিয়া › (অঞ্চল) */
    public const DEPTH = 5;

    /**
     * রিপোর্টের স্তর, উপর থেকে নিচে — শেষে গ্রাহক। ⓘ প্রতিটার নিজের রিপোর্ট; অঙ্কে চাপলে পরেরটা খোলে।
     *
     * @var list<string>
     */
    public const LEVELS = [Location::AREA, Location::TERRITORY, Location::POINT, self::CUSTOMER];

    public const CUSTOMER = 'customer';

    /** সিঁড়ির জোড় — `l1` গ্রাহকের নিজের জায়গা, `l2`…`l5` তার উপরের ধাপ */
    public static function join(Builder $query, string $from = 'cu.location_id'): Builder
    {
        $query->leftJoin('mdm_locations as l1', 'l1.id', '=', $from);

        for ($i = 2; $i <= self::DEPTH; $i++) {
            $query->leftJoin("mdm_locations as l{$i}", "l{$i}.id", '=', 'l'.($i - 1).'.parent_id');
        }

        return $query;
    }

    /** পড়ার ভাষায় নাম — বাংলায় বাংলা নাম আগে, না থাকলে ইংরেজি */
    public static function named(string $table): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$table}.name_bn, ''), {$table}.name_en)"
            : "COALESCE(NULLIF({$table}.name_en, ''), {$table}.name_bn)";
    }

    /**
     * নিজের জায়গা বা উপরের প্রথম যে ধাপটা এই স্তরের — তার `id`, নাম বা শাখা ([[Customer::pointName()]]-এর একই নিয়ম)।
     *
     * @param  'id'|'name'|'branch_id'  $what
     */
    public static function first(string $level, string $what): string
    {
        $cases = [];

        for ($i = 1; $i <= self::DEPTH; $i++) {
            $value = match ($what) {
                'id' => "l{$i}.id",
                'branch_id' => "l{$i}.branch_id",
                default => self::named("l{$i}"),
            };
            $cases[] = "WHEN l{$i}.level = ".DB::getPdo()->quote($level).' THEN '.$value;
        }

        return 'CASE '.implode(' ', $cases).' END';
    }

    /** গ্রাহকের জায়গা বা তার উপরের যেকোনো ধাপ এই জায়গাটা — পয়েন্ট, টেরিটরি, এরিয়া বা আরও উপরে বাছলে */
    public static function under(Builder $query, int $want): void
    {
        $query->where(function ($m) use ($want): void {
            for ($i = 1; $i <= self::DEPTH; $i++) {
                $m->orWhere("l{$i}.id", $want);
            }
        });
    }

    /**
     * ⭐ গ্রাহক-সারির কোয়েরিকে এক স্তর ধরে দলে ভাগ করা — `groupBy` বসায়, আর বাছার জন্য SQL ফেরায়:
     *   `id`      সারির জায়গার (বা গ্রাহকের) id — নিচে নামার ছাঁকনি; টেরিটরি বসানো না থাকলে তার এরিয়া
     *   `order`   সাজানোর ঘর
     *   `place`   নাম — এরিয়া "শাখা › এরিয়া" (ADI-র চার শাখার চার এরিয়ার একই নাম; মালিক, ২৩ নভেম্বর ২০২৬), খালি হলে "… বসানো নেই"
     *   `parent`  উপরের ধাপের নাম (এরিয়ার স্তরে `NULL`)
     *
     * ⓘ সারির উপনাম `$t`-তে থাকতে হয়: `area_id, area, area_branch_id, territory_id, territory, point_id, point, customer_id, customer`।
     *
     * @return array{id: string, order: string, place: string, parent: string}
     */
    public static function grouped(Builder $query, string $t, string $level): array
    {
        $query->leftJoin('branches as ab', 'ab.id', '=', "{$t}.area_branch_id")
            ->groupBy("{$t}.area_id", "{$t}.area", 'ab.name_bn', 'ab.name_en');
        $area = "CASE WHEN {$t}.area_id IS NULL THEN NULL ELSE CONCAT_WS(' › ', ".self::named('ab').", {$t}.area) END";

        [$id, $name, $parent, $none] = match ($level) {
            Location::AREA => ["{$t}.area_id", $area, 'NULL', 'no_place'],
            Location::TERRITORY => ["{$t}.territory_id", "{$t}.territory", $area, 'no_territory'],
            Location::POINT => ["{$t}.point_id", "{$t}.point", "CONCAT_WS(' · ', {$t}.territory, {$area})", 'no_place'],
            default => ["{$t}.customer_id", "{$t}.customer", "{$t}.point", 'no_place'],
        };

        match ($level) {
            Location::AREA => null,
            Location::TERRITORY => $query->groupBy("{$t}.territory_id", "{$t}.territory"),
            Location::POINT => $query->groupBy("{$t}.point_id", "{$t}.point", "{$t}.territory"),
            default => $query->groupBy("{$t}.customer_id", "{$t}.customer", "{$t}.point"),
        };

        return [
            // ⓘ টেরিটরি বসানো নেই এমন সারিতে চাপলে তার এরিয়ার পয়েন্টগুলো — নিচে নামার জায়গা এরিয়াই
            'id' => $level === Location::TERRITORY ? "COALESCE({$t}.territory_id, {$t}.area_id)" : $id,
            'order' => $id,
            'place' => "COALESCE({$name}, ".DB::getPdo()->quote((string) __('sales::area_sales.'.$none)).')',
            'parent' => $parent,
        ];
    }

    /** স্তরের পরেরটা — এরিয়া → টেরিটরি → পয়েন্ট → গ্রাহক; গ্রাহকের পরে কিছু নেই */
    public static function below(string $level): ?string
    {
        $at = array_search($level, self::LEVELS, true);

        return $at === false ? null : (self::LEVELS[$at + 1] ?? null);
    }
}
