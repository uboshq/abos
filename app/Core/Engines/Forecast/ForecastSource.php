<?php

declare(strict_types=1);

namespace App\Core\Engines\Forecast;

/**
 * ⭐ পূর্বাভাসের একটা উৎস — মডিউল বলে কোন সংখ্যার দিন-ধরা ইতিহাস তার কাছে আছে (পূর্ণ পরিকল্পনা অংশ ১০: "এক সংখ্যা, এক জায়গা")।
 *
 * ⓘ মডিউল নিজে দেয় (`module.php` → `forecasts`), কোর মডিউলের নাম জানে না ([[ForecastEngine]])।
 * ⓘ উৎস কেবল ইতিহাস দেয় — হিসাবের নিয়ম (গড়, সপ্তাহের দিনের ধরন, ভরসার সীমা) একটাই, ইঞ্জিনে; তাই মজুদের পুনঃক্রয়,
 *   বিক্রির গতি আর কোপাইলটের প্রস্তাব একই চিনির জন্য কখনো দুই রকম সংখ্যা বলে না।
 */
interface ForecastSource
{
    /** অনন্য নাম — "sales.net", "stock.out:42", "cash.out" */
    public static function key(): string;

    /** যে চাবি লাগে */
    public static function permission(): string;

    /**
     * দিন ধরে ইতিহাস — 'Y-m-d' => মান (টাকা বা পরিমাণ); যে দিন কিছু হয়নি তা না দিলেও চলে (ইঞ্জিন শূন্য ধরে)।
     *
     * @param  array<string, mixed>  $args  যেমন পণ্য বা গুদাম
     * @return array<string, string>
     */
    public function history(string $from, string $to, array $args = []): array;
}
