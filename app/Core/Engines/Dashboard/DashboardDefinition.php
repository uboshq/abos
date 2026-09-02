<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use InvalidArgumentException;

/**
 * একটা মডিউলের ড্যাশবোর্ড কী কী দিয়ে তৈরি।
 *
 * ── কেন উপরে সংখ্যা, নিচে পট ─────────────────────────────────────────
 * পর্দায় ঢুকে মানুষ প্রথমে জানতে চান **আজ কেমন আছি** — সেটা কয়েকটা
 * সংখ্যা। তারপর **কেন এমন** — সেটা ধারা আর ভাগ। শেষে **এখন কী করব** —
 * সেটা তালিকা।
 *
 * ক্রমটা এখানে বাঁধা, প্রতিটা মডিউলের পছন্দে নয়। বারোটা মডিউল বারো
 * রকম সাজালে পর্দাগুলো এক পরিবারের মনে হত না, আর মানুষ প্রতিটায় নতুন
 * করে চোখ সেট করতেন।
 */
final class DashboardDefinition
{
    /**
     * @param  list<Stat>  $stats
     * @param  list<Series|Breakdown>  $panels
     * @param  list<Listing>  $listings
     * @param  list<Tile>  $tiles
     * @param  list<\App\Core\Dashboard\Widget>  $reminders
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $stats = [],
        public readonly array $panels = [],
        public readonly array $listings = [],

        /**
         * দ্রুত-কাজের টাইল — পড়ার পর করার জায়গা।
         *
         * সবার উপরে বসে, সংখ্যারও আগে: মানুষ প্রায়ই ড্যাশবোর্ডে আসেন
         * কিছু **করতে**, আর তখন সংখ্যাগুলো পেরিয়ে নামতে হলে পরেরবার
         * তিনি সোজা মেনুতে যান।
         */
        public readonly array $tiles = [],

        /**
         * করণীয় — মডিউলের নিজের উইজেট থেকেই।
         *
         * ── কেন নতুন কোনো ধরন নয় ────────────────────────────────────
         * প্রতিটা মডিউল ইতিমধ্যেই [[DashboardWidgets]] দিয়ে বলে "কী
         * আটকে আছে" — হোম পর্দা সেটাই দেখায়। ড্যাশবোর্ডের জন্য
         * দ্বিতীয়বার একই কথা লেখালে দুইটা তালিকা একদিন আলাদা হত।
         */
        public readonly array $reminders = [],
    ) {
        if ($stats === [] && $panels === [] && $listings === []) {
            throw new InvalidArgumentException(
                "Dashboard '{$title}' has nothing on it. An empty dashboard is worse than none: "
                .'it teaches people the screen is not worth opening.'
            );
        }
    }
}
