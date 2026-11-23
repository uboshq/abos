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

    /** স্তরের পরেরটা — এরিয়া → টেরিটরি → পয়েন্ট → গ্রাহক; গ্রাহকের পরে কিছু নেই */
    public static function below(string $level): ?string
    {
        $at = array_search($level, self::LEVELS, true);

        return $at === false ? null : (self::LEVELS[$at + 1] ?? null);
    }
}
