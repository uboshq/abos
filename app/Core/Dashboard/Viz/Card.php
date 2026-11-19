<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Viz;

/**
 * ⭐ গ্রাফের বোর্ডের একটা কার্ড — মডিউল ড্যাশবোর্ড এটা দিয়ে তার ছয়টা গ্রাফ বলে ([[viz-board]] আঁকে, [[Svg]] গ্রাফ বানায়)।
 *
 * মালিক, ২০ নভেম্বর ২০২৬ (নমুনা RoPEayT7 "ok"): খোলার প্রথম পর্দায় এক মাপের ছয়টা গ্রাফ, ছয় রকম; আগের সংখ্যার ঘর গ্রাফে,
 * একই তথ্য দুইবার নয়। ⓘ টাকা বা পরিমাণ এখানে আসে স্ট্রিং/পূর্ণসংখ্যা হয়ে — float কেবল [[Svg]]-এ, আঁকার মাপে।
 *
 * @phpstan-type CardArray array{title: string, kind: string, data: array<string, mixed>, value: string, sub: string, legend: list<array{0: string, 1: string}>, href: string, colour: string}
 */
final class Card
{
    /**
     * @param  string  $kind  [[Svg::render()]]-এর একটা: line, bars, hbars, pairs, lollipop, donut, gauge, radial, waffle, stackedArea, stack100,
     *                        funnel, heat, calendar, bullet, bubble, gantt, radar, sankey, waterfall, treemap, spark
     * @param  array<string, mixed>  $data  গ্রাফের তথ্য — প্রতিটা ধরনের চাবি [[Svg]]-এর পদ্ধতির মাথায় লেখা
     * @param  string  $value  মাথার ডানে বড় করে (৳ 41.2 লা, 92% …) — না থাকলে ফাঁকা
     * @param  string  $sub  শিরোনামের নিচে এক লাইন: কী দেখানো হচ্ছে, কোন সময়ের
     * @param  list<array{0: string, 1: string}>  $legend  একাধিক সারি হলে রং আর নাম
     * @param  string  $href  "বিস্তারিত →" — পুরো তালিকা বা রিপোর্টের ঠিকানা
     * @param  string  $colour  কার্ডের মাথার দাগের রং
     * @return CardArray
     */
    public static function make(string $title, string $kind, array $data, string $value = '', string $sub = '', array $legend = [], string $href = '', string $colour = ''): array
    {
        return [
            'title' => $title,
            'kind' => $kind,
            'data' => $data,
            'value' => $value,
            'sub' => $sub,
            'legend' => $legend,
            'href' => $href,
            'colour' => $colour !== '' ? $colour : Svg::PALETTE[abs(crc32($title)) % count(Svg::PALETTE)],
        ];
    }

    /**
     * ⭐ ড্যাশবোর্ডের সংজ্ঞায় বসানোর অংশ — মাথায় (`place` = top), গোনা কেবল পাতা আঁকার সময় (Closure)।
     *
     * @param  \Closure(): list<CardArray>  $cards
     * @param  list<string>  $covers  আগের যে সংখ্যা/চার্ট/তালিকার নাম এই গ্রাফগুলোয় এসেছে — পাতায় সেগুলো আর আলাদা আঁকা হয় না
     * @return array{view: string, place: string, permission?: string, covers: list<string>, data: \Closure(): array<string, mixed>}
     */
    public static function board(\Closure $cards, array $covers = [], ?string $permission = null): array
    {
        $section = [
            'view' => 'dashboard.viz-board',
            'place' => 'top',
            'covers' => $covers,
            'data' => fn (): array => ['vizCards' => $cards()],
        ];

        if ($permission !== null) {
            $section['permission'] = $permission;
        }

        return $section;
    }
}
