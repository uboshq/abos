<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ প্রতিটা পাতায় ঠিক একটা মূল শিরোনাম (h1) — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (WCAG 2.2 §1.3.1, §2.4.6)।
 *
 * <p>⛔ লাইভ নিরীক্ষায় ১৪টা পাতায় h1 দুটো, ৮টায় একটাও নয়। দুটো আসত যখন একই পাতায় [[ui/page-header]] আর
 * [[ui/toolbar]] দুজনেই নিজের শিরোনাম h1-এ আঁকত — প্রত্যেকে জানত না অন্যজন আছে। স্ক্রিন-রিডার তখন পাতার দুটো "নাম"
 * পড়ত, আর শিরোনাম ধরে লাফানো ভাঙত।
 *
 * <p>ⓘ নিয়ম: অনুরোধে প্রথম যে উপাদান শিরোনাম চায়, সে h1 পায়; পরেরগুলো h2 — দেখতে একই (শ্রেণি অপরিবর্তিত), কেবল
 * ট্যাগ। পাতায় কোনো h1-ই না থাকলে layout নিজে একটা অদৃশ্য h1 বসায় পাতার নামে ([[layouts/app]])।
 * ⓘ অবস্থা অনুরোধের গায়ে (`request()->attributes`), তাই এক অনুরোধের দাবি পরেরটায় যায় না — টেস্টে পরপর পাতা খুললেও।
 */
final class PageHeading
{
    private const KEY = 'abos.page_heading_given';

    /** শিরোনামের ট্যাগ — অনুরোধে প্রথমবার `h1`, তারপর `h2` */
    public static function tag(): string
    {
        $attributes = request()->attributes;

        if ($attributes->get(self::KEY) === true) {
            return 'h2';
        }

        $attributes->set(self::KEY, true);

        return 'h1';
    }

    /** পাতার আঁকা অংশে কোনো h1 আছে কি না — layout-এর অদৃশ্য শিরোনামের সিদ্ধান্ত */
    public static function present(string ...$rendered): bool
    {
        foreach ($rendered as $html) {
            if (preg_match('/<h1[\s>]/i', $html) === 1) {
                return true;
            }
        }

        return false;
    }
}
