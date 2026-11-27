<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;

/**
 * ⭐ তালিকার "সাজানো" — পুরো বারের একটা অংশ, সব তালিকায় এক নিয়মে (মালিক, ২৪ নভেম্বর ২০২৬: "the WHOLE standard bar on every page";
 * জরিপ পুরো-বার-জরিপ-২৬-নভেম্বর.html)।
 *
 * ⓘ পাতা বলে কোন চাবিতে কোন কলাম — `['newest' => ['trx_date', 'desc'], 'amount_high' => ['amount', 'desc']]`; প্রথম চাবিই আগের মতো ক্রম
 *   (কিছু না বাছলে তালিকা যেমন ছিল তেমন)। অজানা চাবি চুপচাপ প্রথমটায় ফেরে — ঠিকানায় যা খুশি লিখে SQL-এ কলাম ঢোকানো যায় না।
 * ⓘ লেখা এক জায়গায় (`core.list_sort.*`), বারের ড্রপডাউন [[labels()]] থেকে।
 */
final class ListSort
{
    /** জানা চাবি — লেখা `core.list_sort.{key}`-এ */
    public const KEYS = ['newest', 'oldest', 'amount_high', 'amount_low', 'name_az', 'name_za', 'code', 'due_soon'];

    /**
     * বাছা চাবি — অজানা বা ফাঁকা হলে প্রথমটা।
     *
     * @param  array<string, array{0: string|\Illuminate\Contracts\Database\Query\Expression, 1: string}|list<array{0: string|\Illuminate\Contracts\Database\Query\Expression, 1: string}>>  $map
     */
    public static function chosen(mixed $key, array $map): string
    {
        $key = $key instanceof Request ? $key->query('sort') : $key;

        return is_string($key) && array_key_exists($key, $map) ? $key : (string) array_key_first($map);
    }

    /**
     * ক্রম বসানো — আগের ক্রম মুছে (`reorder`), তারপর বাছা চাবির কলাম(গুলো), শেষে `id` একই দিকে, যাতে পাতা বদলালে সারি না লাফায়।
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @param  array<string, array{0: string|\Illuminate\Contracts\Database\Query\Expression, 1: string}|list<array{0: string|\Illuminate\Contracts\Database\Query\Expression, 1: string}>>  $map
     * @return T
     */
    public static function apply(BuilderContract $query, mixed $sort, array $map, string $idColumn = 'id'): BuilderContract
    {
        // ⓘ `$sort` — অনুরোধ নিজে (কন্ট্রোলারে) বা বাছা চাবি (সেবায়; সেবা অনুরোধ পড়ে না)
        $key = self::chosen($sort, $map);
        $orders = $map[$key];
        $orders = is_array($orders[0] ?? null) ? $orders : [$orders];

        $query->reorder();
        $last = 'desc';
        foreach ($orders as [$column, $direction]) {
            $last = strtolower($direction) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($column, $last);
        }

        return $query->orderBy($idColumn, $last);
    }

    /**
     * বারের ড্রপডাউন — চাবি => লেখা, পাতার দেওয়া ক্রমে।
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function labels(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) __('core.list_sort.'.$key);
        }

        return $out;
    }
}
