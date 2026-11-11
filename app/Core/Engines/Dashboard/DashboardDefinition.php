<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use App\Core\Dashboard\Widget;
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
     * @param  list<Widget>  $reminders
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

        /**
         * ⭐ ড্যাশবোর্ডের মাথায় খোঁজার ঘর — কোথায় পাঠায় আর কোন নামে (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৩: "হোম থেকে সব সেটিং জুড়ে
         * খোঁজা; ফল সরাসরি সেই সেটিংয়ে")। চাবি না থাকলে ঘরটাই নেই ([[DashboardEngine]])।
         *
         * @var array{action: string, name: string, placeholder: string, permission: string}|null
         */
        public readonly ?array $search = null,

        /**
         * ⭐ মডিউলের নিজের অংশ — পাতার সবার নিচে, তালিকারও পরে (মালিক, ৯ নভেম্বর ২০২৬: অর্থের ড্যাশবোর্ডে চলতি মূলধন "সবার নিচে",
         * মালিকের ছবির হুবহু, তারিখ বাছার ঘরসহ)। ⓘ সংখ্যা-চার্ট-তালিকার সাধারণ ছাঁচে যা ধরে না, তা মডিউল নিজের Blade-এ আঁকে।
         *
         * ⓘ `place` = `top` হলে সংখ্যার আগে মাথায়, নাহলে সবার নিচে; `data` একটা Closure-ও হতে পারে — তখন অঙ্ক কেবল পাতা আঁকার
         * সময় গোনা হয় (হোম অন্য মডিউলের সংজ্ঞা তোলে, অংশগুলো আঁকে না)।
         *
         * @var list<array{view: string, data: array<string, mixed>|\Closure(): array<string, mixed>, permission?: string, place?: 'top'|'bottom'}>
         */
        public readonly array $sections = [],

        /**
         * ⭐ পাতায় কেবল নিজের সারি (sections) — মালিকের চূড়ান্ত নমুনার হুবহু (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
         * ⓘ সংখ্যা-চার্ট-তালিকা তবু থাকে — হোম, ফোন আর খোঁজা ওগুলো পড়ে; কেবল মডিউলের নিজের পাতায় আঁকা হয় না ([[forPage()]])।
         */
        public readonly bool $board = false,
    ) {
        // ⓘ নিজের সারিতে আঁকা ড্যাশবোর্ডও (মালিকের চূড়ান্ত নমুনার হুবহু, 10 নভেম্বর 2026 রাত) খালি নয় — sections-ই তার সবটা
        if ($stats === [] && $panels === [] && $listings === [] && $sections === []) {
            throw new InvalidArgumentException(
                "Dashboard '{$title}' has nothing on it. An empty dashboard is worse than none: "
                .'it teaches people the screen is not worth opening.'
            );
        }
    }

    /**
     * ⭐ মডিউলের নিজের পাতার রূপ — `board` হলে কেবল sections (সংখ্যা, চার্ট, তালিকা, টাইল, করণীয় পাতায় আঁকা হয় না); নাহলে হুবহু এটাই।
     */
    public function forPage(): self
    {
        /*
         * ⓘ বোর্ডের কোনো সারির চাবি যাঁর নেই (যেমন কেবল মূলধনের চাবি — অর্থের সব সারি হিসাবের চাবি চায়), তাঁর পাতা খালি হত, আর খালি
         *   ড্যাশবোর্ড ভাঙে (৫০০; Mac run of 050355e0c, EveryModuleDashboardHasItsOwnDoorTest)। ⭐ তখন আগের কার্ড-চার্টের পাতা — চাবি মেনে
         *   যা তাঁর দেখার, সেটুকুই ([[DashboardEngine::for()]] আগেই ছেঁকেছে)।
         */
        if (! $this->board || $this->sections === []) {
            return $this;
        }

        return new self(title: $this->title, subtitle: $this->subtitle, search: $this->search, sections: $this->sections);
    }
}
