<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ পক্ষ খোঁজার একটাই নিয়ম — গ্রাহক আর সরবরাহকারী, সব জায়গায়।
 *
 * মালিক, ২৩ নভেম্বর ২০২৬: *পক্ষকে নাম, মোবাইল, ঠিকানা বা পয়েন্ট — যেকোনোটা দিয়ে সব জায়গায় খুঁজে পেতে হবে*;
 * সরাসরি বিক্রয়ে পয়েন্টের নাম লিখে কিছুই আসত না। ⓘ আগে প্রতিটা নিয়ন্ত্রক নিজের হাতে নাম/কোড মেলাত, তাই
 * একেক পর্দায় একেক রকম। এখন মডেলের `scopeSearch()` এখানে আসে, আর বাকিরা সেই স্কোপ ডাকে।
 *
 * নিয়ম:
 *   - প্রতিটা শব্দ কোনো না কোনো ঘরে মিলতে হবে (শব্দে শব্দে AND, ঘরে ঘরে OR)।
 *   - সংখ্যার মতো লেখা (স্পেস, ড্যাশ, +88 সহ) এক টুকরো ধরা হয়: খালি অঙ্ক, আর ফোনের ঘরে ফাঁক-ড্যাশ বাদ দিয়ে
 *     "ভেতরে আছে" — তাই মোবাইলের শেষ চার অঙ্কেও পক্ষ আসে।
 *   - পয়েন্ট: পক্ষের নিজের জায়গা, তার মা, আর তারও মা — যেকোনোটার নাম (দুই ভাষা)।
 *
 * ⛔ তালিকার খোঁজার ঘরে কোনো ন্যূনতম দৈর্ঘ্য নেই (১-২ অক্ষর Enter চাপলে চলে); টাইপের সাথে সাথে চলা JSON
 * খোঁজা আর গ্লোবাল খোঁজা [[longEnough()]] দেখে।
 */
final class PartySearch
{
    /** টাইপের সাথে সাথে চলা সার্ভার-খোঁজার ন্যূনতম অক্ষর। */
    public const MIN_TYPED = 3;

    /** এর বেশি শব্দ এলে বাকিগুলো বাদ — একটা খোঁজায় অগুনতি শর্ত নয়। */
    private const MAX_WORDS = 6;

    /**
     * @param  list<string>  $columns  নাম, কোড, ঠিকানা … — যেগুলো "ভেতরে আছে" মেলে
     * @param  list<string>  $phones  ফোনের ঘর — সংখ্যার খোঁজায় ফাঁক-ড্যাশ বাদ দিয়েও মেলে
     * @param  string|null  $locationColumn  পয়েন্টের ঘর (mdm_locations), না থাকলে null
     */
    public static function apply(Builder $query, ?string $term, array $columns, array $phones = [], ?string $locationColumn = null): Builder
    {
        $words = self::words($term);

        if ($words === []) {
            return $query;
        }

        $model = $query->getModel();
        $grammar = $query->getQuery()->getGrammar();

        foreach ($words as [$word, $digits]) {
            $like = '%'.self::escape($word).'%';

            $query->where(function (Builder $q) use ($like, $digits, $columns, $phones, $locationColumn, $model, $grammar) {
                foreach (array_unique([...$columns, ...$phones]) as $column) {
                    $q->orWhere($model->qualifyColumn($column), 'like', $like);
                }

                if ($digits !== null) {
                    $digitsLike = '%'.$digits.'%';

                    foreach ($phones as $phone) {
                        $wrapped = $grammar->wrap($model->qualifyColumn($phone));
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE({$wrapped}, ' ', ''), '-', ''), '+', ''), '.', '') like ?", [$digitsLike]);
                    }
                }

                if ($locationColumn !== null) {
                    $q->orWhereIn($model->qualifyColumn($locationColumn), fn ($s) => $s->select('l.id')->from('mdm_locations as l')
                        ->leftJoin('mdm_locations as p', 'p.id', '=', 'l.parent_id')
                        ->leftJoin('mdm_locations as g', 'g.id', '=', 'p.parent_id')
                        ->where(fn ($w) => $w->where('l.name_en', 'like', $like)->orWhere('l.name_bn', 'like', $like)
                            ->orWhere('p.name_en', 'like', $like)->orWhere('p.name_bn', 'like', $like)
                            ->orWhere('g.name_en', 'like', $like)->orWhere('g.name_bn', 'like', $like)));
                }
            });
        }

        return $query;
    }

    /**
     * লেখাটা টুকরো করে — প্রতিটা [শব্দ, অঙ্ক বা null]। সংখ্যার মতো পুরো লেখা একটাই টুকরো।
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public static function words(?string $term): array
    {
        $term = trim((string) $term);

        if ($term === '') {
            return [];
        }

        $digits = self::phoneDigits($term);

        if ($digits !== null) {
            return [[$digits, $digits]];
        }

        $words = array_values(array_unique(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        return array_map(
            fn (string $word) => [$word, self::phoneDigits($word)],
            array_slice($words, 0, self::MAX_WORDS),
        );
    }

    /**
     * সংখ্যার মতো লেখা হলে খালি অঙ্ক — "+880 1711-223344" → "01711223344"; না হলে null।
     *
     * ⓘ চার অঙ্কের কম হলে null: "12" দিয়ে ফোনের ঘরে ফাঁক বাদ দিয়ে খুঁজলে প্রায় সবাই আসত।
     */
    public static function phoneDigits(string $term): ?string
    {
        $term = trim($term);

        if ($term === '' || preg_match('/^\+?[\d\s\-().]+$/', $term) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $term);

        // +88 / 88 দেশের কোড — দেশের ভেতরের নম্বর 0 দিয়ে শুরু
        if (strlen($digits) > 11 && str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 4 ? $digits : null;
    }

    /** টাইপের সাথে চলা খোঁজায় যথেষ্ট লম্বা কি না — ফাঁক বাদ দিয়ে অন্তত [[MIN_TYPED]] অক্ষর। */
    public static function longEnough(?string $term): bool
    {
        return mb_strlen(trim((string) $term)) >= self::MIN_TYPED;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
