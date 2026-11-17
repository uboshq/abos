<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * ⭐ পাতার জন্য প্যানেলের পুরো তথ্য এক ডাকে — `x-ui.filter-layout :filters` আর টুলবারের `:facets` এটাই পায়।
 *
 * ⓘ তালিকার কন্ট্রোলার লেখে: `'filters' => Panel::of($set, fn () => $base(), $request)`। ⛔ `$base` ছাঁকনি ছাড়া, কিন্তু
 *   শাখা/দেয়াল/খোঁজা সহ — সংখ্যা সেই তালিকা থেকেই যা মানুষটা দেখতে পান।
 */
final class Panel
{
    /**
     * @param  Closure(): Builder  $base
     * @return array{sections: list<array<string, mixed>>, count: int, chips: list<array{text: string, url: string, tone: string}>, clear: string, date: array<string, mixed>|null, cards: list<array<string, mixed>>}
     */
    public static function of(FilterSet $set, Closure $base, Request $request): array
    {
        /*
         * ⭐ টিক দিতে দিতেই সংখ্যা — প্যানেল একই ঠিকানায় `_filter_counts=1` দিয়ে জিজ্ঞেস করে, আর পাতার বদলে এই উত্তর পায়:
         *   প্রতিটা ঘরের সংখ্যা আর মোট। ⓘ একই কোয়েরি, একই দেয়াল — পাতা যা দেখাত তার বাইরের কিছু নয়।
         */
        if ($request->boolean('_filter_counts')) {
            throw new HttpResponseException(response()->json([
                'total' => $set->total($base, $request),
                'counts' => $set->counts($base, $request),
            ]));
        }

        /*
         * ⓘ বারের "📅 তারিখ ▾" বোতামে বাছা তারিখ লেখা থাকে ("এই মাস") — তাই সেটা আবার দুটো চিপ ("থেকে …", "পর্যন্ত …") হয়ে
         *   আসে না, আর ছাঁকনির বোতামের সংখ্যাতেও গোনা হয় না; একই কথা দুইবার লিখলে বারটা দুই লাইনে ভাঙত (১৬ নভেম্বর ২০২৬-এর ছবি)।
         *   ⓘ কার্ডের "কিছু বাছা নেই" প্রশ্নে তারিখও গোনে — ওটা পুরো তালিকা বনাম ছাঁকা তালিকা।
         */
        $all = $set->chips($request);
        $chips = array_values(array_map(fn (array $c) => array_diff_key($c, ['bar' => true]), array_filter($all, fn (array $c) => ! $c['bar'])));

        return [
            'sections' => $set->sections($request, $set->counts($base, $request)),
            'count' => count($chips),
            'chips' => $chips,
            'clear' => $set->clearUrl($request),
            'date' => $set->bar($request),
            'cards' => array_map(fn (ListCard $card) => $card->view($base, $request, $set->keys(), $all === []), $set->cards),
        ];
    }
}
