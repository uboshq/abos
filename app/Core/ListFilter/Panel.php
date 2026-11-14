<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;
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
     * @return array{sections: list<array<string, mixed>>, count: int, chips: list<array{text: string, url: string}>, clear: string, folded: bool}
     */
    public static function of(FilterSet $set, Closure $base, Request $request): array
    {
        $chips = $set->chips($request);
        $prefs = $request->user()?->list_prefs;

        return [
            'sections' => $set->sections($request, $set->counts($base, $request)),
            'count' => count($chips),
            'chips' => $chips,
            'clear' => $set->clearUrl($request),
            'folded' => is_array($prefs) && ($prefs['folded'] ?? false) === true,
        ];
    }
}
