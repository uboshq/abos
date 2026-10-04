<?php

declare(strict_types=1);

namespace App\Modules\Sales\Metrics;

use App\Modules\MasterData\Models\Location;
use Illuminate\Support\Collection;

/**
 * বিক্রির "এলাকা" — একটাই নিয়ম, দুই জায়গায় ([[SalesAnalytics::byTerritory()]] আর হোমের ছাঁকনি, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ ধাপটা `territory` (মালিকের নামে "এরিয়া"); সেটা বন্ধ থাকলে `area` ("রিজিয়ন")। গ্রাহকের জায়গা থেকে মই বেয়ে
 * সেই ধাপে ওঠা। ⚠️ গাছটা একবারে আনা হয় আর মইটা PHP-তে বাওয়া হয় — এক কোয়েরিতে জোড়া দিলে গাছের ধাপ হারায় (abos-bb)।
 */
final class SalesArea
{
    public static function level(): string
    {
        return in_array(Location::TERRITORY, Location::activeLadder(), true) ? Location::TERRITORY : Location::AREA;
    }

    /** @return Collection<int, Location> */
    public static function tree(): Collection
    {
        return Location::query()->get(['id', 'parent_id', 'level', 'name_en', 'name_bn'])->keyBy('id');
    }

    /**
     * একটা জায়গার উপরের এলাকা — না পেলে `null`।
     *
     * @param  Collection<int, Location>  $tree
     */
    public static function of(?int $locationId, Collection $tree, string $level): ?Location
    {
        $node = $tree->get($locationId);

        for ($depth = 0; $node !== null && $node->level !== $level && $depth < 10; $depth++) {
            $node = $tree->get($node->parent_id);
        }

        return $node;
    }
}
